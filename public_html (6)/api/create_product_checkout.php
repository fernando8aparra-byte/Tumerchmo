<?php
/**
 * create_product_checkout.php
 * -------------------------------------------------------------
 * Crea una sesión de pago (Stripe Checkout) para comprar UN producto
 * comercial directo -- no toca saldo de técnico, no pasa por /tec/.
 * El precio SIEMPRE se lee de Firestore (commercial_products/{id}),
 * nunca del navegador -- mismo principio que place_order.php.
 *
 * IMPORTANTE -- lo que NO hace: este archivo no marca nada como
 * "comprado". Eso lo hace ÚNICAMENTE stripe_webhook.php cuando Stripe
 * confirma el cobro con firma criptográfica.
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

/* ---------- Verificar sesión de Firebase (el comprador debe estar logueado) ---------- */
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

/* ---------- El producto y su precio real, nunca el del navegador ---------- */
$input = json_decode(file_get_contents('php://input'), true);
$productId = trim((string)($input['productId'] ?? ''));
if ($productId === '') {
    echo json_encode(['ok' => false, 'error' => 'Falta productId']);
    exit;
}
$product = fsGetDoc($fsToken, 'commercial_products/' . $productId);
if (!$product || ($product['enabled'] ?? true) === false) {
    echo json_encode(['ok' => false, 'error' => 'Ese producto ya no está disponible.']);
    exit;
}
$price = (float)($product['price'] ?? 0);
if ($price <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Este producto no tiene un precio configurado.']);
    exit;
}
$amountCents = (int) round($price * 100);

/* ---------- Llave de Stripe ---------- */
$stripeConfig = fsGetDoc($fsToken, 'config/stripe');
if (!$stripeConfig || empty($stripeConfig['secretKey'])) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Falta configurar Stripe (admin.html → Pagos)']);
    exit;
}
$stripeSecret = $stripeConfig['secretKey'];

/* ---------- URLs de regreso -- se quedan en la página comercial ---------- */
$origin = $input['origin'] ?? '';
if (!preg_match('#^https?://#', $origin)) {
    echo json_encode(['ok' => false, 'error' => 'Origen inválido']);
    exit;
}
$successUrl = rtrim($origin, '/') . '/index.html?compra=exito';
$cancelUrl  = rtrim($origin, '/') . '/index.html?compra=cancelada';

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
            'product_data' => [
                'name' => $product['name'] ?? 'Producto',
                'description' => mb_substr((string)($product['description'] ?? ''), 0, 300),
            ],
        ],
    ]],
    // Esto es lo que distingue una compra de producto comercial de una
    // recarga de saldo de técnico -- stripe_webhook.php revisa
    // "kind" para saber qué hacer al confirmar el pago.
    'metadata' => [
        'kind' => 'commercial_product',
        'uid' => $uid,
        'productId' => $productId,
        'productName' => $product['name'] ?? 'Producto',
        'amount_usd' => (string)$price,
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
