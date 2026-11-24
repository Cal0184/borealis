# Borealis 1.9 — Configuración externa

Lo que la administración de la instalación tiene que hacer **fuera de Borealis** para activar Google Calendar, los avisos en el móvil, el correo y los recordatorios automáticos en un servidor con HestiaCP. Nada de esto es obligatorio: sin ello Borealis funciona igual y cada pantalla dice qué falta.

Antes de empezar: copia de seguridad (Administración › Datos › Crear copia ahora) y la migración `011_v19` aplicada (Administración › Datos › Actualizar base de datos, o `php bin/migrate.php`).

Todas las claves van en `config/config.php`, que está fuera de `public/` y no se incluye en el ZIP. Nunca en el código.

---

## 1. Google Calendar (OAuth 2.0 y Google Calendar API)

Si ya conectaste Google en 1.6–1.8, **no hay que cambiar nada en Google Cloud**: el envío de tareas a Google Calendar usa los mismos permisos (`calendar.events`). Salta al paso 6.

1. Entra en <https://console.cloud.google.com/> con la cuenta que administrará la integración y crea un proyecto (por ejemplo «Borealis»).
2. **APIs y servicios › Biblioteca:** habilita **Google Calendar API** (y **Google Tasks API** si quieres «Mis tareas» con Google Tasks).
3. **Google Auth Platform › Branding:** nombre «Borealis», correo de asistencia, el dominio de tu instalación en «Dominios autorizados» y los enlaces a tu página de inicio y política de privacidad.
4. **Google Auth Platform › Público (Audience):**
   - «Interno» si todas las cuentas son de tu Google Workspace (lo más simple: sin verificación).
   - Si no, «Externo». Mientras esté en **Prueba (Testing)**, añade como usuarios de prueba las cuentas que vayan a conectarse (máximo 100) y ten en cuenta que Google caduca el permiso a los **7 días**: Borealis lo detecta y muestra «Error de autorización · Volver a conectar». Para uso normal, pasa la app a **En producción**; como `calendar.events` y `tasks` son permisos sensibles, Google puede pedir la verificación de la app (formulario, vídeo de uso y política de privacidad).
5. **Google Auth Platform › Acceso a los datos (Data access):** añade exactamente estos permisos (los mínimos que usa Borealis):
   - `openid` y `email`: saber qué cuenta se conectó.
   - `https://www.googleapis.com/auth/calendar.calendarlist.readonly`: ver la lista de calendarios (para elegir uno).
   - `https://www.googleapis.com/auth/calendar.events`: crear, cambiar y quitar los eventos que Borealis envía (no permite borrar calendarios ni cambiar sus ajustes).
   - `https://www.googleapis.com/auth/tasks`: solo si usas Google Tasks.
6. **Clientes › Crear cliente › Aplicación web.** En **URI de redireccionamiento autorizados** escribe exactamente:
   - `https://TU-DOMINIO/google/callback`
   - con Borealis en una subcarpeta: `https://TU-DOMINIO/borealis/google/callback`
   - con `app.pretty_urls = false`: `https://TU-DOMINIO/index.php?r=/google/callback`

   Tiene que ser HTTPS (HestiaCP › Web › el dominio › SSL › Let's Encrypt).
7. Copia el ID y el secreto en `config/config.php`:
   ```php
   'google' => [
       'client_id' => '1234567890-xxxx.apps.googleusercontent.com',
       'client_secret' => 'GOCSPX-...',
   ],
   ```
8. Comprueba que `storage/` admite escritura (Borealis crea `storage/app.key` para cifrar los tokens; guarda una copia de ese archivo: sin él, todas las personas tienen que volver a conectar) y que PHP tiene `curl` y puede salir por HTTPS a `oauth2.googleapis.com` y `www.googleapis.com`.
9. **Prueba real** (no se pudo hacer desde aquí): con tu cuenta, Perfil › Google Calendar › Conectar con Google › acepta › elige tu calendario › «Empezar a enviar». En Google Calendar deben aparecer tus tareas con fecha como «[CLAVE-12] Título». Cambia el título de una en Borealis: el mismo evento cambia (sin duplicarse). Complétala: desaparece. «Desconectar y quitar sus eventos»: se quitan solo esos.

Cómo se comporta:
- Una dirección: Borealis → Google. Lo que se cambie en Google en esos eventos se reemplaza en el siguiente cambio de la tarea. Nada de Google vuelve a Borealis por esta vía (la vista de calendarios de Google dentro de Borealis, de 1.6, sigue disponible aparte).
- Solo se tocan eventos creados por Borealis (llevan una marca privada); el resto del calendario no se lee para esto, ni se cambia ni se borra.
- Sin duplicados: cada evento tiene un id estable; si una respuesta se pierde, o se desconecta y se vuelve a conectar, Borealis encuentra el evento anterior y lo actualiza.
- Cuota y caídas: si Google pide esperar o no responde, Borealis sigue funcionando, guarda el motivo (visible en el perfil) y reintenta solo, con pausas de 5 min, 10, 20… hasta 6 h. Máximo 120 operaciones por persona y pasada; el resto sigue en la próxima pasada del cron.
- Calendarios compartidos de Google: si alguien elige uno compartido, quienes lo comparten en Google ven esos eventos. El perfil lo advierte al elegir y ofrece «Solo título, proyecto y enlace».

## 2. Avisos en el móvil (Web Push, VAPID)

No hay que crear cuentas ni claves: Borealis genera su par de claves **VAPID** (P-256) la primera vez en `storage/vapid.json` (la privada, cifrada con `storage/app.key`). Requisitos:

- HTTPS con certificado válido. Extensiones `openssl` (con curvas EC) y `curl` de PHP.
- Salida HTTPS a los servicios de los navegadores: `fcm.googleapis.com` (Chrome, Edge, Android), `updates.push.services.mozilla.com` (Firefox), `web.push.apple.com` (Safari, iPhone/iPad), `*.notify.windows.com`.
- Opcional, en `config/config.php`: `'push' => ['subject' => 'mailto:soporte@tu-dominio.cl']` (contacto que ven esos servicios).
- iPhone/iPad: iOS/iPadOS 16.4 o posterior, **Borealis añadido a la pantalla de inicio** (Safari › Compartir › Añadir a pantalla de inicio) y abierto desde ese icono. Perfil › «Borealis como aplicación» lo explica en el propio dispositivo.
- Nadie recibe la petición de permiso al entrar: solo al pulsar «Activar en este dispositivo» en Perfil › Notificaciones.
- Si se borra `storage/vapid.json` o `storage/app.key`, cada dispositivo tiene que volver a activar los avisos.

Prueba real: en un Android con Chrome y en un iPhone con Borealis en la pantalla de inicio, «Activar en este dispositivo» › «Enviar una prueba»; después asígnate una tarea desde otra cuenta con el teléfono bloqueado.

## 3. Correo (SMTP)

1. HestiaCP › **Mail** › tu dominio › **Add Mail Account**: crea un buzón para Borealis (por ejemplo `borealis@tu-dominio.cl`) con una contraseña larga. Comprueba en HestiaCP que el dominio tiene **DKIM** activado y que los registros **SPF** y **DKIM** están en el DNS (si el DNS lo lleva HestiaCP, ya están).
2. En `config/config.php`:
   ```php
   'mail' => [
       'transport' => 'smtp',
       'host' => 'mail.tu-dominio.cl',     // o el nombre del servidor que muestra HestiaCP
       'port' => 587,                       // 587 = STARTTLS; 465 = SSL
       'encryption' => 'tls',               // 'tls' con 587, 'ssl' con 465
       'username' => 'borealis@tu-dominio.cl',
       'password' => 'la contraseña del buzón',
       'from_address' => 'borealis@tu-dominio.cl',
       'from_name' => 'Borealis',
   ],
   ```
   Si prefieres que la contraseña no esté en el archivo: deja `'password' => ''` y define la variable de entorno `BOREALIS_SMTP_PASSWORD` para PHP-FPM y para el cron.
3. El certificado del servidor de correo se **verifica**: `host` tiene que coincidir con el certificado (el de HestiaCP suele ser `mail.tu-dominio.cl` o el nombre del servidor). Borealis no envía la contraseña sin cifrar: si el servidor no ofrece STARTTLS, se niega y lo dice.
4. Alternativa sin buzón: `'transport' => 'sendmail'` usa `mail()` de PHP (el Exim del propio servidor), con `from_address` de tu dominio. Funciona en muchos HestiaCP, pero llega peor a Gmail/Outlook que SMTP con DKIM.
5. **Prueba:** Administración › Datos › «Enviar correo de prueba» (solo la administración de la instalación) manda un correo a tu propia dirección y, si falla, muestra el motivo (servidor que no responde, usuario o contraseña rechazados, certificado…), nunca la contraseña.

Qué se envía: los avisos de las categorías que cada persona eligió (por defecto asignaciones, por vencer, vencidas, sprints, capacidad y resumen semanal), con «Por correo» encendido en su perfil, si no los leyó antes en Borealis. Varios avisos juntos van en un solo correo. Nada en horario de silencio (sale al terminar). Lo envía el cron; si el servidor de correo falla, los avisos esperan al siguiente intento.

## 4. Cron en HestiaCP (recordatorios sin Borealis abierto)

HestiaCP › **Cron Jobs** › **Add Cron Job**:

- Command: `cd /home/USUARIO/web/DOMINIO/public_html/borealis && php bin/notify.php >/dev/null 2>&1`
  (ajusta la ruta a donde está Borealis; si `php` no es la versión correcta, usa la ruta completa, por ejemplo `/usr/bin/php8.2`).
- Minute: `*/5` · Hour: `*` · Day: `*` · Month: `*` · Day of week: `*`

Cada pasada: crea los recordatorios (24 h y 30 min antes, vencidas, sprints, resumen del lunes) de todas las personas en su zona horaria, revisa las alertas de capacidad, envía lo pendiente al móvil y por correo, y pone al día Google Calendar. Es seguro ejecutarlo a menudo: nada se repite y dos pasadas no se pisan (bloqueo en `storage/notify.lock`). Para comprobarlo a mano, por SSH: `php bin/notify.php` imprime cuántos avisos creó y envió.

Con el cron cada 5 minutos, el aviso de «30 minutos antes» llega entre 25 y 30 minutos antes. Sin cron, los recordatorios se crean cuando cada persona abre Borealis y los cambios de tareas llegan a Google Calendar al momento, pero no hay correos ni avisos en el móvil de lo que vence mientras nadie usa Borealis.

## 5. Aplicación instalable (PWA)

Nada que configurar más allá de HTTPS. Si Nginx de HestiaCP sirve `manifest.webmanifest` con otro tipo, añade `application/manifest+json webmanifest;` a los tipos MIME (en la mayoría ya está). El service worker (`public/sw.js`) no guarda páginas ni datos: solo la página estática «Sin conexión».
