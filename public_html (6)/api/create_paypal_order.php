<?php
/**
 * create_paypal_order.php
 * -------------------------------------------------------------
 * Crea una orden en PayPal. Lo llama el botón OFICIAL de PayPal (su
 * propio SDK, cargado en el navegador) en cuanto el cliente le da clic
 * -- no nosotros directamente.
 *
 * Este archivo NO suma saldo -- solo crea la orden. El saldo se
 * acredita en capture_paypal_order.php, después de que PayPal confirma
 * que el cliente de verdad aprobó y pagó.
 * -------------------------------------------------------------
 * INSTALACIÓN: sube este archivo a /api.
 * -------------------------------------------------------------
 */

require __DIR__ . '/firestore-admin.php';

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
$config = fsGetDoc($fsToken, 'config/paypal');
if (!$config || empty($config['clientId']) || empty($config['secret'])) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Falta configurar PayPal (admin.html → Config. PayPal)']);
    exit;
}
$environment = $config['environment'] ?? 'sandbox';
$apiBase = $environment === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';

$input = json_decode(file_get_contents('php://input'), true);
$amount = isset($input['amount']) ? (float)$input['amount'] : 0;
if ($amount < MIN_RECHARGE_USD || $amount > MAX_RECHARGE_USD) {
    echo json_encode(['ok' => false, 'error' => 'El monto debe estar entre $' . MIN_RECHARGE_USD . ' y $' . MAX_RECHARGE_USD]);
    exit;
}

$accessToken = getPaypalAccessToken($apiBase, $config['clientId'], $config['secret']);
if (!$accessToken) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo autenticar con PayPal. Revisa tus credenciales.']);
    exit;
}

$body = [
    'intent' => 'CAPTURE',
    'purchase_units' => [[
        'custom_id' => $uid, // vuelve en la captura, así sabemos a quién acreditar
        'description' => 'Recarga de saldo',
        'amount' => [
            'currency_code' => 'USD',
            'value' => number_format($amount, 2, '.', ''),
        ],
    ]],
];

$ch = curl_init($apiBase . '/v2/checkout/orders');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($body),
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
if ($code < 200 || $code >= 300 || empty($data['id'])) {
    echo json_encode(['ok' => false, 'error' => 'PayPal rechazó la orden', 'raw' => $data]);
    exit;
}

echo json_encode(['ok' => true, 'id' => $data['id']]);
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
