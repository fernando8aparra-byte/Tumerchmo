// =====================================================================
// TUMERCHMO — Configuración central de Firebase
// =====================================================================

import { initializeApp } from "https://www.gstatic.com/firebasejs/10.12.2/firebase-app.js";
import { getAnalytics } from "https://www.gstatic.com/firebasejs/10.12.2/firebase-analytics.js";
import {
  getAuth,
  createUserWithEmailAndPassword,
  signInWithEmailAndPassword,
  signInWithPopup,
  GoogleAuthProvider,
  signOut,
  onAuthStateChanged,
  updateProfile,
} from "https://www.gstatic.com/firebasejs/10.12.2/firebase-auth.js";
import {
  getFirestore,
  doc,
  setDoc,
  getDoc,
  getDocs,
  addDoc,
  updateDoc,
  deleteDoc,
  collection,
  query,
  where,
  orderBy,
  serverTimestamp,
} from "https://www.gstatic.com/firebasejs/10.12.2/firebase-firestore.js";
import {
  getStorage,
  ref,
  uploadBytes,
  getDownloadURL,
} from "https://www.gstatic.com/firebasejs/10.12.2/firebase-storage.js";

const firebaseConfig = {
  apiKey: "AIzaSyDUE6QZa8ClQLNPXSuv0QFa2myLIAT2Y9s",
  authDomain: "mensaje-76bbc.firebaseapp.com",
  databaseURL: "https://mensaje-76bbc-default-rtdb.firebaseio.com",
  projectId: "mensaje-76bbc",
  storageBucket: "mensaje-76bbc.firebasestorage.app",
  messagingSenderId: "257129078581",
  appId: "1:257129078581:web:48c923addfa3d28526f67c",
  measurementId: "G-7F10K2Z3VJ",
};

const app = initializeApp(firebaseConfig);
const analytics = getAnalytics(app);
const auth = getAuth(app);
const db = getFirestore(app);
const storage = getStorage(app);

export const CORREOS_ADMIN = ["fernando8aparra@gmail.com"];
export function esAdmin(user) {
  return !!user && CORREOS_ADMIN.includes(user.email);
}

// ── Auth ──────────────────────────────────────────────────────────────
export async function registrarConCorreo(nombre, correo, password) {
  const cred = await createUserWithEmailAndPassword(auth, correo, password);
  await updateProfile(cred.user, { displayName: nombre });
  await crearPerfilUsuario(cred.user, nombre);
  return cred.user;
}
export async function iniciarSesionConCorreo(correo, password) {
  const cred = await signInWithEmailAndPassword(auth, correo, password);
  return cred.user;
}
export async function iniciarSesionConGoogle() {
  const provider = new GoogleAuthProvider();
  const cred = await signInWithPopup(auth, provider);
  await crearPerfilUsuario(cred.user, cred.user.displayName);
  return cred.user;
}
export async function cerrarSesion() { await signOut(auth); }
export function escucharSesion(callback) { return onAuthStateChanged(auth, callback); }

async function crearPerfilUsuario(user, nombre) {
  const r = doc(db, "usuarios", user.uid);
  const existente = await getDoc(r);
  if (!existente.exists()) {
    await setDoc(r, {
      nombre: nombre || user.email,
      correo: user.email,
      rol: esAdmin(user) ? "admin" : "usuario",
      creado: serverTimestamp(),
    });
  }
}

// ── Productos ─────────────────────────────────────────────────────────
export async function obtenerProductos() {
  const snap = await getDocs(collection(db, "productos"));
  return snap.docs.map((d) => ({ id: d.id, ...d.data() }));
}
export async function crearProducto(producto) {
  return await addDoc(collection(db, "productos"), { ...producto, creado: serverTimestamp() });
}
export async function actualizarProducto(id, cambios) {
  return await updateDoc(doc(db, "productos", id), cambios);
}
export async function eliminarProducto(id) {
  return await deleteDoc(doc(db, "productos", id));
}

// ── Storage: imagen ───────────────────────────────────────────────────
export async function subirImagenProducto(archivo, productoId) {
  const nombreArchivo = `${productoId}-${Date.now()}-${archivo.name}`;
  const storageRef = ref(storage, `productos/${nombreArchivo}`);
  await uploadBytes(storageRef, archivo);
  return await getDownloadURL(storageRef);
}

// ── Storage: PDF ──────────────────────────────────────────────────────
// Guarda el PDF en /pdfs/{productoId}/{nombre} y devuelve la URL pública.
// El webhook de Stripe usa esa URL para enviarla por correo al cliente.
export async function subirPdfProducto(archivo, productoId) {
  const nombreArchivo = `${productoId}-${Date.now()}-${archivo.name}`;
  const storageRef = ref(storage, `pdfs/${productoId}/${nombreArchivo}`);
  await uploadBytes(storageRef, archivo);
  return await getDownloadURL(storageRef);
}

// ── Pedidos ───────────────────────────────────────────────────────────
export async function obtenerPedidosDeUsuario(uid) {
  const q = query(collection(db, "pedidos"), where("uid", "==", uid), orderBy("fecha", "desc"));
  const snap = await getDocs(q);
  return snap.docs.map((d) => ({ id: d.id, ...d.data() }));
}
export async function obtenerTodosLosPedidos() {
  const q = query(collection(db, "pedidos"), orderBy("fecha", "desc"));
  const snap = await getDocs(q);
  return snap.docs.map((d) => ({ id: d.id, ...d.data() }));
}
export async function crearPedido(pedido) {
  return await addDoc(collection(db, "pedidos"), { ...pedido, fecha: serverTimestamp() });
}

export { auth, db, storage };
