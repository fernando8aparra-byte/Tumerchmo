<?php
/**
 * paypal_client_id.php
 * -------------------------------------------------------------
 * Entrega el Client ID público de PayPal al navegador, para que pueda
 * cargar el SDK oficial de PayPal (el que dibuja el botón real).
 *
 * El Client ID NO es secreto -- está diseñado para vivir en el
 * navegador (así funciona el botón de PayPal en cualquier tienda). El
 * secreto real (Secret) nunca sale de este archivo ni de los otros
 * endpoints de PayPal.
 * -------------------------------------------------------------
 * INSTALACIÓN: sube este archivo a /api.
 * -------------------------------------------------------------
 */

require __DIR__ . '/firestore-admin.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$fsToken = getFirestoreAdminToken();
if (!$fsToken) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo autenticar con Firestore']);
    exit;
}

$config = fsGetDoc($fsToken, 'config/paypal');
if (!$config || empty($config['clientId'])) {
    echo json_encode(['ok' => false, 'error' => 'PayPal no está configurado todavía']);
    exit;
}

echo json_encode([
    'ok' => true,
    'clientId' => $config['clientId'],
    'environment' => $config['environment'] ?? 'sandbox',
]);
