# Borealis 1.16 · Informe: notificaciones con Borealis cerrado

## Punto de partida

El servidor de producción no está en HestiaCP. No hay SSH, terminal, cron ni acceso a la configuración del servidor: solo FTP (FileZilla) y el administrador de la base de datos. Con esas condiciones, los avisos llegaban únicamente con Borealis abierto o al volver a entrar, y fallaban con confirmaciones abiertas.

## 1. Auditoría de la implementación 1.15.1

| Pieza | Estado encontrado | ¿Causa del problema? |
|---|---|---|
| Web Push + VAPID (`app/Services/Push.php`) | Correcto: firma ES256 (RFC 8292) y cifrado `aes128gcm` (RFC 8291) en PHP puro, sin librerías. Claves en `storage/vapid.json`. | No |
| Service worker (`public/sw.js`) | Muestra el aviso con el contenido cifrado que trae el mensaje, sin pedir nada al servidor ni necesitar sesión. | No |
| Suscripciones | Ligadas a la persona, no a la sesión. Cerrar sesión no las borra. Renovación automática (`pushsubscriptionchange`). | No |
| Envío al instante (asignaciones, cambios) | Se envía tras responder la petición de quien hace el cambio. | No. En Apache la página esperaba al envío (corregido) |
| **Tarea programada sin cron** | El motor interno (1.15.1) y el respaldo por visitas (1.14) **solo funcionaban con PHP-FPM** (`fastcgi_finish_request`). En LiteSpeed o Apache, lo habitual en alojamientos que se administran solo por FTP, ambos quedaban apagados. Lo único que creaba recordatorios era la visita de la propia persona a Inicio. | **Sí: causa principal.** Coincide con el síntoma «solo al volver a entrar» |
| Instrucciones | Suponían HestiaCP y cron. | Sí: imposibles de seguir |
| Categorías al dispositivo | Cambios en proyectos y de estado, comentarios, capacidad y tareas que sigues venían **apagadas** para el teléfono. | Sí: «modificaciones» no llegaban |
| Horario de silencio | **Activado por defecto** de 22:00 a 07:00: retenía los avisos de la noche sin que la persona lo hubiera elegido. | Sí, de noche |
| Volver a vincular el dispositivo | Con el permiso ya dado, solo se volvía a suscribir si en ese navegador se había pulsado «Activar» antes. Un navegador nuevo quedaba en silencio. | Sí, en algunos dispositivos |
| Invitación a activar | Solo en Inicio. «Ahora no» la ocultaba para siempre. | Parcial |
| **Confirmaciones abiertas** | Los `<dialog>` modales ocupan la capa superior del navegador. Los avisos dentro de la página (y el de sesión terminada) se pintaban **detrás** del diálogo, por encima de cualquier `z-index`. No quedaba ningún `window.confirm()`: se retiraron en 1.15. | **Sí** |
| Duplicados | Ya protegidos: clave única por aviso, una entrega por aviso y dispositivo, bloqueo de la tarea y reintentos con espera. | No |

## 2. Qué se cambió

1. **`app/Core/Background.php`: trabajo tras responder en cualquier servidor.** Usa `fastcgi_finish_request` en PHP-FPM, `litespeed_finish_request` en LiteSpeed y, en Apache u otros, un modo compatible: `Content-Length`, `Connection: close`, vaciado de búferes e `ignore_user_abort`. El motor interno, el respaldo por visitas y el envío inmediato funcionan ahora en los tres.
2. **Motor interno más resistente.** Da por aceptado el latido en cuanto llega el «202», aunque el servidor retenga el resto de la respuesta (por ejemplo, con compresión). Antes de detenerse reintenta una vez a los 10 segundos.
3. **`/cron/ping` y service worker.** Cada dispositivo que recibe o abre un aviso, y cada despertar periódico del navegador (Borealis instalado en Chrome, Edge o Android), avisa a Borealis de que hay alguien. Eso reanuda el motor y la tarea si el alojamiento los había cortado. La dirección no lleva datos ni sesión y tiene límites: el motor arranca como mucho una vez por minuto y la tarea una vez cada 2 minutos.
4. **Preferencias activadas por defecto** y silencio apagado por defecto. Quien guardó las preferencias antiguas sin cambiar nada pasa a las nuevas; lo que alguien apagó a propósito se respeta.
5. **Dispositivo con permiso = dispositivo vinculado.** Así queda salvo que la persona lo desactive en él. La invitación a activar aparece en todas las páginas y «Ahora no» dura una semana.
6. **Avisos encima de las confirmaciones.** La caja de avisos es un *popover* y se muestra en la capa superior, encima del diálogo abierto.
7. **Administración › Notificaciones** muestra el servidor detectado, qué funciona sin acceso al servidor y los pasos para un servicio externo gratuito.

## 3. Qué funciona con PHP + JavaScript + MySQL y qué requiere infraestructura externa

| Necesidad | Solo PHP/JS/MySQL | Externo |
|---|---|---|
| Aviso del sistema operativo con Borealis cerrado (computador y celular) | Sí: Web Push + service worker. El servicio de mensajería del navegador (Google, Mozilla, Apple) ya viene con el navegador y no hay que contratar nada. | No |
| Asignaciones, cambios, comentarios y alertas creadas por una acción | Sí, al instante | No |
| Sesión caducada | Sí: no afecta | No |
| Sin duplicados ni pérdidas | Sí: deduplicación, entregas registradas y reintentos | No |
| Recordatorios por hora (por vencer, vencidas, reuniones) mientras alguien usa Borealis o recibe avisos | Sí: motor interno, visitas y `/cron/ping` | No |
| Recordatorios por hora **garantizados** después de un reinicio del servidor, de noche o en fin de semana sin nadie | No. Ningún programa PHP puede despertarse solo si el servidor no recibe ninguna petición. | **Sí: un servicio gratuito que visite `/cron/run?token=…` cada 5 minutos** (cron-job.org o UptimeRobot). No requiere acceso al servidor. Pasos en `CONFIGURACION-1.16.md` |

Límites de los navegadores que ningún servidor puede cambiar:
- La primera vez el navegador **siempre** pide permiso, y solo después de que la persona pulse un botón.
- En iPhone y iPad, solo con Borealis añadido a la pantalla de inicio (iOS 16.4 o posterior).
- En un computador, el aviso llega mientras el navegador esté en marcha, aunque no haya ninguna pestaña de Borealis. Si el navegador está totalmente cerrado, llega al abrirlo, siempre dentro de las 24 horas de validez del mensaje.

## 4. Pruebas

Los resultados reales están en `docs/PRUEBAS.md` y en el resumen de la entrega. Se probó con servidores reales:
- **Integración** (HTTP + MariaDB): nuevas pruebas en `tests/integration/v116.php`. Cubren `/cron/ping`, los límites de frecuencia, las preferencias por defecto y su paso desde 1.15, la asignación y el cambio de fecha que llegan cifrados al «teléfono» con la sesión terminada, y que nada se repite.
- **Navegador** (Chromium): aviso visible encima de una confirmación abierta, service worker activo y `/cron/ping`.
- **Servidores reales sin cron ni sesión** (`tests/fpm/engine.sh`): Nginx + PHP-FPM y **Apache + mod_php** (modo compatible). Una sola visita anónima, una tarea escrita directamente en la base y el aviso llega al teléfono sin ninguna otra petición. Después se corta el motor y un `/cron/ping` lo reanuda.

No se pudo probar: un teléfono real con el servicio de Google o Apple (se usa un servicio de notificaciones simulado que descifra igual que un navegador), un servidor LiteSpeed real (se usa la función estándar `litespeed_finish_request`) ni el alojamiento de producción.
