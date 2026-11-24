# Configuración de la 1.14: tarea programada y notificaciones

Desde la 1.14 Borealis puede enviar los recordatorios aunque nadie lo tenga abierto, siempre que algo lo despierte cada pocos minutos. Hay tres maneras. Basta una, y se pueden combinar porque nunca se repite nada: hay un bloqueo en `storage/notify.lock` y cada aviso se registra una vez por dispositivo.

Para comprobar cuál está funcionando, entra en **Administración › Notificaciones**. Esa página muestra:

- cuándo fue la última ejecución y de dónde vino (consola, dirección o visita);
- el último error;
- los envíos a dispositivos que fallaron en los últimos 7 días, con su motivo.

## 1. Cron por línea de comandos (recomendado)

Pasos en HestiaCP:

1. Ve a **Cron Jobs › Add Cron Job**.
2. Minuto `*/5`. En hora, día, mes y día de la semana, `*`.
3. Comando:

   ```
   cd /home/USUARIO/web/DOMINIO/public_html/borealis && /usr/bin/php8.2 bin/notify.php >> storage/logs/notify.log 2>&1
   ```

   - Usa la misma versión de PHP que el sitio. Administración › Notificaciones muestra el comando exacto con la versión de esta instalación.
   - En HestiaCP, `php` a secas puede ser otra versión, sin `pdo_mysql`, `openssl` o `curl`. Desde la 1.14, `bin/notify.php` lo dice en el registro en lugar de fallar en silencio.
   - Antes se recomendaba `>/dev/null 2>&1`, que ocultaba cualquier error. Ahora se recomienda guardarlo en `storage/logs/notify.log`.

## 2. Dirección `/cron/run` (si el alojamiento solo permite llamar a una URL)

```
curl -fsS "https://DOMINIO/borealis/cron/run?token=CLAVE" >/dev/null
```

- **Dónde está la dirección completa:** la administración de la instalación la ve en Administración › Notificaciones. La clave se genera sola y se guarda cifrada en `storage/cron.json`. «Generar otra» la cambia, y desde ese momento la anterior deja de funcionar.
- **Qué permite la clave:** iniciar la tarea, nada más. No da acceso a datos ni abre sesión.
- **Si la dirección se llama mal o demasiado seguido:**
  - Sin clave, o con una clave equivocada, responde 403.
  - Dos llamadas con menos de un minuto de diferencia no la ejecutan dos veces.
- **Clave en una cabecera:** también se acepta `X-Borealis-Token: CLAVE`, para no dejar la clave en los registros del servidor web.
- **Clave fija (opcional):** en `config/config.php`, `'cron' => ['token' => 'una-clave-larga-de-24-caracteres-o-mas']`. No la subas a ningún repositorio.

## 3. Respaldo por visitas (automático, sin configurar nada)

Con PHP-FPM, que es lo que usa HestiaCP, Borealis ejecuta la tarea **después de responder** a una visita cualquiera si la última ejecución fue hace más de 5 minutos. Nadie espera por ello.

Es solo un respaldo: si de noche nadie entra, los recordatorios de la mañana salen con la primera visita. Por eso conviene la opción 1.

- Para desactivarlo: `'cron' => ['web_fallback' => false]`.
- Para forzarlo en servidores sin PHP-FPM: `'cron' => ['web_fallback' => 'always']`. Ahí la visita que lo dispara espera a que termine.

## Qué hace cada ejecución

1. Crea los recordatorios de todas las personas, en su zona horaria: 24 h y 30 min antes, vencidas, sprints y resumen del lunes.
2. Revisa las alertas de capacidad y las reglas de Monitoreo, incluidas las de la 1.14: «superó el tiempo acordado» y «ampliación importante de la estimación».
3. Envía al móvil lo pendiente, una vez por dispositivo, con registro. Si el servicio del navegador falla de forma pasajera (sin red, 429 o 5xx), reintenta a los 5, 15, 60 y 180 minutos. Si el error es permanente (por ejemplo, 401/403 de VAPID), lo deja anotado con el motivo y no insiste. Un aviso leído entretanto o una tarea ya completada no se reenvían.
4. Envía los correos (si están configurados) y pone al día Google Calendar y Google Tasks, como en la 1.9.

## Notificaciones en el dispositivo

- **Personas nuevas:** el envío al dispositivo viene activado. Solo falta que cada persona dé permiso al navegador. El inicio se lo propone una vez y el perfil sigue teniendo el botón.
- **Personas con dispositivo pero envío apagado:** la migración 016 enciende el envío a quien tenía un dispositivo activado pero la preferencia apagada, que era el error de la 1.13. Quien lo apague después a propósito lo mantiene apagado.
- **Suscripción renovada por el navegador:** si el navegador cambia la suscripción, el service worker la renueva solo (`/api/push/renew`), aunque no haya sesión abierta. Lo hace demostrando que conoce la suscripción anterior.
- **Requisitos del servidor (sin cambios desde la 1.8):**
  - HTTPS.
  - Las extensiones `openssl` (con curvas EC) y `curl`.
  - Salida HTTPS hacia los servicios de notificaciones de los navegadores.

## Qué no hace falta

- No hace falta ningún servicio externo nuevo, ni claves de terceros, ni cambios en Google Cloud.
- No se guardan contraseñas ni tokens en el registro de actividad: se ocultan antes de escribirlo.
