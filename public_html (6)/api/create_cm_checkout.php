<?php
/**
 * create_cm_checkout.php
 * -------------------------------------------------------------
 * Crea una sesión de pago (Stripe Checkout) para comprar UN servicio
 * de "Productos CM" (la tienda pública de tumerchmo.com) -- separado
 * por completo del catálogo de técnicos y de commercial_products.
 * El precio y los campos que se piden (IMEI/ECID/etc.) SIEMPRE se
 * leen de Firestore (cm_products/{id}), nunca del navegador -- mismo
 * principio que create_product_checkout.php y place_order.php.
 *
 * IMPORTANTE -- lo que NO hace: este archivo no crea el pedido ni
 * marca nada como pagado. Eso lo hace ÚNICAMENTE stripe_webhook.php
 * cuando Stripe confirma el cobro con firma criptográfica (igual que
 * ya hace con "commercial_product"). Aquí solo se arma la sesión y se
 * manda todo lo necesario escondido en la metadata para que el
 * webhook pueda crear el pedido completo cuando de verdad se pague.
 * -------------------------------------------------------------
 * INSTALACIÓN: sube este archivo a /api, junto a los demás.
 * -------------------------------------------------------------
 */

require __DIR__ . '/firestore-admin.php';
require __DIR__ . '/notify-admin.php';

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

// Qué campos exige cada identifierType -- debe ser el mismo mapa que
// IDENTIFIER_FIELDS en index.html. Nunca confiamos en la validación
// que ya hizo el navegador; se repite aquí del lado servidor.
const IDENTIFIER_REQUIRED_KEYS = [
    'NONE' => [],
    'IMEI' => ['imei'],
    'SN' => ['sn'],
    'BOTH' => ['imei', 'sn'],
    'USER_EMAIL' => ['accountUser', 'accountEmail'],
    'ECID' => ['ecid'],
    'PHONE' => ['ownerPhone'],
];

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
if (empty($firebaseUser['emailVerified'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Verifica tu correo antes de comprar (revisa tu bandeja de entrada).']);
    exit;
}
$uid = $firebaseUser['uid'];

$fsToken = getFirestoreAdminToken();
if (!$fsToken) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo autenticar con Firestore']);
    exit;
}

/* ---------- El producto, su precio real y qué exige (nunca del navegador) ---------- */
$input = json_decode(file_get_contents('php://input'), true);
$productId = trim((string)($input['productId'] ?? ''));
$details = is_array($input['details'] ?? null) ? $input['details'] : [];
$contactEmail = trim((string)($input['contactEmail'] ?? ''));

if ($productId === '') {
    echo json_encode(['ok' => false, 'error' => 'Falta productId']);
    exit;
}
if (!filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['ok' => false, 'error' => 'El correo de contacto no es válido']);
    exit;
}

$product = fsGetDoc($fsToken, 'cm_products/' . $productId);
if (!$product || ($product['enabled'] ?? true) === false) {
    echo json_encode(['ok' => false, 'error' => 'Ese producto ya no está disponible.']);
    exit;
}
$price = (float)($product['price'] ?? 0);
if ($price <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Este producto no tiene un precio configurado.']);
    exit;
}

$identifierType = $product['identifierType'] ?? 'NONE';
$requiredKeys = IDENTIFIER_REQUIRED_KEYS[$identifierType] ?? [];
$cleanDetails = [];
foreach ($requiredKeys as $key) {
    $value = trim((string)($details[$key] ?? ''));
    if ($value === '') {
        echo json_encode(['ok' => false, 'error' => 'Falta un dato obligatorio del pedido.']);
        exit;
    }
    $cleanDetails[$key] = $value;
}

/* ---------- Saldo a favor -- SIEMPRE el que dice Firestore, nunca el
   navegador. Se aplica primero, antes de pensar en cobrar con Stripe. ---------- */
$customer = fsGetDoc($fsToken, 'customers/' . $uid);
$customerBalance = (float)($customer['balance'] ?? 0);
$balanceUsed = round(min($customerBalance, $price), 2);
$amountDue = round($price - $balanceUsed, 2);

/* ---------- Si el saldo cubre TODO el precio: se confirma de una vez,
   sin pasar por Stripe (Stripe no acepta cobros de $0). El pedido se
   crea aquí mismo, directo, en vez de esperar al webhook. ---------- */
if ($amountDue <= 0) {
    fsPatchDoc($fsToken, 'customers/' . $uid, ['balance' => round($customerBalance - $balanceUsed, 2)]);
    fsCreateDoc($fsToken, 'customers/' . $uid . '/movimientos', null, [
        'tipo' => 'canje_saldo',
        'monto' => -$balanceUsed,
        'productId' => $productId,
        'serviceName' => $product['name'] ?? 'Servicio',
        'saldoResultante' => round($customerBalance - $balanceUsed, 2),
        'createdAt' => gmdate('Y-m-d\TH:i:s\Z'),
    ]);
    fsCreateDoc($fsToken, 'cm_orders', null, [
        'serviceId' => $productId,
        'serviceName' => $product['name'] ?? 'Servicio',
        'category' => $product['category'] ?? null,
        'price' => $price,
        'balanceUsed' => $balanceUsed,
        'amountCharged' => 0,
        'identifierType' => $identifierType,
        'details' => $cleanDetails,
        'contactEmail' => $contactEmail,
        'buyerUid' => $uid,
        'buyerEmail' => $contactEmail,
        'status' => 'pagado',
        'paidWithBalance' => true,
        'createdAt' => gmdate('Y-m-d\TH:i:s\Z'),
    ]);
    if ($productId) {
        $currentCount = (int)($product['purchaseCount'] ?? 0);
        fsPatchDoc($fsToken, 'cm_products/' . $productId, ['purchaseCount' => $currentCount + 1]);
    }
    notifyAdmin(
        $fsToken,
        'Nuevo pedido tienda (pagado con saldo) -- ' . ($product['name'] ?? 'Servicio'),
        "🛒 Nuevo pedido en la tienda pública (Productos CM)\n" .
        'Servicio: ' . ($product['name'] ?? 'Servicio') . "\n" .
        'Pagado completo con saldo a favor: $' . number_format($balanceUsed, 2) . " USD\n" .
        'Cliente: ' . $contactEmail . "\n" .
        'Datos: ' . json_encode($cleanDetails)
    );
    $origin = $input['origin'] ?? '';
    if (!preg_match('#^https?://#', $origin)) {
        echo json_encode(['ok' => false, 'error' => 'Origen inválido']);
        exit;
    }
    echo json_encode(['ok' => true, 'url' => rtrim($origin, '/') . '/index.html?pago=exito']);
    exit;
}
$amountCents = (int) round($amountDue * 100);

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
$successUrl = rtrim($origin, '/') . '/index.html?pago=exito';
$cancelUrl  = rtrim($origin, '/') . '/index.html?pago=cancelado';

$params = [
    'mode' => 'payment',
    'success_url' => $successUrl,
    'cancel_url' => $cancelUrl,
    'client_reference_id' => $uid,
    'customer_email' => $contactEmail,
    'line_items' => [[
        'quantity' => 1,
        'price_data' => [
            'currency' => 'usd',
            'unit_amount' => $amountCents,
            // Stripe rechaza "description" si se manda como string vacío
            // (lo interpreta como un intento de "borrar" el campo) -- por
            // eso solo se agrega cuando de verdad hay texto.
            'product_data' => array_filter([
                'name' => $product['name'] ?? 'Servicio',
                'description' => mb_substr((string)($product['description'] ?? ''), 0, 300) ?: null,
            ]),
        ],
    ]],
    // "kind" = "cm_order" es lo que stripe_webhook.php revisa para
    // saber que este pago es de la tienda pública (Productos CM), y
    // no una recarga de técnico ni un producto comercial simple. El
    // pedido en sí NO existe todavía -- el webhook lo crea completo
    // con todo lo que viene aquí en la metadata, solo si el pago se
    // confirma de verdad.
    'metadata' => [
        'kind' => 'cm_order',
        'uid' => $uid,
        'productId' => $productId,
        'productName' => $product['name'] ?? 'Servicio',
        'category' => $product['category'] ?? '',
        'amount_usd' => (string)$amountDue,
        'balanceUsed' => (string)$balanceUsed,
        'identifierType' => $identifierType,
        'contactEmail' => $contactEmail,
        'details' => json_encode($cleanDetails),
    ],
];

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
    return [
        'uid' => $data['users'][0]['localId'],
        'email' => $data['users'][0]['email'] ?? null,
        'emailVerified' => (bool)($data['users'][0]['emailVerified'] ?? false),
    ];
}