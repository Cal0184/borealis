# Configuración de la 1.15

La 1.15 **no necesita** servicios externos, claves de terceros ni dependencias nuevas. Basta con actualizar los archivos y aplicar la migración 017:

```
php bin/migrate.php
```

El asistente de actualización también la aplica. La migración solo agrega tablas y columnas, así que los datos existentes no cambian.

## Avisos con Borealis cerrado: el motor interno (nuevo)

Hasta ahora, sin una tarea programada (cron) en el servidor, los recordatorios solo se creaban cuando alguien tenía Borealis abierto. Desde esta versión, Borealis mantiene **su propio motor de avisos**, que funciona aunque nadie haya iniciado sesión y no haya ninguna pestaña abierta.

- **Arranque:** el motor arranca con cualquier visita a cualquier página, incluso la de inicio de sesión.
- **Funcionamiento:** cada 60 segundos crea los recordatorios y los envía a los teléfonos y equipos activados. Para eso se llama a sí mismo en `POST /cron/tick`, una dirección protegida con la misma clave secreta que `/cron/run`.
- **Cuándo se detiene:** si el servidor ya tiene un cron funcionando, el motor se detiene solo. Si el cron deja de funcionar, vuelve a arrancar con la próxima visita.
- **Dónde ver su estado:** en **Administración › Notificaciones**, con el último latido y la cantidad de latidos, o el error si no pudo arrancar.

### Requisitos

1. **PHP-FPM.** HestiaCP lo usa por defecto, tanto con Nginx como con Apache + PHP-FPM.
2. **Que el servidor pueda abrir su propia dirección**, la de `app.url`.
   - Si la dirección pública no responde desde el propio servidor, el motor lo intenta por `127.0.0.1` con el mismo nombre, y el certificado HTTPS sigue siendo válido.
   - Si eso tampoco funciona, define `'cron' => ['heartbeat_url' => 'https://…/cron/tick']` con una dirección que sí responda.
3. **La extensión `curl` de PHP.** Ya la exigían las notificaciones push.

### Límites

- Ocupa un proceso de PHP-FPM, casi siempre en espera.
- **Después de reiniciar el servidor o PHP-FPM**, el motor vuelve a arrancar con la primera visita, de quien sea. Solo la tarea programada cubre ese momento, y por eso sigue siendo lo más robusto. Configurarla en HestiaCP toma dos minutos (punto 1 de la sección siguiente).
- **iPhone y iPad:** reciben avisos solo con Borealis añadido a la pantalla de inicio (iOS 16.4 o posterior). Es una regla de Apple.
- **Teléfono apagado o sin conexión:** el servicio de avisos del fabricante (Google, Apple o Mozilla) entrega el aviso cuando el teléfono vuelve a conectarse.

### Cómo se probó

Con `tests/fpm/engine.sh` se montó un servidor con **Nginx + PHP-FPM**, igual que HestiaCP, sin cron. La única petición de una persona fue una visita a la página de inicio de sesión, sin sesión iniciada. Después se escribió directamente en la base una tarea que vence mañana.

Sin otra visita, el motor:
- creó el recordatorio;
- lo entregó cifrado al servicio de avisos de prueba, y el «teléfono» lo descifró;
- no lo repitió;
- mantuvo una sola cadena de latidos;
- se detuvo al ejecutarse un cron real.

El resultado fue de 12 comprobaciones correctas de 12. Lo que no se probó es la entrega final a un teléfono de verdad a través de Google, Apple o Mozilla.

### Desactivarlo

En `config/config.php`: `'cron' => ['heartbeat' => false]`.

## Lo que conviene revisar

### 1. Tarea programada (recomendada, igual que en la 1.14)

Las automatizaciones por fecha («Tarea vencida», «Superó las horas», «Bloqueo prolongado», «Informe del sprint» y «Prioridades de la semana») se revisan en cada ejecución de la tarea programada. Con el cron cada 5 minutos se aplican a tiempo. Ver [CONFIGURACION-1.14.md](CONFIGURACION-1.14.md):

```
*/5 * * * *  cd /home/USUARIO/web/DOMINIO/public_html/borealis && /usr/bin/php8.2 bin/notify.php >> storage/logs/notify.log 2>&1
```

- **Sin cron:** se usa el respaldo por visitas. Desde la 1.15, una pestaña abierta también lo activa, porque consulta los avisos cada minuto.
- **Automatizaciones inmediatas:** «Bloqueo resuelto» y «Proyecto terminado» se aplican en el momento del cambio y no dependen del cron.

### 2. Notificaciones del sistema

Siguen los requisitos de la 1.8:

- HTTPS;
- las extensiones `openssl` y `curl`;
- salida HTTPS hacia los servicios de push de los navegadores.

El service worker (`/sw.js`) se actualiza solo con la versión 1.15.0: no hay que hacer nada. Si un servidor sirve `sw.js` con caché larga, conviene `Cache-Control: no-cache` para ese archivo (Nginx: `location = /sw.js { add_header Cache-Control "no-cache"; }`).

### 3. Conservación del registro maestro (opcional)

Por defecto el registro no caduca. Para fijar una política, en `config/config.php`:

```php
'audit' => ['retention_months' => 24],
```

Y una tarea mensual:

```
0 3 1 * *  cd /ruta/a/borealis && /usr/bin/php8.2 bin/audit-archive.php >> storage/logs/audit-archive.log 2>&1
```

- **Qué hace el script:** mueve lo anterior a `storage/audit-archive/wsN-AAAA-MM-DD.jsonl.gz`, con las huellas, y lo comprueba antes de borrarlo de la tabla.
- **Para probar antes:** `--dry-run`.
- **Mínimo:** 6 meses.
- **Dónde guardar los archivos:** `storage/` no se publica en la web, pero conviene incluir esos archivos en las copias de seguridad.
- **Privacidad:** si alguna ley de privacidad exige plazos, este es el punto para cumplirlos.

### 4. Permisos

No hay permisos nuevos:

- «Ver auditoría» da acceso al registro maestro;
- «Administrar el espacio» da acceso a las automatizaciones;
- «Crear proyectos» permite compartir vistas guardadas con todo el espacio.

## Lo que no se guarda

- Contraseñas, tokens, claves, cookies, sesiones y datos de suscripciones push: se ocultan antes de escribir en el registro.
- De un acceso se guarda solo la red abreviada, por ejemplo `190.20.33.x`.
- Los errores se guardan sin traza, sin rutas de archivos y sin valores de consultas. El detalle completo sigue solo en `storage/logs/error.log`, en el servidor.
