/* ============================================================
   Menú hamburguesa + autenticación (compartido entre páginas)
   SDK modular v10 — importa auth desde firebase-config.js
   ============================================================ */
import { auth } from './firebase-config.js';
import {
  onAuthStateChanged,
  signInWithEmailAndPassword,
  signOut
} from "https://www.gstatic.com/firebasejs/10.13.0/firebase-auth.js";

const menuBtn = document.getElementById('menuBtn');
const overlay = document.getElementById('overlay');
const sidePanel = document.getElementById('sidePanel');
const closePanel = document.getElementById('closePanel');

const loggedOutView = document.getElementById('loggedOutView');
const loggedInView = document.getElementById('loggedInView');

const loginEmail = document.getElementById('loginEmail');
const loginPassword = document.getElementById('loginPassword');
const loginBtn = document.getElementById('loginBtn');
const loginError = document.getElementById('loginError');

const avatarInitials = document.getElementById('avatarInitials');
const profileEmail = document.getElementById('profileEmail');
const logoutBtn = document.getElementById('logoutBtn');

function openPanel(){
  overlay.classList.add('open');
  sidePanel.classList.add('open');
}
function closeSidePanel(){
  overlay.classList.remove('open');
  sidePanel.classList.remove('open');
}

menuBtn.addEventListener('click', openPanel);
closePanel.addEventListener('click', closeSidePanel);
overlay.addEventListener('click', closeSidePanel);
window.openAccountPanel = openPanel;

/* Otras páginas se suscriben con window.onAuthChange(fn) para reaccionar
   cuando cambia la sesión (ej. habilitar el botón de registrar). */
window._authListeners = [];
window.onAuthChange = function(fn){ window._authListeners.push(fn); };
window.currentUser = null;

function notifyAuthListeners(user){
  window.currentUser = user;
  window._authListeners.forEach(fn => fn(user));
}

onAuthStateChanged(auth, user => {
  if (user) {
    loggedOutView.style.display = 'none';
    loggedInView.style.display = 'block';
    profileEmail.textContent = user.email;
    avatarInitials.textContent = user.email.slice(0,2).toUpperCase();
  } else {
    loggedOutView.style.display = 'block';
    loggedInView.style.display = 'none';
  }
  notifyAuthListeners(user);
});

loginBtn.addEventListener('click', () => {
  const email = loginEmail.value.trim();
  const pass = loginPassword.value;
  if (!email || !pass) {
    loginError.textContent = 'Ingresa correo y contraseña.';
    return;
  }
  loginError.textContent = '';
  loginBtn.disabled = true;
  loginBtn.textContent = 'Entrando...';
  signInWithEmailAndPassword(auth, email, pass)
    .then(() => {
      loginBtn.disabled = false;
      loginBtn.textContent = 'Iniciar sesión';
      loginEmail.value = '';
      loginPassword.value = '';
      closeSidePanel();
    })
    .catch(err => {
      loginBtn.disabled = false;
      loginBtn.textContent = 'Iniciar sesión';
      loginError.textContent = 'No se pudo iniciar sesión. Verifica tus datos.';
      console.error(err);
    });
});

logoutBtn.addEventListener('click', () => {
  signOut(auth);
  closeSidePanel();
});
