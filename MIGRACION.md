# Migración a la estructura por carpetas (clientes que ya funcionan)

Se hace **una vez por cliente**. Calcula unos 10 minutos por cliente. Hazlo primero en uno de prueba o en el tuyo.

## 0. Antes de empezar
- Plesk → **Copias de seguridad** del subdominio (o descarga `httpdocs` en un zip).
- Exporta su base de datos desde phpMyAdmin.

## 1. Subir el código nuevo
- Con Git: conecta el subdominio al repositorio (README, apartado 2) y pulsa **Desplegar**.
- Sin Git: sube el contenido del zip a `httpdocs` y **sobrescribe** los archivos.

Las páginas (`index.html`, `admin.html`, …), `api.php` y `zkteco_adms_listener.php` siguen en la raíz. Las URLs no cambian y el ZKteco no hay que tocarlo.

## 2. Mover los archivos propios del cliente
Usa el Administrador de archivos de Plesk:

| Ahora en la raíz | Se mueve a |
|---|---|
| `config.php` | `app/config.php` |
| `custom-theme.json` | `datos/custom-theme.json` |
| `custom-theme.css` | `datos/custom-theme.css` |
| `logs/*` (si existe la carpeta) | `logs/` (se queda donde está) |

Comprueba que `datos/` y `logs/` tienen permisos de escritura.

## 3. Cambiar las tareas programadas
En Plesk → **Tareas programadas**, añade `cron/` a la ruta de cada tarea:

```
.../httpdocs/cron-auto-clockout.php        →  .../httpdocs/cron/cron-auto-clockout.php
.../httpdocs/cron-finalizar-ausencias.php  →  .../httpdocs/cron/cron-finalizar-ausencias.php
.../httpdocs/sincronizar-fichajes-jibble.php → .../httpdocs/cron/sincronizar-fichajes-jibble.php   (si la tenías)
```

Pulsa **Ejecutar ahora** en una de ellas. Luego mira `logs/auto-clockout.log`.

## 4. Borrar los archivos antiguos de la raíz
Ahora están duplicados o eran públicos. Bórralos:

- **PHP:** `JibbleAPI.php`, `horarios_helper.php`, `auditoria_helper.php`, `informes_helper.php`, `reportes.php`, `cron-auto-clockout.php`, `cron-finalizar-ausencias.php`, `sincronizar-fichajes-jibble.php`, `get-people.php`, `get-sync-logs.php`, `test-clockin-debug.php`
- **HTML:** `test-clockin.html` (ahora es `clockin.html`), `test-clockout-all.html`, `admin.html.backup`
- **CSS/JS:** `styles.css`, `horarios-calendario.css`, `theme-loader.js`, `horarios-calendario.js`, `auditoria.js`, `informes.js`
- **Imágenes:** `favicon.svg`, `favicon.png`, `favicon-96x96.png`, `apple-touch-icon.png`, `web-app-manifest-192x192.png`, `web-app-manifest-512x512.png`. `favicon.ico` se queda en la raíz.
- **Carpetas y documentos:** carpeta `lib/` (ahora `app/lib/`), `Jibble_DPA_final-merged.pdf` (ahora `assets/docs/`)
- **Generados, datos y logs:** `reporte_mensual_*.json/.html/.csv` (si los quieres, guárdalos antes), `*.log` sueltos en la raíz, `.env` (la app no lo usa), `*.pem`, `jibble_fichajes*.sql`

## 5. nginx
Pega las directivas del README (apartado *Seguridad: nginx en Plesk*) en **Configuración de Apache y nginx → Directivas adicionales de nginx**.

## 6. Caché
Cloudflare → **Caching → Purge Everything**.

## 7. Comprobar
- Entra al admin y a la página del empleado. Abre el calendario, el historial y los informes, y mira que el tema se ve con tus colores.
- Ficha con RFID o ZKteco y comprueba que aparece.
- Ejecuta el bucle de `curl` del README. Todo debe dar **403**.

## Si algo falla
- **Pantalla en blanco o error 500:** falta `app/config.php` (paso 2).
- **El tema sale por defecto:** falta `datos/custom-theme.*`.
- **Iconos o estilos rotos:** es la caché. Purga Cloudflare o fuerza la recarga.
- **Volver atrás:** restaura la copia del paso 0.
