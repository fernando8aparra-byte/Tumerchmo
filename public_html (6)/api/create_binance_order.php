<?php
/**
 * create_binance_order.php
 * -------------------------------------------------------------
 * Crea una orden de cobro en Binance Pay y devuelve la URL de pago
 * (checkoutUrl) para redirigir al cliente -- mismo patrón que Stripe y
 * Mercado Pago.
 *
 * Este archivo NUNCA suma saldo. Solo crea la orden y guarda un
 * registro temporal (binance_orders/{merchantTradeNo}) para que
 * binance_webhook.php sepa a quién acreditarle cuando Binance confirme
 * el pago de verdad.
 * -------------------------------------------------------------
 * INSTALACIÓN: sube este archivo a /api.
 * Documentación oficial usada:
 *   https://developers.binance.com/docs/binance-pay/api-order-create-v2
 *   https://merchant.binance.com/en/docs/getting-started
 * -------------------------------------------------------------
 */

require __DIR__ . '/firestore-admin.php';
require __DIR__ . '/binance-sign.php';

define('FIREBASE_WEB_API_KEY', 'AIzaSyAv2zk1jEQJcyTfEb7RsmsNtFAp3J5sSnI');
define('MIN_RECHARGE_USD', 5);
define('MAX_RECHARGE_USD', 2000);

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
    echo json_encode(['ok' => false, 'error' => 'Token inválido o expirado, vuelve a iniciar sesión']);
    exit;
}
$uid = $firebaseUser['uid'];

$fsToken = getFirestoreAdminToken();
if (!$fsToken) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo autenticar con Firestore']);
    exit;
}
$config = fsGetDoc($fsToken, 'config/binance');
if (!$config || empty($config['apiKey']) || empty($config['secretKey'])) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Falta configurar Binance Pay (admin.html → Config. Binance)']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$amount = isset($input['amount']) ? (float)$input['amount'] : 0;
if ($amount < MIN_RECHARGE_USD || $amount > MAX_RECHARGE_USD) {
    echo json_encode(['ok' => false, 'error' => 'El monto debe estar entre $' . MIN_RECHARGE_USD . ' y $' . MAX_RECHARGE_USD]);
    exit;
}
$origin = $input['origin'] ?? '';
if (!preg_match('#^https?://#', $origin)) {
    echo json_encode(['ok' => false, 'error' => 'Origen inválido']);
    exit;
}
$origin = rtrim($origin, '/');

// merchantTradeNo: solo letras/números, máx 32 caracteres.
$merchantTradeNo = 'tm' . bin2hex(random_bytes(13)); // 2 + 26 = 28 chars

$body = [
    'env' => ['terminalType' => 'WEB'],
    'merchantTradeNo' => $merchantTradeNo,
    'orderAmount' => round($amount, 2),
    'currency' => 'USDT', // Binance Pay solo acepta BUSD/USDT/MBOX -- 1 USDT ≈ 1 USD
    'goods' => [
        'goodsType' => '02', // 02 = bien virtual
        'goodsCategory' => 'Z000', // otros
        'referenceGoodsId' => 'saldo-recarga',
        'goodsName' => 'Recarga de saldo',
    ],
    'returnUrl' => $origin . '/index.html?recarga=exito',
    'cancelUrl' => $origin . '/index.html?recarga=cancelada',
    // Binance nos notifica el resultado aquí -- no hace falta configurar
    // nada en su panel de comerciante, se manda por parámetro.
    'webhookUrl' => $origin . '/api/binance_webhook.php',
];

$result = callBinanceApi($config['apiKey'], $config['secretKey'], '/binancepay/openapi/v2/order', $body);
if (!$result['ok']) {
    echo json_encode(['ok' => false, 'error' => $result['error'], 'raw' => $result['raw'] ?? null]);
    exit;
}
$data = $result['data'];
if (($data['status'] ?? '') !== 'SUCCESS' || empty($data['data']['checkoutUrl'])) {
    $msg = $data['errorMessage'] ?? ('Binance rechazó la orden (código ' . ($data['code'] ?? '?') . ')');
    echo json_encode(['ok' => false, 'error' => $msg, 'raw' => $data]);
    exit;
}

// Guardamos a quién le pertenece esta orden -- el webhook lo necesita
// para saber a quién acreditar cuando confirme el pago.
fsCreateDoc($fsToken, 'binance_orders', $merchantTradeNo, [
    'uid' => $uid,
    'amount' => round($amount, 2),
    'prepayId' => $data['data']['prepayId'] ?? null,
    'status' => 'pending',
    'createdAt' => gmdate('Y-m-d\TH:i:s\Z'),
]);

echo json_encode(['ok' => true, 'url' => $data['data']['checkoutUrl']]);
exit;


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
