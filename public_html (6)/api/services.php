<?php
/**
 * services.php
 * -------------------------------------------------------------
 * Devuelve el catálogo COMPLETO de servicios de territorioremoto.com
 * (acción "imeiservicelist" de DHRU Fusion), incluyendo el campo
 * "CUSTOM" de cada servicio -- que es justo lo que index.html necesita
 * para saber qué campo(s) pedirle al usuario antes de pagar.
 *
 * Usa el MISMO patrón ya comprobado que funciona en imeicheck.php:
 * XML armado con DOMDocument (no base64, no JSON) + POST como arreglo
 * (multipart/form-data), tal como lo hace la clase oficial de Dhru.com.
 *
 * Requiere sesión de Firebase (Authorization: Bearer <idToken>) para
 * que solo técnicos logueados vean el catálogo y sus precios.
 * -------------------------------------------------------------
 * INSTALACIÓN: sube este archivo a la misma carpeta que imeicheck.php.
 * -------------------------------------------------------------
 */

/* ============ CONFIGURACIÓN ============ */
define('DHRU_API_URL', 'https://territorioremoto.com/api/index.php');
define('DHRU_USERNAME', 'fernando8aparra');
define('DHRU_API_KEY', 'R5M-ZX-G3Z-BZB-LCK-CIJ-1FY-NMM');
define('FIREBASE_WEB_API_KEY', 'AIzaSyAv2zk1jEQJcyTfEb7RsmsNtFAp3J5sSnI');
/* ========================================= */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/* ---------- Verificar sesión de Firebase ---------- */
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if (!preg_match('/Bearer\s+(.+)/i', $authHeader, $m)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Falta el token de sesión (Authorization: Bearer ...)']);
    exit;
}
if (!verifyFirebaseIdToken(trim($m[1]))) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Token inválido o expirado, vuelve a iniciar sesión']);
    exit;
}

/* ---------- Pedir el catálogo ---------- */
$dhru = callDhruApi(['action' => 'imeiservicelist']);
if (!$dhru['ok']) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => $dhru['error'] ?? 'Error al conectar con el proveedor', 'raw' => $dhru]);
    exit;
}
$body = $dhru['response'];
if (isset($body['ERROR'])) {
    $msg = $body['ERROR'][0]['MESSAGE'] ?? 'El proveedor rechazó la solicitud del catálogo';
    echo json_encode(['ok' => false, 'error' => $msg, 'raw' => $body]);
    exit;
}
$list = $body['SUCCESS'][0]['LIST'] ?? [];

$groups = [];
foreach ($list as $groupData) {
    $services = [];
    foreach (($groupData['SERVICES'] ?? []) as $svc) {
        $services[] = [
            'id'      => $svc['SERVICEID'] ?? null,
            'name'    => $svc['SERVICENAME'] ?? 'Servicio',
            'type'    => $svc['SERVICETYPE'] ?? '',
            'credit'  => $svc['CREDIT'] ?? null,
            'time'    => $svc['TIME'] ?? '',
            'info'    => $svc['INFO'] ?? '',
            // Esto es lo nuevo que api-services.php no traía: el campo
            // CUSTOM define qué dato(s) pedirle al usuario (ej. "IMEI / SN").
            // Puede venir como objeto único o ausente según el servicio.
            'custom'  => $svc['CUSTOM'] ?? null,
        ];
    }
    if (count($services)) {
        $groups[] = [
            'group'    => $groupData['GROUPNAME'] ?? 'General',
            'type'     => $groupData['GROUPTYPE'] ?? '',
            'services' => $services,
        ];
    }
}

echo json_encode(['ok' => true, 'groups' => $groups], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;


/* =================================================================
 * Verifica un ID token de Firebase contra la API de Google.
 * ================================================================= */
function verifyFirebaseIdToken(string $idToken): ?array
{
    if (!function_exists('curl_init')) return null;
    $url = 'https://identitytoolkit.googleapis.com/v1/accounts:lookup?key=' . FIREBASE_WEB_API_KEY;
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['idToken' => $idToken]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false || $code !== 200) return null;
    $data = json_decode($raw, true);
    if (!isset($data['users'][0]['localId'])) return null;
    return ['uid' => $data['users'][0]['localId'], 'email' => $data['users'][0]['email'] ?? null];
}

/* =================================================================
 * Llama a la API DHRU de territorioremoto.com (multipart, sin parámetros
 * XML para acciones de catálogo que no los necesitan).
 * ================================================================= */
function callDhruApi(array $extraParams): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'La extensión cURL de PHP no está activada en este hosting.'];
    }
    $payload = array_merge([
        'username'      => DHRU_USERNAME,
        'apiaccesskey'  => DHRU_API_KEY,
        'requestformat' => 'JSON',
    ], $extraParams);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => DHRU_API_URL,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload, // arreglo => multipart/form-data, igual que el cliente oficial
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $rawResponse = curl_exec($ch);
    $httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError   = curl_error($ch);
    curl_close($ch);

    if ($rawResponse === false) {
        return ['ok' => false, 'http_code' => $httpCode, 'error' => 'Error de conexión: ' . $curlError];
    }
    $decoded = json_decode($rawResponse, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        return ['ok' => ($httpCode >= 200 && $httpCode < 300), 'http_code' => $httpCode, 'response' => $decoded];
    }
    return ['ok' => false, 'http_code' => $httpCode, 'error' => 'La respuesta del proveedor no es JSON válido', 'raw_response' => $rawResponse];
}
