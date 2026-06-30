// =====================================================================
// TUMERCHMO — Cloud Functions: integración con Stripe + envío de correo
// =====================================================================

const { onCall, HttpsError } = require("firebase-functions/v2/https");
const { onRequest } = require("firebase-functions/v2/https");
const { setGlobalOptions } = require("firebase-functions/v2");
const { defineSecret } = require("firebase-functions/params");
const admin = require("firebase-admin");
const Stripe = require("stripe");
const nodemailer = require("nodemailer");

admin.initializeApp();
const db = admin.firestore();

setGlobalOptions({ region: "us-central1" });

// ---------------------------------------------------------------------
// Secrets
// ---------------------------------------------------------------------
const STRIPE_SECRET_KEY    = defineSecret("STRIPE_SECRET_KEY");
const STRIPE_WEBHOOK_SECRET = defineSecret("STRIPE_WEBHOOK_SECRET");
const EMAIL_USER           = defineSecret("EMAIL_USER");
const EMAIL_PASS           = defineSecret("EMAIL_PASS");

const SITIO_URL = "https://tumerchmo.com";
const ID_COMBO  = "combo";
const PRECIO_COMBO_RESPALDO   = 99.0;
const DESCUENTO_PRIMERA_COMPRA = 0.30;

// =====================================================================
// Auxiliar: datos del producto desde Firestore
// =====================================================================
async function obtenerPrecioYDatosProducto(idProducto) {
  if (idProducto === ID_COMBO) {
    const snap = await db.collection("contenido").doc("oferta_principal").get();
    const datos = snap.exists ? snap.data() : null;
    return {
      id: ID_COMBO,
      nombre: (datos && datos.titulo) || "Recetario de Recetarios",
      precio: Number(datos && datos.precioActual) || PRECIO_COMBO_RESPALDO,
      imagenUrl: (datos && datos.imagenUrl) || "",
      pdfUrl: (datos && datos.pdfUrl) || "",
      emoji: "🎉",
    };
  }

  const snap = await db.collection("productos").doc(idProducto).get();
  if (!snap.exists) return null;
  const datos = snap.data();
  return {
    id: idProducto,
    nombre: datos.nombre,
    precio: Number(datos.precio),
    imagenUrl: datos.imagenUrl || "",
    pdfUrl: datos.pdfUrl || "",
    emoji: datos.emoji || "📖",
  };
}

// =====================================================================
// Auxiliar: ¿el usuario ya tiene compras?
// =====================================================================
async function usuarioYaTieneCompras(uid) {
  const snap = await db
    .collection("pedidos")
    .where("uid", "==", uid)
    .where("estado", "==", "pagado")
    .limit(1)
    .get();
  return !snap.empty;
}

// =====================================================================
// Auxiliar: descarga el PDF desde Firebase Storage y lo devuelve como Buffer
// =====================================================================
async function descargarPdfComoBuffer(pdfUrl) {
  if (!pdfUrl) return null;
  try {
    // La URL de Firebase Storage tiene el formato:
    // https://firebasestorage.googleapis.com/v0/b/BUCKET/o/PATH?alt=media&token=...
    // Extraemos el bucket y el path para usar el Admin SDK.
    const url = new URL(pdfUrl);
    const partes = url.pathname.split("/o/");
    if (partes.length < 2) return null;
    const bucket = partes[0].replace("/v0/b/", "");
    const filePath = decodeURIComponent(partes[1].split("?")[0]);
    const file = admin.storage().bucket(bucket).file(filePath);
    const [buffer] = await file.download();
    return buffer;
  } catch (e) {
    console.error("No se pudo descargar el PDF:", e.message);
    return null;
  }
}

// =====================================================================
// Auxiliar: manda el correo con el PDF adjunto
// =====================================================================
async function mandarCorreoConPdf({ correo, nombreProducto, pdfUrl, pdfNombre }) {
  const transporter = nodemailer.createTransport({
    host: "smtp.hostinger.com",
    port: 465,
    secure: true,
    auth: {
      user: EMAIL_USER.value(),
      pass: EMAIL_PASS.value(),
    },
  });

  const pdfBuffer = await descargarPdfComoBuffer(pdfUrl);

  const adjuntos = pdfBuffer
    ? [{ filename: pdfNombre || "recetario.pdf", content: pdfBuffer, contentType: "application/pdf" }]
    : [];

  await transporter.sendMail({
    from: `"Tumerchmo" <${EMAIL_USER.value()}>`,
    to: correo,
    subject: `¡Tu recetario está aquí! 🎉 — ${nombreProducto}`,
    html: `
      <div style="font-family:'Helvetica Neue',Arial,sans-serif;max-width:560px;margin:0 auto;background:#FDF6EC;border-radius:12px;overflow:hidden;border:1px solid #EDD9BB;">
        <div style="background:#2C1A0E;padding:28px 32px;text-align:center;">
          <h1 style="color:#F0B860;font-size:24px;margin:0;letter-spacing:-0.5px;">Tumerchmo<span style="color:#D4882A;">.</span></h1>
        </div>
        <div style="padding:36px 32px;">
          <h2 style="color:#2C1A0E;font-size:22px;margin:0 0 12px;">¡Tu compra fue exitosa! 🎉</h2>
          <p style="color:#5C3820;font-size:15px;line-height:1.7;margin:0 0 20px;">
            Gracias por tu compra. Adjunto a este correo encontrarás tu recetario:<br>
            <strong style="color:#2C1A0E;">${nombreProducto}</strong>
          </p>
          <p style="color:#5C3820;font-size:14px;line-height:1.7;margin:0 0 28px;">
            Abre el PDF adjunto y empieza a cocinar hoy mismo. 🍪<br>
            Si tienes alguna duda, responde a este correo y con gusto te ayudamos.
          </p>
          <div style="background:#2C1A0E;border-radius:8px;padding:20px 24px;text-align:center;">
            <p style="color:#F0B860;font-size:13px;margin:0;font-weight:600;">
              ¿Ya la hiciste? Cuéntanos cómo te fue 👇<br>
              <a href="https://tumerchmo.com" style="color:#D4882A;">tumerchmo.com</a>
            </p>
          </div>
        </div>
        <div style="padding:16px 32px;text-align:center;border-top:1px solid #EDD9BB;">
          <p style="color:#8B6347;font-size:12px;margin:0;">© 2026 Tumerchmo · Hermosillo, Sonora</p>
        </div>
      </div>`,
    ...(adjuntos.length ? { attachments: adjuntos } : {}),
  });

  console.log(`Correo enviado a ${correo} con PDF: ${pdfNombre}`);
}

// =====================================================================
// 1) crearCheckoutSession
// =====================================================================
exports.crearCheckoutSession = onCall(
  { secrets: [STRIPE_SECRET_KEY] },
  async (request) => {
    if (!request.auth) {
      throw new HttpsError("unauthenticated", "Debes iniciar sesión para comprar.");
    }
    const uid = request.auth.uid;
    const correoUsuario = request.auth.token.email || null;

    const items = request.data && request.data.items;
    if (!Array.isArray(items) || items.length === 0) {
      throw new HttpsError("invalid-argument", "El carrito está vacío o llegó mal formado.");
    }

    const stripe = Stripe(STRIPE_SECRET_KEY.value());
    const yaCompro = await usuarioYaTieneCompras(uid);

    const lineItems = [];
    const detalleParaElPedido = [];

    for (const item of items) {
      const idProducto = String(item.id || "").trim();
      const cantidad = Math.max(1, Math.min(20, parseInt(item.cantidad, 10) || 1));

      const producto = await obtenerPrecioYDatosProducto(idProducto);
      if (!producto || !Number.isFinite(producto.precio) || producto.precio <= 0) {
        throw new HttpsError("not-found", `El producto "${idProducto}" no existe o no tiene precio válido.`);
      }

      let precioFinal = producto.precio;
      let descuentoAplicado = false;

      if (idProducto === ID_COMBO && !yaCompro) {
        precioFinal = Math.round(producto.precio * DESCUENTO_PRIMERA_COMPRA * 100) / 100;
        descuentoAplicado = true;
      }

      lineItems.push({
        price_data: {
          currency: "mxn",
          unit_amount: Math.round(precioFinal * 100),
          product_data: {
            name: producto.nombre,
            images: producto.imagenUrl ? [producto.imagenUrl] : undefined,
          },
        },
        quantity: cantidad,
      });

      detalleParaElPedido.push({
        idProducto,
        nombreProducto: producto.nombre,
        emoji: producto.emoji,
        imagenUrl: producto.imagenUrl,
        pdfUrl: producto.pdfUrl,
        cantidad,
        precioUnitario: precioFinal,
        descuentoAplicado,
      });
    }

    const session = await stripe.checkout.sessions.create({
      mode: "payment",
      payment_method_types: ["card"],
      customer_email: correoUsuario || undefined,
      line_items: lineItems,
      success_url: `${SITIO_URL}/pago-exitoso.html?session_id={CHECKOUT_SESSION_ID}`,
      cancel_url: `${SITIO_URL}/index.html#catalogo`,
      metadata: {
        uid,
        items: JSON.stringify(
          detalleParaElPedido.map((d) => ({
            id: d.idProducto,
            c: d.cantidad,
            p: d.precioUnitario,
            n: d.nombreProducto,
            e: d.emoji,
            img: d.imagenUrl,
            pdf: d.pdfUrl,
            desc: d.descuentoAplicado,
          }))
        ),
      },
    });

    return { url: session.url };
  }
);

// =====================================================================
// 2) stripeWebhook — registra pedidos Y manda correos con PDF
// =====================================================================
exports.stripeWebhook = onRequest(
  { secrets: [STRIPE_SECRET_KEY, STRIPE_WEBHOOK_SECRET, EMAIL_USER, EMAIL_PASS] },
  async (req, res) => {
    const stripe = Stripe(STRIPE_SECRET_KEY.value());
    const firma = req.headers["stripe-signature"];

    let evento;
    try {
      evento = stripe.webhooks.constructEvent(
        req.rawBody,
        firma,
        STRIPE_WEBHOOK_SECRET.value()
      );
    } catch (err) {
      console.error("Firma de webhook inválida:", err.message);
      res.status(400).send(`Webhook Error: ${err.message}`);
      return;
    }

    if (evento.type === "checkout.session.completed") {
      const session = evento.data.object;
      try {
        await registrarPedidosDesdeSession(session);
        await mandarCorreosDesdeSession(session);
      } catch (err) {
        console.error("Error procesando sesión:", err);
      }
    }

    res.status(200).send({ recibido: true });
  }
);

// =====================================================================
// Registra pedidos en Firestore (idempotente)
// =====================================================================
async function registrarPedidosDesdeSession(session) {
  const uid = session.metadata && session.metadata.uid;
  if (!uid) {
    console.warn("Sesión sin uid en metadata.", session.id);
    return;
  }

  let items = [];
  try {
    items = JSON.parse((session.metadata && session.metadata.items) || "[]");
  } catch (e) {
    console.error("No se pudo parsear metadata.items:", e);
    return;
  }

  const correo = session.customer_details ? session.customer_details.email : null;
  const batch = db.batch();

  items.forEach((item, indice) => {
    const idPedido = `${session.id}_${indice}`;
    const ref = db.collection("pedidos").doc(idPedido);
    batch.set(ref, {
      uid,
      email: correo,
      idProducto: item.id,
      nombreProducto: item.n,
      emoji: item.e || "📖",
      imagenUrl: item.img || "",
      pdfUrl: item.pdf || "",
      cantidad: item.c,
      montoPagado: item.p * item.c,
      descuentoAplicado: !!item.desc,
      estado: "pagado",
      stripeSessionId: session.id,
      fecha: admin.firestore.FieldValue.serverTimestamp(),
    }, { merge: true });
  });

  await batch.commit();
  console.log(`Pedidos registrados para uid=${uid}, sesión=${session.id}`);
}

// =====================================================================
// Manda un correo por cada producto comprado
// =====================================================================
async function mandarCorreosDesdeSession(session) {
  const correo = session.customer_details ? session.customer_details.email : null;
  if (!correo) {
    console.warn("Sesión sin correo, no se puede mandar email.", session.id);
    return;
  }

  let items = [];
  try {
    items = JSON.parse((session.metadata && session.metadata.items) || "[]");
  } catch (e) {
    console.error("No se pudo parsear metadata.items para correo:", e);
    return;
  }

  for (const item of items) {
    try {
      await mandarCorreoConPdf({
        correo,
        nombreProducto: item.n,
        pdfUrl: item.pdf || "",
        pdfNombre: `${item.n.replace(/[^a-zA-Z0-9]/g, "_")}.pdf`,
      });
    } catch (e) {
      console.error(`Error mandando correo para producto ${item.id}:`, e.message);
    }
  }
}