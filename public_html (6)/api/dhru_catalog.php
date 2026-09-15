<?php
/**
 * dhru_catalog.php
 * -------------------------------------------------------------
 * Reemplaza al viejo dhru_test.php (que tenía tu API key escrita
 * adentro). Sirve para BUSCAR servicios en el catálogo de tu proveedor
 * desde admin.html -- solo lectura: no coloca pedidos ni cobra nada.
 *
 * Las credenciales se leen de Firestore (config/dhru), igual que en
 * place_order.php. Y solo responde si quien llama es administrador
 * (custom claim admin=true), para que el catálogo con precios de costo
 * no quede expuesto a cualquiera.
 * -------------------------------------------------------------
 * INSTALACIÓN: sube este archivo a la carpeta /api.
 * -------------------------------------------------------------
 */

require __DIR__ . '/firestore-admin.php';

define('FIREBASE_WEB_API_KEY', 'AIzaSyAv2zk1jEQJcyTfEb7RsmsNtFAp3J5sSnI');
define('DHRU_API_URL', 'https://territorioremoto.com/api/index.php');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

/* ---------- Verificar que es admin ---------- */
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if (!preg_match('/Bearer\s+(.+)/i', $authHeader, $m)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Falta el token de sesión']);
    exit;
}
$user = verifyFirebaseIdToken(trim($m[1]));
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Token inválido o expirado, vuelve a iniciar sesión']);
    exit;
}
if (empty($user['admin'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Solo el administrador puede consultar el catálogo del proveedor']);
    exit;
}

/* ---------- Credenciales de DHRU desde Firestore ---------- */
$fsToken = getFirestoreAdminToken();
if (!$fsToken) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo autenticar con Firestore']);
    exit;
}
$dhruConfig = fsGetDoc($fsToken, 'config/dhru');
if (!$dhruConfig || empty($dhruConfig['username']) || empty($dhruConfig['apikey'])) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Faltan las credenciales de DHRU. Ve a admin.html → Configuración de DHRU.']);
    exit;
}

/* ---------- Ejecutar la acción pedida ---------- */
$input = json_decode(file_get_contents('php://input'), true);
$action = trim((string)($input['action'] ?? 'imeiservicelist'));

// Lista blanca: solo acciones de consulta. Así este archivo nunca
// puede usarse para colocar un pedido (eso es exclusivo de place_order.php).
$allowed = ['imeiservicelist', 'servicelist', 'pricelist', 'getservicelist', 'accountinfo', 'fileservicelist'];
if (!in_array($action, $allowed, true)) {
    echo json_encode(['ok' => false, 'error' => "Acción '$action' no permitida en este endpoint"]);
    exit;
}

$payload = [
    'username'      => $dhruConfig['username'],
    'apiaccesskey'  => $dhruConfig['apikey'],
    'requestformat' => 'JSON',
    'action'        => $action,
];

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => DHRU_API_URL,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload, // arreglo => multipart, como el cliente oficial
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 40,
    CURLOPT_SSL_VERIFYPEER => true,
]);
$raw = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($raw === false) {
    echo json_encode(['ok' => false, 'error' => 'Error de conexión con el proveedor: ' . $curlError]);
    exit;
}

$decoded = json_decode($raw, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode(['ok' => false, 'error' => 'El proveedor no respondió JSON válido', 'http_code' => $httpCode, 'raw_response' => mb_substr($raw, 0, 2000)]);
    exit;
}

echo json_encode(['ok' => true, 'action' => $action, 'response' => $decoded], JSON_UNESCAPED_UNICODE);
exit;


/* =================================================================
 * Verifica el token de Firebase Y lee si trae el claim de admin.
 * ================================================================= */
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

    // customAttributes es donde vive el claim admin=true que pusimos
    // con setup_admin_claim.php.
    $isAdmin = false;
    if (!empty($data['users'][0]['customAttributes'])) {
        $attrs = json_decode($data['users'][0]['customAttributes'], true);
        $isAdmin = !empty($attrs['admin']);
    }

    return [
        'uid'   => $data['users'][0]['localId'],
        'email' => $data['users'][0]['email'] ?? null,
        'admin' => $isAdmin,
    ];
}