# Configuración de la 1.15

La 1.15 **no necesita** servicios externos, claves de terceros ni dependencias nuevas. Basta con actualizar los archivos y aplicar la migración 017:

```
php bin/migrate.php
```

El asistente de actualización también la aplica. La migración solo agrega tablas y columnas, así que los datos existentes no cambian.

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
