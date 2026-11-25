# Cambios

## 1.16.2 (25/11/2026)

**Correo sin editar archivos por FTP.** Hasta ahora, activar los correos exigía editar la sección `mail` de `config/config.php`, y sin SSH eso significa descargar el archivo, modificarlo y volver a subirlo.

**Configuración desde Administración › Datos › Correo:**
- **Formulario:** proveedor (buzón del dominio, Gmail, Microsoft 365, Zoho, Brevo), servidor, puerto, usuario, contraseña y remitente.
- **Contraseña:** se guarda cifrada en `storage/mail.json` y nunca se muestra.
- **Prueba:** «Guardar y probar» envía al instante un correo de prueba y dice el motivo si no sale.
- **Estado:** se ve el último envío correcto y el último error.
- **Desactivar:** se puede desde la misma pantalla.
- **Compatibilidad:** una sección `mail` en `config/config.php` sigue teniendo prioridad.

**Envío al instante.** Asignaciones, cambios, comentarios y alertas salen por correo en el momento en que ocurren, no solo cuando corre la tarea programada (antes, además, había una espera de 2 minutos). Los recordatorios por hora siguen saliendo con la tarea programada, y nada se envía dos veces.

**Todas las categorías por correo por defecto.** Antes solo estaban activadas las asignaciones, los vencimientos, los sprints y la capacidad. Quien tenía las preferencias antiguas sin cambiar pasa a las nuevas. Cada persona puede apagar lo que no quiera en Perfil › Notificaciones.

## 1.16.1 (25/11/2026)

Sin migraciones. Va en el mismo PR que la 1.16.0.

**Un aviso que figura como «entregado» pero no aparece.** «Entregado» significa que el servicio del navegador (Google, Microsoft, Mozilla o Apple) aceptó el aviso. Lo muestra el navegador del dispositivo cuando está en marcha. En Windows, el Asistente de concentración o «No molestar» lo guarda en el centro de notificaciones, sin mostrarlo ni sonar. Para ver dónde se corta:
- **Perfil › Notificaciones:** lista cada dispositivo con la fecha de activación, la última entrega y el último problema. Antes solo se veía cuántos había.
- **«Probar con Borealis cerrado (30 s)»:** el servidor responde enseguida y envía la prueba 30 segundos después, así se puede cerrar todo y comprobar que llega desde el sistema.
- **Más robusto:** si el navegador rechaza una opción de la notificación, el service worker la muestra igual, en versión simple.

**Sonido y avisos que se quedan en pantalla** (Perfil › Notificaciones, ambos activados por defecto):
- **«Con sonido»:** el aviso suena con el sonido de notificaciones del dispositivo y vibra en Android. Un aviso que reemplaza a otro vuelve a sonar. Con Borealis abierto y sin notificaciones del sistema, suena además un tono corto dentro de la página. Los navegadores no permiten un sonido propio en las notificaciones del sistema, así que se usa el del dispositivo.
- **Asignaciones, vencimientos, vencidas y alertas de monitoreo:** se quedan en pantalla hasta cerrarlos (`requireInteraction`), en Windows, macOS, Linux y ChromeOS.

## 1.16.0 (25/11/2026)

Avisos con Borealis cerrado en un alojamiento administrado solo por FTP, sin SSH ni cron. No tiene migraciones nuevas. Guía: `docs/CONFIGURACION-1.16.md`. Auditoría y causas: `docs/INFORME-1.16.md`.

**Causa principal corregida: el trabajo «después de responder» solo existía con PHP-FPM.** El motor interno (1.15.1), el respaldo por visitas (1.14) y el envío inmediato dependían de `fastcgi_finish_request()`. En alojamientos con LiteSpeed o Apache esa función no existe, así que el motor y el respaldo quedaban apagados y los recordatorios solo se creaban cuando alguien volvía a entrar.
- **`app/Core/Background.php`:** cierra la respuesta y sigue trabajando con PHP-FPM, LiteSpeed (`litespeed_finish_request`) o Apache/otros (modo compatible: `Content-Length`, `Connection: close` e `ignore_user_abort`).
- **Motor interno y respaldo por visitas:** activos en los tres tipos de servidor. El motor reintenta una vez antes de detenerse, y considera aceptado un latido en cuanto recibe «202», aunque el servidor retenga el resto de la respuesta.
- **Prueba real:** con Apache + mod_php además de Nginx + PHP-FPM (`SERVER=apache tests/fpm/engine.sh`).

**Los dispositivos mantienen vivo el motor.**
- **`/cron/ping`:** dirección pública, sin sesión ni datos. Responde 204 y, como mucho, arranca el motor (una vez por minuto) y la tarea (una vez cada 2 minutos, nunca dos a la vez).
- **Service worker:** llama a `/cron/ping` al recibir un aviso, al abrirlo y en la sincronización periódica del navegador (Chrome, Edge y Android con Borealis instalado). Si el alojamiento corta el motor, el siguiente aviso que llegue a cualquier dispositivo lo reanuda.
- **Visitas:** una visita ejecuta la tarea si lleva más de 2 minutos sin ejecutarse (antes, 5).

**Preferencias activadas por defecto.**
- **Categorías:** todas llegan al dispositivo. Antes, cambios en proyectos, comentarios, capacidad y tareas que sigues había que activarlas a mano.
- **Preferencias guardadas antes de 1.16:** quien no había cambiado nada pasa a las nuevas. Lo que alguien apagó a propósito se respeta.
- **Horario de silencio:** apagado hasta que la persona lo elija. Antes venía activado de 22:00 a 07:00 y retenía los avisos de la noche.
- **Permiso ya dado:** si el navegador ya lo concedió, el dispositivo se vuelve a vincular solo, salvo que la persona lo desactivara en ese dispositivo.
- **Invitación a activar:** aparece en todas las páginas, no solo en Inicio. «Ahora no» la oculta una semana, no para siempre.

**Confirmaciones abiertas.** Un `<dialog>` modal ocupa la capa superior del navegador, así que los avisos dentro de la página quedaban escondidos detrás de cualquier confirmación o formulario abierto. Ahora la caja de avisos es un *popover* y se muestra encima. El aviso de «sesión terminada» también se ve con un diálogo abierto.

**Administración › Notificaciones.**
- **Servidor detectado:** PHP-FPM, LiteSpeed o Apache.
- **Qué funciona sin acceso al servidor y qué no.**
- **Pasos para un servicio externo gratuito** (cron-job.org) con la dirección secreta `/cron/run`. Reemplaza las instrucciones de HestiaCP, que quedan como opción para paneles con «Tareas Cron».

## 1.15.1 (24/11/2026)

Se entrega junto con la 1.15.0, en el mismo PR. No tiene migraciones nuevas.

**Avisos sin cron y con Borealis cerrado: motor interno.** Sin una tarea programada en el servidor, los recordatorios solo se creaban mientras alguien tenía Borealis abierto. Ahora Borealis mantiene su propio motor (`app/Services/Heartbeat.php`):
- **Arranque:** arranca con cualquier visita, incluso anónima.
- **Funcionamiento:** cada 60 s se llama a sí mismo en `POST /cron/tick`, protegido con la clave de `/cron/run`, y ejecuta la tarea programada. Funciona sin sesión iniciada y sin pestañas abiertas.
- **Seguridad:** hay una sola cadena de latidos a la vez, y el motor se detiene solo cuando hay un cron real.
- **Estado:** visible en Administración › Notificaciones.
- **Prueba real:** con Nginx + PHP-FPM (`tests/fpm/engine.sh`). Requisitos y límites en `docs/CONFIGURACION-1.15.md`.

**Perfil (rediseño).**
- **Cabecera:** foto, nombre, cargo, rol, correo, mención y horas semanales.
- **Índice de secciones:** a la izquierda en escritorio y fijo arriba en el móvil. Sigue el desplazamiento y lleva a Datos personales, Apariencia, Notificaciones, Conexiones, Seguridad y Ayuda.
- **Una sola columna** de ancho cómodo.
- **Datos personales:** formulario en dos columnas y colores de iniciales como muestras.
- **Apariencia:** incluye ahora las animaciones, que antes estaban en una tarjeta suelta, «Preferencias».
- **Notificaciones:** las categorías se ven en cuadrícula, cada una marcada al estar activa.
- **Menú de la cuenta:** arriba a la derecha ya no repite «Apariencia», porque llevaba a la misma página que «Mi perfil».

**Ficha de la tarea (rediseño).**
- **Panel lateral:** más ancho en pantallas grandes (hasta 1080 px) y título más grande.
- **Secciones:** subtareas, relaciones, archivos y enlaces quedan en un solo bloque con separadores. El área de archivos ocupa una línea y los dos enlaces de trabajo van lado a lado.
- **Descripción vacía:** se ve como una caja discreta, no como un hueco grande.
- **Actividad en pestañas:** Comentarios, Horas (con las estimaciones), Seguimiento e Historial. Un punto rojo avisa cuando el seguimiento necesita atención o hay una estimación por revisar. La pestaña elegida se mantiene al guardar y al abrir otra tarea.
- **Propiedades:** la fecha y la hora de entrega quedan en la misma línea, y «Sugerencia según el historial» se alinea con los valores.

## 1.15.0 (24/11/2026)

**Refinamiento funcional, auditoría social y experiencia de uso.** El detalle, la investigación de Atlassian y la evaluación heurística están en `docs/INFORME-1.15.md`. La configuración está en `docs/CONFIGURACION-1.15.md`: no hace falta nada nuevo, solo la migración 017.

**Notificaciones con confirmaciones de salida (causa real).** Se sumaban cinco causas:
- la campana solo cambiaba al cargar una página;
- `window.confirm()` congelaba la página;
- el service worker navegaba la ventana aunque tuviera cambios sin guardar;
- sin cron, quien se quedaba en un formulario no disparaba el respaldo por visitas;
- una sesión vencida llevaba al inicio de sesión y se perdía lo escrito.

Cambios:
- **Campana en vivo:** `notify-live.js` consulta `/api/notifications/status` cada minuto, al volver a la pestaña, al recuperar la red y al llegar un push.
- **Confirmaciones:** todas usan el diálogo propio, que no bloquea la página.
- **Avisos del sistema:** el service worker pregunta a la página antes de seguir un aviso; si hay cambios sin guardar, abre otra pestaña.
- **Sesión:** la consulta en segundo plano no alarga la sesión. Si la sesión termina, aparece un aviso y el formulario se queda.
- **Protección contra la pérdida de cambios:** se mantiene, con el diálogo propio, y `beforeunload` sigue solo para cerrar la pestaña.

**Registro maestro.** `/admin/audit` reúne todo:
- **Lo nuevo que registra:** inicio y cierre de sesión e intentos rechazados (con la red abreviada); errores del servidor sin traza; avisos que no se pudieron entregar; resumen diario de avisos; fallos de la tarea programada; automatizaciones y decisiones.
- **Resultado:** columna nueva (hecho, error, rechazado) que entra en la huella solo cuando existe, así que las cadenas de la 1.14 siguen válidas.
- **Consulta:** línea de tiempo por día, semana o mes; colores por tipo (trabajo, administración, automático, errores); filtros por tipo, resultado y tarea; enlace al elemento; páginas por n.º.
- **Exportación:** JSON con las huellas, y CSV con columnas nuevas.
- **Conservación opcional:** `bin/audit-archive.php` archiva sin romper la comprobación de integridad.

**Barra lateral.**
- Cuatro áreas plegables según el rol: Mi espacio, Organización, Seguimiento y Administración.
- Modo compacto, favoritos (estrella en proyectos y tareas) y barra inferior en el móvil.
- La búsqueda muestra favoritos y lo abierto hace poco. Las preferencias se guardan en el perfil.

**Tareas.**
- **Pestaña Equipo:** tabla, lista compacta o tablero. Se agrupa por proyecto, responsable, estado o fecha, y se filtra por responsable, fecha, prioridad, bloqueo y texto.
- **Vistas guardadas:** personales o compartidas. La vista elegida se recuerda. Las páginas son de 100.
- **Detalle:** cabecera con Seguir y Favorito; propiedades con horas reales y progreso; contenido en el orden descripción, subtareas, dependencias, archivos, comentarios, horas e historial.

**Colaboración.**
- **Seguir una tarea:** llegan sus comentarios y cambios de estado, sin duplicar los avisos de responsables. Por defecto no llegan al móvil.
- **Decisiones:** un comentario se marca como decisión; queda en el registro y en el resumen del proyecto.

**Automatizaciones** (Administración › Automatizaciones). Siete plantillas:
- tarea vencida;
- bloqueo resuelto;
- superó las horas acordadas;
- proyecto terminado;
- prioridades de la semana;
- informe al cerrar el sprint;
- bloqueo prolongado.

Solo envían avisos: no hay bucles. Cada acción tiene una clave única, así que nunca se repite. Hay un tope por ejecución, permisos de administración y registro de cada ejecución.

**Monitoreo.** Quien trabaja ve una nota que dice quién más puede ver sus datos de carga. Borealis no mide tiempo en pantalla.

**Migración `017_v115`:** solo agrega columnas e índices (`activity.result`, `audit_chain.base_id` y `base_hash`, `comments.is_decision`) y seis tablas (`user_favorites`, `user_recent`, `saved_views`, `task_watchers`, `automation_rules`, `automation_runs`).

## 1.14.0 (24/11/2026)

**Gestión, monitoreo y trazabilidad.** El detalle de lo que se hizo, lo que no y por qué está en `docs/INFORME-1.14.md`. La configuración de la tarea programada está en `docs/CONFIGURACION-1.14.md`.

**Notificaciones con Borealis cerrado.** Causa encontrada: los recordatorios dependían de un cron que en la práctica no corría o fallaba en silencio. Se recomendaba `>/dev/null` y en HestiaCP `php` puede ser otra versión. Además se sumaban tres errores:
- la preferencia oculta «push» quedaba apagada para muchas personas;
- un fallo pasajero del servicio del navegador perdía el aviso;
- nadie veía si el cron funcionaba.

Las sesiones no eran la causa: el aviso cifrado ya lleva su contenido. Cambios:
- **Tarea programada (`Scheduler`):** la usan `bin/notify.php`, la dirección nueva `/cron/run?token=…` (para alojamientos que solo llaman a una URL) y un respaldo tras cualquier visita con PHP-FPM. Cada ejecución queda registrada en `system_jobs`. Administración › Notificaciones, nueva, dice si funciona, da el comando exacto para HestiaCP y muestra los envíos fallidos. El perfil avisa si no corre.
- **Registro por dispositivo:** cada aviso enviado a cada dispositivo queda en `notification_deliveries`, una sola vez. Los errores pasajeros se reintentan a los 5, 15, 60 y 180 minutos. Los permanentes quedan anotados con su motivo (por ejemplo, VAPID). No se reintenta lo leído, lo completado ni lo de hace más de 24 h.
- **Preferencias:** el envío al dispositivo viene activado para las personas nuevas, y activar un dispositivo lo enciende. La migración lo repara para quien tenía un dispositivo con la preferencia apagada. Se respeta a quien lo apague después.
- **Renovación y permiso:** el service worker renueva la suscripción cuando el navegador la cambia (`pushsubscriptionchange`). El inicio propone dar permiso al navegador una vez.

**Registro de actividad y auditoría.**
- **Qué guarda:** cada cambio registra categoría, antes, después, motivo y origen (web, Google, sistema).
- **Integridad:** la cadena de huellas por espacio detecta modificaciones y eliminaciones hechas fuera de Borealis («Comprobar integridad»).
- **Consulta:** filtros por persona, proyecto, categoría, acción y período (semana, mes, trimestre, año), y exportación CSV.
- **Datos sensibles:** contraseñas, tokens, claves y datos de dispositivos se ocultan antes de guardarse. No hay forma de editar ni borrar registros desde Borealis.
- **Historial del calendario:** creación, cambio de fecha, reprogramación, participantes, cancelación y restauración de eventos, cambios de fecha de tareas con motivo, sincronización de Google y nueva planificación original, con antes y después.

**Estimaciones del trabajador.**
- **Cinco cifras por tarea:** administración, trabajador, acordado, real y diferencia. «Acordado» es el `estimate_hours` de siempre, así que capacidad, sprints, Monitoreo y Análisis no cambian de fuente.
- **Cambio con motivo:** quien es responsable cambia la estimación con un motivo. Queda «por revisar» y se avisa a quien gestiona el proyecto, que acuerda el valor.
- **Historial:** cada paso queda en `task_estimates` y en la auditoría.

**Desvíos (Monitoreo).** Hay dos reglas nuevas, de nivel 2 para quien gestiona:
- «superó el tiempo acordado»;
- «ampliación importante de la estimación», con un umbral configurable que por defecto es 25 %.

Se suman a vencidas, capacidad agotada con trabajo pendiente (el caso de las 15 h), dependencias y sobrecarga. Los textos describen la situación; no califican a la persona.

**Trabajos rápidos.** Nueva página semanal para urgencias, solicitudes, tareas administrativas y trabajo de la semana sin proyecto. Son tareas normales de un espacio especial que no aparece entre los proyectos: se asignan, registran horas y cuentan en la carga, Mi trabajo, el calendario, Monitoreo y Análisis. El formulario se queda abierto para añadir varios seguidos.

**Crear proyectos y tareas más rápido.**
- **Nuevo proyecto:** basta el nombre. Responsable, entrega («Esta semana» / «Este mes») e integrantes están a la vista, y el objetivo y lo demás en «Más opciones».
- **Nueva tarea:** «Crear y añadir otra», «Trabajos rápidos (sin proyecto)», la carga de la semana del responsable al elegirlo, inicio, hito e imprevista.
- **A quién se ve:** solo se listan las personas con las que se comparte algún proyecto (la administración ve a todas).

**Carta Gantt** (módulo de proyecto activado por defecto).
- **Contenido:** tareas y subtareas, responsables, dependencias (marca las que están en conflicto), hitos, sprints, avance, retrasos, reprogramaciones, y el plan original frente al actual.
- **Uso:** escalas por días, semanas y meses. Se arma sola en cada visita. Quien gestiona puede fijar la planificación actual como la original, y queda registrado.

**Análisis por período.**
- **Períodos:** semana, mes, trimestre, año y 12 meses.
- **Filtro por persona:** solo para la administración o para quien gestiona personas.
- **Tarjeta «¿Qué ocurrió en el período?»:** proyectos iniciados y finalizados; trabajos creados, finalizados, pendientes y vencidos; horas registradas, imprevistas y en trabajos rápidos; acordado frente a real; horas por proyecto.

**Base de datos:** migración `016_v114`.
- **Qué añade:** 4 tablas, columnas nuevas e índices.
- **Datos que rellena:** copia la estimación existente a «administración» y toma la planificación original de la primera fecha registrada.
- **Qué conserva y si se repite:** no borra nada y se puede repetir.

## 1.13.0 (17/11/2026)

**Análisis y decisiones** a partir del «Prompt maestro — Fase C». El prompt pedía 1.7.0, pero esa versión ya existe; por eso es la **1.13.0**, la siguiente a 1.12.0. Se revisó contra el código (detalle en `docs/INFORME-1.13.md`). Se reutilizan Monitoreo (carga, puntualidad, alertas, umbrales), la capacidad semanal y por sprint, el compromiso de sprint guardado desde 1.12, el historial de fechas límite y el historial de actividad para los bloqueos.

**Menú «Análisis»** (después de Monitoreo). Pestañas:
- **Resumen:** indicadores del período con su fórmula, numerador y denominador; riesgos relevantes; capacidad de las próximas semanas; recomendaciones y calidad de los datos.
- **Tendencias:** series mensuales o semanales de cumplimiento, desviación, reprogramaciones y trabajo pendiente; tendencia solo con 3 períodos con datos suficientes; patrones descritos (no causas); comparación de sprints cerrados.
- **Estimaciones:** desviación por categoría y sugerencias a partir de tareas parecidas del mismo proyecto, sin valores extremos y solo con 5 o más; también en el diálogo de nueva tarea («Consultar el historial») y en la ficha.
- **Planificación:** carga frente a capacidad por semana (por persona si hay más de una), «La carga planificada supera la capacidad disponible en N horas…» con alternativas, y riesgo por sprint y proyecto con reglas y puntos explicados.
- **Escenarios:** simulador por sprint (trasladar, estimar, fechas, responsables, prioridad, capacidad, suponer desbloqueo) sin escribir en la base; aplicar con vista previa, permisos, comprobación de que nada cambió, confirmación, transacción y auditoría; revertir.
- **Informes:** informe ejecutivo por proyecto, sprint, equipo y período, imprimible y en CSV.

**Administración › Ajustes › Parámetros de análisis:** 9 parámetros con límites; cada cambio queda en la auditoría.

**Privacidad:** sin rankings ni puntuaciones de personas; los mismos permisos de visibilidad que Monitoreo; 404 para proyectos y sprints ajenos.

**Base de datos:** migración `015_v113` (solo añade 2 tablas y 3 índices). Se puede repetir. Reversión en README › Actualizaciones.

## 1.12.0 (17/11/2026)

**Monitoreo de cumplimiento y carga de trabajo** a partir del «Prompt maestro — Fase B». El prompt pedía 1.6.0, pero esa versión ya existe; por eso es la **1.12.0**, la siguiente a 1.11.0. Se revisó sección por sección contra el código (detalle en `docs/INFORME-1.12.md`): horas registradas, capacidad semanal, horas extra por semana, capacidad por sprint, la alerta «15 h usadas con trabajo pendiente», reportes con CSV, relaciones «bloquea a», el cron con bloqueo y las preferencias de avisos ya existían y se reutilizan.

**Menú «Monitoreo»** (sustituye a «Reportes» en el menú; Reportes sigue disponible desde Monitoreo). Pestañas:
- **Resumen:** 8 indicadores que llevan a sus registros (proyectos activos, pendientes, completadas, atrasadas, bloqueadas, próximas a vencer, sprints activos, compromisos en riesgo), estado calculado de cada proyecto (En curso / Atención / Crítico / Sin datos suficientes) con el porqué, alertas relevantes, próximos compromisos, carga de la semana y sprints activos.
- **Cumplimiento:** dentro del plazo, próximas a vencer, atrasadas, completadas dentro o fuera del plazo, sin fecha, bloqueadas. Son indicadores, no estados. Muestra cuántas veces cambió la fecha y el último motivo registrado. Dependencias en riesgo (sin cambiar fechas).
- **Carga de trabajo:** horas pendientes estimadas repartidas en días laborables entre el inicio y la fecha límite, frente a la capacidad semanal (+ horas extra). Por persona (4 semanas, con el detalle de tareas), por proyecto o por sprint. Clases: disponible, equilibrada, elevada, sobrecarga y «sin información suficiente». Lo que no tiene estimación o fecha se lista aparte, sin inventar horas.
- **Tiempos:** estimado frente a registrado por tarea (p. ej. 5 h y 12 h → +7 h, +140 %), sin dividir por cero, con la mediana para que un valor extremo no distorsione, y marcas «Sin estimación» y «Registro incompleto».
- **Riesgos y alertas:** motor de reglas explicadas (vencidas, importantes por vencer, sprint vencido con pendientes, sobrecarga, desviación de horas, sin estimación en el sprint, bloqueos, dependencias). Severidad informativa/advertencia/crítica, sin duplicados, se resuelven solas, «Marcar revisada» con nota, escalado nivel 1 → 2 → 3, avisos agrupados.
- **Informes:** por proyecto, sprint y período, con descarga CSV.

**En la ficha de la tarea, «Seguimiento»:** puntualidad, inicio real, bloqueada desde y por quién, estimado/registrado/desviación, horas restantes (opcional), historial de cambios de la fecha límite y motivos de retraso o desviación (7 motivos, comentario, acción correctiva, autor y fecha). Al finalizar con una desviación importante se sugiere (no se obliga) registrar el motivo.

**Horas:** cada registro se puede corregir (horas, fecha, nota) con el antes y el después en el historial; un registro idéntico enviado dos veces seguidas no se duplica.

**Dependencias:** se rechazan las circulares (también indirectas), la misma relación dos veces y las relaciones con tareas de proyectos donde no se puede editar.

**Sprints:** al iniciar se guarda el compromiso original; lo añadido después queda como «añadida»; al cerrar se guarda qué se completó, qué pasó a otro sprint y qué volvió al backlog.

**Administración › Ajustes › Umbrales de monitoreo:** días de «próxima a vencer», umbrales de carga, de desviación, de bloqueo crítico y de escalado. Se guardan en la base (no en `config/`).

**Cron:** `bin/notify.php` (el mismo de siempre, cada 5 minutos) ejecuta además las reglas, con un bloqueo por espacio y registro de errores. Sin cron, las reglas corren al abrir Monitoreo (como mucho cada 10 minutos).

**Privacidad:** sin rankings ni puntuaciones de personas; cada quien ve solo los proyectos en los que participa y la carga de quienes comparten proyecto con él; la administración ve todo.

**Base de datos:** migración `014_v112` (solo añade 4 columnas a `tasks` y 5 tablas). Se puede repetir. Reversión en README › Actualizaciones.

## 1.11.0 (17/11/2026)

Consolidación del gestor de proyectos a partir del «Prompt maestro — Fase A». El prompt pedía llamar a esta versión 1.5.0, pero 1.5.0 ya existe (alertas de capacidad y temas, octubre de 2026); por eso es la **1.11.0**, la siguiente a 1.10.0. Se revisó el prompt sección por sección contra el código: casi todo ya existía (sprints, calendarios, Mis tareas, notificaciones, correo, Google Calendar, temas, tutorial, permisos en el servidor). Solo se añadió lo que faltaba:

**Proyectos: estado «Planificado».** Los proyectos tienen ahora cuatro estados: Planificado, Activo, En pausa y Completado. «Archivado» sigue siendo aparte (archivar y restaurar ya existían y conservan el estado que tenía). Al crear un proyecto, «Más opciones › Estado» permite elegir «Planificado (aún no empieza)»; el listado filtra por ese estado.

**Tareas atrasadas, sin estado nuevo.** Una tarea abierta que pasó su fecha límite muestra «N días de retraso» junto a la fecha (tablero, Mi trabajo, Mis tareas y ficha) y conserva su estado de trabajo: puede estar «En progreso» y atrasada a la vez. No se avisa a la administración por cada retraso: el aviso de «vencida» (ya existente) llega una sola vez a quien es responsable.

**Terminadas fuera de plazo.** Al finalizar una tarea Borealis guarda la fecha límite que tenía en ese momento. La ficha dice «Finalizada el … · fuera de plazo (fecha límite original: …)» y «Mi trabajo › Finalizadas» la marca «Fuera de plazo · N días» (o «A tiempo»). Cambiar la fecha después no borra la original; reabrir la tarea la deja otra vez pendiente. La API de la tarea incluye `completed_at`, `completed_due_date` y `punctuality`; exportar e importar un proyecto la conservan.

**Mi trabajo › «Hoy».** Nueva pestaña, la primera, con lo que vence hoy. «Próximas» empieza mañana, así ninguna tarea aparece en las dos; lo vencido sigue en «Vencidas».

**Aviso al dejar de ser responsable.** Quien deja de ser responsable de una tarea recibe «Ya no eres responsable de …» (categoría «Nuevas asignaciones», un aviso por cambio, nunca a quien hizo el cambio ni de tareas finalizadas).

**Base de datos:** migración `013_v111` (añade el valor «planned» al estado del proyecto y la columna `tasks.completed_due_date`, rellenada para las tareas ya finalizadas). Solo añade; se puede repetir. Reversión en README › Actualizaciones.

## 1.10.0 (13/11/2026)

**Enlaces de trabajo en cada tarea:** la ficha de la tarea (página y panel lateral del tablero) tiene una sección «Enlaces de trabajo» con la **carpeta de Google Drive** y el **repositorio de GitHub** de esa tarea.
- Se pega el enlace y se guarda solo al salir del campo, como el resto de la ficha. Vaciar el campo lo quita.
- Botón **Copiar** junto a cada enlace (avisa «Enlace … copiado»; si el navegador no deja usar el portapapeles, por ejemplo con http sin certificado, Borealis lo copia por otra vía y, si tampoco puede, muestra el enlace para copiarlo a mano) y botón para **abrirlo en otra pestaña**.
- Se aceptan los enlaces como se suelen pegar: sin `https://`, con `http://` o `www.`, y el de GitHub «Code › SSH» (`git@github.com:org/repo.git`); se guardan siempre como `https://…`. Un enlace de otro sitio (Dropbox, GitLab…), sin repositorio, con usuario y contraseña o con código se rechaza con el motivo y vuelve el anterior.
- Quien solo puede ver la tarea ve los enlaces y puede copiarlos y abrirlos, pero no cambiarlos.
- Los cambios quedan en el historial de la tarea; duplicar una tarea copia sus enlaces; exportar e importar un proyecto los conserva; la API de la tarea los incluye (`drive_url`, `github_url`).
- Borealis no se conecta a Drive ni a GitHub para esto ni comprueba permisos: guarda y comparte el enlace. Quien lo abra necesita acceso a la carpeta o al repositorio.

**Correcciones:** el icono de GitHub de «Todo a mano» no se veía (faltaba en el juego de iconos). Al rechazarse un cambio en la ficha, el aviso ahora dice el motivo del campo («Pega el enlace de un repositorio de GitHub…», «Pon primero la fecha de entrega.») en lugar de solo «Revisa los campos marcados».

**Actualizar desde 1.9.0:** migración `012_v110` (solo añade dos columnas). Ver README › Actualizaciones.

## 1.9.0 (2026-11-11)

Tus tareas en Google Calendar, recordatorios 24 h y 30 min antes, horario de silencio, zona horaria por persona, avisos por correo y Borealis instalable como aplicación. Incluye todo lo de 1.8.1.

**Google Calendar: tu trabajo en tu calendario** (Perfil › Google Calendar)
- Conectar (OAuth 2.0 con PKCE; Borealis nunca ve la contraseña de Google) › autorizar › elegir el calendario (solo los que permiten crear eventos) › elegir qué enviar › «Empezar a enviar». Estados claros: Conectado, Sincronizando, Con errores, Error de autorización, Desconectado.
- Qué se envía: las tareas con fecha de las que eres responsable o corresponsable; si quieres, también las de tus equipos y los sprints y entregas de los proyectos en que participas.
- Cada evento: «[CLAVE-12] Título», proyecto, estado, tiempo estimado, descripción breve y enlace directo a la tarea. Con hora de entrega, un bloque que termina a esa hora y dura lo estimado (30 min si no tiene); con solo fecha, evento de todo el día. Opción «Solo título, proyecto y enlace» para calendarios compartidos.
- Una sola dirección (Borealis → Google). Cambiar la tarea actualiza el mismo evento al momento, sin duplicarlo (id estable; si una respuesta se pierde o reconectas, Borealis encuentra el evento anterior). Completar, quitar la fecha, borrar la tarea o dejar de ser responsable quita el evento (o lo deja con ✓, si lo eliges). Cambiar de calendario mueve los eventos.
- Solo se tocan eventos creados por Borealis (marca privada); el resto del calendario no se lee para esto, ni se cambia ni se borra. «Dejar de enviar» y «Desconectar y quitar sus eventos» quitan solo esos; «Desconectar» a secas los deja en Google.
- Cuota de Google, red o Google caído: Borealis sigue funcionando, guarda el motivo y reintenta con pausas crecientes (5 min a 6 h). Tokens cifrados, nunca en el navegador ni en los registros. Los mismos permisos de Google de 1.6 (no hay que volver a autorizar).

**Hora de entrega** (opcional) en las tareas, junto a la fecha: en la ficha, en «+ Nueva tarea» y en las tarjetas del tablero («12 nov · 17:30»). Se escribe en la zona de la instalación; cada persona la ve y recibe avisos en la suya.

**Notificaciones más inteligentes** (Perfil › Notificaciones)
- **Avisar antes de la entrega:** 1 semana, 3 días, 24 horas, 2 horas, 30 minutos (por defecto 24 h y 30 min). Si ya pasaron varios momentos (tarea creada tarde), llega solo el más cercano. Las tareas solo con fecha avisan «vence mañana» y «vence hoy». Sustituye a «Avisar de una tarea por vencer» de 1.8 (tu elección anterior se respeta).
- Cambiar la fecha o la hora anula los recordatorios pendientes de la anterior; completar una tarea, también. «Vencida» llega una vez.
- **Zona horaria** de cada persona (por defecto, la de la instalación) para recordatorios, silencio, correos y Google Calendar.
- **Horario de silencio** (por defecto 22:00–07:00): nada al móvil ni al correo; los avisos esperan en la campana y salen juntos al terminar.
- **Agrupación:** más de tres avisos a la vez llegan al móvil como uno («5 avisos nuevos»); por correo, siempre en un solo mensaje.
- **Resumen semanal** (apagado por defecto): el lunes por la mañana, lo que vence en la semana y lo vencido.
- **Por correo** (encendido por defecto, si la instalación lo configura): asignaciones, por vencer, vencidas, sprints, capacidad y resumen semanal; se elige por categoría. No se envía lo que ya leíste. SMTP autenticado con STARTTLS o SSL y certificado verificado, sin librerías externas; o `mail()` del servidor. Administración › Datos › Correo muestra el estado y envía un correo de prueba.
- **Alerta para la administración** de 1.5 con el texto pedido: «Juan completó sus 15 h semanales, pero mantiene una tarea planificada pendiente (discrepancia de planificación)». Una vez por persona y semana; el cron la revisa aunque nadie abra Borealis.

**Borealis como aplicación (PWA):** Perfil › «Borealis como aplicación» con botón «Instalar» (Chrome, Edge, Android) o los pasos de iPhone/iPad. Manifiesto con accesos directos (Mis tareas, Calendario, Notificaciones). Página «Sin conexión» propia; el service worker no guarda páginas ni datos de nadie.

**Cron:** `bin/notify.php` ahora también envía los correos, pone al día Google Calendar y revisa las alertas de capacidad. Recomendado cada 5 minutos (ver `docs/CONFIGURACION-1.9.md`).

**Base de datos:** migración `011_v19` (hora de entrega en tareas; columnas de Google Calendar en las cuentas de Google; tabla `google_exports`; columna `emailed_at` en notificaciones). Solo añade; se puede repetir.

**Pruebas:** dos pruebas de 1.6/1.8 cambiaron porque cambió la pantalla (la insignia dice «Conectado» y la anticipación es una lista de casillas en vez de un desplegable).

## 1.8.1 (2026-11-10)

**Fechas en día/mes/año en toda la aplicación** (pedido de Francisca Antilef). Los campos de fecha del navegador seguían el idioma del navegador y, en uno en inglés, pedían mm/dd/aaaa. Ahora todos los campos de fecha (tareas, Mis tareas, proyectos, sprints, eventos y reuniones, repetición, alertas de capacidad, Mi trabajo) muestran y aceptan **dd/mm/aaaa**, con un botón para elegir en el calendario. Al escribir solo números se añaden las barras (09112026 → 09/11/2026); se acepta también 9/11/26, 09-11-2026 y 09.11.2026. Una fecha que no existe (31/02/2026) se rechaza con el motivo y no se guarda. Los textos de fechas ya eran inequívocos («9 nov»). Las exportaciones CSV de tareas, horas y proyectos escriben las fechas como dd/mm/aaaa y los momentos como dd/mm/aaaa hh:mm en la hora de la instalación. Lo que se guarda en la base de datos no cambia.

**Tiempo estimado al crear una tarea** (pedido de Lissette Cortés): ya estaba desde 1.7.1 en «+ Nueva tarea» (campo «Tiempo estimado», junto a «Sprint»); sin cambios.

**Pruebas:** las pruebas de integración que cambian `config.php` esperan 3,2 s en lugar de 2,6 s a que el servidor relea el archivo (en esta máquina 2,6 s a veces no bastaba).

**Base de datos:** sin migraciones.

## 1.8.0 (2026-11-07)

Calendarios personal y de equipo, centro de notificaciones configurable, avisos en el móvil, sprints en dos etapas y en el calendario, y «Todo a mano» con Discord y GitHub. Incluye todo lo de 1.7.1.

**Calendario personal y de equipo**
- Selector **Ambos / Personal / Equipo** en el calendario (se recuerda por persona). La lista lateral se separa en «Personal · solo tú» y «De equipo · compartidos»; cada calendario mantiene su color y los eventos personales llevan un candado.
- Personal: privado siempre (nunca se liga a un equipo, aunque se intente por la API). Muestra tus eventos, tus tareas personales y los vencimientos de lo que tienes asignado. Puede convivir con tus calendarios de Google conectados.
- De equipo: al crearlo se elige el equipo (solo uno del que formas parte); solo sus integrantes lo ven. Respeta los permisos de ver / crear y cambiar / administrar de 1.6. Muestra reuniones, hitos de proyectos y sprints.
- Nueva capa **Hitos de proyectos** (inicio y entrega de cada proyecto que ves). Al crear un evento, el selector de calendario se agrupa en «Personal (solo tú)» y «De equipo».

**Notificaciones**
- Perfil › Notificaciones: nueve categorías que cada persona enciende o apaga (asignaciones, por vencer, vencidas, cambios de fecha, reuniones, sprints, cambios de proyecto, menciones, capacidad) y cuándo avisar de lo que vence (el mismo día, 1, 2, 3 días o una semana antes).
- Nuevos avisos: tarea vencida (una sola vez), cambio de la fecha de entrega de tus tareas, cambio de la entrega de un proyecto, sprint que empieza hoy o mañana, que inicia, que termina pronto, que llegó a su fecha sin cerrarse y que se cerró (con el resumen de completadas y pendientes).
- Cada aviso se crea una sola vez (clave única); nunca se avisa de tareas completadas. El centro de notificaciones filtra por tipo y enlaza a las preferencias.

**Avisos en el móvil (Web Push)**
- Perfil › Notificaciones › «Activar en este dispositivo»: el navegador pide permiso solo al pulsar el botón. Mensajes cifrados (RFC 8291) y firmados con VAPID (RFC 8292); las claves se crean solas en `storage/vapid.json` (la privada, cifrada con `storage/app.key`). Sin servicios de terceros más allá del servicio de notificaciones del propio navegador.
- Se elige qué categorías llegan al móvil. Una tarea completada antes de enviarse el aviso no se envía; un dispositivo dado de baja se quita solo; al activar un dispositivo no le llega de golpe lo anterior.
- Android (Chrome, Edge, Firefox): basta con HTTPS. iPhone/iPad: iOS 16.4 o posterior y **añadir Borealis a la pantalla de inicio** (Compartir › Añadir a pantalla de inicio); por eso se añadió un manifiesto e iconos.
- `bin/notify.php` (cron cada 10 minutos) crea los recordatorios aunque nadie tenga Borealis abierto. Sin el cron, los recordatorios se crean al abrir Borealis y las asignaciones se envían al momento.

**Sprints**
- Nueva página **Sprints** (menú lateral): los sprints de tus proyectos, con avance, «Pendiente de cierre» y lo que falta planificar.
- Creación en dos etapas: primero nombre, proyecto, objetivo y fechas estimadas; después, en el sprint, la planificación (añadir tareas existentes o nuevas, responsable, estimación, prioridad y horas en la misma tabla).
- En el calendario: inicio (previsto o real), fin previsto, «Fin (sin cerrar)» y el día del cierre real. **Llegar a la fecha de fin nunca cierra el sprint**: se avisa y se marca como pendiente de cierre hasta que alguien lo finaliza.

**«Todo a mano»** (menú lateral)
- Por proyecto: **enlaces directos** (carpeta de Drive, canal de Discord, repositorio, cualquier página) y, aparte, las **integraciones por API**. La página distingue los dos con una etiqueta.
- **Discord** (integración real por webhook del canal): eventos a elegir por proyecto (inicio y cierre de sprint, sprint por empezar, por terminar o sin cerrar, hitos, tareas que vencen mañana con solo clave y título). Sin menciones, sin datos personales, una vez por evento. Botón «Enviar prueba».
- **GitHub** (integración real, solo lectura): issues y pull requests abiertos y últimos commits del repositorio vinculado. Repositorios privados con un token de solo lectura.
- Webhook y token se guardan cifrados. Solo quien administra el proyecto configura; quien participa ve; quien no participa no ve nada. Si Discord o GitHub fallan o no hay conexión, Borealis sigue funcionando y muestra el motivo.
- **Drive:** solo enlace directo. Leer carpetas de Drive exige otro permiso de Google (`drive.readonly`, verificación más estricta); no se finge.
- **Google Calendar:** sigue en Perfil (1.6); el centro lo muestra y enlaza.

**Paquete:** el ZIP de 1.7.1 incluía por error restos de las pruebas en `storage/` (12 copias `.sql` de la base de prueba en `storage/backups/`, 3 archivos de prueba en `storage/uploads/` y un `storage/logs/error.log`). Solo contenían datos ficticios de las pruebas (cuentas `@example.test`), nunca claves ni datos tuyos, y `storage/app.key` no iba incluido. 1.8.0 ya no los incluye. Si al instalar 1.7.1 copiaste su carpeta `storage/`, borra en `storage/backups/` las copias con fecha 2026-11-07 04:01–04:21 que no hiciste tú y los archivos de `storage/uploads/` que no correspondan a adjuntos reales.

**Base de datos:** migración `010_v18` (tablas de dispositivos, enlaces, integraciones y entregas; una columna en notificaciones). No borra nada y se puede repetir.

## 1.7.1 (2026-11-07)

Correcciones críticas: el tutorial vuelve a cambiar de página, «Mis tareas» reúne las tareas asignadas y las personales, «Completadas» se ve bien y «+ Nueva tarea» permite el tiempo estimado.

**Tutorial entre páginas**
- Causa más probable de que en tu servidor siguiera sin navegar: la página cargaba `app.js?v=…` actualizado, pero los módulos que importa (`tour.js`, `core.js`…) se pedían **sin versión**, y Nginx (HestiaCP pone `expires max` a los `.js`) dejaba que el navegador siguiera usando los de una versión anterior. Las pruebas pasaban porque siempre empiezan con la caché vacía. Ahora cada importación lleva `?v=<versión>` (`bin/stamp-assets.php`, comprobado por `tests/unit/urls.php`), así que al actualizar el navegador pide los archivos nuevos.
- Además, el tutorial ya no depende de un solo mecanismo para recordar el paso: el enlace a la página siguiente lleva el paso (`?tour=N`, que se quita de la dirección al llegar), la pestaña lo guarda y el servidor también. Se probó que sigue funcionando aunque el servidor no pueda guardar el paso.
- Recorrido: Inicio → Proyectos → Tareas → Sprints (en un proyecto real con sprints) → Calendario → Configuración, con la sección actual resaltada. Espera a que la página termine de cargar. Botones Anterior, Siguiente, Omitir y **Reiniciar**. Mientras está abierto, una capa transparente impide hacer clic en la página: el tutorial no puede modificar información real.

**Mis tareas**
- Antes «Mis tareas» mostraba solo la lista de tareas personales, así que quien solo tenía tareas asignadas en proyectos veía la sección vacía. Ahora reúne, por separado, **Asignadas en proyectos** (como responsable, corresponsable o por tu equipo; agrupadas en Vencidas, Para hoy, Próximos 7 días, Más adelante y Sin fecha) y **Personales** (privadas, con crear, editar, completar, subtareas y notas como antes). Filtro «Todas / De proyectos / Personales». La pestaña «Asignadas a mí» se integró aquí; sus enlaces antiguos siguen funcionando.
- Si una parte no se puede cargar, la página ya no queda en blanco: muestra qué falló, un botón «Reintentar» y el resto del contenido. El detalle queda en el registro del servidor.

**Completadas**
- Causa: las dos tarjetas usaban la clase `grid-2` sin `grid`, así que no formaban una cuadrícula (se apilaban pegadas, sin separación), y las filas no tenían el relleno de la tarjeta (tocaban el borde). Además el tachado alcanzaba el nombre de la lista. Corregido: dos columnas en escritorio, una en móvil, filas con relleno y solo el título tachado.
- En «Mis tareas», el resumen «Notas y subtareas» con icono ya no se parte en tres líneas, y en móvil las tareas asignadas se muestran como tarjetas sin desplazamiento horizontal.

**+ Nueva tarea** (pedido por Lissette Cortés)
- Campos **Tiempo estimado** (horas, opcional, el mismo dato que «Estimado» en el detalle) y **Sprint** (solo los del proyecto elegido que no han terminado), junto a Responsable y Prioridad. La estimación cuenta en la disponibilidad del sprint con la misma comprobación que al editar.

**Otros**
- Las pruebas de integración usan la zona horaria de la instalación: entre las 00:00 y las 06:00 UTC, tres pruebas de horas fallaban porque la prueba enviaba la fecha de «mañana» para Borealis (fallo de la prueba, no de la aplicación).

**Base de datos:** sin migraciones. Basta con sustituir los archivos.

## 1.7.0 (2026-11-06)

Fotografías de perfil, enlace de cuenta corregido, proyectos solo para quienes participan, horas semanales desde el alta y una barra lateral más limpia.

**Correcciones**
- Enlace del mensaje de cuenta creada: con Borealis en una carpeta salía `https://gepdev.bonfyr.cl/borealis/borealis/login`. La causa: `app.url` ya incluía la carpeta y se le añadía otra vez la ruta base. Ahora todos los enlaces que salen de Borealis (cuenta creada, invitaciones, nuevo enlace de invitación, «Copiar enlace» de una tarea y la dirección de regreso de Google) usan una sola función, `absolute_url()`, que toma de `app.url` solo el protocolo y el dominio. Funciona tanto si `app.url` lleva la carpeta como si no. Borealis no tiene recuperación de contraseña por correo: el restablecimiento lo hace la administración y no genera enlaces.
- Barra lateral: ya no se dibuja ninguna barra de desplazamiento (reemplaza el desplazador fino de 1.6). Si el menú no cabe, se sigue desplazando con la rueda, el trackpad, el gesto táctil y el teclado.

**Privacidad de proyectos**
- Cada persona ve solo los proyectos donde participa (integrante directo o por un equipo del proyecto). La administración (permiso `projects.manage_all`) los ve todos y puede filtrar «Solo donde participo». Se aplica en un solo lugar (`Policy`), así que vale para listados, inicio, búsqueda, calendario, tareas de equipo, enlaces directos y la API. Desaparece la opción «Todo el espacio puede verlo».
- La migración convierte en colaboradoras a las personas con tareas u horas en proyectos donde no eran integrantes.

**Fotografías de perfil**
- Subir, encuadrar (arrastrar, flechas, zoom), cambiar y eliminar desde el perfil. Se ven en el menú de la cuenta, la barra lateral, tarjetas, listas de responsables, comentarios, notificaciones y equipos. Sin fotografía, las iniciales de siempre. Se guardan en WebP de 96 y 256 px, re-codificadas en el servidor, con una dirección nueva en cada cambio (caché larga sin mostrar fotos viejas).

**Horas semanales**
- Campo «Horas semanales disponibles» al crear una cuenta (Administración y Equipos) y en las invitaciones (se aplica al aceptarla). Se cambia después en Administración › Personas. Capacidad del sprint = horas semanales × semanas laborables del sprint, salvo ajuste para ese sprint. Al cambiar las horas semanales, los sprints ya terminados guardan las horas que tenían, para no alterar el historial.

**Calendario en el menú**
- «Calendario» ya estaba en la barra lateral desde 1.6 (antes solo aparecía con el módulo activo); se comprobó en escritorio y móvil.

**Base de datos**
- Migración `009_v17`: tres columnas nuevas y las altas de colaboradores descritas arriba. El actualizador ahora puede repetir una migración interrumpida aunque una columna ya exista (también en MySQL 8).

**Pruebas**
- Integración: 543 pruebas (51 nuevas, en los grupos «1.7 · …» y `tests/unit/urls.php`). Navegador: 166 pruebas, con grupo «Novedades 1.7» (recorte de la fotografía, alta con horas y enlace, Calendario en el menú) y la barra lateral sin desplazador.

## 1.6.0 (2026-11-04)

Tutorial por varias páginas, «Mis tareas», calendario general con reuniones y varios calendarios, conexión opcional con Google Calendar y Google Tasks, y tres correcciones.

**Correcciones**
- Saludo: usa el nombre real de la persona («Buenas tardes, Carlos»). Si la cuenta se llama literalmente «Usuario» (o está vacía), saluda con su identificador o la parte del correo antes de la @ y sugiere escribir el nombre en el perfil. El saludo sigue la hora del navegador de cada persona. Las páginas se envían con `Cache-Control: no-store` y `Vary: Cookie`, para que ningún proxy (por ejemplo Nginx de HestiaCP) entregue la página de otra persona.
- Barra lateral: desplazador fino y discreto del color del tema (Chrome, Edge, Safari y Firefox).
- Temas: Medieval, Agua, Fuego, Tierra, Cielo y Bosques cambian también el fondo del área principal (siluetas claras derivadas de las ilustraciones de Cal), la cabecera de Inicio y del proyecto (las ilustraciones originales), la línea superior y la pestaña activa. Todo con las variables CSS de 1.5; el tema llega en el HTML, sin parpadeo.

**Tutorial**
- 13 pasos por Inicio, Proyectos, Tareas, Responsables, Sprints, Calendario y Configuración, señalando los controles reales. Siguiente, Anterior, Omitir y Finalizar. Cambia de página solo y continúa; si se recarga o se interrumpe, sigue en el mismo paso (guardado por persona). Espera a que el control cargue. Sin proyectos todavía, «Responsables» y «Sprints» muestran un ejemplo que no toca datos. En móvil se muestra como hoja inferior. Se repite desde Perfil › Ayuda o el menú de la cuenta.

**Tareas**
- Nueva página **Tareas**: «Mis tareas» (personales y privadas: nadie más las ve, ni la administración; no aparecen en búsquedas, reportes ni proyectos), con listas, subtareas, orden arrastrando (o Subir/Bajar), prioridad, fecha límite y notas, todo con guardado automático sin cerrar el panel abierto. «Asignadas a mí», «Equipo» y «Completadas» usan las tareas de proyectos de siempre.

**Calendario**
- Calendario general con vistas día, semana y mes, «Hoy», y clic en un día u hora libre para crear.
- Eventos y reuniones: participantes (reciben aviso y la ven en su calendario), lugar, enlace de videollamada («Unirse»), proyecto o equipo, recordatorio en la aplicación, repetición diaria, semanal, quincenal o mensual con fecha de fin. Cancelar (queda visible y avisa) o eliminar, con confirmación.
- Varios calendarios con color: Personal (privado) por persona y Equipo, Proyectos, Reuniones y Eventos compartidos; crear más; mostrar u ocultar (se recuerda); permisos de lectura o edición, también por persona; al eliminar uno se elige a qué calendario pasan sus eventos.
- También muestra vencimientos de tareas de proyectos, tareas personales e inicio y fin de sprints, sin copiarlos: cambiar la fecha de la tarea cambia el calendario. Clic en una tarea abre su detalle; arrastrarla a otro día cambia su vencimiento.
- Horas guardadas en UTC y mostradas en la zona de la instalación; la reunión de los lunes a las 10:00 sigue a las 10:00 tras un cambio de horario.

**Google (opcional)**
- Conexión por persona con OAuth 2.0 + PKCE y permisos mínimos; tokens cifrados en el servidor (nunca llegan al navegador), renovación automática, detección de permisos revocados y «Volver a conectar». Elegir qué calendarios de Google ver (marcados «Google»); sincronización incremental sin duplicados; los cambios hechos en Borealis se escriben en Google; conflicto: gana Google y se avisa. Google Tasks ⇄ «Mis tareas». Nunca borra nada en Google por su cuenta; desconectar no toca Google. Si Google falla, el error se ve y el calendario interno sigue.
- Requiere que la administración cree un cliente OAuth en Google Cloud (pasos exactos en el README). Sin eso, la opción no aparece. Probado contra un servidor local que imita a Google, **no contra Google real**.

**Base de datos**
- Migración `008_v16`: siete tablas nuevas, nada existente cambia; se puede repetir.

**Pruebas**
- Integración: 111 pruebas nuevas (492 en total), incluido un Google simulado localmente (`tests/fake-google/server.php`) y reglas de zona horaria (`tests/unit/events-time.php`). Navegador: 155 pruebas, con el grupo «Novedades 1.6» y el tutorial nuevo.

## 1.5.1 (2026-11-04)

Ilustraciones de los temas con las imágenes de Cal.
- Medieval, Agua, Fuego, Cielo y Bosques: la ilustración queda al pie de la barra lateral, siempre visible, oscurecida lo justo para que el texto mantenga un contraste de 4,5:1 y fundida por arriba con el color de la barra.
- Tierra: la ilustración clara es el fondo del área principal.
- Imágenes WebP optimizadas (de unos 1,1 MB cada original a 2-12 KB) en `public/assets/img/themes/`.
- Sin cambios de base de datos. Desde 1.5.0 basta con sustituir los archivos.

## 1.5.0 (2026-11-04)

Alertas de «Capacidad agotada — Trabajo pendiente» y temas visuales completos. Incluye todas las correcciones de seguridad de 1.4.1.

**Capacidad agotada — Trabajo pendiente**
- Nueva alerta cuando una persona ya registró en la semana todas sus horas semanales (más las adicionales autorizadas) y todavía tiene tareas comprometidas para esa semana sin terminar. Se cuentan las horas reales de todos sus proyectos una sola vez; las estimaciones nunca cuentan como horas consumidas.
- Se muestra en Inicio, Equipos, el resumen y los sprints del proyecto afectado, el perfil de la persona y la nueva página **Alertas de capacidad** (`/capacity`), con el historial.
- Se resuelve desde la alerta: reprogramar a otra fecha o sprint, reasignar a alguien con horas libres, revisar la estimación, autorizar horas adicionales para esa semana o mantener pendiente con justificación. Cada decisión guarda quién, cuándo y qué; verla no la cierra.
- La ven y la resuelven la administración del espacio y la responsable de cada proyecto afectado. Un solo aviso por alerta (tipo «Capacidad»); otro solo si se reabre.
- Casos especiales: tareas sin estimación marcadas «sin estimar» (sin horas inventadas), vencidas de semanas anteriores aparte, sin alerta si todo está terminado o la persona no tiene horas semanales, recálculo al cambiar la persona responsable. Nunca completa tareas ni añade horas sin autorización.

**Temas**
- Medieval, Agua, Fuego, Tierra, Cielo y Bosques cambian también la barra lateral (fondo, textos, iconos, elemento activo, hover, bordes, tarjeta «Tu semana»), una variante del símbolo de Borealis y la ilustración inferior. Las miniaturas del perfil lo anticipan.
- Todo con variables CSS compartidas; el tema original no cambia. Contraste de texto ≥ 4,5:1 en todos los temas. Sin recarga y respetando «reducir movimiento».

**Base de datos y mantenimiento**
- Migración `007_v15`: tres tablas nuevas, nada existente cambia; se puede repetir.
- Las migraciones usan un bloqueo de MySQL/MariaDB: dos actualizaciones a la vez ya no se pisan.

**Pruebas**
- 44 pruebas de integración y 12 de navegador nuevas.

Desde 1.4.1: sustituye los archivos conservando `config/config.php` y `storage/`, y aplica la actualización de base de datos.

## 1.4.1 (2026-11-04)

Actualización solo de seguridad. Corrige las vulnerabilidades de la auditoría de 1.4.0. Sin cambios de base de datos ni funciones nuevas.

**Crítica — toma de cuentas entre espacios (C1)**
- Crear espacios de trabajo queda reservado a la administración de la instalación. Nueva opción `app.allow_workspace_creation` (desactivada por defecto) para permitirlo a cualquiera. El formulario «Crear espacio» solo aparece a quien puede usarlo.
- «Crear cuenta» con un correo que ya tiene cuenta ya no la incorpora al espacio: genera una invitación que la persona acepta con su propia contraseña. No se crea otra cuenta ni se cambia la contraseña o el perfil.
- La administración de un espacio solo restablece la contraseña o edita nombre, correo y cargo de cuentas que pertenecen únicamente a su espacio y no tienen más permisos que ella. Las cuentas compartidas y la de la administración de la instalación solo las cambia la administración de la instalación. La propia contraseña se cambia desde el perfil.
- La importación desde Borealis v0.2 tampoco incorpora cuentas de otros espacios (salvo si la hace la administración de la instalación): avisa y deja sus tareas sin responsable.

**Alta — escalada de roles (A1)**
- Nadie cambia su propio rol. Cambiar el rol de otra persona exige «Administrar integrantes» y «Administrar roles».
- Nadie asigna (al crear cuentas, invitar o cambiar roles), define o retira un rol con permisos que no tiene; tampoco suspende a quien tiene más permisos. Propietarios y administración de la instalación no tienen ese límite.
- Todas estas reglas viven en `app/Security/AccountPolicy.php` y las usan todos los servicios.

**Endurecimiento**
- Importar un proyecto (.json) aplica a cada archivo las mismas comprobaciones que una subida normal (`Files::validate`: extensiones dobles, tipo real y firma). Los rechazados se informan. Si la importación falla, se borran los archivos ya escritos.
- Las personas invitadas solo encuentran, en la búsqueda, en Equipos y en los perfiles, a las personas y equipos de sus proyectos y equipos, y no pueden buscar por correo.
- Inicio de sesión: límites por correo + IP con esperas crecientes, por IP y por cuenta desde muchas IP; ya no se puede bloquear a otra persona. Limpieza automática de intentos antiguos. Un correo inexistente tarda lo mismo que uno real.

**Herramientas y pruebas**
- `php bin/audit-accounts.php`: revisión de solo lectura de lo que el fallo de 1.4.0 pudo producir.
- 41 pruebas de integración nuevas, incluida la cadena completa del ataque de 1.4.0.

Desde 1.4.0: sustituye los archivos conservando `config/config.php` y `storage/`. No hay migración.

## 1.4.0 (2026-11-04)

Tareas que se abren con un clic, sección Sprints por estado, archivos por proyecto, tutorial de bienvenida y fondos personales.

**Abrir tareas**
- En la lista, el tablero y las tarjetas el nombre ya no es un campo editable: un clic en la tarea (o en su fila) la abre. El nombre y el resto se editan dentro del panel, que sigue abierto al guardar. Al cerrarlo vuelves al mismo sitio. Arrastrar una tarjeta no la abre.

**Sprints**
- La lista se agrupa en «Activo», «Planificados» y «Finalizados», cada sprint con fechas, completadas/total y una barra de avance.
- El detalle muestra nombre, objetivo, fechas, estado, completadas, pendientes, progreso, participantes, horas por persona y «Ver en el tablero» para ir a sus tareas. Sin sprint activo se abre el siguiente planificado o el último finalizado. Usa los datos que ya existían.

**Archivos del proyecto**
- Pestaña «Archivos» siempre visible en cada proyecto. Subir eligiendo o arrastrando, vista de cuadrícula (miniaturas para imágenes) o de lista (nombre, formato, tamaño, tarea y fecha).
- Abrir, descargar, cambiar el nombre visible (se conserva la extensión) y eliminar con confirmación.
- Respeta los permisos del proyecto. Tipos permitidos y tamaño máximo en `config/config.php` (`uploads.allowed_extensions`, `uploads.max_bytes`). Los archivos se guardan en `storage/uploads`, fuera de la carpeta pública, y solo se sirven tras comprobar el acceso.

**Tutorial**
- Recorrido de 8 pasos que se abre solo la primera vez que entra cada persona, con «Siguiente», «Anterior» y «Omitir». Se guarda por persona. Se repite desde el menú de la cuenta («Ver el tutorial») o desde Perfil › Ayuda. En móvil la explicación aparece abajo, sin tapar el elemento.
- Las personas que ya existían antes de 1.4 no lo ven al actualizar (pueden abrirlo desde el menú).

**Apariencia**
- Perfil › Apariencia: Predeterminado, Medieval, Agua, Fuego, Tierra, Cielo y Bosques, con miniatura. Un clic lo aplica al momento y queda guardado para esa persona. Un solo sistema de temas con fondos ligeros (SVG), sin animaciones y manteniendo el contraste del contenido.
- Migración 006. Desde 1.3.x: sube los archivos y pulsa «Actualizar» en el aviso.

## 1.3.0 (2026-11-04)

Guardado sin interrupciones, arrastre completo de personas y horas disponibles por persona y sprint.

**Guardado automático**
- Causa encontrada: tras cada guardado se reconstruía el panel de la tarea. En 1.2.0 eso cerraba el selector de responsables; en 1.2.1 seguía abierto, pero los clics hechos mientras se guardaba se perdían y la respuesta del servidor deshacía lo marcado.
- Ahora cada clic se ve al instante y los guardados salen en cola, de uno en uno y siempre con la última elección, así que una respuesta antigua no puede pisar un cambio nuevo. El selector abierto se conserva tal cual (mismo elemento, foco y posición) y solo se actualizan sus datos.
- Indicador discreto «Guardando…», «Guardado» o «Error al guardar» en el panel de la tarea y en la fila de personas del tablero. Si el servidor rechaza un cambio, las casillas vuelven a lo guardado y se explica el motivo.

**Arrastrar personas en el tablero** (proyecto o equipo polivalente, como en 1.2)
- Asignar: de la fila de personas a una tarjeta.
- Retirar: de una tarjeta a la fila de personas («Suelta aquí para quitarla de la tarea»).
- Reasignar: de una tarjeta a otra. Si no cabe en la nueva, no se quita de la anterior.
- Mientras arrastras se marcan las tarjetas válidas y en rojo las que no caben; al soltar en una roja se explica por qué. Nunca se duplica un responsable. Sin arrastre, sigue el selector «Responsables» de la tarea.

**Horas disponibles por persona y sprint**
- En Sprints › Control del sprint se fijan las horas de cada persona para ese sprint. Vacío = horas semanales × duración del sprint. Cada sprint guarda las suyas.
- Carga = horas estimadas de sus tareas del sprint. Una tarea con varias personas reparte sus horas a partes iguales (se cuentan una sola vez). Las tareas sin estimar aparecen como «pendientes de estimar», no como cero.
- El servidor rechaza cualquier cambio que dé a alguien más horas de las que le quedan: asignar, mover, subir la estimación, pasar una tarea al sprint o crear una tarea ya asignada. Mensaje: «Lucía solo tiene 5 h disponibles…». Se permite llegar justo al límite. La comprobación bloquea el sprint mientras guarda, para que dos personas no usen a la vez las últimas horas.
- Si se bajan las horas disponibles por debajo de la carga, se avisa y no se borra nada; a esa persona se le puede quitar trabajo pero no dar más.
- Tablero del sprint: personas «Disponibles» y «Sin disponibilidad» con «15 / 40 h» y una barra compacta. Quien no tiene horas no se puede arrastrar. El selector de responsables muestra las horas libres y desactiva a quien no cabe.
- Migración 005 (tabla `sprint_capacity`). Desde 1.2.x: sube los archivos y pulsa «Actualizar» en el aviso de actualización pendiente.

## 1.2.1 (2026-11-04)

Panel lateral de la tarea rediseñado (era demasiado cargado).

- Estado y «Finalizar» juntos arriba, en una sola fila.
- Las propiedades se muestran como lista compacta (etiqueta a la izquierda, valor a la derecha). Los campos no tienen borde hasta pasar el ratón.
- «Responsable», «Más responsables» y «Equipo responsable» se unen en un solo selector «Responsables»: muestra avatares y equipo, y al abrirlo se marcan personas y se elige el equipo.
- Horas y puntos estimados en una sola fila; etiquetas con un botón «Añadir».
- Sin cambios de base de datos: basta con subir los archivos.

## 1.2.0 (2026-11-03)

Basada en los comentarios de uso de la 1.1.

### Nuevo
- **Equipos como participantes del proyecto:** en Ajustes › Integrantes se añade un equipo completo con un rol. Quien entre al equipo después también tiene acceso, y la coordinación del equipo queda como responsable.
- **Varios responsables por tarea o un equipo:** en el detalle de la tarea, «Más responsables» y «Equipo responsable». Todos reciben aviso y ven la tarea en «Mi trabajo»; las tarjetas muestran las caras y el equipo; el CSV lista a todos.
- **Mover personas en el tablero:** si el proyecto o un equipo participante está marcado como **polivalente**, aparece una fila de personas sobre el tablero. Arrastrar a alguien sobre una tarjeta lo suma como responsable; arrastrarlo desde otra tarjeta lo mueve. Sin polivalente, el servidor lo rechaza.
- **Perfil de participantes con horas:** la ficha de cada persona muestra horas de la semana (con su capacidad), del mes, de las últimas 8 semanas, por proyecto y los registros recientes, con selector de período. Solo cuenta proyectos que quien mira puede ver. Los nombres en Ajustes › Integrantes y Administración llevan a esa ficha.
- **Exportar e importar proyectos:** Ajustes › General › Exportar proyecto genera un `.json` (con o sin adjuntos, hasta 25 MB). Proyectos › Importar lo trae como proyecto nuevo en este u otro Borealis, conservando columnas, tareas y sus números, subtareas, responsables, equipo, épicas, sprints, comentarios, horas, documentos y carpetas. Las personas se reconocen por correo; las que no existen se informan.
- **La administración edita cuentas:** Administración › Usuarios › Editar cuenta cambia nombre, correo de acceso y cargo. Si la persona usa otros espacios, solo la administración de la instalación puede cambiar su correo.
- **«Nuevo enlace»** en invitaciones pendientes, por si se perdió el mensaje (el anterior deja de valer).

### Cambios
- **Correo más estricto** en todas partes (alta, invitación, perfil, instalador, edición): ya no basta con «@» y «.». Exige un dominio real con terminación de al menos dos letras, sin espacios ni puntos dobles, y sugiere el correcto ante errores típicos (gmail.con → gmail.com, hotmial.com → hotmail.com…). El navegador avisa antes de enviar.

### Correcciones
- **«Invitar por enlace» no generaba nada:** el enlace sí se creaba, pero el cuadro del mensaje quedaba vacío porque el formulario se reiniciaba justo después. Afectaba también al mensaje de «Crear cuenta».

### Actualizar desde 1.1
1. Haz una copia de seguridad (Administración › Datos).
2. Sustituye los archivos conservando `config/config.php` y la carpeta `storage/`.
3. Inicia sesión como administración de la instalación: aparece «Actualización pendiente»; pulsa el botón (hace otra copia antes y aplica `004_v12`). También puedes usar `php bin/migrate.php`.

## 1.1.1 (2026-10-29)

### Corrección
- Al subir los archivos de 1.1 sobre una instalación 1.0, todas las páginas daban **Error 500** hasta aplicar la migración, y la pantalla para aplicarla también fallaba. Ahora, si falta actualizar la base, Borealis muestra la pantalla «Actualización pendiente» tras iniciar sesión: la administración de la instalación pulsa **Actualizar la base de datos ahora** (se guarda una copia antes) y todo vuelve a funcionar. El resto de las personas ve un aviso de espera.

## 1.1.0 (2026-10-29)

Basada en los comentarios de uso de la 1.0.

### Nuevo
- **Horas:** registro de horas por tarea (1.5, 1,5, 1:30, 90m), totales por tarea y por persona, horas estimadas por tarea. Cada quien registra las suyas; la persona responsable del proyecto puede registrar las de otras.
- **Al finalizar una tarea** se ofrece dejar un comentario de cierre y las horas que tomó (se puede omitir).
- **Horas semanales por persona** (en Administración › Usuarios o en el propio perfil), visibles también en el equipo.
- **Control de sprints:** capacidad en horas (calculada con las horas semanales o fijada a mano), horas planificadas y registradas, gráfico de avance (burndown) con ritmo ideal, tabla por persona y alertas de sobrecarga o retraso.
- **Exportar reportes:** botones para el informe imprimible (o PDF desde el cuadro de impresión) y CSV de tareas, horas y proyectos, en el proyecto y en el espacio.
- **Cuentas fantasma para pruebas:** la administración de la instalación crea cuentas que no pueden iniciar sesión, entra como ellas con un aviso visible y «Volver a mi cuenta», y las borra todas de una vez (con copia de seguridad previa). No se puede entrar como una persona real.
- **Siglas del proyecto editables** al crearlo y en Ajustes; los enlaces con las siglas anteriores siguen funcionando.
- **Apariencia del proyecto:** selector de color con paleta y siete fondos para el encabezado.
- **Barra lateral** renovada: contadores de Mi trabajo, tarjeta «Tu semana» con tus horas, proyectos recientes con siglas y avance, tus equipos y tu perfil.

### Cambios
- Crear un equipo ya no te lleva dentro de él: aparece un aviso con «Abrir equipo».
- Añadir a un equipo ofrece tres caminos: elegir a alguien que ya tiene cuenta, crearle la cuenta ahí mismo, o generar un enlace de invitación. Al crear la cuenta se muestra un mensaje listo para copiar con el enlace, el correo y la contraseña temporal. Se aclara que Borealis no envía correos.
- El diálogo de nueva tarea permite elegir a cualquier persona del proyecto como responsable (antes solo «Asignármela»).
- Botón «Abrir» visible en las tarjetas del tablero y en la lista.
- Pestañas del proyecto con indicador deslizante.

### Correcciones
- El detalle de la tarea se desacomodaba (título y columnas); se reorganizó.
- Las barras de los reportes («Tareas por estado», «Distribución del trabajo») no se veían.
- Exportar las tareas de un proyecto archivado devolvía un archivo vacío.
- En móvil, los botones del encabezado del sprint se salían de la tarjeta.

### Actualizar desde 1.0
1. Haz una copia de seguridad (Administración › Datos).
2. Sustituye los archivos conservando `config/config.php` y la carpeta `storage/`.
3. Inicia sesión como administración de la instalación: aparece «Actualización pendiente»; pulsa el botón (hace otra copia antes). También puedes usar `php bin/migrate.php`.

## 1.0.0 — Forest Edition (2026-10-29)

Reescritura completa respecto a la v0.2.2.

### Nuevo
- Base de datos MySQL/MariaDB con migraciones versionadas (antes: archivos JSON).
- Cuentas personales con roles por espacio de trabajo y por proyecto, roles personalizados y permisos comprobados en el servidor (antes: una contraseña compartida).
- Varios espacios de trabajo aislados entre sí; proyectos privados.
- Equipos con coordinación, responsabilidades y carga de trabajo.
- Módulos por proyecto con dependencias: backlog, sprints, estimaciones, épicas, calendario, cronograma, documentación y reportes.
- Tareas con subtareas, relaciones, bloqueo, etiquetas, menciones, archivos e historial.
- Tablero con arrastrar y soltar y alternativa por menú o teclado; panel lateral de tarea.
- Lista con filtros, orden y edición masiva.
- Notificaciones (asignaciones, menciones, comentarios, vencimientos).
- Gamificación profesional sin rankings y preferencia de animaciones.
- Instalador web, actualizador web y de consola con copia previa, copias de seguridad, exportación JSON/CSV.
- Importación desde la v0.2 (pantalla Datos o `bin/import-v022.php`), conservando las claves de tarea.
- Pruebas automáticas reales: 143 de integración y 38 en navegador.

### Seguridad
- Argon2id, CSRF, sesiones endurecidas, bloqueo por intentos fallidos, CSP sin scripts en línea, archivos fuera de la carpeta pública con verificación de contenido.

### Migración desde 0.2.2
1. Instala la 1.0 en una carpeta y una base nuevas (la v0.2.2 puede seguir funcionando aparte mientras tanto).
2. En Administración › Datos › *Importar desde Borealis v0.2*, sube un ZIP de la carpeta antigua (o solo `storage/borealis.json`).
3. Asigna contraseñas a las personas importadas en Administración › Personas.
