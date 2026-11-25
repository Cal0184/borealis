# Auditoría externa de Borealis 1.16.3

**Qué es este documento:** una revisión independiente del código de Borealis, hecha como la haría un revisor externo que no conoce la historia del proyecto. Solo se juzgó lo que está en el código (rama del PR #3, versión `1.16.3`), no los informes ni las intenciones anteriores.

**Para quién es:** para la empresa (sin conocimientos técnicos), en la parte 0, y para la próxima persona de informática que tome el proyecto, en las partes 1 a 5.

**Cómo se revisó:** se leyeron las piezas centrales (arranque, rutas, sesión, permisos, archivos, copias, motor de avisos, correo, push), se buscaron patrones de fallas típicas (inyección SQL, XSS, accesos a datos de otros, secretos expuestos) y se ejecutaron las pruebas unitarias (4 de 4 archivos pasan). **No se ejecutaron** las pruebas de integración ni las de navegador, porque necesitan MariaDB y Node instalados; el repositorio dice que pasan 1190/1190 y 336/336, pero eso no se verificó en esta auditoría. Tampoco se revisó el servidor real.

---

## 0. Resumen para la empresa

Borealis es una aplicación web propia para organizar el trabajo: proyectos, tareas, calendario, equipos, horas, avisos al celular y por correo, e informes. Funciona en un hosting web normal (PHP y una base de datos MySQL/MariaDB) y se sube por FTP.

**Estado general: bien hecho en lo básico de seguridad, frágil en la operación.** El código protege bien contra los ataques más comunes. Los riesgos reales están en cómo se instala y se mantiene:

| Semáforo | Tema | En una frase |
|---|---|---|
| 🔴 | Carpeta `storage/` | Guarda la llave maestra, las copias completas de la base de datos y los archivos subidos. Si el servidor no respeta el archivo `.htaccess`, cualquiera en internet podría descargarlos. Hay que comprobarlo el mismo día de la instalación (parte 5, paso 1). |
| 🔴 | Copias de seguridad | Solo existen dentro del mismo servidor, no se borran nunca, no incluyen los archivos subidos y no hay botón para restaurarlas. Si el hosting se pierde, se pierde todo. |
| 🟠 | Avisos automáticos | Sin una tarea programada (cron), Borealis se llama a sí mismo cada minuto para mandar avisos. Es ingenioso pero frágil en hosting compartido. Conviene usar un servicio externo gratuito que lo llame. |
| 🟠 | Dependencia de una sola fuente | Todo está escrito a mano, sin librerías estándar. Es fácil de subir por FTP, pero cualquier programador nuevo tendrá que aprender este código en particular. |
| 🟢 | Seguridad del código | Contraseñas bien guardadas, protección contra ataques comunes, permisos revisados en el servidor, archivos subidos aislados. |

**Lo que no se debe borrar nunca** al actualizar o cambiar de servidor: el archivo `config/config.php` y la carpeta `storage/` completa.

---

## 1. Cómo funciona

### 1.1 Tecnología

- **Lenguaje:** PHP 8.2 o superior, sin framework y sin dependencias externas (no hay `composer.json`). Todo el código es propio: enrutador, acceso a base de datos, sesiones, cliente de correo SMTP, notificaciones push (cifrado incluido), conexión con Google, copias de seguridad.
- **Base de datos:** MySQL o MariaDB. El esquema está en `database/migrations/` (17 archivos SQL, del `001_core.sql` al `017_v115.sql`).
- **Interfaz:** HTML generado en el servidor (`app/Views/`) más JavaScript propio sin librerías (`public/assets/js/`). Hay un *service worker* (`public/sw.js`) para avisos en el celular y uso como aplicación instalable.
- **Tamaño:** unas 30.000 líneas de PHP y JavaScript, 416 archivos en git. La carpeta `docs/capturas` pesa 25 MB, casi todo el paquete.

### 1.2 Estructura de carpetas

| Carpeta | Qué contiene | ¿Pública en internet? |
|---|---|---|
| `public/` | `index.php` (la única puerta de entrada), CSS, JS, iconos, `sw.js` | **Sí, debe serlo** |
| `app/` | Todo el código: `Core/` (motor), `Security/` (permisos y cifrado), `Services/` (lógica de negocio), `Controllers/` y `Api/` (respuestas a cada dirección), `Views/` (pantallas) | No |
| `config/` | `config.php` con la contraseña de la base de datos | **No, nunca** |
| `storage/` | Secretos, copias, archivos subidos, registros (ver 2.2) | **No, nunca** |
| `database/migrations/` | Estructura de la base de datos | No |
| `bin/` | Scripts de consola (copias, avisos, migraciones). Se niegan a correr desde la web | No |
| `tests/` | Pruebas automáticas | No |

### 1.3 Recorrido de una petición

1. Todo entra por `public/index.php`.
2. Si no existe `config/config.php`, se muestra el **instalador web** (`app/Controllers/InstallController.php`).
3. Las direcciones `/cron/run`, `/cron/tick` y `/cron/ping` (el motor de avisos) se atienden antes de abrir sesión.
4. Se abre la sesión (`app/Core/Session.php`), se revisa si hay que actualizar la base de datos (`UpdateGate.php`) y el enrutador (`app/Core/Router.php`) busca la ruta en `app/routes.php`.
5. El enrutador exige sesión iniciada salvo en rutas marcadas `public`, y exige el token CSRF en todo lo que no sea GET.
6. El controlador llama a un servicio de `app/Services/`, que comprueba permisos con `app/Security/Policy.php` (espacio de trabajo, proyecto, rol).
7. Después de responder, `Scheduler::maybeAfterResponse()` puede ejecutar la tarea de avisos si hace más de 5 minutos que no corre.

### 1.4 Multiempresa (espacios de trabajo)

Una instalación puede tener varios **espacios de trabajo**, cada uno con sus miembros, roles, proyectos y equipos. Las consultas filtran por `workspace_id`. Existe una **administración de la instalación** (`users.is_instance_admin = 1`), por encima de los espacios, que es la única que puede crear copias completas y actualizar la base de datos.

### 1.5 El motor de avisos (la parte más delicada)

Los recordatorios de vencimiento, los avisos push al celular, los correos y las alertas de monitoreo dependen de una **tarea programada** (`app/Services/Scheduler.php`). Hay cuatro formas de que corra, de la más fiable a la menos fiable:

1. **Cron del servidor** con `php bin/notify.php`. No está disponible en el hosting actual (solo FTP y base de datos).
2. **Un servicio externo** (por ejemplo cron-job.org) que visita cada minuto `https://dominio/cron/run?token=SECRETO`. El token se ve en Administración › Notificaciones.
3. **Motor interno** (`app/Services/Heartbeat.php`): el propio servidor se llama a sí mismo a `/cron/tick` cada 60 segundos, en cadena, para siempre. Se detiene solo si detecta un cron real.
4. **Visitas:** cada página visitada, y cada celular que recibe un aviso (`/cron/ping`), pueden encender la tarea si estaba parada.

### 1.6 Despliegue (cómo se instala y actualiza)

- **Instalar:** subir todo por FTP. Lo ideal es que la raíz web apunte a `public/`. Si el hosting no lo permite, se sube todo a la raíz y el `.htaccess` de la raíz redirige a `public/` y bloquea el resto. Luego se abre el sitio y el instalador pide los datos de la base de datos y crea `config/config.php`.
- **Actualizar:** subir los archivos nuevos por FTP **sin tocar** `config/config.php` ni `storage/`. Al entrar, la administración ve un aviso para actualizar la base de datos; antes de hacerlo, Borealis crea una copia SQL automática.
- **Cambiar de dominio:** cambiar `app.url` en `config/config.php`. Cada persona debe volver a activar los avisos en cada dispositivo, porque las suscripciones push quedan ligadas al dominio anterior.

---

## 2. Lo importante

### 2.1 Piezas críticas del código

| Archivo | Por qué importa |
|---|---|
| `public/index.php` | Única entrada. Cualquier cambio aquí afecta a todo. |
| `app/Core/Router.php` | Decide qué exige sesión y CSRF. Una ruta mal marcada como `public` o `nocsrf` abre un hueco. |
| `app/routes.php` | Lista de todas las direcciones (261 líneas). Es el mapa del sistema. |
| `app/Security/Policy.php` | Todos los permisos. Si falla, una persona podría ver datos de otro espacio. |
| `app/Security/Secrets.php` | Cifrado de secretos con la llave `storage/app.key`. |
| `app/Core/Auth.php`, `Session.php`, `Csrf.php`, `app/Services/Users.php` | Inicio de sesión, límite de intentos, sesiones. |
| `app/Services/Scheduler.php`, `Heartbeat.php`, `Core/Background.php` | Motor de avisos. |
| `app/Services/Backup.php` | Copias SQL. |
| `app/Controllers/UpdateGate.php`, `Core/Migrator.php` | Actualizaciones de la base de datos desde el navegador. |

### 2.2 Datos y secretos: qué hay en `config/` y `storage/`

| Ruta | Contenido | Si se pierde | Si se filtra |
|---|---|---|---|
| `config/config.php` | Usuario y contraseña de la base de datos, URL, zona horaria, opcionalmente Google, correo y soporte | El sitio vuelve a mostrar el instalador (ver falla A1) | Acceso directo a la base de datos |
| `storage/app.key` | **Llave maestra** que cifra todos los demás secretos | Hay que reconectar Google, volver a escribir la contraseña de correo y los webhooks; los avisos push dejan de funcionar hasta reactivarlos | Se pueden descifrar todos los secretos guardados |
| `storage/vapid.json` | Llaves de los avisos push (privada cifrada) | Todos deben reactivar avisos en cada dispositivo | Con la llave maestra, alguien podría enviar avisos falsos a los dispositivos suscritos |
| `storage/cron.json` | Token de `/cron/run` (cifrado) | Se crea otro; hay que actualizar el servicio externo | Cualquiera podría disparar la tarea de avisos |
| `storage/mail.json` | Servidor y cuenta de correo, contraseña cifrada | Hay que volver a configurar el correo | Con la llave maestra, acceso a la cuenta de correo |
| `storage/backups/*.sql` | **Copia completa** de la base de datos: todos los espacios, correos, hashes de contraseñas, tareas, comentarios | Se pierde el respaldo | Filtración total de los datos de la empresa |
| `storage/uploads/` | Archivos subidos y fotos de perfil, con nombres aleatorios | Se pierden los archivos (las copias SQL **no** los incluyen) | Documentos internos expuestos |
| `storage/logs/error.log` | Errores del sistema | Nada grave | Puede revelar rutas y detalles internos |
| `storage/heartbeat.json`, `*.lock`, `mail-status.json` | Estado del motor y del correo | Nada; se recrean | Poco |

**Regla de oro:** `config/config.php` y `storage/` deben copiarse juntos en cada traslado de servidor y nunca deben poder descargarse desde internet. La llave `app.key` y las copias viven en la misma carpeta: quien obtiene una obtiene la otra.

### 2.3 Lo que está bien hecho (para no romperlo)

- Contraseñas con Argon2id (`Auth::hash`), comparación en tiempo constante y nuevo cifrado automático al iniciar sesión.
- Límite de intentos de inicio de sesión por correo y por IP (`Users::throttle`).
- Sesión con cookie `HttpOnly`, `SameSite=Lax`, `Secure` en HTTPS, modo estricto e invalidación al cambiar la contraseña.
- Token CSRF obligatorio en todo lo que no sea GET.
- Política de seguridad de contenido estricta (`script-src 'self'`), `X-Frame-Options: DENY` y `nosniff` (`app/Core/Response.php`). Esto frena casi cualquier XSS aunque se escape mal algún texto.
- Consultas SQL con parámetros; los órdenes de listados usan listas cerradas (`Tasks::SORTS`, `match` en `Projects`).
- Permisos comprobados en el servidor en cada servicio revisado (tareas, archivos, horas, comentarios): siempre se filtra por espacio de trabajo y se vuelve a cargar el proyecto con sus permisos.
- Archivos subidos: lista de extensiones, verificación del tipo real, nombre aleatorio, fuera de `public/`, descarga con CSP de *sandbox*.
- Correo SMTP solo con cifrado (no envía la contraseña sin TLS salvo a `localhost`) y con verificación de certificado.
- Los scripts de `bin/` se niegan a correr desde la web.

---

## 3. Errores y falencias

Ordenadas de mayor a menor gravedad. Cada una dice dónde está y qué pasaría.

### 🔴 C1. `storage/` queda expuesto si el servidor ignora `.htaccess`

- **Dónde:** `.htaccess` (raíz), `storage/.htaccess`, `README.md` líneas 5, 16 y 28.
- **Qué pasa:** la única barrera que impide descargar `storage/` cuando todo se sube a la raíz es el archivo `.htaccess`. Apache y LiteSpeed lo respetan; **Nginx lo ignora por completo**. El README dice que funciona con «Nginx o el servidor que tengas», y el hosting actual es desconocido. Si el servidor es Nginx (o un Apache con `AllowOverride None`) y la raíz web no es `public/`, cualquiera puede pedir `https://dominio/storage/app.key`. (`config/config.php` en sí no se lee, porque el servidor lo ejecuta como PHP y no muestra nada, pero sí todo lo que no es `.php`: `storage/`, `database/`, `docs/`.)
- **Agravante:** los nombres de las copias son predecibles (`borealis-AAAAMMDD-HHMMSS-pre-update.sql`, `app/Services/Backup.php:22`), así que se pueden adivinar probando fechas. También `storage/logs/error.log`.
- **Agravante 2:** la aplicación nunca comprueba si esto ocurre. No hay ninguna alerta en Administración.
- **Solución rápida:** ver la parte 5, paso 1. **Solución de fondo:** que la administración pruebe sola, desde el servidor, si `URL/storage/.htaccess` responde 200, y muestre una alerta roja; además, nombres de copia con una parte aleatoria.

### 🔴 C2. Copias de seguridad insuficientes

- **Dónde:** `app/Services/Backup.php`.
- **Qué pasa:**
  - Las copias se guardan **en el mismo servidor** que la base de datos. Si el hosting falla, se borra o se pierde la cuenta, no hay respaldo.
  - **No incluyen** `storage/uploads` (los archivos subidos), ni `config/config.php`, ni `storage/app.key`. Sin la llave, una copia restaurada no puede leer los secretos cifrados.
  - **No se borran nunca.** Se crean solas antes de cada actualización, antes de importar, antes de borrar fantasmas y **antes de cada eliminación de usuario** (`MemberDeletion.php:68`, `Ghosts.php:105`). En un hosting con cuota, el disco se llena y entonces fallan las subidas y las copias.
  - **No hay restauración** en la aplicación: restaurar exige importar el `.sql` a mano en el administrador de base de datos (por ejemplo phpMyAdmin).
  - `fopen()` no se comprueba (`Backup.php:23`): si la carpeta no tiene permiso de escritura, el error es confuso.
  - Con una base de datos grande, la copia hecha desde el navegador puede cortarse por tiempo máximo de PHP y dejar un archivo incompleto que parece válido.
- **Solución rápida:** una vez por semana, descargar la última copia desde Administración › Datos y guardar una copia de `storage/uploads`, `storage/app.key` y `config/config.php` fuera del servidor. Además, exportar la base de datos desde el panel del hosting.

### 🟠 A1. Instalador abierto si falta `config/config.php`

- **Dónde:** `public/index.php:14`, `app/Controllers/InstallController.php`.
- **Qué pasa:** mientras no exista `config/config.php`, cualquiera que visite el sitio ve el instalador. El instalador se niega a pisar una base de datos que ya tiene usuarios, lo que está bien. Pero quien llegue primero puede apuntarlo a **su propia** base de datos, crear su administrador y quedarse con el sitio. El riesgo es real durante una transferencia: si se suben los archivos y `config.php` se sube después, o se olvida, el sitio queda abierto.
- **Solución rápida:** subir `config/config.php` **antes** que el resto, o subir todo con el sitio cerrado. **Solución de fondo:** exigir un archivo `storage/install.allow` o un código para instalar.

### 🟠 A2. El motor interno de avisos es frágil en hosting compartido

- **Dónde:** `app/Services/Heartbeat.php`, `app/Core/Background.php`.
- **Qué pasa:** sin cron, el servidor se llama a sí mismo cada 60 segundos y deja **un proceso de PHP ocupado durmiendo** entre llamadas, de forma permanente. En un hosting compartido esto:
  - ocupa uno de los pocos procesos disponibles, lo que puede volver lento el sitio con varios usuarios;
  - puede ser cortado por el hosting (límites de tiempo o de procesos) o considerado abuso;
  - depende de que el servidor pueda llegar a su propio dominio, algo que muchos hostings bloquean;
  - si se corta, solo vuelve a arrancar con la siguiente visita o aviso, así que puede haber horas sin recordatorios, justo de noche.
- Es un diseño ingenioso, pero es un parche alrededor de no tener cron. La pieza `/cron/ping` es pública y sin token; está limitada (como máximo una ejecución cada dos minutos), así que el riesgo de abuso es bajo.
- **Solución rápida:** usar un servicio externo gratuito (cron-job.org, UptimeRobot) que visite `/cron/run?token=…` cada 1 a 5 minutos. Con eso el motor interno se apaga solo. Si el hosting da problemas de rendimiento, poner `'cron' => ['heartbeat' => false]` en `config.php`.

### 🟠 A3. Mantenibilidad: todo hecho a mano

- **Qué pasa:** no hay framework ni librerías. El enrutador, la capa de base de datos, el cliente SMTP, el cifrado de avisos push (RFC 8291), OAuth de Google y las copias SQL están escritos desde cero.
  - **A favor:** se sube por FTP sin instalar nada, y no hay dependencias que actualizar.
  - **En contra:** la seguridad depende de que ese código propio sea correcto, y nadie más lo revisa ni lo corrige. Un programador nuevo no puede apoyarse en documentación pública de un framework conocido.
- **Archivos grandes:** `Services/Monitoring.php` (936 líneas), `Services/Tasks.php` (767), `public/assets/js/ui.js` (633). Cuestan de entender y de cambiar sin romper algo.
- **Comentarios atados a versiones:** cerca de 170 comentarios dicen «1.14:», «1.15:», «1.16:», etc. Cuentan la historia del cambio en vez de explicar qué hace el código. Para alguien nuevo, eso es ruido.
- **Documentación fragmentada y desactualizada:** el `README.md` se titula «Borealis 1.13» aunque la versión es 1.16.3. La configuración está repartida en `CONFIGURACION-1.9.md`, `-1.14`, `-1.15` y `-1.16`, más 6 `INFORME-*.md` y un `CHANGELOG.md` de 73 KB. No hay un único documento que diga «así se configura hoy».
- **Solo en español y para una sola zona:** textos fijos en el código, sin sistema de traducción.

### 🟠 A4. Pruebas existentes, pero sin automatización

- **Dónde:** `tests/` (unitarias, de integración y de navegador).
- **Qué pasa:** hay muchas pruebas, lo que es muy bueno. Pero no hay integración continua (no existe `.github/workflows`), así que nadie las corre al cambiar algo. Además, las de integración necesitan MariaDB y una base vacía, y las de navegador necesitan Node y Playwright, montados a mano. Las pruebas usan servicios falsos (`tests/fake-google`, `tests/fake-services`): Google, correo real, push real y el hosting real **no** quedan probados.
- **Solución:** agregar un flujo de GitHub Actions que levante MariaDB y corra `tests/integration/run.php` en cada cambio.

### 🟡 M1. Bloqueo de inicio de sesión compartido detrás de un proxy

- **Dónde:** `app/Core/Request.php:89` (usa solo `REMOTE_ADDR`), `Users::throttle`.
- **Qué pasa:** si el nuevo hosting pone a Borealis detrás de un proxy o CDN (algo común en universidades), todas las visitas llegan con la **misma IP**. Entonces, unos cuantos intentos fallidos de cualquiera bloquean el inicio de sesión de **todos** durante 15 minutos. Un atacante podría hacerlo a propósito.
- **Solución:** opción en `config.php` para confiar en `X-Forwarded-For` solo cuando venga de la IP del proxy.

### 🟡 M2. El servidor hace peticiones a direcciones que elige el usuario (SSRF a ciegas)

- **Dónde:** `app/Services/Push.php:75-82`.
- **Qué pasa:** al activar avisos, el navegador envía una dirección (endpoint) y el servidor le hará POST. Solo se exige que empiece por `https://`; no se limita a los servicios push conocidos (Google, Mozilla, Apple, Microsoft). Una persona con cuenta podría registrar una dirección interna de la red del hosting, y el mensaje de error se le muestra en su perfil (`profile.php:143`). El impacto es bajo (solo POST cifrado y solo usuarios con cuenta), pero es fácil de cerrar.
- **Solución:** aceptar solo dominios de servicios push conocidos y rechazar IPs privadas.

### 🟡 M3. Recuperación de cuenta y segundo factor

- No hay «olvidé mi contraseña» por correo: solo la administración puede restablecerla (`/api/members/{id}/password`). Si **la única** cuenta de administración de la instalación pierde la contraseña, hay que editar la base de datos a mano.
- No hay segundo factor (2FA) para la administración.
- **Solución rápida:** tener **al menos dos** cuentas con `is_instance_admin = 1`.

### 🟡 M4. Zona horaria por defecto equivocada para Chile

- **Dónde:** `app/Controllers/InstallController.php:34` y `config/config.example.php` (`America/Mexico_City`).
- **Qué pasa:** en una instalación nueva, si nadie cambia el campo, las horas y los recordatorios quedan con hora de México. Hay que revisar que `config.php` diga `America/Santiago`.

### ⚪ Menores

- `rich_text()` (`app/helpers.php:229`) convierte `**`, `_` y `` ` `` antes de detectar enlaces, así que una URL que contiene `**` se corta. Es raro, pero rompe algún enlace.
- La sesión dura hasta 4 horas sin actividad (`session.idle_minutes = 240`), lo que es razonable pero largo para computadores compartidos.
- El paquete pesa unos 24 MB por las capturas de `docs/capturas`, que no hacen falta en el servidor.
- Al cambiar de dominio, cada persona debe reactivar los avisos en cada dispositivo. La aplicación no lo avisa sola.

**No se encontró:** inyección SQL, XSS explotable (y la CSP lo frenaría), acceso a datos de otro espacio en los servicios revisados, secretos dentro del código, ni contraseñas en texto plano.

---

## 4. Mejoras rápidas, priorizadas

| # | Mejora | Esfuerzo | Quién |
|---|---|---|---|
| 1 | Comprobar que `storage/` y las demás carpetas internas no se descargan (parte 5, paso 1) | 5 minutos | Cualquiera |
| 2 | Copia **fuera del servidor**: base de datos más `storage/` más `config/config.php`, una vez por semana | 15 min/semana | Cualquiera |
| 3 | Servicio externo que llame a `/cron/run?token=…` cada 1 a 5 minutos | 10 minutos | Cualquiera, con el token de Administración › Notificaciones |
| 4 | Dos cuentas de administración de la instalación | 5 minutos | Administración |
| 5 | Revisar `timezone` = `America/Santiago` y `app.url` con el dominio nuevo en `config.php` | 5 minutos | Quien tenga el FTP |
| 6 | Alerta en Administración si `storage/` es accesible desde internet; nombres de copia aleatorios | Medio día | Programador |
| 7 | Borrado automático de copias viejas (por ejemplo, conservar 10) y copia que incluya `uploads` en un ZIP | 1 día | Programador |
| 8 | Bloquear el instalador sin un código | 2 horas | Programador |
| 9 | Limitar endpoints push a servicios conocidos; IP real detrás de proxy | 2 horas | Programador |
| 10 | GitHub Actions con MariaDB que corra las pruebas | Medio día | Programador |
| 11 | Un solo `docs/CONFIGURACION.md` vigente, y un README al día | 1 día | Programador |
| 12 | Quitar números de versión de los comentarios y dividir `Monitoring.php` y `Tasks.php` | Continuo | Programador |

---

## 5. Lista de control para el día de la instalación

1. **Prueba de exposición.** Abrir en el navegador, con el dominio real:
   - `https://DOMINIO/storage/.htaccess`
   - `https://DOMINIO/database/migrations/001_core.sql`
   - `https://DOMINIO/README.md`

   Las tres deben dar **403 o 404** (si Borealis está en una subcarpeta, agregarla después del dominio). Si alguna muestra contenido o descarga un archivo, **detener todo**: el servidor no respeta `.htaccess` y hay que pedir al hosting que la raíz web sea la carpeta `public/`.
2. Subir `config/config.php` **antes** que el resto de los archivos, para que el instalador nunca quede abierto.
3. Copiar la carpeta `storage/` completa desde el servidor anterior. Sin `app.key`, los secretos guardados (correo, Google, webhooks) dejan de funcionar.
4. En `config/config.php`: `app.url` con el dominio nuevo, `timezone` = `America/Santiago`, `debug` = `false`.
5. Entrar como administración, aplicar la actualización de la base de datos si la pide, y en Administración › Datos pulsar «Guardar y probar» el correo.
6. Configurar el servicio externo con la dirección `/cron/run?token=…`.
7. Pedir a cada persona que vuelva a activar los avisos en su celular y su computador.
8. Descargar una copia y guardarla fuera del servidor.

---

*Auditoría hecha el 25/11/2026 sobre la versión 1.16.3. Pruebas unitarias ejecutadas: 4 de 4 archivos sin fallas. Pruebas de integración y de navegador: no ejecutadas en esta auditoría. Servidor real: no revisado.*

---

## 6. Estado tras la versión 1.16.4

La versión 1.16.4 corrigió lo que se podía resolver solo con código (sin acceso al servidor). Este cuadro dice qué quedó resuelto y qué sigue dependiendo de una persona.

| Hallazgo | Estado en 1.16.4 | Qué falta |
|---|---|---|
| C1 · `storage/` descargable | **Mitigado:** comprobación automática con alerta roja (Administración › Datos › Seguridad de la instalación), nombres de copia aleatorios, llave en `config.php` en instalaciones nuevas, README corregido para Nginx | Si la alerta aparece, el hosting debe apuntar la raíz web a `public/`. Las instalaciones antiguas siguen con `storage/app.key` |
| C2 · Copias insuficientes | **Mitigado:** copia completa en un ZIP (base + `config.php` + `storage/`) para guardar fuera del servidor, rotación de las automáticas (20), copias cortadas que nunca parecen completas | Alguien debe descargar el ZIP cada semana y guardarlo fuera del servidor. No hay restauración automática (se usa phpMyAdmin › Importar) |
| A1 · Instalador abierto | **Resuelto** para traslados: si `storage/` tiene datos y falta `config.php`, el instalador no se abre | En una carpeta totalmente vacía sigue abierto hasta instalar (normal) |
| A2 · Motor interno frágil | **Sin cambios en el código** | Configurar un servicio externo (cron-job.org) con la dirección de Administración › Notificaciones |
| A3 · Mantenibilidad | **Parcial:** README al día, cambios en `CHANGELOG.md` | Unificar la documentación de configuración, dividir los archivos grandes y quitar los números de versión de los comentarios |
| A4 · Pruebas sin automatizar | **Resuelto:** `.github/workflows/pruebas.yml` corre en GitHub las pruebas unitarias y de integración con cada cambio | Las pruebas de navegador y de PHP-FPM/Apache siguen siendo manuales |
| M1 · Bloqueo tras un proxy | **Resuelto:** opción `app.trusted_proxies` | Ponerla en `config.php` solo si el hosting usa proxy o CDN |
| M2 · SSRF en avisos push | **Resuelto:** solo servicios push conocidos | — |
| M3 · Recuperar cuenta | **Resuelto:** «¿Olvidaste tu contraseña?» por correo y aviso si hay una sola administración | Configurar el correo y dar administración de la instalación a una segunda persona. No hay segundo factor (2FA) |
| M4 · Zona horaria | **Resuelto:** `America/Santiago` por defecto | Revisar `config.php` de la instalación actual |
| Menores · enlaces con `**` | **Resuelto** | — |
