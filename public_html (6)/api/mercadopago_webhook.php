<?php
/**
 * mercadopago_webhook.php
 * -------------------------------------------------------------
 * ESTE es el único archivo que suma saldo por pagos con Mercado Pago.
 *
 * Mercado Pago avisa aquí cuando cambia el estado de un pago. Antes de
 * acreditar nada:
 *   1) Verificamos la firma (header x-signature) contra la "Clave
 *      secreta" del webhook -- solo Mercado Pago la conoce.
 *   2) Con el ID del pago, le PREGUNTAMOS a la API de Mercado Pago
 *      cuál es el estado real y el monto real -- nunca confiamos en
 *      los datos que vienen en la notificación misma, porque esos sí
 *      podrían falsificarse más fácilmente que la firma.
 * -------------------------------------------------------------
 * INSTALACIÓN:
 * 1. Sube este archivo a /api.
 * 2. En Mercado Pago → Tu aplicación → Webhooks → "Configurar
 *    notificaciones": URL = https://TU-DOMINIO.com/api/mercadopago_webhook.php
 *    Eventos: Pagos.
 * 3. Copia la "Clave secreta" que te muestran y guárdala en
 *    admin.html → Config. Mercado Pago.
 * -------------------------------------------------------------
 */

require __DIR__ . '/firestore-admin.php';
require __DIR__ . '/notify-admin.php';

$fsToken = getFirestoreAdminToken();
if (!$fsToken) {
    http_response_code(500);
    error_log('mercadopago_webhook: no se pudo autenticar con Firestore');
    echo 'error';
    exit;
}

$mpConfig = fsGetDoc($fsToken, 'config/mercadopago');
if (!$mpConfig || empty($mpConfig['accessToken']) || empty($mpConfig['webhookSecret'])) {
    http_response_code(500);
    error_log('mercadopago_webhook: falta accessToken o webhookSecret en config/mercadopago');
    echo 'error';
    exit;
}
$accessToken = $mpConfig['accessToken'];
$webhookSecret = $mpConfig['webhookSecret'];

/* ---------- 1) Sacar el ID del pago (puede venir por GET o por el body) ---------- */
$paymentId = $_GET['data_id'] ?? $_GET['id'] ?? null;
if (!$paymentId) {
    $body = json_decode(file_get_contents('php://input'), true);
    $paymentId = $body['data']['id'] ?? null;
}
$topic = $_GET['type'] ?? $_GET['topic'] ?? null;

if (!$paymentId || ($topic && $topic !== 'payment')) {
    http_response_code(200); // no es un pago, no hay nada que hacer
    echo 'ignorado';
    exit;
}

/* ---------- 2) VERIFICAR LA FIRMA ---------- */
$xSignature = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
$xRequestId = $_SERVER['HTTP_X_REQUEST_ID'] ?? '';
if (!verifyMpSignature($xSignature, $xRequestId, (string)$paymentId, $webhookSecret)) {
    http_response_code(400);
    error_log('mercadopago_webhook: firma inválida -- aviso descartado');
    echo 'firma invalida';
    exit;
}

/* ---------- 3) Preguntarle a Mercado Pago el estado REAL del pago ---------- */
$ch = curl_init('https://api.mercadopago.com/v1/payments/' . urlencode((string)$paymentId));
curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
]);
$raw = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$payment = json_decode($raw, true);
if ($code !== 200 || empty($payment)) {
    http_response_code(200); // puede ser un pago de prueba/inexistente; no reintentar
    error_log('mercadopago_webhook: no se pudo leer el pago ' . $paymentId);
    echo 'sin datos';
    exit;
}

$status = $payment['status'] ?? '';
$uid = $payment['metadata']['uid'] ?? ($payment['external_reference'] ?? '');
$amount = (float)($payment['transaction_amount'] ?? 0);

if ($status !== 'approved' || !$uid || $amount <= 0) {
    http_response_code(200);
    echo 'sin aplicar (status=' . $status . ')';
    exit;
}

/* ---------- 4) Evitar acreditar dos veces ---------- */
$docId = 'mp_' . $paymentId;
$existing = fsGetDoc($fsToken, 'payments/' . $docId);
if ($existing) {
    http_response_code(200);
    echo 'ya procesado';
    exit;
}

/* ---------- 5) Acreditar el saldo ---------- */
$tech = fsGetDoc($fsToken, 'technicians/' . $uid);
$currentBalance = (float)($tech['balance'] ?? 0);
$newBalance = round($currentBalance + $amount, 2);

$ok = fsPatchDoc($fsToken, 'technicians/' . $uid, ['balance' => $newBalance]);
if (!$ok) {
    http_response_code(500);
    error_log("mercadopago_webhook: no se pudo actualizar el saldo de $uid");
    echo 'error al acreditar';
    exit;
}

fsCreateDoc($fsToken, 'payments', $docId, [
    'uid' => $uid,
    'provider' => 'mercadopago',
    'amount' => $amount,
    'paymentId' => (string)$paymentId,
    'email' => $payment['payer']['email'] ?? null,
    'balanceBefore' => $currentBalance,
    'balanceAfter' => $newBalance,
    'createdAt' => gmdate('Y-m-d\TH:i:s\Z'),
]);

notifyAdmin(
    $fsToken,
    'Nueva recarga -- $' . number_format($amount, 2) . ' USD (Mercado Pago)',
    "💳 Nueva recarga en F3RN4N\n" .
    "Vía: Mercado Pago\n" .
    'Monto: $' . number_format($amount, 2) . " USD\n" .
    'Técnico: ' . ($payment['payer']['email'] ?? $uid) . "\n" .
    'Saldo nuevo: $' . number_format($newBalance, 2) . ' USD'
);

http_response_code(200);
echo 'ok';
exit;


/* =================================================================
 * Verifica la firma del header x-signature de Mercado Pago.
 * Formato: "ts=1704908010,v1=618c85345248dd..."
 * El texto firmado es: "id:{data.id};request-id:{x-request-id};ts:{ts};"
 * (si el id trae letras, Mercado Pago pide usarlo en minúsculas)
 * ================================================================= */
function verifyMpSignature(string $xSignature, string $xRequestId, string $dataId, string $secret): bool
{
    if (!$xSignature) return false;

    $ts = null;
    $v1 = null;
    foreach (explode(',', $xSignature) as $part) {
        $kv = explode('=', trim($part), 2);
        if (count($kv) !== 2) continue;
        if (trim($kv[0]) === 'ts') $ts = trim($kv[1]);
        if (trim($kv[0]) === 'v1') $v1 = trim($kv[1]);
    }
    if (!$ts || !$v1) return false;

    $manifest = 'id:' . strtolower($dataId) . ';request-id:' . $xRequestId . ';ts:' . $ts . ';';
    $expected = hash_hmac('sha256', $manifest, $secret);

    return hash_equals($expected, $v1);
}