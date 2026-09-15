/* ============================================================
   CONFIGURACIÓN DE FIREBASE (SDK modular v10)
   Proyecto: f3rn4n
   ============================================================ */
import { initializeApp } from "https://www.gstatic.com/firebasejs/10.13.0/firebase-app.js";
import { getAnalytics, isSupported } from "https://www.gstatic.com/firebasejs/10.13.0/firebase-analytics.js";
import { getAuth } from "https://www.gstatic.com/firebasejs/10.13.0/firebase-auth.js";
import { getFirestore } from "https://www.gstatic.com/firebasejs/10.13.0/firebase-firestore.js";

const firebaseConfig = {
  apiKey: "AIzaSyAv2zk1jEQJcyTfEb7RsmsNtFAp3J5sSnI",
  authDomain: "f3rn4n.firebaseapp.com",
  projectId: "f3rn4n",
  storageBucket: "f3rn4n.firebasestorage.app",
  messagingSenderId: "156609223149",
  appId: "1:156609223149:web:b1d92517932610696cafe4",
  measurementId: "G-5SME8LS1V2"
};

export const app = initializeApp(firebaseConfig);
export const auth = getAuth(app);
export const db = getFirestore(app);
export const firebaseReady = true;

/* Colección donde se guardan los registros de verificación */
export const VERIFICATIONS_COLLECTION = "verificaciones";

/* Analytics solo se activa si el navegador lo soporta (no falla en SSR/entornos raros) */
isSupported().then(supported => {
  if (supported) getAnalytics(app);
}).catch(() => {});