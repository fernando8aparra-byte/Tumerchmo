# Conexión con tu proveedor (API DHRU Fusion)

## Archivos
- `dhru-config.php` — dominio, usuario y clave. **Rellena `domain` y `username`.**
- `dhru-client.php` — habla el protocolo DHRU (no lo toques salvo que tu proveedor use otra ruta).
- `api-services.php` — endpoint que tu web consulta para listar servicios.
- `api-balance.php` — endpoint para verificar que la conexión funciona (saldo/cuenta).
- `snippet-para-index.html` — botón + JS para pegar en tu `index.html`.

## Pasos
1. Sube los 4 archivos PHP a tu hosting (necesitas PHP habilitado; casi cualquier
   hosting compartido lo trae). Ponlos en la misma carpeta que tu `index.html`.
2. Abre `dhru-config.php` y reemplaza:
   - `domain` → la URL del panel de tu proveedor (te la da tu proveedor).
   - `username` → tu usuario en ese proveedor.
   - `endpoint_path` → normalmente `/api/index.php`, pero confírmalo con tu proveedor.
   La clave `apiaccesskey` ya está puesta con la que me diste para pruebas.
3. Prueba primero el saldo, abriendo en el navegador:
   `https://tu-sitio.com/api-balance.php`
   Si ves `{"ok":true,...}` con tu crédito, la conexión funciona.
   Si ves un error, revisa dominio/usuario/ruta con tu proveedor.
4. Luego prueba:
   `https://tu-sitio.com/api-services.php`
   Deberías ver la lista completa de servicios con su costo.
5. Pega el contenido de `snippet-para-index.html` en tu `index.html` siguiendo
   las dos instrucciones marcadas ahí (un botón en el drawer + una función JS).

## Seguridad
- La clave API **nunca** debe ir en el HTML/JS del navegador — por eso vive
  solo dentro de `dhru-config.php`, que corre en el servidor.
- Restringe el acceso directo a `dhru-config.php` desde el navegador (bloquéalo
  con `.htaccess` o muévelo fuera de la carpeta pública), aunque técnicamente
  ya no expone nada porque es un `return [...]` de PHP y no un archivo de texto plano.
- Si más adelante agregas "Colocar pedido" (placeimeiorder) desde el botón
  "Usar", hazlo siempre desde un endpoint PHP tuyo (nunca directo desde el
  navegador), para no exponer la clave ni permitir que cualquiera dispare
  pedidos y gaste tu saldo.

## Si tu proveedor no es DHRU Fusion "puro"
Algunos revendedores (como el ejemplo iSave que vi en la documentación)
ofrecen además una API REST propia más simple (JSON + header `X-API-Key`)
en paralelo al modo DHRU clásico. Si tu proveedor te dio un dominio +
un solo API key (como el que me compartiste) sin usuario, dime y ajusto
`dhru-client.php` a ese formato en vez del protocolo DHRU clásico.
