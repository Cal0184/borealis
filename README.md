# Borealis 1.13 — Forest Edition

Borealis organiza equipos, proyectos y tareas. Empieza simple (un proyecto solo necesita nombre y una tarea solo título) y crece por módulos cuando el equipo lo necesita: backlog, sprints, calendario, estimaciones, épicas, documentación, reportes y cronograma.

Aplicación web en PHP 8.2+ y MySQL/MariaDB, sin dependencias externas, pensada para hospedaje convencional (Apache con `mod_rewrite`, Nginx o el servidor que tengas).

---

## 1. Requisitos

| Componente | Versión mínima |
|---|---|
| PHP | 8.2 (probado con 8.3.6) |
| Extensiones PHP | `pdo_mysql`, `mbstring`, `fileinfo`, `iconv`, `json` (estándar) |
| Base de datos | MySQL 8.0+ o MariaDB 10.5+ (probado con MariaDB 10.11.14) |
| Servidor web | Apache 2.4 con `mod_rewrite` (recomendado), Nginx o cualquiera con PHP-FPM |
| Navegadores | Versiones actuales de Chrome, Edge, Firefox y Safari |

Se recomienda HTTPS: la cookie de sesión se marca como `Secure` automáticamente cuando la conexión es HTTPS.

## 2. Instalación

1. Descomprime el ZIP en tu servidor.
2. Crea una base de datos vacía (`utf8mb4`) y un usuario con permisos sobre ella. El instalador también puede crear la base si el usuario tiene permiso.
3. Asegúrate de que PHP pueda escribir en `config/` y `storage/` (y sus subcarpetas).
4. Apunta el dominio:
   - **Recomendado:** la raíz web (DocumentRoot) en la carpeta `public/`.
   - **Hospedaje compartido sin control del DocumentRoot:** sube todo a la raíz; el `.htaccess` incluido redirige a `public/` y bloquea `app/`, `config/`, `storage/`, `database/`, `bin/` y `tests/`.
5. Abre la URL en el navegador. El instalador:
   - verifica requisitos,
   - prueba la conexión a la base,
   - **se niega a instalar sobre una base que ya tenga usuarios de Borealis** (no sobrescribe datos),
   - aplica las migraciones,
   - crea tu cuenta de administración y tu primer espacio de trabajo,
   - escribe `config/config.php` con permisos 0640.
6. Al terminar quedas dentro de la aplicación.

**Sin `mod_rewrite`:** desmarca «URLs amigables» en el instalador; las direcciones serán del tipo `index.php?r=/projects`.

**Nginx (ejemplo):**

```nginx
root /var/www/borealis/public;
location / { try_files $uri /index.php?$query_string; }
location ~ \.php$ { include fastcgi_params; fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name; fastcgi_pass unix:/run/php/php8.3-fpm.sock; }
```

**Prueba local rápida:**

```bash
php -S 127.0.0.1:8080 -t public public/router.php
```

## 3. Configuración

Todo vive en `config/config.php` (lo genera el instalador; `config/config.example.php` documenta cada clave).

| Clave | Para qué sirve |
|---|---|
| `app.url` | URL pública (se usa en los mensajes de cuenta creada, las invitaciones y «Copiar enlace»). Desde 1.7 Borealis toma de aquí solo el protocolo y el dominio y añade `app.base_path` una vez, así que da igual si la escribes con la carpeta (`https://ejemplo.org/borealis`) o sin ella (`https://ejemplo.org`). |
| `app.base_path` | Subcarpeta si Borealis no está en la raíz del dominio (p. ej. `/borealis`). Es la única que decide la carpeta de todos los enlaces. |
| `app.pretty_urls` | `true` con reescritura de URLs; `false` usa `index.php?r=`. |
| `app.timezone` | Zona horaria para mostrar fechas (los datos se guardan en UTC). |
| `app.allow_registration` | `false` por defecto: solo se entra por invitación o alta de la administración. |
| `app.allow_workspace_creation` | `false` por defecto (1.4.1): solo la administración de la instalación crea espacios de trabajo. Con `true`, cualquier persona con sesión puede crear el suyo. Si la clave no existe se considera `false`. |
| `app.debug` | `false` en producción. Con `true` se muestran detalles de errores. |
| `db.*` | Conexión a MySQL/MariaDB. |
| `uploads.max_bytes` | Tamaño máximo por archivo (10 MB por defecto; también limita `upload_max_filesize` de PHP). |
| `uploads.allowed_extensions` | Lista blanca de extensiones permitidas. |
| `session.idle_minutes` | Cierre de sesión por inactividad (240 por defecto). |
| `google.client_id`, `google.client_secret` | Opcional (1.6). Cliente OAuth de Google Cloud para Google Calendar y Google Tasks. Sin estas claves la conexión con Google no aparece y todo lo demás funciona igual. Ver «Google Calendar y Google Tasks». |
| `google.redirect_uri` | Opcional. Solo si la URL de retorno no es `app.url` + `/google/callback`. |
| `app.key` | Opcional. Clave de 32 bytes en base64 para cifrar los tokens de Google. Si no existe, Borealis crea `storage/app.key` (permisos 0600) la primera vez. Si se pierde, cada persona solo tiene que volver a conectar Google. |

El ZIP **no incluye** contraseñas, claves ni credenciales: el instalador las pide y genera un nombre de sesión aleatorio por instalación.

## 4. Estructura

```
app/
  Core/        Router, Request/Response, base de datos (PDO), sesión, CSRF, validación, vistas, errores, migraciones
  Security/    Catálogo de permisos y Policy (autorización en servidor)
  Services/    Lógica de negocio: espacios, personas, equipos, proyectos, tareas, sprints, archivos, reportes…
  Api/         Endpoints JSON internos (todo cambio pasa por aquí)
  Controllers/ Páginas renderizadas en el servidor
  Views/       Plantillas (layouts, parciales y páginas)
bin/           migrate.php, backup.php e import-v022.php para consola/cron
config/        config.example.php (y config.php tras instalar)
database/migrations/  Migraciones SQL versionadas
public/        Única carpeta expuesta: index.php, CSS, JS, iconos
storage/       uploads/ (fuera de la web), backups/, logs/
tests/         integration/ (HTTP + base real), e2e/ (navegador) y fixtures/ (datos de ejemplo de la v0.2)
docs/          ESTADO.md (funciones y decisiones), PRUEBAS.md (resultados) y capturas/
```

## 5. Funciones

Resumen; la tabla completa con su estado está en [`docs/ESTADO.md`](docs/ESTADO.md).

- **Inicio:** saludo, tus tareas de hoy y atrasadas, proyectos recientes con su progreso, actividad reciente.
- **Mi trabajo:** pestañas Abiertas, Por hacer, En curso, Atrasadas, Próximas y Finalizadas, con edición directa.
- **Equipos:** crear, renombrar, archivar/restaurar, integrantes con rol (coordinación/integrante), responsabilidad y horas semanales, proyectos del equipo, perfil de cada persona con su carga. Para añadir a alguien puedes elegir una cuenta existente, crearle la cuenta ahí mismo o generar un enlace de invitación (Borealis no envía correos: te da el mensaje para copiar).
- **Proyectos:** creación con solo nombre, siglas editables (p. ej. `WEB-12`; los enlaces con las siglas anteriores siguen funcionando), color y fondo del encabezado, estados, equipo, responsable, fechas, archivado/restauración, integrantes y roles, columnas/estados con límite WIP, etiquetas, módulos.
- **Tareas:** título, descripción con formato ligero, estado, prioridad, responsable, fechas, etiquetas, épica, sprint, estimación en puntos y en horas, registro de horas por persona, comentario y horas de cierre al finalizar, bloqueo con motivo, subtareas, relaciones (bloquea, relacionada, duplicada), comentarios con @menciones, archivos, historial, duplicar, eliminar (recuperable).
- **Tablero:** arrastrar y soltar con ratón o lápiz; en táctil y con teclado (Alt+flechas) se mueve con el menú de la tarjeta. Alta rápida por columna.
- **Lista:** filtros (texto, estado, responsable, prioridad, etiqueta, épica, sprint, vencimiento), orden, selección múltiple y edición masiva.
- **Backlog y sprints:** ordenar por prioridad arrastrando, planificar sprints, iniciar (uno activo), finalizar con traslado de pendientes y notas de resultado. Control del sprint: capacidad en horas (horas semanales × semanas, o fijada a mano), planificado vs. registrado, gráfico de avance y alertas de sobrecarga.
- **Calendario general (1.6):** vistas día, semana y mes, botón «Hoy», varios calendarios con color que se superponen o se ocultan (se recuerda por persona), eventos y reuniones (participantes, lugar, enlace de videollamada, proyecto o equipo, recordatorio y repetición diaria, semanal, quincenal o mensual), vencimientos de tareas, tareas personales e inicio/fin de sprints, cada uno con su marca. Clic en un día o una hora libre para crear; arrastrar un evento o una tarea a otro día cambia su fecha. Permisos por calendario: privado, compartido con lectura o edición, y personas añadidas. Al eliminar un calendario se elige si sus eventos pasan a otro o se eliminan. Funciona sin Google. El calendario de cada proyecto sigue en su pestaña.
- **Tareas (1.6):** «Mis tareas» (personales y privadas, en listas, con subtareas, orden arrastrando, prioridad, fecha límite y notas), «Asignadas a mí», «Equipo» y «Completadas». Las tareas de equipo son las de siempre, con su proyecto y sprint.
- **Google Calendar y Google Tasks (1.6, opcional):** cada persona conecta su cuenta desde Perfil, elige qué calendarios de Google ver y sincroniza Google Tasks con «Mis tareas». Ver la sección siguiente.
- **Tutorial (1.6, 1.7.1):** recorre Inicio, Proyectos, Tareas, Sprints, Calendario y Configuración señalando los controles reales, sigue tras cambiar de página o recargar y se repite desde Perfil › Ayuda o el menú de la cuenta.
- **Cronograma, épicas, documentación y archivos** (carpetas, subida múltiple, arrastrar archivos).
- **Fechas (1.8.1):** todos los campos de fecha muestran y aceptan dd/mm/aaaa, sea cual sea el idioma del navegador, con un botón para elegir en el calendario. Las exportaciones CSV usan dd/mm/aaaa.
- **Notificaciones (1.8):** campana con contador y centro filtrable por tipo. Cada persona elige en Perfil › Notificaciones qué categorías recibe (asignaciones, por vencer, vencidas, cambios de fecha, reuniones, sprints, cambios de proyecto, menciones, capacidad), con cuánta anticipación y cuáles llegan al móvil. Un aviso por evento, nunca de tareas completadas.
- **Calendario personal y de equipo (1.8):** «Ambos / Personal / Equipo». El personal es privado; uno de equipo se comparte con un equipo concreto (solo sus integrantes lo ven). Capa de hitos de proyectos.
- **Sprints (1.8):** página con todos tus sprints, creación en dos etapas (datos básicos y después la planificación), fechas en el calendario y avisos. Llegar a la fecha de fin nunca cierra un sprint.
- **«Todo a mano» (1.8):** enlaces directos por proyecto (Drive, Discord, GitHub, cualquier página) e integraciones reales con Discord (webhook) y GitHub (API, solo lectura). Ver la sección siguiente.
- **Análisis (1.13):** indicadores históricos con su fórmula y denominador (cumplimiento de plazos, desviación de estimaciones, reprogramaciones, bloqueos, compromisos de sprint, trabajo pendiente), tendencias solo con datos suficientes, patrones descritos sin atribuir causas ni personas, sugerencias de estimación a partir de tareas parecidas (nunca cambian nada solas), capacidad de las próximas semanas con alternativas (nunca «trabajar más horas»), riesgo por reglas explicadas (sin probabilidades), recomendaciones, simulador de escenarios por sprint (aplicar con vista previa, confirmación y auditoría, y revertir) e informe ejecutivo imprimible y en CSV. Fórmulas y reglas en [`docs/ANALISIS-1.13.md`](docs/ANALISIS-1.13.md).
- **Reportes:** por estado, carga por persona, horas por persona y por tarea, atrasadas, bloqueadas, creadas vs. completadas (8 semanas). Botones de exportación: informe imprimible (o PDF con «Guardar como PDF»), CSV de tareas, de horas y de proyectos.
- **Administración:** datos del espacio, personas (alta, rol, horas semanales, suspender, restablecer contraseña, invitaciones por enlace), cuentas fantasma para pruebas (solo la administración de la instalación: «Entrar como», «Volver a mi cuenta» y borrado de todas con copia previa), roles personalizados con matriz de permisos, prioridades, auditoría, exportación JSON, copias de seguridad y actualización de base.
- **Capacidad agotada — Trabajo pendiente (1.5):** cuando las horas **registradas** de una persona en la semana (todos sus proyectos, sin contarlas dos veces) llegan a sus horas semanales y aún tiene tareas comprometidas para esa semana sin terminar (vencen esa semana o están en un sprint activo que termina esa semana), aparece una alerta en Inicio, Equipos, el resumen y los sprints del proyecto afectado, el perfil de la persona y **Equipos › Alertas de capacidad** (`/capacity`). La ven la administración del espacio y la responsable de cada proyecto afectado, que reciben un solo aviso. Se resuelve desde la propia alerta: reprogramar (fecha u otro sprint), reasignar a alguien con horas libres, revisar la estimación, autorizar horas adicionales para esa semana (queda quién y cuántas) o mantener pendiente con una justificación. Verla no la cierra; queda un historial con cada decisión. Las tareas sin estimación no reciben horas inventadas y las vencidas de semanas anteriores se muestran aparte. Nunca completa tareas ni añade horas por su cuenta, y no es una evaluación de desempeño.
- **Temas personales (1.5):** Medieval, Agua, Fuego, Tierra, Cielo, Bosques y el original. Cada tema cambia el fondo, la barra lateral (fondo, textos, iconos, elemento activo, al pasar el ratón y bordes), una variante del símbolo de Borealis (sin sustituir el logotipo) y la ilustración inferior. Se elige en Perfil › Apariencia, se aplica sin recargar, se guarda por persona y respeta «reducir movimiento». Contraste comprobado (texto ≥ 4,5:1).
- **Quién ve cada proyecto (1.7):** solo sus integrantes, las personas de los equipos que participan en él y quien tenga el permiso «Ver y administrar todos los proyectos del espacio» (por defecto, Propietario y Administración). Se aplica en Inicio, Proyectos, búsqueda, calendario, Tareas › Equipo, reportes, enlaces directos y la API (un proyecto ajeno responde «no existe»). Al añadir o retirar a alguien, su lista cambia al momento. La administración puede filtrar «Solo donde participo». La antigua opción «Todo el espacio puede verlo» ya no da acceso.
- **Fotografía de perfil (1.7):** Perfil › Datos personales › Subir fotografía. Antes de guardar se encuadra en un círculo (arrastrar, flechas del teclado, zoom). Se puede cambiar o eliminar; sin fotografía se ven las iniciales en su color. El servidor la vuelve a codificar en WebP de 96 y 256 píxeles (unos pocos KB) y la guarda en `storage/uploads/avatars`; solo la ven personas con sesión del mismo espacio. Requiere la extensión GD de PHP con WebP.
- **Horas semanales disponibles (1.7):** se indican al crear la cuenta o la invitación y se cambian después en Administración › Personas. Cada sprint calcula su capacidad con ellas (15 h × un sprint de dos semanas = 30 h) salvo que se fijen otras horas para ese sprint, y no se puede asignar más de lo que queda. Cambiarlas no recalcula los sprints ya terminados: conservan las horas que tenían.
- **Gamificación profesional:** progreso tipo «expedición», celebración discreta al completar y resumen al terminar un proyecto. Sin rankings competitivos. Cada persona puede elegir animaciones completas, reducidas o desactivadas (también se respeta la preferencia del sistema).

### Google Calendar y Google Tasks (opcional)

Borealis usa las API oficiales de Google con OAuth 2.0 (flujo de código con PKCE). Para activarlo, la administración de la instalación hace esto **una vez** en Google Cloud. Borealis no trae claves de ejemplo: sin un cliente OAuth real la conexión no aparece.

1. Entra en <https://console.cloud.google.com/> con la cuenta que administrará la integración y crea un proyecto (p. ej. «Borealis»).
2. **APIs y servicios › Biblioteca:** habilita **Google Calendar API** y **Google Tasks API**.
3. **Google Auth Platform** (antes «Pantalla de consentimiento de OAuth»):
   - **Branding / Información de la app:** nombre «Borealis», correo de asistencia, dominio de tu instalación y enlaces a tu política de privacidad.
   - **Público (Audience):** «Interno» si todas las cuentas son de tu Google Workspace; si no, «Externo». En «Externo» y estado **Prueba (Testing)** añade como usuarios de prueba las cuentas que vayan a conectarse (máximo 100). Importante: en estado Prueba, Google caduca los permisos a los 7 días y cada persona tiene que volver a conectar; Borealis lo detecta y lo muestra. Para uso normal, pasa la app a **En producción**; como pide permisos sensibles (eventos y tareas), Google puede exigir su verificación.
   - **Acceso a los datos (Data access):** añade exactamente estos permisos:
     - `openid` y `.../auth/userinfo.email` (o `email`): saber qué cuenta se conectó.
     - `https://www.googleapis.com/auth/calendar.calendarlist.readonly`: ver la lista de calendarios.
     - `https://www.googleapis.com/auth/calendar.events`: ver y editar eventos (no da acceso a los ajustes ni a borrar calendarios).
     - `https://www.googleapis.com/auth/tasks`: ver y editar tareas.
4. **Clientes (Credenciales) › Crear cliente › Aplicación web.** En **URI de redireccionamiento autorizados** escribe exactamente `https://TU-DOMINIO/google/callback` (con `app.pretty_urls = false`: `https://TU-DOMINIO/index.php?r=/google/callback`; con subcarpeta, inclúyela). Debe ser HTTPS (Google solo admite `http` para `localhost`).
5. Copia el **ID de cliente** y el **secreto** en `config/config.php`:
   ```php
   'google' => [
       'client_id' => '1234567890-xxxx.apps.googleusercontent.com',
       'client_secret' => 'GOCSPX-...',
   ],
   ```
   Si PHP usa OPcache, el cambio se nota en unos segundos.
6. Comprueba que `storage/` admite escritura (Borealis crea `storage/app.key` para cifrar los tokens) y que el servidor puede salir por HTTPS a `oauth2.googleapis.com` y `www.googleapis.com` (extensión `curl` de PHP).
7. Cada persona: **Perfil › Google Calendar › Conectar con Google**, acepta los permisos y elige su calendario en «Enviar mi trabajo a Google Calendar» (1.9) y, si quiere, qué calendarios de Google ver dentro de Borealis.

**1.9 · Enviar el trabajo a Google Calendar:** una dirección (Borealis → Google), sin duplicados, solo eventos creados por Borealis. Detalle, pruebas reales y comportamiento ante cuota o caídas: `docs/CONFIGURACION-1.9.md`.

Cómo funciona:
- Los tokens se guardan cifrados en la base y nunca llegan al navegador. Si caducan se renuevan solos; si Google los revoca, el perfil y el calendario muestran el error y el botón «Volver a conectar».
- Sincronización: al abrir el calendario (si pasaron más de 10 minutos) o con «Sincronizar ahora». Es incremental (`syncToken`) y cada elemento se identifica por su id de Google, así que repetirla no duplica nada.
- Lo que creas o cambias en Borealis en un calendario de Google se escribe primero en Google. **Conflictos:** si alguien lo cambió en Google mientras tanto, gana Google, Borealis carga esa versión y te avisa. En tareas, si cambió en los dos lados desde la última sincronización, gana Google y se informa.
- Borealis **nunca borra nada en Google por su cuenta**: solo el botón «Eliminar» de un evento concreto (con confirmación). Borrar una tarea en Borealis no la borra en Google Tasks. Desconectar no toca nada en Google.
- Si Google no responde, el calendario de Borealis sigue funcionando y el error se ve.
- Las horas se guardan en UTC y se muestran en `app.timezone`; las repeticiones mantienen la hora local en los cambios de horario. Las repeticiones de Google llegan una a una (cada repetición con su id); los eventos que se repiten en un calendario de Google se crean desde Google Calendar.
- Probado con un servidor local que imita las respuestas de Google (`tests/fake-google/server.php`); con Google real hay que hacer la prueba tras los pasos anteriores (ver `docs/PRUEBAS.md`).

### Avisos en el móvil, Discord y GitHub (1.8, opcionales)

**Avisos en el móvil (Web Push).** No hay que crear cuentas ni claves: Borealis genera su par de claves VAPID en `storage/vapid.json` la primera vez. Requisitos:
- HTTPS con certificado válido (Let's Encrypt de HestiaCP sirve). Extensiones `openssl` (con curvas EC) y `curl` de PHP; el servidor debe poder salir por HTTPS a los servicios de notificaciones de los navegadores (`fcm.googleapis.com`, `updates.push.services.mozilla.com`, `web.push.apple.com`, `*.notify.windows.com`).
- Android: Chrome, Edge o Firefox. iPhone/iPad: iOS/iPadOS 16.4 o posterior y abrir Borealis **desde el icono de la pantalla de inicio** (Safari › Compartir › Añadir a pantalla de inicio); en Safari normal iOS no ofrece notificaciones.
- Opcional en `config/config.php`: `'push' => ['subject' => 'mailto:tu-correo@dominio']` (contacto que ven los servicios de notificaciones; si no, se usa `mail.from`).
- **Tarea programada** para que lleguen los recordatorios aunque nadie tenga Borealis abierto. HestiaCP › Cron Jobs › Add Cron Job, cada 5 minutos (`*/5`): `cd /home/USUARIO/web/DOMINIO/public_html/borealis && /usr/bin/php8.2 bin/notify.php >> storage/logs/notify.log 2>&1`. Usa la misma versión de PHP que el sitio.
  - Desde la 1.9 también envía los correos y pone al día Google Calendar.
  - Desde la 1.14, Administración › Notificaciones dice si funciona. Ahí también está la dirección `/cron/run?token=…`, para alojamientos que solo llaman a una URL.
  - Con PHP-FPM, una visita cualquiera también la ejecuta, como respaldo.
  - Detalle en `docs/CONFIGURACION-1.14.md`.

**Correo (1.9, opcional).** Sección `mail` de `config/config.php` con un buzón de tu dominio (SMTP 587/STARTTLS o 465/SSL), o `mail()` del servidor. Pasos para HestiaCP y prueba: `docs/CONFIGURACION-1.9.md` › Correo.
- Si se borra `storage/vapid.json` o `storage/app.key`, los dispositivos tienen que volver a activar las notificaciones.

**Discord.** En el servidor de Discord: Ajustes del canal › Integraciones › Webhooks › Nuevo webhook › Copiar URL. En Borealis: Todo a mano › el proyecto › Discord, pega la dirección, elige los eventos y pulsa «Enviar prueba». No hace falta un bot ni una cuenta de desarrollador.

**GitHub.** Todo a mano › el proyecto › GitHub: escribe `organización/repositorio` o pega su dirección. Un repositorio público funciona sin token (GitHub permite 60 consultas por hora por servidor; Borealis guarda el resumen 10 minutos). Para uno privado: GitHub › Settings › Developer settings › Fine-grained tokens, acceso solo a ese repositorio con permisos de **solo lectura** en Contents, Issues y Pull requests.

**Drive** y cualquier otra herramienta: enlace directo (Borealis no lee su contenido).

Lo que se probó sin servicios reales: las pruebas usan un servidor local que imita el servicio de notificaciones, el webhook de Discord y la API de GitHub (`tests/fake-services/server.php`); comprueban el cifrado y la firma de cada mensaje descifrándolo con las claves del «navegador». La entrega real a un teléfono, a un canal de Discord y la lectura de un repositorio real hay que probarlas en la instalación.

## 6. Seguridad

- Contraseñas con **Argon2id** (`password_hash`), rehash automático; mínimo 8 caracteres.
- Límite de intentos de inicio de sesión (1.4.1): por correo + IP, 5 fallos libres y después esperas que se duplican (30 s, 1 min, 2 min…) hasta 15 min al octavo; 30 fallos por IP en 15 min bloquean esa IP; si una cuenta recibe 50 fallos desde muchas IP, las IP que nunca entraron en ella esperan 60 s entre intentos. Nadie puede dejar fuera a otra persona desde su propia IP. Los registros de más de un día se borran solos.
- **Cuentas compartidas entre espacios** (1.4.1, `app/Security/AccountPolicy.php`): los permisos de un espacio nunca dan control sobre la identidad global de una persona. La administración de un espacio cambia contraseña, nombre, correo o cargo solo de cuentas que pertenecen únicamente a ese espacio y no tienen más permisos que ella; lo demás lo hace la administración de la instalación. «Crear cuenta» con un correo que ya existe genera una invitación, que la persona acepta con su propia contraseña.
- **Roles** (1.4.1): nadie cambia su propio rol; cambiar el rol de otra persona exige «Administrar integrantes» y «Administrar roles»; nadie asigna, define ni retira un rol con permisos que no tiene.
- **Invitados** (1.4.1): la búsqueda, Equipos y los perfiles solo muestran las personas y los equipos de sus proyectos y equipos; no se busca por correo.
- Sesión con cookie `HttpOnly`, `SameSite=Lax`, `Secure` en HTTPS, modo estricto, regeneración al iniciar sesión, cierre por inactividad; cambiar la contraseña invalida las demás sesiones.
- **CSRF** en todas las peticiones que modifican datos (formularios y API).
- **Permisos en el servidor** en cada operación (nunca solo ocultando botones). Roles del espacio (Propietario, Administración, Integrante, Invitado) y del proyecto (Responsable, Colaborador, Observador) más roles personalizados.
- **Aislamiento estricto por espacio de trabajo:** todas las consultas filtran por espacio y por visibilidad del proyecto; las referencias (estado, responsable, épica, sprint, tarea padre…) se validan contra el mismo proyecto.
- Consultas preparadas PDO (sin emulación), `sql_mode` estricto.
- Salida escapada por defecto; el formato de texto escapa primero y solo admite enlaces `http(s)`.
- Cabeceras: CSP sin scripts en línea, `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`.
- **Archivos:** lista blanca de extensiones, bloqueo de extensiones ejecutables en cualquier parte del nombre (`foto.php.jpg`), verificación del contenido real (MIME), tamaño máximo, nombre aleatorio, almacenados fuera de `public/` y servidos solo tras comprobar permisos, como descarga con `nosniff` y CSP de sandbox.
- CSV con neutralización de fórmulas.

## 7. Actualizaciones

1. **Haz una copia** (Administración › Datos › Crear copia ahora, o `php bin/backup.php`).
2. Sustituye los archivos de la aplicación **conservando** `config/config.php` y la carpeta `storage/`.
3. Aplica las migraciones pendientes:
   - desde el navegador: Administración › Datos › **Actualizar base de datos** (solo la administración de la instalación; crea una copia completa antes de aplicar), o
   - desde consola: `php bin/migrate.php` (también crea la copia; `--status` solo informa).

Las migraciones nunca eliminan datos de usuario.

### De 1.13.0 a 1.14.0

1. Copia de seguridad.
2. Sustituye los archivos conservando `config/config.php` y `storage/`.
3. Aplica la migración `016_v114` (Administración › Datos › Actualizar base de datos, o `php bin/migrate.php`).
   - **Tablas nuevas:** `system_jobs`, `notification_deliveries`, `task_estimates` y `audit_chain`.
   - **Columnas nuevas:** en `tasks`, `projects` y `activity`.
   - **Datos que rellena:** copia la estimación actual como «estimación de administración», toma la planificación original de la primera fecha registrada y enciende el envío al móvil a quien tenía un dispositivo activado con la preferencia apagada.
   - **Conserva y se repite:** no borra nada y se puede repetir.
4. **Revisa la tarea programada.** Es la causa de que los avisos no llegaran con Borealis cerrado.
   - En Administración › Notificaciones está el estado y el comando exacto para HestiaCP. Lleva la versión de PHP del sitio y guarda los errores en `storage/logs/notify.log`, no en `/dev/null`.
   - Si el alojamiento solo permite llamar a una URL, usa la dirección `/cron/run?token=…` que aparece ahí.
   - Detalle en `docs/CONFIGURACION-1.14.md`.
5. **Opcional:** Administración › Ajustes › «Ampliación de estimación desde» (25 % por defecto).

**Volver a 1.13.0 (reversión):**
1. Restaura los archivos de 1.13.0 conservando `config/config.php` y `storage/`.
2. La 1.13 funciona con las tablas y columnas de la 1.14 presentes, con dos diferencias:
   - el espacio «Trabajos rápidos» aparece como un proyecto más;
   - lo que se registre mientras tanto queda sin encadenar, y al volver a la 1.14 «Comprobar integridad» lo señala.

   Para quitarlas del todo, restaura la copia previa a la actualización.
3. No elimines solo las columnas de `activity`: se perdería el antes y el después registrado desde la 1.14.

### De 1.12.0 a 1.13.0

1. Copia de seguridad. 2. Sustituye los archivos conservando `config/config.php` y `storage/`. 3. Aplica la migración `015_v113` (Administración › Datos › Actualizar base de datos, o `php bin/migrate.php`). Solo añade 2 tablas (`analysis_cache`, `scenario_applications`) y 3 índices (`ix_task_completed` en `tasks`, `ix_tdc_changed` en `task_due_changes`, `ix_act_entity` en `activity`); se puede repetir. 4. No hay cron nuevo: Análisis se calcula al abrirlo y guarda el resultado hasta que cambian los datos. 5. Opcional: Administración › Ajustes › Parámetros de análisis.

**Volver a 1.12.0 (reversión):**
1. Restaura los archivos de 1.12.0 conservando `config/config.php` y `storage/`.
2. La base puede quedarse como está: 1.12.0 ignora las tablas e índices nuevos. Para dejarla exactamente como antes (se pierde el registro de escenarios aplicados; los cambios que aplicaron se quedan), en phpMyAdmin ejecuta:
   ```sql
   DROP TABLE IF EXISTS scenario_applications, analysis_cache;
   ALTER TABLE tasks DROP INDEX ix_task_completed;
   ALTER TABLE task_due_changes DROP INDEX ix_tdc_changed;
   ALTER TABLE activity DROP INDEX ix_act_entity;
   DELETE FROM schema_migrations WHERE version = '015_v113';
   ```
   Alternativa: restaurar la copia `pre-update` de `storage/backups/` (se pierde lo hecho después).

### De 1.11.0 a 1.12.0

1. Copia de seguridad. 2. Sustituye los archivos conservando `config/config.php` y `storage/`. 3. Aplica la migración `014_v112` (Administración › Datos › Actualizar base de datos, o `php bin/migrate.php`). Solo añade 4 columnas a `tasks` (`started_at`, `blocked_at`, `blocked_by`, `remaining_hours`) y 5 tablas (`task_due_changes`, `task_explanations`, `sprint_commitments`, `monitor_alerts`, `monitor_runs`); se puede repetir. 4. El cron no cambia: el mismo `bin/notify.php` cada 5 minutos ejecuta ahora también las reglas de Monitoreo. En HestiaCP (Cron Jobs): `cd /home/USUARIO/web/DOMINIO/public_html/borealis && php bin/notify.php >/dev/null 2>&1`, cada 5 minutos. Para ver errores, cambia `>/dev/null 2>&1` por `>> storage/logs/notify.log 2>&1`. 5. Opcional: Administración › Ajustes › Umbrales de monitoreo, y las horas semanales de cada persona en Miembros (sin ellas la carga sale «sin información suficiente»).

**Volver a 1.11.0 (reversión):**
1. Restaura los archivos de 1.11.0 conservando `config/config.php` y `storage/`.
2. La base puede quedarse como está: 1.11.0 ignora las columnas y tablas nuevas. Si quieres dejarla exactamente como antes (se pierden el historial de fechas, los motivos, los compromisos de sprint y las alertas), en phpMyAdmin ejecuta:
   ```sql
   DROP TABLE IF EXISTS monitor_runs, monitor_alerts, sprint_commitments, task_explanations, task_due_changes;
   ALTER TABLE tasks DROP COLUMN started_at, DROP COLUMN blocked_at, DROP COLUMN blocked_by, DROP COLUMN remaining_hours;
   DELETE FROM schema_migrations WHERE version = '014_v112';
   ```
   Alternativa: restaurar la copia `pre-update` de `storage/backups/` (se pierde lo hecho después).

### De 1.10.0 a 1.11.0

1. Copia de seguridad. 2. Sustituye los archivos conservando `config/config.php` y `storage/`. 3. Aplica la migración `013_v111` (Administración › Datos › Actualizar base de datos, o `php bin/migrate.php`). Solo añade: el valor «planned» al estado de los proyectos (los existentes no cambian) y la columna `tasks.completed_due_date`, que se rellena con la fecha límite de las tareas ya finalizadas. Se puede repetir. No cambia la configuración ni el cron.

**Volver a 1.10.0 (reversión):**
1. Antes, pasa a «Activo» (o al estado que corresponda) los proyectos que hayas puesto en «Planificado»: 1.10 no conoce ese estado. Para verlos: Proyectos › Estado › Planificado.
2. Restaura los archivos de 1.10.0 conservando `config/config.php` y `storage/`.
3. La base puede quedarse como está: 1.10.0 ignora la columna `completed_due_date`. Si quieres dejarla exactamente como antes, en phpMyAdmin (HestiaCP › DB › phpMyAdmin) ejecuta:
   ```sql
   UPDATE projects SET state = 'active' WHERE state = 'planned';
   ALTER TABLE projects MODIFY COLUMN state ENUM('active','on_hold','completed') NOT NULL DEFAULT 'active';
   ALTER TABLE tasks DROP COLUMN completed_due_date;
   DELETE FROM schema_migrations WHERE version = '013_v111';
   ```
   Alternativa: restaurar la copia `pre-update` que Borealis creó en `storage/backups/` al actualizar (se pierde lo hecho después).

### De 1.9.0 a 1.10.0

1. Copia de seguridad. 2. Sustituye los archivos conservando `config/config.php` y `storage/`. 3. Aplica la migración `012_v110` (Administración › Datos › Actualizar base de datos, o `php bin/migrate.php`); solo añade dos columnas a las tareas y se puede repetir. No cambia la configuración ni el cron.

### De 1.8.x a 1.9.0

1. Copia de seguridad. 2. Sustituye los archivos conservando `config/config.php` y `storage/`. 3. Aplica la migración `011_v19` (Administración › Datos › Actualizar base de datos, o `php bin/migrate.php`); solo añade columnas y una tabla y se puede repetir. 4. Cambia el cron de `bin/notify.php` a cada 5 minutos (antes 10). 5. Opcional: correo (`mail` en `config/config.php`). No hay que tocar Google Cloud. Todo en `docs/CONFIGURACION-1.9.md`.

### De 1.8.0 a 1.8.1

Sustituye los archivos conservando `config/config.php` y `storage/`. No hay migraciones.

### De 1.7.x a 1.8.0

1. Copia de seguridad. 2. Sustituye los archivos conservando `config/config.php` y `storage/`. 3. Aplica la migración `010_v18` (Administración › Datos › Actualizar base de datos, o `php bin/migrate.php`). Solo crea tablas y añade una columna; se puede repetir. 4. Opcional: el cron de `bin/notify.php` (ver «Avisos en el móvil, Discord y GitHub»). Si vienes de 1.7.0, incluye las correcciones de 1.7.1 (sin migraciones).

### De 1.7.0 a 1.7.1

Sustituye los archivos conservando `config/config.php` y `storage/`. No hay migraciones. La primera vez, si el tutorial o algún botón se comporta como antes, recarga con Ctrl+F5 (o Cmd+Mayús+R): desde 1.7.1 los módulos de JavaScript llevan la versión en su dirección y esto deja de ser necesario en las siguientes actualizaciones.

### De 1.6.0 a 1.7.0

1. Copia de seguridad. 2. Sustituye los archivos conservando `config/config.php` y `storage/`. 3. Aplica la migración `009_v17` (Administración › Datos › Actualizar base de datos, o `php bin/migrate.php`). Añade tres columnas (fotografía, horas en invitaciones, origen de las horas de un sprint) y no borra nada; se puede repetir.

**Cambia quién ve los proyectos:** desde 1.7 cada persona ve solo los proyectos donde participa (como integrante o por un equipo). La migración añade como colaboradoras a las personas que tenían tareas u horas en un proyecto sin ser integrantes, para que nadie pierda acceso a su propio trabajo. Si alguien debe seguir viendo otro proyecto, añádelo en Proyecto › Ajustes › Integrantes (por ejemplo como Observador).

Si el mensaje de cuenta creada mostraba la carpeta dos veces (`/borealis/borealis/login`), queda corregido sin tocar `config.php`.

### De 1.5.1 a 1.6.0

1. Copia de seguridad. 2. Sustituye los archivos conservando `config/config.php` y `storage/`. 3. Aplica la migración `008_v16` (Administración › Datos › Actualizar base de datos, o `php bin/migrate.php`). Solo crea tablas nuevas; se puede repetir si se interrumpe. Mientras falte, las páginas muestran el aviso de actualización. Google es opcional: si quieres usarlo, sigue «Google Calendar y Google Tasks».

### De 1.5.0 a 1.5.1

Solo cambian archivos (ilustraciones de los temas): sustituye los archivos conservando `config/config.php` y `storage/`. No hay migración.

### De 1.4.1 a 1.5.0

1. Haz una copia (Administración › Datos › Crear copia ahora, o `php bin/backup.php`).
2. Sustituye los archivos conservando `config/config.php` y `storage/`.
3. Aplica la migración `007_v15` desde Administración › Datos › **Actualizar base de datos** o con `php bin/migrate.php`. Solo **crea tres tablas nuevas** (`capacity_alerts`, `capacity_alert_events`, `capacity_extras`); no modifica ni borra nada existente. Si se interrumpe, se puede repetir. Dos actualizaciones a la vez no se pisan: la segunda espera o avisa de que hay otra en curso.

Las alertas empiezan a calcularse con las horas que ya estaban registradas para la semana en curso; solo se tienen en cuenta personas con horas semanales definidas.

### De 1.4.0 a 1.4.1

1.4.1 es una actualización solo de seguridad y **no tiene migraciones**: basta con sustituir los archivos conservando `config/config.php` y `storage/`. No hace falta tocar `config.php`: sin la clave `app.allow_workspace_creation`, crear espacios queda reservado a la administración de la instalación.

Después, si la instalación estuvo en uso con 1.4.0, ejecuta `php bin/audit-accounts.php`. Solo lee: lista espacios creados por cuentas que no administran la instalación, cuentas incorporadas sin invitación cuando ya pertenecían a otro espacio, contraseñas de cuentas compartidas restablecidas por la administración de un espacio y personas que cambiaron su propio rol. No borra nada; revisa cada caso en Administración.

### Desde Borealis v0.2.x

La v0.2 guardaba todo en archivos (`storage/borealis.json`). Para traer esos datos:

1. Instala la 1.1 aparte (carpeta y base de datos nuevas).
2. Entra como administración y ve a **Administración › Datos › Importar desde Borealis v0.2**.
3. Sube un **ZIP de la carpeta completa** de la versión anterior, que incluye los archivos adjuntos, o solo `storage/borealis.json`.
   Desde consola: `php bin/import-v022.php --email=TU_CORREO /ruta/a/borealis-antiguo` (con `--dry-run` solo muestra lo que encontraría).
4. Asigna una contraseña a cada persona importada en **Administración › Personas**.

Se importan personas (unificadas por correo), equipos, proyectos con sus estados, sprints, tareas con su clave original (p. ej. `BOR-101`), etiquetas, checklists como subtareas, comentarios y archivos. Antes de empezar se crea una copia de seguridad. Nada existente se modifica, y los mismos datos no se pueden importar dos veces. El historial de actividad de la v0.2 no se importa.

## 8. Copias de seguridad

- **Completa (SQL):** Administración › Datos (solo la administración de la instalación) o `php bin/backup.php`. Se guardan en `storage/backups/` y se descargan desde la misma pantalla.
- **Por cron:** `0 3 * * * php /ruta/a/borealis/bin/backup.php --keep=14` (con `--keep` solo se rotan las copias automáticas; las manuales y las previas a actualizar nunca se borran).
- **Exportación del espacio (JSON):** Administración › Datos › Exportar.
- **Restaurar:** `mysql -u USUARIO -p BASE < storage/backups/archivo.sql` y copia de vuelta `storage/uploads/`.
- Respalda también `storage/uploads/` (los archivos adjuntos).

## 9. Solución de problemas

| Síntoma | Causa probable y solución |
|---|---|
| Página en blanco o error 500 | Revisa `storage/logs/error.log`. Comprueba la versión de PHP y que `storage/` sea escribible. |
| 404 en todas las páginas salvo el inicio | Falta `mod_rewrite` o `AllowOverride All`. Actívalos o pon `app.pretty_urls => false`. |
| «Tu sesión terminó» constantemente | El reloj del servidor o la cookie: revisa que `app.url` coincida con el dominio real (http/https). |
| No se pueden subir archivos grandes | Aumenta `upload_max_filesize` y `post_max_size` de PHP, y `uploads.max_bytes`. |
| «Demasiados intentos» al entrar | Bloqueo temporal por intentos fallidos; espera 15 minutos. |
| El instalador dice que la base ya tiene datos | Protección contra sobrescritura: usa una base vacía o restaura `config/config.php`. |
| Tras actualizar, algo se comporta como en la versión anterior (por ejemplo, el tutorial) | El navegador usa archivos `.js` viejos guardados en caché (Nginx de HestiaCP los marca con `expires max`). Desde 1.7.1 no debería pasar; si pasa, recarga con Ctrl+F5. Para regenerar las versiones tras editar JavaScript: `php bin/stamp-assets.php`. |
| «Activar en este dispositivo» no aparece o falla | Hace falta HTTPS. En iPhone/iPad, abre Borealis desde el icono de la pantalla de inicio (iOS 16.4+). Si el permiso se denegó, se cambia en los ajustes del navegador para el sitio. |
| Los avisos al móvil llegan solo cuando alguien abre Borealis | La tarea programada no corre. Administración › Notificaciones dice desde cuándo y muestra el último error. Revisa que el comando use la versión de PHP del sitio (`/usr/bin/php8.2`, no `php`) y mira `storage/logs/notify.log`. Si el alojamiento solo permite URLs, usa `/cron/run?token=…`. |
| Un dispositivo dejó de recibir avisos | Administración › Notificaciones › Envíos muestra el motivo. Un 401/403 indica un problema con las claves VAPID (¿se borró `storage/vapid.json`?). Quien tenga el problema puede volver a pulsar «Activar en este dispositivo». |
| Discord o GitHub muestran un error en «Todo a mano» | El mensaje dice la causa (webhook eliminado, token caducado, límite de consultas, sin conexión). El resto de Borealis no se ve afectado. |
| Olvidé la contraseña de administración | Otra cuenta con permiso «Gestionar personas» puede restablecerla en Administración › Personas. |

## 10. Pruebas

Ver [`docs/PRUEBAS.md`](docs/PRUEBAS.md) para los resultados reales y cómo repetirlas.

```bash
# Servidor de prueba con una base de datos VACÍA
php -S 127.0.0.1:8080 -t public public/router.php
export BOREALIS_URL=http://127.0.0.1:8080 BOREALIS_TEST_DB_NAME=borealis_test BOREALIS_TEST_DB_USER=usuario BOREALIS_TEST_DB_PASS=...
php tests/integration/run.php          # instalación, auth, permisos, proyectos, equipos, tareas, archivos, calendario, Google (simulado)…
php tests/unit/events-time.php         # zonas horarias y repeticiones de eventos (sin servidor)
php tests/unit/urls.php                # enlaces de los mensajes con Borealis en una carpeta (sin servidor)
php tests/unit/reminders.php           # reglas de recordatorios 24 h / 30 min e ids de Google Calendar (sin servidor)
php tests/unit/analysis.php            # fórmulas y reglas de Análisis (sin servidor)
node tests/e2e/run.mjs                 # navegador (requiere Playwright + Chromium) y capturas en 4 tamaños
```

Las pruebas instalan Borealis desde cero: **nunca las ejecutes contra una base con datos reales**.

## Licencia y créditos

Iconos de estilo Lucide (licencia ISC). Ilustración del bosque creada para Borealis.
