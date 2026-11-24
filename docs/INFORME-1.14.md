# Informe: Borealis 1.14.0 (gestión, monitoreo y trazabilidad)

## Punto de partida

Se partió de la 1.13.0 entregada el 17/11/2026. Todo lo que ya existía se reutilizó:
- capacidad semanal y por sprint;
- alerta de capacidad agotada (el caso de las 15 h);
- reglas de Monitoreo;
- historial de fechas límite;
- Análisis;
- integración con Google Calendar y Google Tasks;
- Web Push con VAPID.

Nada se reconstruyó desde cero. La migración `016_v114` solo añade tablas, columnas e índices y copia datos; no borra nada. No hace falta ningún servicio externo nuevo: sigue siendo PHP y MySQL/MariaDB en HestiaCP.

**Repositorio:** no había un repositorio de Borealis en GitHub al que esta sesión tuviera acceso. El trabajo se entrega como ZIP. Está listo para subirse como rama y PR en cuanto exista el repositorio (los pasos están al final).

## 1. Por qué no llegaban las notificaciones con Borealis cerrado

Se siguió el camino completo de un recordatorio en el código de la 1.13.

**Causa principal: dependía de un cron que no daba señales.** Solo dos cosas creaban los recordatorios («vence mañana», «vence en 30 minutos», «vencida»):
1. la visita de la propia persona (`Push::later` al cargar páginas);
2. `bin/notify.php` ejecutado por cron.

Sin ese cron funcionando, el aviso aparecía cuando la persona abría Borealis, que es justo el síntoma reportado. Hay tres motivos para pensar que el cron no corría:
- la documentación recomendaba `>/dev/null 2>&1`, que oculta cualquier error;
- en HestiaCP, `php` en el cron suele ser otra versión de PHP (sin `pdo_mysql`), distinta de la del sitio;
- nada en Borealis mostraba si el cron se ejecutaba.

**Agravantes encontrados:**
1. **La preferencia oculta `notify.push` quedaba apagada.** Pasaba si el perfil se guardaba antes de pulsar «Activar», o si el guardado fallaba. A partir de ahí, todos los avisos se descartaban en silencio, aunque el dispositivo estuviera suscrito.
2. **Un fallo pasajero perdía el aviso para siempre.** Si el servicio del navegador (FCM, Mozilla, Apple) daba un error temporal (sin red, 429 o 5xx), el aviso ya estaba marcado como enviado.
3. **La suscripción no se renovaba.** Si el navegador la cambiaba, el dispositivo dejaba de recibir avisos sin que nadie lo supiera.

**Lo que no era la causa:** las sesiones. El aviso cifrado ya lleva su contenido y el service worker no necesita sesión para mostrarlo.

**Qué se hizo** (detalle de configuración en `docs/CONFIGURACION-1.14.md`):
- **Una sola tarea programada** (`Scheduler`), con tres formas de arrancarla:
  - `bin/notify.php`, como antes;
  - la dirección `/cron/run?token=…`, para alojamientos que solo permiten llamar a una URL;
  - un respaldo tras cualquier visita con PHP-FPM (sin esperas para quien visita).
- **Registro de cada ejecución** en `system_jobs`. Administración › Notificaciones, nueva, dice si funciona, desde cuándo no corre y cuál fue el último error, y da el comando exacto para HestiaCP con la versión de PHP del sitio. El perfil también avisa si no corre.
- **`bin/notify.php` avisa de lo que falta:** si a su PHP le faltan extensiones, lo dice y sugiere `/usr/bin/php8.x`.
- **Un registro por aviso y dispositivo** (`notification_deliveries`):
  - nunca se envía dos veces;
  - un error pasajero se reintenta a los 5, 15, 60 y 180 minutos;
  - un error permanente queda anotado con un motivo claro (por ejemplo, VAPID 401/403);
  - no se reenvía lo que ya se leyó, lo completado ni lo de hace más de 24 h.
- **El envío al dispositivo viene activado** para las personas nuevas, y activar un dispositivo lo enciende. La migración lo repara para quien tenía un dispositivo con la preferencia apagada. Quien lo apague después a propósito lo mantiene apagado.
- **Renovación sin sesión:** el service worker renueva la suscripción cuando el navegador la cambia (`pushsubscriptionchange`). Solo vale si demuestra que conoce la suscripción anterior.
- **Permiso del navegador:** el inicio propone darlo una vez. El perfil sigue teniendo el botón, y se mantiene la petición de permiso solo tras un gesto de la persona, como exigen los navegadores.
- **Google Calendar y Google Tasks:** la integración no cambió y corre en la misma tarea.

**Lo que no se puede comprobar desde aquí:**
- **El servidor real.** Hace falta que alguien con acceso a HestiaCP cree o corrija el cron, o use la dirección `/cron/run`. Después basta con mirar Administración › Notificaciones.
- **Los servicios reales de los navegadores.** Las pruebas usan un servicio de notificaciones simulado que descifra y verifica lo recibido. Eso prueba el lado de Borealis, pero no que Google, Mozilla o Apple acepten el envío.

## 2 a 13. Cada sección del pedido

| # | Sección | Qué se hizo | Por qué / límites |
|---|---|---|---|
| 1 | Notificaciones con la app cerrada | **Seguido.** Ver arriba. | Las preferencias internas siguen activadas por defecto. El resumen semanal se mantiene como algo que cada persona elige (no se activó para todos), porque cambiaría lo que ya reciben. |
| 2 | Crear proyectos más simple | **Seguido.** Solo se exige el nombre; las siglas se sugieren solas. Responsable, entrega («Esta semana» / «Este mes») e integrantes se eligen en el mismo diálogo. El objetivo, el equipo, el color y el estado quedan en «Más opciones». El responsable entra como gestor y cada integrante recibe el aviso. | |
| 3 | Crear y asignar tareas más rápido | **Seguido.** «Crear y añadir otra» deja el diálogo abierto conservando proyecto y responsable. Al elegir responsable se ve su carga de la semana (horas usadas y planificadas frente a disponibles). Se puede marcar inicio, hito e imprevisto, y elegir «Trabajos rápidos (sin proyecto)». | **No se hizo** el pegado masivo de muchas tareas a la vez: el diálogo que se queda abierto cubre el caso de «varias seguidas» sin otro formulario. |
| 4 | Estimación de horas del trabajador | **Seguido.** La ficha muestra administración, trabajador, acordado, real y diferencia. Quien es responsable propone su estimación; si cambia lo acordado, el motivo es obligatorio, queda «por revisar» y se avisa a quien gestiona. Esa persona acuerda el valor y se avisa al trabajador. Todo queda en `task_estimates` y en la auditoría. | «Acordado» es el `estimate_hours` de siempre, así que capacidad, sprints, Monitoreo, Análisis y Gantt no cambian de fuente. |
| 5 | Detectar desvíos y avisar | **Seguido.** Dos reglas nuevas de nivel 2 para quien gestiona: «superó el tiempo acordado» y «ampliación importante de la estimación» (25 % por defecto, configurable entre 5 y 500 %). Ya existían: vencidas, capacidad agotada con trabajo pendiente (las 15 h, también con trabajos rápidos), dependencias en riesgo y sobrecarga. | Los textos describen la situación («15 h registradas de 13 h acordadas») y nunca califican a la persona. La regla de dependencias se mantiene en nivel 1 con su escalado por antigüedad de la 1.12, para no avisar a la administración de cada retraso encadenado. |
| 6 | Carta Gantt automática | **Seguido.** Muestra tareas y subtareas, responsables, dependencias (las que están en conflicto, marcadas), hitos, sprints, avance, días de retraso, veces reprogramada y plan original frente a actual. Escalas por días, semanas y meses. Se arma en cada visita, sin datos duplicados. Quien gestiona puede «fijar las fechas actuales como planificación original», y el cambio queda registrado. | Es un módulo de proyecto **activado por defecto** (se puede desactivar). **No se pueden arrastrar barras:** las fechas se cambian en la tarea, que ya pide el motivo y lo registra. Un arrastre dejaría cambios sin motivo. |
| 7 | Historial de cambios del calendario | **Seguido.** Cubre creación, cambio de fecha, reprogramación, participantes, cancelación y restauración de eventos; cambios de fecha y de responsable de tareas con motivo; nueva planificación original; y la sincronización con Google. Guarda quién, cuándo, antes, después y origen (web, Google, sistema). | De Google solo se registran **cuántos** elementos cambió cada sincronización, no los títulos de los calendarios personales: es información privada de cada persona. |
| 8 | Registro global de actividad y auditoría | **Seguido.** Cada registro tiene categoría, antes, después, motivo, origen y proyecto. Filtros por persona, proyecto, categoría, acción y período (semana, mes, trimestre, año o fechas), y CSV. Una cadena de huellas por espacio detecta cualquier modificación, eliminación o huella borrada hecha fuera de Borealis («Comprobar integridad» indica desde qué registro). Borealis no ofrece forma de editar ni borrar registros. Contraseñas, tokens, claves, webhooks y datos de dispositivos se ocultan **antes** de guardarse. | **Detecta, no impide:** quien tenga acceso directo a la base puede cambiar filas, pero queda a la vista. Impedirlo del todo exige permisos de MySQL (ver «Lo que necesita el servidor»). Los registros anteriores a la 1.14 no tienen huella y se cuentan aparte. |
| 9 | Trabajos rápidos y semanales | **Seguido.** Página «Trabajos rápidos»: título, para quién, horas, fecha y tipo (solicitud imprevista, urgente, administrativo, de la semana, corto, otro). Son tareas normales de un espacio especial que no aparece entre los proyectos ni necesita configuración. Se asignan, registran horas y cuentan en la carga, Mi trabajo, el calendario, Monitoreo, Análisis y la auditoría. | Se asigna a otras personas solo si se comparte algún proyecto con ellas (la administración, a cualquiera). Los invitados solo crean los suyos. |
| 10 | Análisis histórico | **Seguido.** Semana, mes, trimestre, año y 12 meses. La tarjeta «¿Qué ocurrió en el período?» muestra proyectos iniciados y finalizados; trabajos creados, finalizados, pendientes y vencidos; horas registradas, imprevistas y en trabajos rápidos; acordado frente a real; y horas por proyecto. Se suma a lo de la 1.13: reprogramaciones, bloqueos, carga, tendencias. Filtros por proyecto, equipo, sprint, persona y período. | El filtro **por persona** es solo para la administración o para quien gestiona personas. La 1.12 y la 1.13 prohíben comparar o puntuar personas, y un integrante no debe ver el historial de otro. |
| 11 | Integración entre funciones | **Seguido.** Hay una sola fuente para cada dato: las horas acordadas (`estimate_hours`), las horas reales (`time_entries`), el historial de fechas (`task_due_changes`) y la actividad (`activity`). Trabajos rápidos son tareas normales, y la carta Gantt y Análisis leen de las mismas tablas. | |
| 12 | Experiencia de uso | **Seguido.** Se usan los mismos componentes y colores de los temas existentes (claro, oscuro y los de la 1.x). La carta Gantt se desplaza horizontalmente en el móvil y lleva al día de hoy. Los formularios nuevos se apilan en pantallas estrechas. | |
| 13 | Repositorio, rama, PR | **No se pudo:** no existe el repositorio. | Lo demás de esta sección sí está: migración repetible, compatibilidad, datos conservados, HestiaCP, pruebas, documentación, versión 1.14.0 y CHANGELOG. |

## Lo que necesita el servidor (no se puede hacer desde el código)

1. **Tarea programada.** Elige una de dos (pasos en `docs/CONFIGURACION-1.14.md`):
   - un cron cada 5 minutos con la versión de PHP del sitio;
   - la dirección `/cron/run?token=…`.

   Sin ninguna de las dos, el respaldo por visitas funciona con PHP-FPM, pero de noche sin visitas los avisos esperan a la primera.
2. **Opcional, impedir cambios al registro de actividad.** La aplicación nunca modifica ni borra filas de `activity`; solo escribe la huella justo al crearlas. Para impedirlo a nivel de base de datos haría falta un usuario de MySQL con permisos más finos que los que HestiaCP da por defecto. Es opcional, y la detección funciona sin esto.
3. **Nada más.** No hacen falta servicios externos, claves de terceros ni cambios en Google Cloud.

## Pruebas

Los resultados están en `docs/PRUEBAS.md`.
- **Integración:** `tests/integration/v114.php` cubre:
  - la migración y su repetición;
  - la tarea programada por consola, por dirección (con clave, sin clave y con una clave cambiada), por visita y su estado;
  - envíos con fallo pasajero, reintento y error permanente, sin duplicados;
  - la renovación de la suscripción;
  - las preferencias;
  - auditoría: categorías, antes y después, filtros, CSV, permisos, integridad ante modificación, eliminación y huella borrada, y que no quede ningún secreto;
  - el historial del calendario;
  - estimaciones: proponer, motivo, permisos, acordar, avisos y alertas;
  - trabajos rápidos y la carga al asignar;
  - la creación de proyectos;
  - la carta Gantt: contenido, escalas, actualización, línea base y permisos;
  - el análisis por período;
  - la actualización desde la 1.13.
- **Navegador:** `tests/e2e/run.mjs` recorre lo anterior con capturas en `docs/capturas`.

## Si se crea el repositorio

1. Crea un repositorio vacío en GitHub (por ejemplo, `borealis`).
2. Se sube la 1.13.0 como `main`.
3. Se sube la 1.14 en una rama `release/1.14.0` con commits por tema y se abre el PR.

El código ya está separado así:
- notificaciones;
- auditoría;
- estimaciones y Monitoreo;
- trabajos rápidos, proyectos y tareas;
- carta Gantt;
- Análisis;
- pruebas y documentación.
