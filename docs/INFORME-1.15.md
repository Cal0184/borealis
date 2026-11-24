# Borealis 1.15: refinamiento funcional, auditoría social y experiencia de uso

Este informe explica qué se cambió en la 1.15 y por qué. También dice qué se verificó y qué queda pendiente.

- Configuración del servidor: [CONFIGURACION-1.15.md](CONFIGURACION-1.15.md).
- Resultados de las pruebas: [PRUEBAS.md](../PRUEBAS.md).

## 1. Resumen

| Área | Qué cambia para quien usa Borealis |
|---|---|
| Notificaciones | La campana se actualiza sola aunque estés en un formulario. Las confirmaciones ya no congelan la página. Al tocar un aviso del sistema no se pierden cambios sin guardar. Si la sesión terminó, aparece un aviso y lo escrito se conserva. |
| Registro maestro | Un solo registro de todo lo que pasa: trabajo, administración, acciones automáticas y errores. Se puede ver por día, semana o mes, filtrar por tipo y exportar a CSV o JSON. Nadie puede editarlo ni borrarlo, y se puede comprobar su integridad. |
| Barra lateral | Cuatro áreas plegables en lugar de doce enlaces sueltos, cada una visible según el rol. Además: modo compacto, favoritos, búsqueda con lo abierto hace poco y, en el móvil, una barra inferior. |
| Tareas | La pestaña Equipo se puede ver como tabla, lista compacta o tablero, agrupada por proyecto, responsable, estado o fecha. Tiene más filtros, se pueden guardar vistas y se recuerda la elección. El detalle queda en tres zonas, con horas reales y progreso. |
| Colaboración | Seguir una tarea sin ser responsable de ella. Marcar un comentario como decisión: las decisiones se listan en el resumen del proyecto. |
| Automatizaciones | Siete plantillas del tipo «cuando pasa algo, si se cumple una condición, Borealis avisa». Solo envían avisos, nunca se repiten y cada ejecución queda en el registro maestro. |

## 2. Investigación: Jira, Jira Service Management, Confluence, Atlassian Analytics y Automation

**Cómo se hizo.** La comparación se basa en las funciones públicas y documentadas de esos productos tal como las conozco. No revisé en vivo la documentación de Atlassian para esta entrega ni usé sus productos con datos reales. Cada función se comparó con lo que Borealis ya tenía, para no duplicar nada.

| Función en Atlassian | Borealis 1.14 | Decisión en 1.15 |
|---|---|---|
| Dependencias y tareas bloqueadas (issue links «blocks») | Ya existían las relaciones «bloquea a», el estado bloqueado con motivo y las alertas de dependencia en Monitoreo. | Se renombró la sección a «Dependencias y relaciones». Nueva automatización «Bloqueo resuelto»: avisa a quien esperaba. |
| Jerarquía de trabajo (épicas, tareas, subtareas) | Ya existía: épicas, tareas y subtareas. | Sin cambios. Se muestra el progreso por subtareas en el detalle. |
| Relación entre proyectos | Las relaciones entre tareas ya funcionan de un proyecto a otro. | Sin cambios: un tipo nuevo de relación entre proyectos agregaba complejidad sin un caso claro. |
| Watchers (seguir una incidencia) | No existía. | **Nuevo: «Seguir».** Quien comenta queda siguiendo la tarea. Los avisos no se duplican con los de responsables, y por defecto no llegan al móvil para evitar el exceso de avisos. |
| @menciones | Ya existían. | Sin cambios. |
| Filtros guardados (JQL saved filters) | No existían. | **Nuevo: vistas guardadas** con filtros simples, sin lenguaje de consultas. Pueden ser personales o compartidas. |
| Acciones masivas (bulk change) | Ya existían en las listas de proyecto. | Sin cambios. |
| Favoritos (starred) y recientes | No existían. | **Nuevo:** estrella en proyectos y tareas. «Abiertos hace poco» aparece en la búsqueda. |
| Búsqueda global | Ya existía. | Al enfocarla se ven los favoritos y lo reciente antes de escribir. |
| Plantillas de proyecto | Las de la 1.14 al crear un proyecto. | Sin plantillas nuevas: las existentes cubren el caso. |
| Paneles personalizables (dashboards) | Ya estaban Inicio, Monitoreo y Análisis. | No se agregó un editor de paneles. Se priorizó ordenar lo existente. |
| Reglas de Automation | No existían. | **Nuevo:** siete plantillas en lugar de un editor libre. Ver la sección 7. |
| Informes de cumplimiento y cuellos de botella | Ya estaban Monitoreo y Análisis por período. | Se agregaron el informe al cerrar un sprint y el aviso de bloqueo prolongado, como automatizaciones. |
| Historial de decisiones (Confluence decision log) | No existía. | **Nuevo:** un comentario se marca como decisión y queda en el registro y en el resumen del proyecto. |
| Espacio de documentación por proyecto (Confluence spaces) | Ya existían Documentos y Archivos por proyecto. | Sin cambios. |
| Solicitudes internas (JSM request types) | Los trabajos rápidos de la 1.14, con el tipo «Solicitud imprevista». | Sin cambios: un portal de solicitudes agregaría otra área. |
| Bandeja personal y resúmenes | Ya estaban las notificaciones y el resumen del lunes. | Notificaciones pasa a llamarse **Bandeja de entrada** y se actualiza en vivo. Hay una automatización opcional de prioridades de la semana. |
| Registro de auditoría (audit log) | Desde la 1.14, el historial con cadena de huellas. | Pasa a ser el **registro maestro** (sección 4). |

## 3. Notificaciones: la causa real y la solución

**El problema reportado:** las notificaciones dejaban de llegar en las secciones que muestran una confirmación al salir.

Encontré cinco causas que se sumaban:

1. **La campana solo cambiaba al cargar una página.** Quien se quedaba en un formulario no cargaba páginas, así que no veía avisos nuevos hasta salir. *Solución:* `notify-live.js` consulta `/api/notifications/status` en estos momentos:
   - cada minuto mientras la pestaña está visible;
   - al volver a la pestaña;
   - al recuperar la red;
   - cuando el service worker recibe un push.
2. **`window.confirm()` congelaba la página.** Mientras la pregunta estaba abierta no corría ningún JavaScript. *Solución:* todas las confirmaciones usan el diálogo propio, que no bloquea la página. Ya no hay ninguna llamada a `window.confirm`.
3. **Tocar un aviso del sistema podía hacer perder lo escrito.** El service worker llevaba la ventana abierta a la dirección del aviso, aunque tuviera un formulario con cambios. *Solución:* el service worker le pregunta antes a la página si tiene cambios sin guardar. Si los tiene, o si no responde, abre el aviso en otra pestaña.
4. **Sin cron, los recordatorios dependían de las visitas.** Una persona trabajando en un formulario no generaba visitas. *Solución:* la consulta de cada minuto también dispara el respaldo por visitas de la 1.14. El cron sigue siendo lo recomendado.
5. **Una sesión vencida llevaba a la página de inicio de sesión** y se perdía el formulario. *Solución:* la consulta en segundo plano no alarga la sesión (cabecera `X-Borealis-Background`). Si la sesión terminó, aparece un aviso con un enlace para iniciar sesión en otra pestaña, y lo escrito se queda donde estaba.

**Protección contra la pérdida de cambios.** Se mantiene: al cerrar un diálogo, el panel o un enlace con cambios sin guardar, Borealis pregunta con su propio diálogo. Al cerrar la pestaña o recargar, sigue el aviso del navegador (`beforeunload`): es lo único que el navegador permite en ese momento, y no afecta a las notificaciones del sistema.

**Las notificaciones del sistema operativo no dependen de la página.** Las envía el servidor con Web Push (VAPID) y las muestra el service worker, aunque Borealis esté cerrado o la sesión haya vencido. El servidor procesa la cola en la tarea programada, con reintentos, sin duplicados, con registro de errores y con renovación de la suscripción (desde la 1.14). Ahora cada envío que falla también queda en el registro maestro, y hay un resumen diario.

### Escenarios

| Escenario | Qué pasa | Cómo se verificó |
|---|---|---|
| Pestaña abierta y visible | La campana se actualiza en un minuto como máximo. Con push, al momento. | Prueba de integración del endpoint y prueba en navegador. |
| Formulario con cambios abierto | Igual que arriba, y la página no navega. | Prueba en navegador. |
| Confirmación de salida abierta | La página sigue funcionando y el aviso llega igual. | Prueba en navegador: los temporizadores siguen corriendo con la pregunta abierta y no aparece ningún diálogo nativo. |
| Pestaña en segundo plano, ventana minimizada u otra pestaña | La notificación del sistema llega por push. Al volver a la pestaña, la campana se pone al día. | El código de `sw.js` y `visibilitychange` se revisó a mano. No hay prueba automática con el sistema operativo real. |
| Navegador cerrado | Llega si el navegador sigue corriendo en segundo plano (ver limitaciones). El servicio de push la guarda hasta 24 h. | No se puede verificar en este entorno. |
| Sesión vencida | El push llega igual, porque no usa la sesión. La página muestra el aviso de sesión terminada sin perder el formulario. | Prueba de integración: el 401 no redirige. |
| Reconexión | Al recuperar la red, la página consulta de inmediato. Los envíos fallidos se reintentan a los 5, 15, 60 y 180 minutos. | Integración de reintentos (1.14) y evento `online` en el navegador. |
| Móvil | Android con Chrome: igual que en el escritorio. iPhone y iPad: solo con Borealis instalado en la pantalla de inicio. | No se probó en dispositivos reales. |

### Limitaciones de navegadores y sistemas (no dependen de Borealis)

- **iOS y iPadOS 16.4 o posterior:** Web Push funciona solo si Borealis está instalado en la pantalla de inicio. En Safari como pestaña no llega.
- **Windows y macOS con el navegador cerrado del todo:** Chrome y Edge entregan los avisos solo si siguen corriendo en segundo plano (opción del navegador). Firefox, en general, no los entrega con el navegador cerrado. Al abrirlo, llegan los pendientes de las últimas 24 h.
- **Modo «No molestar» y ahorro de batería:** el sistema puede silenciar o retrasar los avisos. Android en ahorro extremo puede retrasarlos varios minutos.
- **Permiso denegado:** si la persona bloqueó las notificaciones del sitio, solo el navegador puede volver a activarlas (candado de la barra de direcciones). La campana dentro de Borealis sigue funcionando.
- **Sin HTTPS no hay service worker** y, por lo tanto, no hay push.

## 4. Registro maestro

Es la memoria histórica de Borealis: un solo registro, la tabla `activity`, que ya existía y ahora cubre más. Los historiales del proyecto y de la tarea salen de ahí mismo, y desde la tarea hay un enlace a «Ver con antes y después».

**Qué se registra.**

- **Proyectos, tareas, asignaciones, fechas, prioridades, estados, horas, estimaciones, calendario, sprints, usuarios, permisos y configuración:** ya se registraban desde la 1.14, con antes y después.
- **Nuevo en la 1.15:**
  - Inicio y cierre de sesión, e intentos rechazados de cuentas que existen. Se guarda solo la red abreviada (`190.20.33.x`), nunca lo que se escribió.
  - Errores del servidor: la clase y un mensaje sin rutas ni valores de consultas, uno por hora para el mismo error.
  - Avisos que no se pudieron entregar a un dispositivo, con el código y el motivo.
  - Un resumen diario de avisos por espacio: creados, entregados y sin entregar.
  - Fallos de la tarea programada: uno por hora como máximo.
  - Automatizaciones: creación, cambios, pausa, cada ejecución que hizo algo y sus errores.
  - Decisiones.

**Campos.** Cada registro guarda:

- n.º único, fecha y hora (UTC);
- persona, o «Sistema»;
- acción y categoría;
- elemento: tipo, id y enlace;
- proyecto;
- antes, después y motivo;
- origen: Borealis, automático, automatización, consola, Google, GitHub o Discord;
- **resultado** (nuevo): hecho, error o rechazado;
- las huellas de la cadena.

**Tipos con color:** trabajo, administración, automático y errores o rechazos. Se puede filtrar por cada uno.

**Cómo se consulta.**

- Línea de tiempo agrupada por día, semana o mes.
- Filtros por persona, proyecto, tarea, categoría, tipo, acción, origen, resultado, período y texto.
- Páginas de 100 por número de registro: sigue siendo rápido con millones de filas gracias a los índices nuevos.
- Exportación a CSV o JSON con los mismos filtros. El JSON lleva las huellas, para comprobar la cadena fuera de Borealis.
- Acceso: solo con el permiso «Ver auditoría».

**Protección.**

- Borealis no tiene ninguna ruta para editar o borrar el registro.
- Cada fila lleva la huella SHA-256 de la anterior. «Comprobar integridad» detecta cualquier modificación, eliminación o huella borrada hecha directamente en la base de datos.
- El resultado entra en la huella solo cuando existe, así que las filas de la 1.14 conservan la suya.
- No se guardan contraseñas, tokens, claves, suscripciones push, cookies ni sesiones: se reemplazan por `[oculto]` antes de escribir.

**Conservación.**

- Por defecto no caduca.
- Si la base de datos crece demasiado o una política lo exige, quien administra el servidor puede ejecutar `php bin/audit-archive.php --months=24`. El script:
  1. guarda lo anterior en `storage/audit-archive/` (JSON por líneas, comprimido, con las huellas);
  2. comprueba que el archivo se lee completo;
  3. recién entonces lo borra de la tabla y anota desde dónde sigue la cadena.

  La integridad se sigue comprobando sobre lo que queda.
- El mínimo es 6 meses. Ver la configuración.

## 5. Auditoría social y de experiencia de uso

**Qué tipo de evaluación es.** Es una **evaluación heurística**: recorrí las pantallas y tareas frecuentes con criterios de usabilidad (Nielsen, WCAG 2.2) desde el punto de vista de cada perfil.

**Qué no es.** No hubo pruebas con usuarios reales, entrevistas, encuestas ni datos de uso. Ninguna de las observaciones siguientes viene de personas reales. Las confirmaría con 5 o 6 personas por perfil antes de hacer cambios mayores.

### Recorrido de operaciones frecuentes, en clics desde Inicio

| Operación | Antes | Ahora |
|---|---|---|
| Ver mis tareas de hoy | 1 (Mi trabajo) | 1 (Mi trabajo, o la barra inferior en el móvil) |
| Crear una tarea | 1 | 1 (también en la barra inferior del móvil) |
| Volver a una tarea abierta hace poco | Buscarla por nombre: 3 o más | 2 (enfocar la búsqueda y elegirla) |
| Ver solo las tareas vencidas del equipo | No había filtro: revisar toda la lista | 2, o 1 con una vista guardada |
| Enterarse de un comentario en una tarea ajena | No era posible | Seguirla: 1 clic, una vez |
| Revisar quién cambió una fecha | Administración › Historial › filtros: 4 | Desde la tarea, «Ver con antes y después»: 1 |

### Hallazgos por perfil y lo que se hizo

| Perfil | Hallazgo (heurística) | Gravedad | En la 1.15 |
|---|---|---|---|
| Trabajador nuevo | Doce enlaces en la barra lateral sin agrupar: es difícil saber por dónde empezar. | Alta | Cuatro áreas con nombres de tarea («Mi espacio»). Las áreas de seguimiento y administración se pueden plegar. |
| Trabajador habitual | Volver a lo mismo cada día exige buscar. | Media | Favoritos, abiertos hace poco y vistas guardadas. |
| Trabajador habitual | «Tareas › Mis tareas» y «Mi trabajo» se parecen. | Media | La barra lateral dice «Mi trabajo» (el día) y «Todas las tareas» (la lista completa). |
| Administración | Auditoría y errores en lugares distintos (registro, consola del servidor). | Alta | Registro maestro único, con errores y envíos fallidos. |
| Jefatura de equipo | Sin forma de seguir trabajo ajeno sin asignárselo. | Media | Seguir una tarea. |
| Responsable de proyecto | Las decisiones quedaban perdidas en los comentarios. | Media | Marcar un comentario como decisión y verlas en el resumen. |
| Responsable de proyecto | Vigilar vencidas y bloqueos a mano. | Media | Automatizaciones con plantillas, opcionales. |
| Usuario con poca experiencia técnica | Una confirmación del navegador con texto en inglés o genérico, que además congela la página. | Alta | Diálogo propio en español, con el botón seguro enfocado. |
| Usuario con poca experiencia técnica | Una sesión vencida lo llevaba al inicio de sesión y perdía lo escrito. | Alta | Aviso sin salir del formulario. |
| Usuario de móvil | El menú lateral exige abrirlo para todo. | Alta | Barra inferior con Inicio, Mi trabajo, Nueva, Bandeja y Menú, con objetivos táctiles de 48 px. |
| Todos | Exceso de avisos (fatiga). | Media | «Seguir» no llega al móvil por defecto. Seguidores y responsables nunca reciben el mismo aviso dos veces. Cada automatización avisa una sola vez por hecho. Todo se puede apagar por categoría en el perfil. |
| Todos | Vigilancia percibida: un registro de accesos y una página de monitoreo pueden sentirse invasivos. | Alta | Se registra solo lo necesario para la seguridad: la red abreviada, nunca el detalle de la actividad de navegación. No se mide el tiempo en pantalla. El registro solo lo ven quienes tienen el permiso de auditoría. Monitoreo sigue mostrando a cada trabajador solo su propio trabajo, y la página dice quién más puede verlo. |
| Todos | Accesibilidad. | Media | Grupos con `<details>`, navegables con el teclado. Estados con `aria-pressed` en Seguir, Favorito y Compactar. La barra inferior se oculta en el escritorio. El contraste de las etiquetas nuevas se mantiene en el nivel de la 1.14. |

**Pendiente, sin evidencia suficiente para cambiarlo ahora:**

- los formularios largos de proyecto;
- el número de pestañas dentro de un proyecto;
- un modo «solo lo esencial» para quien usa Borealis pocas veces.

Son buenos candidatos para validar con personas reales.

## 6. Barra lateral

| Antes (1.14) | Ahora (1.15) |
|---|---|
| Inicio, Mi trabajo, Equipos, Proyectos, Tareas, Trabajos rápidos, Sprints, Calendario, Todo a mano, Monitoreo, Análisis, Notificaciones, y aparte Administración | **Mi espacio:** Inicio, Mi trabajo, Mi calendario, Bandeja de entrada, Todo a mano<br>**Organización:** Proyectos, Todas las tareas, Trabajos rápidos, Equipos, Planificación<br>**Seguimiento:** Carga y alertas, Análisis e informes<br>**Administración** (según el permiso): Usuarios, Registro maestro, Automatizaciones, Notificaciones y tareas, Configuración |

- Las áreas se pliegan y Borealis lo recuerda en el perfil. El área de la página actual siempre se ve abierta.
- **Modo compacto:** solo iconos. Debajo quedan los favoritos y los proyectos recientes.
- El Gantt, el historial de la tarea y los documentos siguen dentro del proyecto o de la tarea: no se agregaron enlaces de primer nivel.

## 7. Automatizaciones

| Plantilla | Cuándo | Si | Entonces |
|---|---|---|---|
| Tarea vencida | Cada ejecución de la tarea programada | Pasó la fecha y no está finalizada | Avisa a quien es responsable y a quien lleva el proyecto |
| Bloqueo resuelto | Al finalizar una tarea | Otras dependían de ella | Avisa a quienes ya pueden avanzar |
| Superó las horas acordadas | Tarea programada | Horas registradas mayores que la estimación más el porcentaje indicado (20 % por defecto) | Avisa a quien lleva el proyecto |
| Proyecto terminado | Al finalizar la última tarea abierta | No quedan tareas abiertas | Envía un resumen (tareas, horas y % a tiempo) |
| Prioridades de la semana | Lunes desde las 8:00, en la hora de cada persona | Tiene tareas abiertas | Envía sus tres pendientes más importantes |
| Informe al cerrar el sprint | Sprint cerrado o vencido en los últimos 14 días | Tenía tareas | Envía el cumplimiento a quien lleva el proyecto |
| Bloqueo prolongado | Tarea programada | Bloqueada más de los días indicados (3 por defecto) | Pide revisión a quien lleva el proyecto |

**Seguridad.**

- **Sin bucles:** las reglas solo envían avisos y no cambian tareas, así que ninguna puede disparar a otra. Además hay un guardia contra la reentrada.
- **Sin duplicados:** cada acción tiene una clave única (`automation_runs`).
- **Con un tope:** como máximo 100 avisos por regla y ejecución.
- **Sin acciones no autorizadas:**
  - solo «Administrar el espacio» crea o cambia reglas;
  - los avisos van solo a miembros activos que pueden ver el proyecto.
- **Con registro:** cada ejecución y cada error quedan en el registro maestro.

**Por qué plantillas y no un editor libre.** El editor de reglas de Atlassian es potente, pero la mayoría de los equipos usa unas pocas reglas, y una regla mal hecha puede llenar de avisos a todo el mundo. Las plantillas cubren los siete casos pedidos con uno o dos ajustes cada una.

## 8. Lo que no se agregó, a propósito

- No hay un lenguaje de consultas tipo JQL: los filtros simples y las vistas guardadas cubren el uso habitual.
- No hay paneles configurables ni un editor libre de reglas.
- No hay tipos nuevos de relación entre proyectos.
- No hay un área de primer nivel por cada función.
- No hay ajustes nuevos para cada persona más allá de plegar o compactar la barra y recordar la vista de tareas.

## 9. Pendiente y limitaciones conocidas

- El modo compacto no tiene etiquetas emergentes propias: usa el `title` del navegador.
- No hay arrastrar y soltar en el tablero de «Equipo», a diferencia del tablero de cada proyecto, que sí lo tiene. Hacerlo entre proyectos con estados distintos necesita otra decisión de diseño.
- Las automatizaciones por fecha dependen de la tarea programada: con el respaldo por visitas, de noche esperan a la primera visita.
- Las notificaciones con el navegador cerrado dependen del navegador y del sistema (sección 3).
