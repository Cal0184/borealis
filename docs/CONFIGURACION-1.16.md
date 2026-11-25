# Borealis 1.16 · Avisos con Borealis cerrado en un alojamiento sin SSH ni cron

Esta guía es para un servidor que solo se administra por **FTP (FileZilla)** y con el administrador de la base de datos: sin SSH, sin terminal, sin acceso a la configuración del servidor y sin tareas cron. Reemplaza la parte de HestiaCP de `CONFIGURACION-1.14.md` y `CONFIGURACION-1.15.md`.

## 1. Qué funciona solo con PHP, JavaScript y MySQL (sin configurar nada)

| Aviso | Cómo llega con Borealis cerrado | Necesita algo externo |
|---|---|---|
| Asignaciones, cambios de fecha y de estado, comentarios, menciones, tareas que sigues, automatizaciones | Se envía al dispositivo **en el mismo momento** en que otra persona hace el cambio (Web Push, cifrado de extremo a extremo con VAPID). | No |
| Alertas de monitoreo y de capacidad (incluida la escalada a la administración) | Las crea la tarea programada; ver la fila siguiente. | No, pero ver la sección 2 |
| Recordatorios por hora: por vencer, vencidas (retrasos), reuniones, sprints, resumen semanal | Los crea la **tarea programada**. Borealis la mantiene funcionando por su cuenta con tres mecanismos (abajo). | No, pero ver la sección 2 |

La tarea programada se mantiene en marcha sola mediante tres mecanismos:

1. **El motor interno.** Cada 60 segundos Borealis se llama a sí mismo en `POST /cron/tick`, protegido con una clave secreta. Arranca con la primera petición que reciba el servidor, de cualquier persona y aunque no haya iniciado sesión. En 1.16 funciona con **PHP-FPM, LiteSpeed y Apache**. Hasta 1.15 solo funcionaba con PHP-FPM, y por eso en muchos alojamientos compartidos no arrancaba nunca.
2. **Cada visita y cada página abierta.** Una petición cualquiera ejecuta la tarea si lleva más de 2 minutos sin ejecutarse, siempre después de enviar la página, así que nadie espera.
3. **Los dispositivos.** Cuando un teléfono o un computador recibe un aviso, su service worker llama a `/cron/ping`, una dirección pública que no lleva datos. Eso reanuda el motor si el alojamiento lo había detenido. Si Borealis está instalado como aplicación en Chrome, Edge o Android, el navegador además lo despierta de vez en cuando (sincronización periódica) y hace lo mismo.

Nada se envía dos veces. Cada aviso se crea una sola vez y se entrega una sola vez a cada dispositivo (claves únicas en `notifications.dedupe_key` y `notification_deliveries`). La tarea nunca se ejecuta dos veces al mismo tiempo, porque usa un archivo de bloqueo. Si el servicio de notificaciones falla de forma pasajera, el envío se reintenta a los 5, 15, 60 y 180 minutos.

La sesión no hace falta. El dispositivo queda vinculado a la persona, no a su sesión, y el aviso viaja cifrado y completo: el teléfono lo muestra sin pedir nada a Borealis. Que la sesión caduque o que la persona cierre sesión no interrumpe los avisos. Para dejar de recibirlos en un dispositivo hay que pulsar **Desactivar en este dispositivo** o quitar el permiso en el navegador.

## 2. Lo que requiere infraestructura externa (opcional, gratis, 5 minutos)

Hay un límite que ningún programa PHP puede saltarse: si el alojamiento reinicia el servidor o corta los procesos largos, el motor interno se detiene. Entonces espera hasta la siguiente petición, que puede ser una visita, una página abierta o un aviso recibido por un dispositivo. Con el equipo trabajando a diario esto casi no se nota. De noche o en fin de semana, en cambio, un recordatorio de «vence en 30 minutos» podría llegar tarde.

Para que los recordatorios lleguen **a su hora siempre**, sin depender de nadie, conviene usar un servicio externo que visite una dirección secreta de Borealis cada 5 minutos. No se instala nada en el servidor:

1. Entra en Borealis como administración de la instalación › **Administración › Notificaciones** y copia la dirección del recuadro **Servicio externo**. Tiene la forma `https://tu-dominio/…/cron/run?token=…`.
2. Crea una cuenta gratuita en **cron-job.org**.
3. Ve a **Create cronjob**, pega la dirección en **URL**, elige **Every 5 minutes** en **Execution schedule** y guarda.
4. Vuelve a Administración › Notificaciones: tras unos minutos debe decir «Última ejecución … desde cron por dirección (/cron/run)».

También sirven UptimeRobot (monitor HTTP cada 5 minutos) o cualquier servicio que visite direcciones.

En cuanto a la seguridad, la dirección solo **inicia** la tarea: no devuelve datos ni permite ver nada. Se compara en tiempo constante, y dos llamadas con menos de un minuto de diferencia no la ejecutan dos veces. Si se filtra, pulsa **Generar otra** y actualízala en el servicio.

Cuando el servicio externo funciona, el motor interno se pone en reposo solo, y vuelve a arrancar solo si el servicio deja de llamar.

## 3. Lo que hace cada persona (una vez por dispositivo)

- **Computador (Chrome, Edge, Firefox, Safari en macOS 13 o posterior):** pulsa **Activar** en el aviso que aparece arriba en cualquier página (o en Perfil › Notificaciones) y acepta el permiso del navegador. Los avisos llegan aunque Borealis esté cerrado mientras el navegador esté abierto, incluso en segundo plano. En Windows y macOS, Chrome y Edge pueden recibirlos también con todas las ventanas cerradas si en su configuración está activada la opción «Seguir ejecutando aplicaciones en segundo plano».
- **Android:** igual, desde Chrome. Si se instala Borealis como aplicación (menú › **Instalar aplicación**), además funciona la sincronización periódica.
- **iPhone/iPad (iOS 16.4 o posterior):** Safari › Compartir › **Añadir a pantalla de inicio**, abre Borealis desde ese icono y pulsa **Activar**. Apple no permite notificaciones web desde una pestaña normal de Safari.
- Todas las categorías vienen activadas. El navegador **siempre** pide permiso la primera vez: es una regla de los navegadores y no se puede saltar. Si la persona pulsa «Ahora no», la invitación vuelve a aparecer a la semana.

## 4. Requisitos del servidor (ya los cumplen casi todos los alojamientos)

- HTTPS (las notificaciones web no funcionan con `http://`).
- PHP 8.2 o superior con las extensiones `openssl` (con curvas EC) y `curl`. Administración › Notificaciones avisa si falta alguna.
- Que el servidor pueda abrir su propia dirección. Si no puede, el motor interno lo indica en Administración y basta con el servicio externo de la sección 2.
- Permisos de escritura en `storage/` (claves VAPID, clave de la tarea y estado del motor).

## 5. Ajustes opcionales en `config/config.php`

```php
'cron' => [
    'heartbeat' => true,           // false apaga el motor interno (por ejemplo, si ya usas un servicio externo)
    'heartbeat_seconds' => 60,     // intervalo del motor (1–300)
    'heartbeat_url' => '',         // otra dirección para llamarse a sí mismo, si el dominio público no se alcanza desde el servidor
    'web_fallback' => true,        // false: las visitas y /cron/ping no ejecutan la tarea
],
```

## 6. Actualizar desde 1.15 / 1.15.1 por FileZilla

1. Haz una copia de la carpeta actual y de la base de datos (Administración › Copias de seguridad, o exportándola desde el administrador de la base).
2. Sube el contenido del ZIP **sin** borrar ni reemplazar `config/config.php` ni la carpeta `storage/` del servidor. Esa carpeta guarda las claves de notificaciones: si se reemplazara, los dispositivos tendrían que activarse otra vez.
3. Entra como administración. La 1.16 no tiene migraciones nuevas.
4. Abre Administración › Notificaciones y revisa «Servidor» y el estado del motor.

## 7. «Se entregó» pero no lo vi (1.16.1)

1. **Perfil › Notificaciones › «Dispositivos que reciben tus avisos»:** revisa que aparezca el navegador y el equipo donde esperas el aviso, con una «última entrega» reciente. Si figura un navegador que ya no usas, el aviso se entrega allí.
2. **«Probar con Borealis cerrado (30 s)»:** pulsa el botón y cierra todas las pestañas de Borealis, sin cerrar el navegador. A los 30 segundos debe aparecer el aviso del sistema.
3. **Si no aparece:**
   - **Windows:** Configuración › Sistema › Notificaciones. Activa Google Chrome o Microsoft Edge (o Borealis, si lo instalaste como aplicación) y revisa que estén permitidos los banners y el sonido. Desactiva «No molestar» o el Asistente de concentración, o añade el navegador a sus prioridades.
   - **Chrome:** en `chrome://settings/content/notifications`, tu dominio debe estar en «Pueden enviar notificaciones». En Configuración › Sistema conviene activar «Seguir ejecutando aplicaciones en segundo plano al cerrar Google Chrome»: así llegan incluso con todas las ventanas cerradas.
   - **Edge:** en `edge://settings/content/notifications`, lo mismo. En Sistema y rendimiento, activa «Seguir ejecutando extensiones y aplicaciones en segundo plano».
4. **Sonido:** Perfil › Notificaciones › «Con sonido». El sonido es el de notificaciones del sistema; en Windows se cambia o se activa en Configuración › Sistema › Notificaciones › (navegador) › «Reproducir un sonido».
