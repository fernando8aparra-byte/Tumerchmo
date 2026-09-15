<?php
/**
 * binance_webhook.php
 * -------------------------------------------------------------
 * ESTE es el único archivo que suma saldo por pagos con Binance Pay.
 *
 * A diferencia de Stripe/Mercado Pago (que firman con una clave
 * secreta compartida, HMAC), Binance firma sus notificaciones con
 * RSA: nosotros necesitamos su LLAVE PÚBLICA para verificar que el
 * aviso de verdad viene de ellos. La conseguimos con la API "Query
 * Certificate" en cada aviso (usando nuestras propias credenciales
 * para firmar ESA consulta con el método normal HMAC).
 * -------------------------------------------------------------
 * INSTALACIÓN: sube este archivo junto con binance-sign.php a /api.
 * No hace falta configurar nada en el panel de Binance -- la URL del
 * webhook se manda directo en cada orden (ver create_binance_order.php).
 *
 * Documentación oficial usada:
 *   https://merchant.binance.com/en/docs/functionalities/webhooks
 * -------------------------------------------------------------
 */

require __DIR__ . '/firestore-admin.php';
require __DIR__ . '/binance-sign.php';
require __DIR__ . '/notify-admin.php';

$rawBody = file_get_contents('php://input');
$timestamp = $_SERVER['HTTP_BINANCEPAY_TIMESTAMP'] ?? '';
$nonce = $_SERVER['HTTP_BINANCEPAY_NONCE'] ?? '';
$signatureB64 = $_SERVER['HTTP_BINANCEPAY_SIGNATURE'] ?? '';

if (!$timestamp || !$nonce || !$signatureB64) {
    http_response_code(400);
    error_log('binance_webhook: faltan headers de firma');
    echo json_encode(['returnCode' => 'FAIL', 'returnMessage' => 'missing headers']);
    exit;
}

$fsToken = getFirestoreAdminToken();
if (!$fsToken) {
    http_response_code(500);
    error_log('binance_webhook: no se pudo autenticar con Firestore');
    echo json_encode(['returnCode' => 'FAIL', 'returnMessage' => 'internal error']);
    exit;
}
$config = fsGetDoc($fsToken, 'config/binance');
if (!$config || empty($config['apiKey']) || empty($config['secretKey'])) {
    http_response_code(500);
    error_log('binance_webhook: falta configuración de Binance');
    echo json_encode(['returnCode' => 'FAIL', 'returnMessage' => 'not configured']);
    exit;
}

/* ---------- 1) Conseguir la llave pública de Binance (Query Certificate) ---------- */
$certResult = callBinanceApi($config['apiKey'], $config['secretKey'], '/binancepay/openapi/certificates', []);
$certData = $certResult['ok'] ? ($certResult['data']['data'] ?? null) : null;
$publicKeyPem = null;
if (is_array($certData)) {
    // La documentación de Binance no da un ejemplo de respuesta para
    // este endpoint -- cubrimos las dos formas plausibles (objeto
    // directo, o arreglo de certificados) en vez de apostar a una sola.
    if (isset($certData['certPublic'])) {
        $publicKeyPem = $certData['certPublic'];
    } elseif (isset($certData[0]['certPublic'])) {
        $publicKeyPem = $certData[0]['certPublic'];
    }
}
if (!$publicKeyPem) {
    http_response_code(500);
    error_log('binance_webhook: no se pudo obtener la llave pública de Binance -- ' . json_encode($certResult));
    echo json_encode(['returnCode' => 'FAIL', 'returnMessage' => 'certificate error']);
    exit;
}

/* ---------- 2) VERIFICAR LA FIRMA (lo más importante) ---------- */
$payload = $timestamp . "\n" . $nonce . "\n" . $rawBody . "\n";
$decodedSignature = base64_decode($signatureB64);
$isValid = openssl_verify($payload, $decodedSignature, $publicKeyPem, OPENSSL_ALGO_SHA256) === 1;

if (!$isValid) {
    http_response_code(400);
    error_log('binance_webhook: firma inválida -- aviso descartado');
    echo json_encode(['returnCode' => 'FAIL', 'returnMessage' => 'invalid signature']);
    exit;
}

/* ---------- 3) Procesar el aviso ---------- */
$event = json_decode($rawBody, true);
$bizType = $event['bizType'] ?? '';
$bizStatus = $event['bizStatus'] ?? '';
$innerData = json_decode($event['data'] ?? '{}', true) ?: [];

// Siempre respondemos 200+SUCCESS a Binance para eventos que no nos
// interesan (payouts, reembolsos) -- si no, Binance sigue reintentando.
if ($bizType !== 'PAY' || $bizStatus !== 'PAY_SUCCESS') {
    echo json_encode(['returnCode' => 'SUCCESS', 'returnMessage' => null]);
    exit;
}

$merchantTradeNo = $innerData['merchantTradeNo'] ?? '';
$amount = isset($innerData['totalFee']) ? (float)$innerData['totalFee'] : 0;
$transactionId = $innerData['transactionId'] ?? null;

if (!$merchantTradeNo || $amount <= 0 || !$transactionId) {
    echo json_encode(['returnCode' => 'SUCCESS', 'returnMessage' => null]); // nada que procesar
    exit;
}

/* ---------- 4) Encontrar a quién le pertenece esta orden ---------- */
$orderDoc = fsGetDoc($fsToken, 'binance_orders/' . $merchantTradeNo);
if (!$orderDoc || empty($orderDoc['uid'])) {
    error_log('binance_webhook: no se encontró binance_orders/' . $merchantTradeNo);
    echo json_encode(['returnCode' => 'SUCCESS', 'returnMessage' => null]);
    exit;
}
$uid = $orderDoc['uid'];

/* ---------- 5) Evitar acreditar dos veces ---------- */
$docId = 'bnc_' . $transactionId;
$existing = fsGetDoc($fsToken, 'payments/' . $docId);
if ($existing) {
    echo json_encode(['returnCode' => 'SUCCESS', 'returnMessage' => null]);
    exit;
}

/* ---------- 6) Acreditar el saldo ---------- */
$tech = fsGetDoc($fsToken, 'technicians/' . $uid);
$currentBalance = (float)($tech['balance'] ?? 0);
$newBalance = round($currentBalance + $amount, 2);

$ok = fsPatchDoc($fsToken, 'technicians/' . $uid, ['balance' => $newBalance]);
if (!$ok) {
    http_response_code(500);
    error_log("binance_webhook: no se pudo actualizar el saldo de $uid");
    echo json_encode(['returnCode' => 'FAIL', 'returnMessage' => 'balance update failed']);
    exit;
}

fsCreateDoc($fsToken, 'payments', $docId, [
    'uid' => $uid,
    'provider' => 'binance',
    'amount' => $amount,
    'transactionId' => $transactionId,
    'merchantTradeNo' => $merchantTradeNo,
    'balanceBefore' => $currentBalance,
    'balanceAfter' => $newBalance,
    'createdAt' => gmdate('Y-m-d\TH:i:s\Z'),
]);
fsPatchDoc($fsToken, 'binance_orders/' . $merchantTradeNo, ['status' => 'completed']);

notifyAdmin(
    $fsToken,
    'Nueva recarga -- $' . number_format($amount, 2) . ' USD (Binance Pay)',
    "💳 Nueva recarga en F3RN4N\n" .
    "Vía: Binance Pay\n" .
    'Monto: $' . number_format($amount, 2) . " USD\n" .
    'Técnico UID: ' . $uid . "\n" .
    'Saldo nuevo: $' . number_format($newBalance, 2) . ' USD'
);

echo json_encode(['returnCode' => 'SUCCESS', 'returnMessage' => null]);
exit;