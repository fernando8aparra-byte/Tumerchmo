<?php
/**
 * api/create-catalog-checkout.php
 * ------------------------------------------------------------------
 * Recibe { orderId } desde index.html (pedido ya guardado en
 * Firestore, colección "commercial_orders", status "pendiente_pago"),
 * crea una Stripe Checkout Session por el precio de ese pedido y
 * regresa { url } para que el navegador redirija al pago.
 *
 * ⚠️ ESTO ES UNA BASE, NO ESTÁ PROBADA CONTRA TU SERVIDOR REAL.
 * No tengo acceso a tu webhook de Stripe actual ni a cómo cargas el
 * SDK de Firebase Admin / Stripe en PHP, así que ajusta las 3 cosas
 * marcadas con "AJUSTAR" antes de subirlo. Cuando me pases tu webhook
 * actual, lo extiendo yo mismo para que reconozca kind=catalog_order.
 *
 * Requiere (composer):
 *   stripe/stripe-php
 *   kreait/firebase-php   (para leer Firestore y el secretKey de Stripe
 *                           guardado en config/stripe, igual que hace
 *                           el resto de tu backend)
 * ------------------------------------------------------------------
 */

header('Content-Type: application/json');

require __DIR__ . '/../vendor/autoload.php'; // AJUSTAR si tu vendor/ está en otra ruta

use Kreait\Firebase\Factory;

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $orderId = $input['orderId'] ?? null;
    if (!$orderId) {
        throw new Exception('Falta orderId.');
    }

    // ---- Firebase Admin (mismo patrón que el resto de tu backend) ----
    $serviceAccountPath = '/home/u511071039/domains/tumerchmo.com/firebase-service-account.json'; // AJUSTAR si cambió
    $factory = (new Factory())->withServiceAccount($serviceAccountPath);
    $firestore = $factory->createFirestore()->database();

    // ---- Leer el pedido ----
    $orderRef = $firestore->collection('commercial_orders')->document($orderId);
    $orderSnap = $orderRef->snapshot();
    if (!$orderSnap->exists()) {
        throw new Exception('Ese pedido no existe.');
    }
    $order = $orderSnap->data();
    if (($order['status'] ?? '') !== 'pendiente_pago') {
        throw new Exception('Este pedido ya no está pendiente de pago.');
    }

    // ---- Leer la llave secreta de Stripe desde config/stripe ----
    $stripeConfig = $firestore->collection('config')->document('stripe')->snapshot()->data();
    $secretKey = $stripeConfig['secretKey'] ?? null;
    if (!$secretKey) {
        throw new Exception('Stripe no está configurado todavía.');
    }

    \Stripe\Stripe::setApiKey($secretKey);

    $amountCents = (int) round(((float) $order['price']) * 100);
    $domain = 'https://tumerchmo.com'; // AJUSTAR si tu dominio es distinto

    $session = \Stripe\Checkout\Session::create([
        'mode' => 'payment',
        'payment_method_types' => ['card'],
        'customer_email' => $order['contactEmail'] ?? null,
        'line_items' => [[
            'quantity' => 1,
            'price_data' => [
                'currency' => 'usd',
                'unit_amount' => $amountCents,
                'product_data' => [
                    'name' => $order['serviceName'] ?? 'Servicio',
                ],
            ],
        ]],
        // "kind" es lo que tu webhook debe leer para saber que este pago
        // corresponde a un pedido del catálogo público (no una recarga
        // de técnico ni un producto comercial simple).
        'metadata' => [
            'kind' => 'catalog_order',
            'orderId' => $orderId,
        ],
        'success_url' => $domain . '/index.html?pago=exito',
        'cancel_url' => $domain . '/index.html?pago=cancelado',
    ]);

    // Guarda el id de la sesión en el pedido, útil para conciliar en el webhook.
    $orderRef->update([
        ['path' => 'stripeSessionId', 'value' => $session->id],
    ]);

    echo json_encode(['url' => $session->url]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
