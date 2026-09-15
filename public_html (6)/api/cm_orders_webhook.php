<?php
/**
 * api/create-catalog-checkout.php
 * ------------------------------------------------------------------
 * El navegador NUNCA escribe en "cm_orders" directamente -- las
 * reglas de Firestore lo bloquean a propósito (allow write: if false)
 * para que nadie pueda inventarse un pedido "pagado" desde la consola.
 * Este endpoint es el único que puede crearlo, porque usa la cuenta
 * de servicio, que no pasa por esas reglas.
 *
 * Recibe: { idToken, productId, details, contactEmail }
 *   - idToken: el token de sesión de Firebase Auth del comprador
 *     (auth.currentUser.getIdToken() en el navegador). Se verifica
 *     aquí para saber de verdad quién es, sin confiar en lo que
 *     mande el navegador.
 *   - productId: el id del documento en "cm_products".
 *   - details: los campos que pidió el producto (imei, sn, ecid, etc.)
 *   - contactEmail: correo para el recibo y avisos del pedido.
 *
 * Hace, en este orden:
 *   1) Verifica el idToken (Firebase Admin Auth).
 *   2) Lee el producto en cm_products y valida que exista, esté
 *      activo, y que vengan los campos que pide su identifierType
 *      (nunca confía en la validación que ya hizo el navegador).
 *   3) Crea el documento en "cm_orders" con status "pendiente_pago".
 *   4) Crea la Stripe Checkout Session (metadata.kind = "cm_order").
 *   5) Regresa { url } para que el navegador redirija al pago.
 *
 * ⚠️ BASE SIN PROBAR contra tu servidor real -- ajusta lo marcado
 * "AJUSTAR". El webhook que confirma el pago es un archivo aparte:
 * api/cm_orders_webhook.php (lo doy junto con este).
 *
 * Requiere (composer):
 *   stripe/stripe-php
 *   kreait/firebase-php
 * ------------------------------------------------------------------
 */

header('Content-Type: application/json');

require __DIR__ . '/../vendor/autoload.php'; // AJUSTAR si tu vendor/ está en otra ruta

use Kreait\Firebase\Factory;

// Campos que cada identifierType exige del comprador -- debe ser el
// mismo mapa que IDENTIFIER_FIELDS en index.html, para que el
// servidor valide lo mismo que ya valida el navegador.
const IDENTIFIER_REQUIRED_KEYS = [
    'NONE' => [],
    'IMEI' => ['imei'],
    'SN' => ['sn'],
    'BOTH' => ['imei', 'sn'],
    'USER_EMAIL' => ['accountUser', 'accountEmail'],
    'ECID' => ['ecid'],
    'PHONE' => ['ownerPhone'],
];

try {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $idToken = $input['idToken'] ?? null;
    $productId = $input['productId'] ?? null;
    $details = is_array($input['details'] ?? null) ? $input['details'] : [];
    $contactEmail = trim((string) ($input['contactEmail'] ?? ''));

    if (!$idToken) throw new Exception('Falta iniciar sesión.');
    if (!$productId) throw new Exception('Falta el producto.');
    if (!filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) throw new Exception('El correo de contacto no es válido.');

    // ---- Firebase Admin (mismo patrón que el resto de tu backend) ----
    $serviceAccountPath = '/home/u511071039/domains/tumerchmo.com/firebase-service-account.json'; // AJUSTAR si cambió
    $factory = (new Factory())->withServiceAccount($serviceAccountPath);
    $firebaseAuth = $factory->createAuth();
    $firestore = $factory->createFirestore()->database();

    // ---- 1) Verificar quién es el comprador ----
    $verifiedIdToken = $firebaseAuth->verifyIdToken($idToken);
    $buyerUid = $verifiedIdToken->claims()->get('sub');
    $buyerEmail = $verifiedIdToken->claims()->get('email');

    // ---- 2) Leer y validar el producto ----
    $productRef = $firestore->collection('cm_products')->document($productId);
    $productSnap = $productRef->snapshot();
    if (!$productSnap->exists()) throw new Exception('Ese producto ya no está disponible.');
    $product = $productSnap->data();
    if (($product['enabled'] ?? true) === false) throw new Exception('Ese producto ya no está disponible.');

    $identifierType = $product['identifierType'] ?? 'NONE';
    $requiredKeys = IDENTIFIER_REQUIRED_KEYS[$identifierType] ?? [];
    $cleanDetails = [];
    foreach ($requiredKeys as $key) {
        $value = trim((string) ($details[$key] ?? ''));
        if ($value === '') throw new Exception('Falta un dato obligatorio del pedido.');
        $cleanDetails[$key] = $value;
    }

    $price = (float) ($product['price'] ?? 0);
    if ($price <= 0) throw new Exception('Ese producto no tiene un precio válido.');

    // ---- 3) Crear el pedido (server-side, con la cuenta de servicio) ----
    $orderRef = $firestore->collection('cm_orders')->newDocument();
    $orderRef->set([
        'serviceId' => $productId,
        'serviceName' => $product['name'] ?? 'Servicio',
        'category' => $product['category'] ?? null,
        'price' => $price,
        'identifierType' => $identifierType,
        'details' => $cleanDetails,
        'contactEmail' => $contactEmail,
        'buyerUid' => $buyerUid,
        'buyerEmail' => $buyerEmail,
        'status' => 'pendiente_pago',
        'createdAt' => new \DateTime(),
    ]);

    // ---- 4) Leer la llave secreta de Stripe y crear la sesión ----
    $stripeConfig = $firestore->collection('config')->document('stripe')->snapshot()->data();
    $secretKey = $stripeConfig['secretKey'] ?? null;
    if (!$secretKey) throw new Exception('Stripe no está configurado todavía.');

    \Stripe\Stripe::setApiKey($secretKey);

    $domain = 'https://tumerchmo.com'; // AJUSTAR si tu dominio es distinto
    $session = \Stripe\Checkout\Session::create([
        'mode' => 'payment',
        'payment_method_types' => ['card'],
        'customer_email' => $contactEmail,
        'line_items' => [[
            'quantity' => 1,
            'price_data' => [
                'currency' => 'usd',
                'unit_amount' => (int) round($price * 100),
                'product_data' => ['name' => $product['name'] ?? 'Servicio'],
            ],
        ]],
        // "kind" es lo que el webhook lee para saber que este pago es
        // de un pedido de Productos CM (no una recarga de técnico ni
        // un producto comercial simple).
        'metadata' => ['kind' => 'cm_order', 'orderId' => $orderRef->id()],
        'success_url' => $domain . '/index.html?pago=exito',
        'cancel_url' => $domain . '/index.html?pago=cancelado',
    ]);

    $orderRef->set(['stripeSessionId' => $session->id], ['merge' => true]);

    echo json_encode(['url' => $session->url]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
