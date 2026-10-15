# Oficia Clock

Control horario y registro de jornada (art. 34.9 ET) para empresas: fichaje web, RFID / ZKteco, horarios con bloqueo, calendario, ausencias, historial de cambios auditado e informes mensuales firmados.

Un repositorio, muchos clientes: cada cliente tiene su subdominio, su base de datos y su `config.php`. El código es el mismo para todos y se actualiza con un `git push`.

---

## Estructura

```
httpdocs/
├── index.html, login.html, admin.html, clockin.html…   páginas (URLs públicas, se quedan en la raíz)
├── api.php                     API única (router ?action=)
├── zkteco_adms_listener.php    recibe los marcajes del ZKteco (/iclock/* → .htaccess)
├── .htaccess, site.webmanifest, favicon.ico
├── assets/
│   ├── css/    styles.css, horarios-calendario.css
│   ├── js/     theme-loader.js, horarios-calendario.js, auditoria.js, informes.js
│   ├── img/    iconos y favicons
│   └── docs/   PDF públicos (DPA)
├── app/        🔒 config.php, JibbleAPI.php, *_helper.php, reportes.php, lib/fpdf/
├── cron/       🔒 tareas programadas (solo por línea de comandos)
├── herramientas/ 🔒 scripts de diagnóstico antiguos (no se usan desde la app)
├── sql/        🔒 scripts de base de datos
├── datos/      🔒 datos propios de cada cliente (tema guardado). No va al repositorio
└── logs/       🔒 logs. No va al repositorio
```

🔒 = carpeta bloqueada desde el navegador (`.htaccess` propio + regla en el `.htaccess` raíz).

**No se suben al repositorio** (ver `.gitignore`): `app/config.php`, `.env`, certificados, `datos/*`, `logs/*`, informes generados, copias de seguridad y exportaciones de la BD.

---

## 1. Primera subida a GitHub (una vez)

1. Crea un repositorio **privado** en GitHub, por ejemplo `oficia-clock`.
2. En tu copia local del código (sin `config.php` ni datos de clientes):

   ```bash
   git init
   git add .
   git commit -m "Oficia Clock: versión inicial"
   git branch -M main
   git remote add origin git@github.com:TU_USUARIO/oficia-clock.git
   git push -u origin main
   ```

3. Antes de hacer push, comprueba con `git status` que **no** aparece `config.php` ni `.env`.

---

## 2. Conectar cada cliente en Plesk (una vez por cliente)

En Plesk → **Sitios web y dominios** → subdominio del cliente → **Git**:

1. **Repositorio remoto**: `git@github.com:TU_USUARIO/oficia-clock.git`
2. Plesk muestra una **clave SSH pública** → cópiala en GitHub → repositorio → **Settings → Deploy keys → Add deploy key** (solo lectura).
3. **Modo de despliegue**: *Automático* · **Ruta de despliegue**: `httpdocs` · Rama: `main`.
4. Copia la **URL del webhook** que muestra Plesk → GitHub → **Settings → Webhooks → Add webhook** (Content type `application/json`, evento *push*).
5. La primera vez, en el servidor:
   - copia `app/config.ejemplo.php` como `app/config.php` y pon los datos de la base de datos de ese cliente;
   - comprueba que `logs/` y `datos/` tienen permisos de escritura.

> Plesk **no borra** archivos que no están en el repositorio, así que `app/config.php`, `datos/` y `logs/` de cada cliente se conservan en cada despliegue.

> Si ya tienes el cliente funcionando, haz una **copia de seguridad de `httpdocs`** antes de conectarlo a Git por primera vez.

---

## 3. Publicar una actualización

```bash
git add .
git commit -m "Descripción del cambio"
git push
```

Cada cliente conectado se actualiza solo en unos segundos. Si en algún cliente prefieres controlarlo, pon su despliegue en *Manual* y pulsa **Desplegar** en Plesk cuando quieras.

Después de desplegar cambios en `.js` o `.css`: **purga la caché de Cloudflare** o sube el `?v=` en las etiquetas `<script>` / `<link>`.

---

## 4. Base de datos

Cada cliente tiene su propia base de datos. Los scripts están en `sql/` y se pueden ejecutar varias veces sin romper nada (`IF NOT EXISTS`):

| Script | Qué hace | Cuándo |
|---|---|---|
| `sql/00_estructura.sql` | Tablas base (usuarios, fichajes, descansos, ausencias, admins…) | Solo clientes nuevos |
| `sql/01_horarios.sql` | Horarios, excepciones, festivos, bloqueos | Clientes nuevos (los actuales ya lo tienen) |
| `sql/02_reparar_claves.sql` | Pone las claves primarias que faltaban en `breaks`, `user_settings`, `fichajes_resumen_diario`, `sync_log`, `geolocation_settings` | **Una vez en cada cliente actual** (con copia de seguridad antes). En nuevos no hace nada |
| `sql/03_historial_e_informes.sql` | Historial de cambios (triggers) e informes firmados | Todos |

Cliente nuevo: ejecutar en orden `00 → 01 → 02 → 03` y crear el primer admin (al final de `00_estructura.sql`).
`sql/.htaccess` impide abrir los scripts desde el navegador.

Ejecución: phpMyAdmin → BD del cliente → pestaña **SQL** → pegar y ejecutar.
Si phpMyAdmin se queja de `DELIMITER`, pon `$$` en la casilla *Delimitador* y quita las líneas `DELIMITER`.

Alternativas desde el propio panel de admin:
- Las tablas de informes se crean solas la primera vez.
- El historial se activa en **Usuarios → 🕓 Historial de cambios → Activar ahora**.

---

## 5. Tareas programadas (Plesk → Tareas programadas)

| Tarea | Frecuencia | Comando |
|---|---|---|
| Salida automática | cada minuto | `php /var/www/vhosts/DOMINIO/SUBDOMINIO/httpdocs/cron/cron-auto-clockout.php` |
| Finalizar ausencias | la que ya tengas configurada | `php /var/www/vhosts/DOMINIO/SUBDOMINIO/httpdocs/cron/cron-finalizar-ausencias.php` |

Una tarea por cliente (cada una apunta a su `httpdocs`).

---

## 6. Alta de un cliente nuevo

1. Plesk: crear subdominio + base de datos + usuario de BD.
2. Conectar el subdominio al repositorio (apartado 2).
3. `app/config.php` con los datos de su BD.
4. Ejecutar los scripts de `sql/` en su BD en orden (00 → 01 → 02 → 03) y crear el primer admin.
5. Crear sus tareas programadas (apartado 5).
6. En el panel de admin del cliente:
   - **📝 Informes firmados → 🏢 Datos de la empresa** (razón social, CIF, centro);
   - **🗓️ Horarios y Bloqueo → Festivos**: elegir comunidad e importar;
   - **🕓 Historial de cambios**: comprobar que está activo.

---

## Seguridad: nginx en Plesk

Los bloqueos de carpetas están en `.htaccess`, que solo aplica Apache. Si en Plesk está activado que **nginx sirva directamente los archivos estáticos**, un `.sql`, `.json` o `.log` podría saltarse el bloqueo. Para cerrarlo también en nginx: subdominio → **Configuración de Apache y nginx** → **Directivas adicionales de nginx**:

```nginx
location ~ ^/(app|cron|sql|herramientas|datos|logs)/ { return 403; }
location ~ /\.(env|git|ht) { return 403; }
```

Comprobación rápida (todas deben devolver 403):

```bash
for u in app/config.php cron/cron-auto-clockout.php sql/00_estructura.sql datos/custom-theme.json logs/zkteco_events.log .env; do
  echo "$u $(curl -s -o /dev/null -w '%{http_code}' https://SUBDOMINIO/$u)"; done
```

---

## Requisitos del servidor

- PHP 8.x con `pdo_mysql`, `mbstring`, `iconv`, `curl`, `zip`
- MariaDB 10.3+ / MySQL 5.7+ con permisos `TRIGGER` y `CREATE ROUTINE` para el usuario de la BD
- HTTPS (Cloudflare delante)
