<?php
/**
 * create_stripe_session.php
 * -------------------------------------------------------------
 * Crea una sesión de pago (Stripe Checkout) y devuelve la URL a la que
 * el navegador debe redirigir al cliente.
 *
 * IMPORTANTE -- lo que NO hace: este archivo nunca suma saldo. Solo
 * abre la sesión de pago. El saldo lo acredita stripe_webhook.php,
 * cuando Stripe confirma el cobro con una firma criptográfica. Así, si
 * alguien llama a este archivo mil veces sin pagar, no pasa nada.
 *
 * Las llaves de Stripe se leen de Firestore (config/stripe), igual que
 * hicimos con DHRU -- no van escritas aquí.
 * -------------------------------------------------------------
 * INSTALACIÓN: sube este archivo a la carpeta /api.
 * -------------------------------------------------------------
 */

require __DIR__ . '/firestore-admin.php';

define('FIREBASE_WEB_API_KEY', 'AIzaSyAv2zk1jEQJcyTfEb7RsmsNtFAp3J5sSnI');
define('MIN_RECHARGE_USD', 5);
define('MAX_RECHARGE_USD', 2000); // tope de sensatez, ajústalo si necesitas más

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

/* ---------- Verificar sesión de Firebase ---------- */
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

/* ---------- Llaves de Stripe desde Firestore ---------- */
$fsToken = getFirestoreAdminToken();
if (!$fsToken) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo autenticar con Firestore']);
    exit;
}
$stripeConfig = fsGetDoc($fsToken, 'config/stripe');
if (!$stripeConfig || empty($stripeConfig['secretKey'])) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Falta configurar la llave secreta de Stripe (admin.html → Config. Stripe)']);
    exit;
}
$stripeSecret = $stripeConfig['secretKey'];

/* ---------- Validar el monto AQUÍ, no en el navegador ---------- */
$input = json_decode(file_get_contents('php://input'), true);
$amount = isset($input['amount']) ? (float)$input['amount'] : 0;

if ($amount < MIN_RECHARGE_USD || $amount > MAX_RECHARGE_USD) {
    echo json_encode(['ok' => false, 'error' => 'El monto debe estar entre $' . MIN_RECHARGE_USD . ' y $' . MAX_RECHARGE_USD . ' USD']);
    exit;
}
// Stripe trabaja en centavos, en enteros.
$amountCents = (int) round($amount * 100);

/* ---------- URLs de regreso ---------- */
$origin = $input['origin'] ?? '';
if (!preg_match('#^https?://#', $origin)) {
    echo json_encode(['ok' => false, 'error' => 'Origen inválido']);
    exit;
}
$successUrl = rtrim($origin, '/') . '/tec/index.html?recarga=exito';
$cancelUrl  = rtrim($origin, '/') . '/tec/index.html?recarga=cancelada';

/* ---------- Crear la sesión en Stripe ---------- */
$params = [
    'mode' => 'payment',
    'success_url' => $successUrl,
    'cancel_url' => $cancelUrl,
    'client_reference_id' => $uid,
    'line_items' => [[
        'quantity' => 1,
        'price_data' => [
            'currency' => 'usd',
            'unit_amount' => $amountCents,
            'product_data' => ['name' => 'Recarga de saldo'],
        ],
    ]],
    // Estos datos viajan con el pago y regresan en el webhook -- así el
    // webhook sabe a quién acreditarle y cuánto, sin confiar en nada
    // que mande el navegador.
    'metadata' => [
        'uid' => $uid,
        'amount_usd' => (string)$amount,
    ],
];
if (!empty($firebaseUser['email'])) {
    $params['customer_email'] = $firebaseUser['email'];
}

$ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query($params),
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $stripeSecret,
        'Content-Type: application/x-www-form-urlencoded',
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
]);
$raw = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data = json_decode($raw, true);
if ($code !== 200 || empty($data['url'])) {
    $msg = $data['error']['message'] ?? 'Stripe rechazó la solicitud';
    echo json_encode(['ok' => false, 'error' => $msg, 'raw' => $data]);
    exit;
}

echo json_encode(['ok' => true, 'url' => $data['url']]);
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