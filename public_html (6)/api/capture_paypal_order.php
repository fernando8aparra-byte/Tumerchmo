<?php
/**
 * capture_paypal_order.php
 * -------------------------------------------------------------
 * ESTE es el único archivo que suma saldo por pagos con PayPal.
 *
 * El botón oficial de PayPal llama aquí justo después de que el
 * cliente aprueba el pago en la ventana de PayPal. Aquí le
 * PREGUNTAMOS a PayPal directamente si el pago de verdad se completó y
 * cuánto fue -- nunca confiamos en nada que mande el navegador del
 * cliente, ni siquiera el "aprobado" que ya vimos en pantalla.
 * -------------------------------------------------------------
 * INSTALACIÓN: sube este archivo a /api.
 * -------------------------------------------------------------
 */

require __DIR__ . '/firestore-admin.php';

define('FIREBASE_WEB_API_KEY', 'AIzaSyAv2zk1jEQJcyTfEb7RsmsNtFAp3J5sSnI');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
    exit;
}

$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if (!preg_match('/Bearer\s+(.+)/i', $authHeader, $m)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Falta el token de sesión']);
    exit;
}
$firebaseUser = verifyFirebaseIdToken(trim($m[1]));
if (!$firebaseUser) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Token inválido o expirado']);
    exit;
}
$uid = $firebaseUser['uid'];

$input = json_decode(file_get_contents('php://input'), true);
$orderId = trim((string)($input['orderId'] ?? ''));
if (!$orderId) {
    echo json_encode(['ok' => false, 'error' => 'Falta orderId']);
    exit;
}

$fsToken = getFirestoreAdminToken();
if (!$fsToken) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo autenticar con Firestore']);
    exit;
}
$config = fsGetDoc($fsToken, 'config/paypal');
if (!$config || empty($config['clientId']) || empty($config['secret'])) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Falta configurar PayPal']);
    exit;
}
$environment = $config['environment'] ?? 'sandbox';
$apiBase = $environment === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';

$accessToken = getPaypalAccessToken($apiBase, $config['clientId'], $config['secret']);
if (!$accessToken) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo autenticar con PayPal']);
    exit;
}

/* ---------- Capturar el pago (esto es lo que de verdad cobra) ---------- */
$ch = curl_init($apiBase . '/v2/checkout/orders/' . urlencode($orderId) . '/capture');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => '{}',
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json',
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
]);
$raw = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data = json_decode($raw, true);
if ($code < 200 || $code >= 300) {
    echo json_encode(['ok' => false, 'error' => 'PayPal no pudo completar el cobro', 'raw' => $data]);
    exit;
}

$status = $data['status'] ?? '';
$unit = $data['purchase_units'][0] ?? [];
$capture = $unit['payments']['captures'][0] ?? [];
$captureId = $capture['id'] ?? null;
$captureStatus = $capture['status'] ?? '';
$amount = isset($capture['amount']['value']) ? (float)$capture['amount']['value'] : 0;
// custom_id es el uid que mandamos en create_paypal_order.php -- lo
// volvemos a leer de la respuesta de PayPal, no de lo que diga el
// navegador, para no confiar en el cliente sobre a quién acreditar.
$orderUid = $unit['custom_id'] ?? '';

if ($status !== 'COMPLETED' || $captureStatus !== 'COMPLETED' || !$captureId || $amount <= 0 || $orderUid !== $uid) {
    echo json_encode(['ok' => false, 'error' => 'El pago no se completó correctamente', 'raw' => $data]);
    exit;
}

/* ---------- Evitar acreditar dos veces ---------- */
$docId = 'pp_' . $captureId;
$existing = fsGetDoc($fsToken, 'payments/' . $docId);
if ($existing) {
    echo json_encode(['ok' => true, 'alreadyProcessed' => true]);
    exit;
}

/* ---------- Acreditar el saldo ---------- */
$tech = fsGetDoc($fsToken, 'technicians/' . $uid);
$currentBalance = (float)($tech['balance'] ?? 0);
$newBalance = round($currentBalance + $amount, 2);

$ok = fsPatchDoc($fsToken, 'technicians/' . $uid, ['balance' => $newBalance]);
if (!$ok) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo actualizar el saldo, contacta soporte con este ID: ' . $captureId]);
    exit;
}

fsCreateDoc($fsToken, 'payments', $docId, [
    'uid' => $uid,
    'provider' => 'paypal',
    'amount' => $amount,
    'captureId' => $captureId,
    'orderId' => $orderId,
    'email' => $data['payer']['email_address'] ?? null,
    'balanceBefore' => $currentBalance,
    'balanceAfter' => $newBalance,
    'createdAt' => gmdate('Y-m-d\TH:i:s\Z'),
]);

echo json_encode(['ok' => true, 'newBalance' => $newBalance]);
exit;


function getPaypalAccessToken(string $apiBase, string $clientId, string $secret): ?string
{
    $ch = curl_init($apiBase . '/v1/oauth2/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
        CURLOPT_USERPWD => $clientId . ':' . $secret,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) return null;
    $data = json_decode($raw, true);
    return $data['access_token'] ?? null;
}

function verifyFirebaseIdToken(string $idToken): ?array
{
    if (!function_exists('curl_init')) return null;
    $url = 'https://identitytoolkit.googleapis.com/v1/accounts:lookup?key=' . FIREBASE_WEB_API_KEY;
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['idToken' => $idToken]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false || $code !== 200) return null;
    $data = json_decode($raw, true);
    if (!isset($data['users'][0]['localId'])) return null;
    return ['uid' => $data['users'][0]['localId'], 'email' => $data['users'][0]['email'] ?? null];
}
