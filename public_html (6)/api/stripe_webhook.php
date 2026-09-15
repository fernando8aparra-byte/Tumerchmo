<?php
/**
 * stripe_webhook.php
 * -------------------------------------------------------------
 * ESTE es el único archivo que suma saldo por pagos con Stripe.
 *
 * Stripe llama aquí cuando un pago se completa. Antes de acreditar
 * nada, se verifica la FIRMA criptográfica del aviso: solo Stripe
 * conoce el "signing secret", así que nadie más puede falsificar un
 * aviso de pago. Sin esa verificación, cualquiera podría mandar un
 * POST fingiendo un pago y regalarse saldo.
 *
 * Además guarda cada pago procesado en la colección "payments" con el
 * id de la sesión como identificador -- si Stripe reintenta el mismo
 * aviso (cosa que hace normalmente), el saldo NO se suma dos veces.
 * -------------------------------------------------------------
 * INSTALACIÓN:
 * 1. Sube este archivo a /api.
 * 2. En Stripe → Developers → Webhooks → "Add endpoint":
 *      URL:    https://TU-DOMINIO.com/api/stripe_webhook.php
 *      Evento: checkout.session.completed
 * 3. Copia el "Signing secret" (empieza con whsec_) y guárdalo en
 *    admin.html → Config. Stripe.
 * -------------------------------------------------------------
 */

require __DIR__ . '/firestore-admin.php';
require __DIR__ . '/notify-admin.php';

// Stripe no espera respuesta JSON, solo un 200. Cualquier otro código
// hace que reintente más tarde (lo cual está bien: si Firestore está
// caído un momento, el pago no se pierde, se reintenta).
$payload = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

$fsToken = getFirestoreAdminToken();
if (!$fsToken) {
    http_response_code(500);
    error_log('stripe_webhook: no se pudo autenticar con Firestore');
    echo 'error';
    exit;
}

$stripeConfig = fsGetDoc($fsToken, 'config/stripe');
if (!$stripeConfig || empty($stripeConfig['webhookSecret'])) {
    http_response_code(500);
    error_log('stripe_webhook: falta el webhookSecret en config/stripe');
    echo 'error';
    exit;
}

/* ---------- 1) VERIFICAR LA FIRMA (lo más importante) ---------- */
if (!verifyStripeSignature($payload, $sigHeader, $stripeConfig['webhookSecret'])) {
    http_response_code(400);
    error_log('stripe_webhook: firma inválida -- aviso descartado');
    echo 'firma invalida';
    exit;
}

$event = json_decode($payload, true);
$type = $event['type'] ?? '';

// Solo nos interesa el pago completado.
if ($type !== 'checkout.session.completed') {
    http_response_code(200);
    echo 'ignorado';
    exit;
}

$session = $event['data']['object'] ?? [];
$sessionId = $session['id'] ?? '';
$paymentStatus = $session['payment_status'] ?? '';
$uid = $session['metadata']['uid'] ?? ($session['client_reference_id'] ?? '');
$amountUsd = isset($session['metadata']['amount_usd']) ? (float)$session['metadata']['amount_usd'] : 0;
$kind = $session['metadata']['kind'] ?? 'recarga'; // "recarga" (default, saldo técnico) | "commercial_product" | "cm_order"

// Doble seguro: el monto real cobrado según Stripe, no el que veníamos
// arrastrando. Si por lo que sea no coinciden, gana el de Stripe.
if (isset($session['amount_total'])) {
    $amountUsd = ((int)$session['amount_total']) / 100;
}

if ($paymentStatus !== 'paid' || !$uid || $amountUsd <= 0 || !$sessionId) {
    http_response_code(200); // no es un error de Stripe, simplemente no aplica
    error_log("stripe_webhook: aviso sin datos utilizables (status=$paymentStatus uid=$uid monto=$amountUsd)");
    echo 'sin datos';
    exit;
}

/* ---------- 2) EVITAR ACREDITAR DOS VECES ----------
   Stripe reintenta los avisos si no responde 200 rápido, así que el
   mismo pago puede llegar varias veces. Guardamos cada sessionId; si
   ya existe, no volvemos a sumar. */
$existing = fsGetDoc($fsToken, 'payments/' . $sessionId);
if ($existing) {
    http_response_code(200);
    echo 'ya procesado';
    exit;
}

/* ================= PEDIDO DE PRODUCTOS CM (tienda pública, con IMEI/ECID/etc.) =================
   Separado por completo de "commercial_product" (venta simple, sin
   pedir datos del equipo) y del catálogo de técnicos. El pedido NO
   existe hasta este momento -- se crea completo aquí, con todo lo que
   viajó escondido en la metadata desde create_cm_checkout.php. */
if ($kind === 'cm_order') {
    $productId = $session['metadata']['productId'] ?? '';
    $productName = $session['metadata']['productName'] ?? 'Servicio';
    $category = $session['metadata']['category'] ?? null;
    $identifierType = $session['metadata']['identifierType'] ?? 'NONE';
    $contactEmail = $session['metadata']['contactEmail'] ?? ($session['customer_details']['email'] ?? null);
    $details = json_decode($session['metadata']['details'] ?? '[]', true);
    if (!is_array($details)) $details = [];
    // Si el cliente tenia saldo a favor, create_cm_checkout.php ya lo
    // desconto del precio antes de mandarlo a Stripe -- $amountUsd
    // (recalculado arriba con lo que Stripe SI cobro) es solo la
    // parte pagada con tarjeta. El precio completo del pedido es esa
    // parte + lo que se cubrio con saldo.
    $balanceUsed = isset($session['metadata']['balanceUsed']) ? (float)$session['metadata']['balanceUsed'] : 0;
    $fullPrice = round($amountUsd + $balanceUsed, 2);

    fsCreateDoc($fsToken, 'cm_orders', null, [
        'serviceId' => $productId,
        'serviceName' => $productName,
        'category' => $category ?: null,
        'price' => $fullPrice,
        'amountCharged' => $amountUsd,
        'balanceUsed' => $balanceUsed,
        'identifierType' => $identifierType,
        'details' => $details,
        'contactEmail' => $contactEmail,
        'buyerUid' => $uid,
        'buyerEmail' => $contactEmail,
        'status' => 'pagado',
        'sessionId' => $sessionId,
        'paymentIntent' => $session['payment_intent'] ?? null,
        'createdAt' => gmdate('Y-m-d\TH:i:s\Z'),
    ]);

    // Si se uso saldo, se descuenta AHORA que el pago ya se confirmo
    // de verdad (nunca antes -- si el cliente hubiera cancelado en
    // Stripe, su saldo no debia tocarse).
    if ($balanceUsed > 0 && $uid) {
        $customer = fsGetDoc($fsToken, 'customers/' . $uid);
        $currentBalance = (float)($customer['balance'] ?? 0);
        $newCustomerBalance = round($currentBalance - $balanceUsed, 2);
        fsPatchDoc($fsToken, 'customers/' . $uid, ['balance' => $newCustomerBalance]);
        fsCreateDoc($fsToken, 'customers/' . $uid . '/movimientos', null, [
            'tipo' => 'canje_saldo',
            'monto' => -$balanceUsed,
            'productId' => $productId,
            'serviceName' => $productName,
            'saldoResultante' => $newCustomerBalance,
            'createdAt' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
    }

    // Mismo candado anti-doble-cobro que usan los demas "sabores".
    fsCreateDoc($fsToken, 'payments', $sessionId, [
        'uid' => $uid,
        'provider' => 'stripe',
        'kind' => 'cm_order',
        'amount' => $amountUsd,
        'sessionId' => $sessionId,
        'createdAt' => gmdate('Y-m-d\TH:i:s\Z'),
    ]);

    // Sumamos 1 al contador de compras del producto (para "mas vendido").
    if ($productId) {
        $product = fsGetDoc($fsToken, 'cm_products/' . $productId);
        $currentCount = (int)($product['purchaseCount'] ?? 0);
        fsPatchDoc($fsToken, 'cm_products/' . $productId, ['purchaseCount' => $currentCount + 1]);
    }

    notifyAdmin(
        $fsToken,
        'Nuevo pedido tienda -- $' . number_format($fullPrice, 2) . ' USD (' . $productName . ')',
        "\xf0\x9f\x9b\x92 Nuevo pedido en la tienda publica (Productos CM)\n" .
        'Servicio: ' . $productName . "\n" .
        'Precio: $' . number_format($fullPrice, 2) . " USD" . ($balanceUsed > 0 ? ' (cobrado: $' . number_format($amountUsd, 2) . ' + saldo usado: $' . number_format($balanceUsed, 2) . ')' : '') . "\n" .
        'Cliente: ' . ($contactEmail ?? $uid) . "\n" .
        'Datos: ' . json_encode($details)
    );

    http_response_code(200);
    echo 'ok';
    exit;
}

/* ================= COMPRA DE PRODUCTO COMERCIAL (venta directa) ================= */
if ($kind === 'commercial_product') {
    $productId = $session['metadata']['productId'] ?? '';
    $productName = $session['metadata']['productName'] ?? 'Producto';
    $email = $session['customer_details']['email'] ?? null;

    fsCreateDoc($fsToken, 'commercial_orders', null, [
        'uid' => $uid,
        'email' => $email,
        'productId' => $productId,
        'productName' => $productName,
        'amount' => $amountUsd,
        'sessionId' => $sessionId,
        'paymentIntent' => $session['payment_intent'] ?? null,
        'status' => 'pagado',
        'createdAt' => gmdate('Y-m-d\TH:i:s\Z'),
    ]);

    // Marcamos el sessionId como procesado también en "payments" --
    // mismo mecanismo anti-doble-cobro que usa la recarga de saldo.
    fsCreateDoc($fsToken, 'payments', $sessionId, [
        'uid' => $uid,
        'provider' => 'stripe',
        'kind' => 'commercial_product',
        'amount' => $amountUsd,
        'sessionId' => $sessionId,
        'createdAt' => gmdate('Y-m-d\TH:i:s\Z'),
    ]);

    // Sumamos 1 al contador de compras del producto (para "más vendido").
    if ($productId) {
        $product = fsGetDoc($fsToken, 'commercial_products/' . $productId);
        $currentCount = (int)($product['purchaseCount'] ?? 0);
        fsPatchDoc($fsToken, 'commercial_products/' . $productId, ['purchaseCount' => $currentCount + 1]);
    }

    notifyAdmin(
        $fsToken,
        'Nueva compra -- $' . number_format($amountUsd, 2) . ' USD (' . $productName . ')',
        "🛍️ Nueva compra en la página comercial\n" .
        'Producto: ' . $productName . "\n" .
        'Monto: $' . number_format($amountUsd, 2) . " USD\n" .
        'Cliente: ' . ($email ?? $uid)
    );

    http_response_code(200);
    echo 'ok';
    exit;
}

/* ================= RECARGA DE SALDO DE TÉCNICO (comportamiento de siempre) ================= */
$tech = fsGetDoc($fsToken, 'technicians/' . $uid);
$currentBalance = (float)($tech['balance'] ?? 0);
$newBalance = round($currentBalance + $amountUsd, 2);

$okBalance = fsPatchDoc($fsToken, 'technicians/' . $uid, ['balance' => $newBalance]);
if (!$okBalance) {
    // Devolvemos error para que Stripe reintente y el pago no se pierda.
    http_response_code(500);
    error_log("stripe_webhook: no se pudo actualizar el saldo de $uid");
    echo 'error al acreditar';
    exit;
}

// Registro del pago (también es lo que impide el doble acreditado).
fsCreateDoc($fsToken, 'payments', $sessionId, [
    'uid' => $uid,
    'provider' => 'stripe',
    'amount' => $amountUsd,
    'sessionId' => $sessionId,
    'paymentIntent' => $session['payment_intent'] ?? null,
    'email' => $session['customer_details']['email'] ?? null,
    'balanceBefore' => $currentBalance,
    'balanceAfter' => $newBalance,
    'createdAt' => gmdate('Y-m-d\TH:i:s\Z'),
]);

notifyAdmin(
    $fsToken,
    'Nueva recarga -- $' . number_format($amountUsd, 2) . ' USD (Stripe)',
    "💳 Nueva recarga en F3RN4N\n" .
    "Vía: Stripe\n" .
    'Monto: $' . number_format($amountUsd, 2) . " USD\n" .
    'Técnico: ' . ($session['customer_details']['email'] ?? $uid) . "\n" .
    'Saldo nuevo: $' . number_format($newBalance, 2) . ' USD'
);

http_response_code(200);
echo 'ok';
exit;


/* =================================================================
 * Verifica la firma que manda Stripe en el header Stripe-Signature.
 * Formato: t=1234567890,v1=abc123...
 * Se firma "timestamp.payload" con HMAC-SHA256 usando el signing secret.
 * ================================================================= */
function verifyStripeSignature(string $payload, string $sigHeader, string $secret): bool
{
    if (!$sigHeader || !$secret) return false;

    $timestamp = null;
    $signatures = [];
    foreach (explode(',', $sigHeader) as $part) {
        $kv = explode('=', trim($part), 2);
        if (count($kv) !== 2) continue;
        if ($kv[0] === 't') $timestamp = $kv[1];
        if ($kv[0] === 'v1') $signatures[] = $kv[1];
    }
    if (!$timestamp || !count($signatures)) return false;

    // Rechaza avisos viejos (protege contra reenvío de un aviso capturado).
    if (abs(time() - (int)$timestamp) > 300) return false;

    $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    foreach ($signatures as $sig) {
        // hash_equals compara en tiempo constante (evita filtrar el
        // secreto midiendo cuánto tarda la comparación).
        if (hash_equals($expected, $sig)) return true;
    }
    return false;
}