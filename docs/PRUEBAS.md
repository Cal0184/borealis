# Resultados de pruebas — Borealis 1.13.0

Resultados **reales**, generados por las propias pruebas el 17/11/2026, instalando desde el ZIP. Nada de esta lista se marcó a mano.

| Suite | Correctas | Fallidas | Total |
|---|---|---|---|
| Integración HTTP (`tests/integration/run.php`, incluye `tests/unit/events-time.php`, `tests/unit/urls.php`, `tests/unit/reminders.php` y `tests/unit/analysis.php`) | 949 | 0 | 949 |
| Navegador Chromium (`tests/e2e/run.mjs`) | 289 | 0 | 289 |

**Entorno:** PHP 8.3.6 (servidor integrado de PHP), MariaDB 10.11, Chromium (Playwright), Linux. Cada ejecución parte de una base de datos vacía e instala Borealis desde el instalador web.

**No probado todavía:** Apache con `mod_rewrite` y Nginx reales (solo el servidor integrado de PHP), MySQL 8, Safari y Firefox, lectores de pantalla, hospedaje compartido real, rendimiento con miles de tareas. **Servicios externos reales:** la entrega de avisos a un teléfono real (FCM/Mozilla/Apple), un canal de Discord real, la API real de GitHub, Google Calendar con un cliente OAuth real y un servidor de correo real. Todos se probaron contra servidores locales que imitan sus respuestas (`tests/fake-services/server.php`, `tests/fake-services/smtp.php`, `tests/fake-google/server.php`): eso prueba lo que envía Borealis (incluido el cifrado y la firma de cada aviso, que la prueba descifra y verifica), no que esos servicios lo acepten.

**Novedades 1.13:** grupo «1.13 · Análisis y decisiones» en integración, con las pruebas A–J de la Fase C: (A) 15 a tiempo y 5 fuera de plazo → 75 % con el denominador y los excluidos; (B) sugerencia de estimación con 5 tareas comparables, sin cambiar ninguna estimación y sin mezclar categorías; (C) con 2 tareas, «No hay datos suficientes para identificar una tendencia confiable.»; (D) 40 h de capacidad y 58 h planificadas → «supera la capacidad disponible en 18 horas» con alternativas; (E) sprint con 22 h pendientes, 12 h de capacidad y una tarea bloqueada → «Riesgo elevado» explicado y sin probabilidad; (F) simular el traslado de la tarea de 12 h sin tocar la base; (G) aplicar con permisos (403), confirmación (422), datos cambiados (409), auditoría, reversión y reversión rechazada si alguien cambió algo después; (H) permisos: 404 para proyectos y sprints ajenos en página, API, CSV, imprimible y simulador; (I) el resultado guardado se recalcula al cambiar una estimación o una fecha; (J) 1 500 tareas y 3 000 registros de horas: menos de 4 s la primera vez y menos de 1 s después. Además: migración 015 repetida, parámetros solo para la administración con auditoría, umbrales incoherentes rechazados, informe, imprimible y CSV, «Solo lo mío», sin rankings, y las 21 comprobaciones sin servidor de `tests/unit/analysis.php`. «Novedades 1.13» en el navegador recorre las seis pestañas, la sugerencia en la ficha, el simulador (simular, aplicar y revertir), los parámetros, el informe imprimible y el móvil. También se probó actualizar una base con datos de 1.12.0 (migración 015 aplicada dos veces: 105 tareas, 26 proyectos, 21 registros de horas y 15 sprints sin pérdidas, y las seis pestañas abren sin errores). **No probado:** Safari y Firefox, MySQL 8 y el servidor real de HestiaCP.

**Fallos encontrados en 1.13 durante las pruebas (corregidos):** la regla de valores extremos descartaba tareas normales; un integrante veía la tabla de carga por persona con solo una persona; al aplicar un escenario el aviso desaparecía por una recarga completa; revertir hacia un sprint sin horas libres daba un mensaje poco claro; la tabla de capacidad del informe desbordaba 7 px en el móvil. Otros fallos eran de las propias pruebas (datos de preparación).

**Novedades 1.12:** grupo «1.12 · Monitoreo de cumplimiento y carga de trabajo» en integración, con los 10 escenarios obligatorios de la Fase B: (1) tarea vencida visible en Monitoreo y en su indicador; (2) completada fuera de plazo con su historial; (3) 5 h estimadas y 12 h registradas → +7 h, +140 %; (4) 15 h de capacidad y 22 h planificadas → sobrecarga de 7 h y alerta; (5) 15 de 15 h con trabajo pendiente; (6) dependencia de una tarea bloqueada sin cambiar fechas; (7) revisión repetida sin duplicados; (8) permisos por URL y API; (9) reprogramación con historial; (10) sprint cerrado incompleto con su compromiso original. Además: migración 014 repetida, dependencias circulares directas e indirectas, duplicadas, consigo misma y con otro proyecto rechazadas, horas duplicadas evitadas, corrección de horas con historial, escalado a nivel 2 sin repetir avisos, «Marcar revisada», resolución automática, umbrales y CSV. «Novedades 1.12» en el navegador recorre las seis vistas, la ficha con «Seguimiento» y el móvil. También se probó actualizar una base con datos de 1.11.0. **No probado:** el cron real de HestiaCP (se probó `bin/notify.php` desde la consola), Safari y Firefox.

**Fallos encontrados en 1.12 durante las pruebas (corregidos):** la instalación desde cero fallaba porque una restricción nueva repetía el nombre de otra de 1.3; la revisión desde el navegador no corría (solo funcionaba desde el cron); cerrar un sprint daba error 500 por una columna ambigua; dos revisiones en el mismo segundo no resolvían las alertas; una dependencia circular directa se rechazaba como «duplicada» en lugar de «circular»; la vista de alertas desbordaba en el móvil.

**Novedades 1.11:** grupo «1.11 · Proyectos planificados, tareas atrasadas y fuera de plazo» en integración (migración 013 repetida, estado Planificado al crear, filtrar y cambiar, estados inválidos y cambios de alguien ajeno rechazados, «N días de retraso» sin cambiar el estado, fecha límite original guardada al finalizar y al mover en el tablero y conservada al cambiar la fecha, reabrir, «A tiempo», «Hoy» y «Próximas» sin solaparse, «Ya no eres responsable de…» con uno y con varios responsables, ningún aviso de retraso a la administración, exportación) y «Novedades 1.11» en el navegador (API de puntualidad, tarjeta del tablero, Hoy, Vencidas, Finalizadas, ficha, nuevo proyecto planificado y móvil sin desplazamiento horizontal). También se probó actualizar una base con datos de 1.10.0 (migración 013 aplicada dos veces: 84 tareas y 23 proyectos sin pérdidas, 5 tareas finalizadas con su fecha original) y la reversión a 1.10.0 con el SQL del README. **No probado:** Safari y Firefox, y la reversión en el servidor real.

**Fallos encontrados en 1.11 durante las pruebas:** ninguno de la aplicación; todas las pruebas pasaron en el primer intento.

**Novedades 1.10:** grupo «1.10 · Enlaces de Drive y GitHub en las tareas» en integración (guardar y normalizar los enlaces, SSH de GitHub, siete enlaces no válidos rechazados con el motivo, historial, ficha con botones Copiar y Abrir, solo lectura para observadores en página y API, API de la tarea, duplicar, vaciar, exportar e importar un proyecto descartando enlaces no válidos) y «Novedades 1.10» en el navegador (pegar y guardar al salir del campo, Copiar comprobado leyendo el portapapeles real, aviso, rechazo de un enlace de GitLab, panel lateral del tablero y móvil sin desplazamiento horizontal). También se probó actualizar una base con datos de 1.9.0 (migración 012 aplicada dos veces, sin pérdida de tareas). **No probado:** la vía alternativa de copiar cuando Borealis se sirve con http sin certificado (las pruebas usan 127.0.0.1, que el navegador trata como seguro), Safari y Firefox.

**Fallos encontrados en 1.10 durante las pruebas:** el icono de GitHub (`code`) no existía en el juego de iconos, por lo que tampoco se veía en «Todo a mano» desde 1.8; se añadió. Al rechazar un enlace, el aviso solo decía «Revisa los campos marcados»; ahora muestra el motivo del campo. Una comprobación de la importación era de la propia prueba (confundía «sin enlace» con «campo ausente»).

**Novedades 1.9:** grupos «1.9 · …» en integración (hora de entrega; Google Calendar: conectar, elegir calendario, envío de tareas sin duplicados, opciones, privacidad, cuota y reintentos, desconectar y reconectar; preferencias de notificaciones; recordatorios 24 h y 30 min con el cron y una hora simulada; correo SMTP con STARTTLS contra `tests/fake-services/smtp.php`; PWA; repetir la migración 011) más la prueba sin servidor `tests/unit/reminders.php`, y «Novedades 1.9» en el navegador (hora de entrega, Google Calendar en el perfil, correo, notificaciones en móvil e instalar como aplicación). **Google Calendar, el correo y los avisos al teléfono no se probaron con servicios reales:** se probaron contra servidores locales que imitan sus respuestas. Falta probarlos en el servidor de HestiaCP con un cliente OAuth, un buzón y un teléfono reales (pasos en `docs/CONFIGURACION-1.9.md`).

**Fallos encontrados en 1.9 durante las pruebas:** al desconectar y reconectar Google después de que un evento se hubiera recreado con un id elegido por Google, se duplicaba; ahora Borealis marca sus eventos y busca la copia anterior antes de crear otra. La API de una tarea no devolvía la hora de entrega (se guardaba bien, pero no salía en la respuesta). Al revisar las capturas, la hora se veía como «04:30 p. m.» porque Chromium en español de México usa 12 horas; ahora todas las horas se escriben y se muestran en 24 horas (hh:mm), igual que las fechas en dd/mm/aaaa. Dos fallos eran de las propias pruebas (el servidor SMTP de prueba guardaba en caché si debía simular una caída; una comprobación de «sin datos personales» confundía `@media` con una dirección de correo).

**Novedades 1.8.1:** grupo «1.8.1 · Fechas en día/mes/año» en integración (CSV de tareas y horas en dd/mm/aaaa, el servidor sigue exigiendo la fecha interna) y «Fechas dd/mm/aaaa (1.8.1)» en el navegador, con el navegador en inglés (en-US): campo dd/mm/aaaa con botón de calendario, barras automáticas, guardado y recarga, fecha inexistente rechazada y diálogo de evento.

**Fallos encontrados en 1.8.1 durante las pruebas:** el campo de fecha tenía un patrón de validación que Chromium no acepta (error en la consola); se quitó, la validación la hace el propio campo. Una prueba de GitHub de 1.8 fallaba a veces porque esperaba 2,6 s a que el servidor releyera `config.php`; ahora espera 3,2 s.

**Novedades 1.8:** grupos «1.8 · …» en integración (calendario personal y de equipo y sus permisos, preferencias y avisos, sprints en dos etapas y en el calendario, «Todo a mano», Discord, GitHub, Web Push, cron, migración 010) y «Novedades 1.8» en el navegador (selector de calendarios en escritorio y móvil, diálogo de calendario de equipo, preferencias de notificaciones, sprint en dos etapas, enlaces de «Todo a mano»).

**Fallos encontrados en 1.8 durante las pruebas:** un dispositivo recién activado recibía de golpe los avisos del día (ahora solo lo nuevo); la vista semanal no marcaba los eventos personales; el texto del cierre de sprint decía «1 completadas» (ahora «1 de 2 tareas completadas»); una prueba del calendario de 1.6 esperaba el texto anterior «Inicio:» de los sprints.

**Novedades 1.7.1:** grupo «1.7.1 · Mis tareas, Completadas, Nueva tarea y tutorial» en integración y «Novedades 1.7.1» en el navegador (tutorial por las seis secciones con el servidor sin poder guardar el paso, clics bloqueados, Anterior entre páginas, Reiniciar; Nueva tarea con 6 h; Mis tareas; Completadas medida en escritorio y móvil).

**Fallos encontrados en 1.7.1:** el tutorial elegía para el paso «Sprints» un proyecto sin el módulo de sprints (mostraba «No encontrado»); ahora prefiere uno con sprints. Tres pruebas de horas fallaban de madrugada (UTC) por usar la fecha UTC en lugar de la de la instalación.

**Novedades 1.7:** grupos «1.7 · …» de integración (enlaces de los mensajes con Borealis en una carpeta, horas semanales al crear la cuenta y en invitaciones, capacidad de sprint de 15 h × 2 semanas = 30 h, historial de sprints terminados, proyectos visibles solo para quienes participan —inicio, listado, búsqueda, calendario, tareas de equipo, enlace directo y API—, fotografía de perfil, menú lateral y repetición de la migración 009) y «Novedades 1.7» en el navegador (recorte real de una fotografía, alta de cuenta con horas y enlace, «Calendario» en escritorio y móvil) más la barra lateral sin desplazador.

**Fallos encontrados en 1.7 y corregidos antes de entregar:** el origen de las horas de un sprint («source») chocaba con otro dato del mismo nombre y no se veía la explicación en sprints terminados; las copias de horas al cambiar las semanales se creaban también en sprints de proyectos donde la persona no participa.

**Novedades 1.6:** grupos «1.6 · …» de integración (saludo y caché, Mis tareas y su privacidad, tareas de equipo, calendario general, reuniones, permisos, varios calendarios, recordatorios, zonas horarias y Google) y «Novedades 1.6» en el navegador (tutorial por varias páginas, saludo en tres zonas horarias, desplazador, fondos de los seis temas, Mis tareas, calendario, reunión semanal, arrastrar una tarea, calendarios, contraste y móvil).

**Google — qué se probó y qué no:** todo el lado de Borealis se probó contra `tests/fake-google/server.php`, un servidor local que responde como los puntos de Google que usa Borealis (OAuth con PKCE, tokens, renovación, `invalid_grant`, lista de calendarios, eventos con `syncToken`, páginas, 410, 412, 503, Google Tasks). **No se probó contra Google real**: hace falta un cliente OAuth creado en Google Cloud y una instalación con HTTPS (pasos en el README). Hasta hacerlo, no se puede asegurar que Google acepte la configuración de cada instalación (dominio, pantalla de consentimiento, verificación de la app).

**Fallos encontrados en 1.6 y corregidos antes de entregar:** la repetición mensual el día 31 repetía meses; los eventos de todo el día con fondo de color y texto blanco no llegaban al contraste 4,5:1 (2,15 con ámbar); botones ocultos del diálogo de evento se veían; en las pruebas, el servidor de PHP tardaba hasta 2 s en releer `config.php` (OPcache).

**1.5.1:** las ilustraciones de los temas (imágenes de Cal) se sirven correctamente (nueva prueba del navegador) y se revisaron en capturas. En la barra lateral se oscurecieron lo justo para que el texto secundario que pasa por encima mantenga al menos 4,5:1, calculado sobre los píxeles de cada imagen (Medieval 4,70; Agua 4,75; Fuego 4,80; Cielo 4,69; Bosques 4,57).

**Novedades 1.5:** grupo «1.5 · Capacidad agotada — Trabajo pendiente» (44 pruebas de integración: detección, dónde se muestra, permisos, las cinco decisiones, reapertura, historial, casos especiales y repetir la migración 007) y «Novedades 1.5» en el navegador (temas en la barra lateral y el símbolo con contraste medido, sin recarga, persistencia al volver a entrar, reducir movimiento; resolver una alerta desde el panel). Actualización desde 1.4.1 con datos: ver al final. No probado en 1.5: el paso real de una semana a la siguiente con alertas abiertas (solo se probó con semanas calculadas en la misma prueba), temas en Safari y Firefox.

**Seguridad 1.4.1:** la auditoría de 1.4.0 encontró una toma de cuentas entre espacios que ninguna prueba cubría; se reprodujo a mano y ahora es una prueba (grupo «1.4.1 · Seguridad…»). Las IP distintas se simulan con direcciones de origen 127.0.0.x. Prueba de actualización: una base de 1.4.0 con datos, al sustituir los archivos por los de 1.4.1, conserva todos los registros y sus 53 pantallas cargan (sin migraciones pendientes).

**Fallos reales encontrados por las pruebas y corregidos:** finalizar un sprint con un destino manipulado provocaba un error 500 (ahora 422); se aceptaban imágenes cuyo contenido no coincidía con la extensión; el área principal se veía en blanco en escritorio (capa del menú móvil ocupando la cuadrícula); los menús de las tarjetas quedaban recortados dentro de las columnas; en móvil la lista desbordaba la página; un título vacío dejaba el panel marcado como «con cambios sin guardar». En 1.1, revisando capturas: las barras de los reportes no se veían y los botones del sprint se salían de la tarjeta en móvil; las pruebas nuevas detectaron que la exportación de tareas de un proyecto archivado salía vacía. En 1.2: «Invitar por enlace» mostraba el cuadro del mensaje vacío porque el formulario se reiniciaba justo después de rellenarlo (afectaba también al mensaje de «Crear cuenta»). En 1.3: con clics al mismo ritmo que la espera del guardado (unos 250-300 ms) se perdían los clics posteriores al primero; al arrastrar desde una tarjeta, el aviso «Suelta aquí» desplazaba el tablero y el navegador no completaba el arrastre; soltar en una tarjeta sin horas no mostraba el motivo. En 1.4: si se salía de la página justo al terminar el tutorial, a veces no se guardaba y volvía a abrirse (y se quedaba con la tecla Escape); ahora el guardado sobrevive al cambio de página. En 1.5 las pruebas no encontraron fallos de la aplicación (los dos primeros intentos fallaron por errores de la propia prueba: iniciar un sprint vacío y esperar el aviso en quien ya estaba viendo la alerta). Al escribir la prueba del navegador se vio, leyendo el código, que el panel no se habría vaciado tras resolver la última alerta; se corrigió antes de ejecutarla.

## Integración HTTP

### Instalación

- ✅ Sin configuración se muestra el instalador
- ✅ Credenciales de base incorrectas: error visible y datos conservados
- ✅ Contraseña corta rechazada en la instalación
- ✅ Instalación correcta redirige a la aplicación
- ✅ Migraciones aplicadas (tablas creadas)
- ✅ Tras instalar, la sesión queda iniciada y se ve el inicio
- ✅ El instalador ya no está disponible
- ✅ Contraseña almacenada con Argon2id/bcrypt (nunca en claro)
- ✅ config.php escrito fuera de public/

### Autenticación y sesión

- ✅ Sin sesión, una página redirige al inicio de sesión
- ✅ Sin sesión, la API responde 401
- ✅ Sin sesión no se puede crear un proyecto
- ✅ Contraseña incorrecta: no inicia sesión y muestra error
- ✅ Login con token CSRF falso es rechazado
- ✅ Petición API sin token CSRF es rechazada (419)
- ✅ Cabeceras de seguridad (CSP, nosniff, frame)
- ✅ Cookie de sesión HttpOnly y SameSite
- ✅ Login correcto redirige
- ✅ Cerrar sesión invalida la sesión

### Personas y roles

- ✅ Alta de bruno con rol member
- ✅ Al crear la cuenta se da un mensaje para enviar con el enlace y el correo
- ✅ Alta de carla con rol member
- ✅ Alta de olga con rol guest
- ✅ Correo repetido en el mismo espacio rechazado con mensaje
- ✅ Las personas creadas pueden iniciar sesión
- ✅ Un integrante normal no accede a Administración
- ✅ Un integrante no puede crear cuentas (API)
- ✅ Un integrante no puede ascenderse a sí mismo

### Proyectos

- ✅ Proyecto sin nombre rechazado con error de campo
- ✅ Crear proyecto solo con nombre
- ✅ El proyecto nuevo se ve al recargar
- ✅ Renombrar proyecto
- ✅ El nombre nuevo aparece en resumen, listado y barra lateral (sin restos del anterior)
- ✅ El nombre nuevo persiste en la base de datos
- ✅ Nombre demasiado largo rechazado; el valor guardado no cambia
- ✅ Clave de proyecto duplicada rechazada
- ✅ Archivar proyecto
- ✅ Archivado: sale de la lista activa y aparece en archivados
- ✅ Proyecto archivado es de solo lectura
- ✅ Restaurar proyecto lo devuelve a la lista activa

### Equipos

- ✅ Equipo sin nombre rechazado
- ✅ Crear equipo
- ✅ Agregar integrante al equipo
- ✅ Renombrar equipo y verlo al recargar
- ✅ Asociar proyecto al equipo
- ✅ Quitar a quien no es integrante da error claro
- ✅ No se puede dejar el equipo sin coordinación
- ✅ La administración puede reasignar el rol en el equipo
- ✅ Archivar y restaurar equipo

### Tareas

- ✅ Tarea sin título rechazada
- ✅ Crear tarea solo con título
- ✅ Editar título, descripción, responsable y fecha
- ✅ La edición se ve al recargar la página de la tarea
- ✅ Mover tarea a «En progreso»
- ✅ Finalizar tarea (y señal de celebración)
- ✅ Última tarea abierta finalizada: se ofrece el resumen del proyecto
- ✅ Reabrir tarea
- ✅ Crear subtarea
- ✅ Comentar con mención
- ✅ La persona mencionada/asignada recibe notificación
- ✅ Duplicar tarea (incluye subtareas)
- ✅ Edición masiva de prioridad
- ✅ Eliminar tarea (borrado recuperable)
- ✅ La tarea eliminada ya no se muestra
- ✅ Texto con HTML se muestra escapado (sin XSS)
- ✅ Lista con filtros conserva los filtros en la vista
- ✅ Siglas no válidas rechazadas
- ✅ Cambiar las siglas del proyecto
- ✅ El enlace con las siglas antiguas redirige a la clave nueva
- ✅ Las siglas antiguas quedan reservadas para no romper enlaces
- ✅ Volver a las siglas originales también conserva los enlaces intermedios

### Permisos

- ✅ Un integrante no ve un proyecto privado ajeno
- ✅ El proyecto privado no aparece en su listado
- ✅ Tampoco puede leer sus tareas por la API
- ✅ Ni abrir la tarea por su enlace
- ✅ Petición manipulada para editar una tarea privada: rechazada
- ✅ La búsqueda no filtra contenido privado
- ✅ Proyecto en el que no participa (aunque se pidiera «visible para el espacio»): no se ve
- ✅ Sin ser integrante no puede modificar
- ✅ Añadir a Olga como observadora
- ✅ La observadora puede ver el tablero
- ✅ La observadora no ve controles de edición en el tablero
- ✅ La observadora no puede editar, crear, mover ni eliminar
- ✅ …y la tarea sigue intacta en la base
- ✅ Invitada (guest) no ve proyectos privados
- ✅ Integrante del equipo accede al proyecto del equipo
- ✅ Integrante del equipo puede editar tareas (colaboración)
- ✅ Colaboración no puede cambiar ajustes del proyecto
- ✅ Colaboración no accede a Ajustes del proyecto
- ✅ Estado de otro proyecto rechazado
- ✅ Responsable inexistente rechazado
- ✅ Tarea padre de otro proyecto rechazada
- ✅ Fecha inválida rechazada
- ✅ Crear un segundo espacio de trabajo
- ✅ El proyecto se crea en el espacio activo
- ✅ Quien no pertenece a otro espacio no accede a nada de él
- ✅ No se puede asignar a alguien de otro espacio

### Módulos por proyecto

- ✅ Sprints desactivado por defecto (la página no existe)
- ✅ Activar Sprints activa también su dependencia Backlog
- ✅ No se inicia un sprint vacío
- ✅ Iniciar sprint con tareas
- ✅ Destino manipulado al finalizar sprint: error de validación, no fallo del servidor
- ✅ Finalizar sprint: pendientes vuelven al backlog
- ✅ Desactivar Backlog desactiva Sprints (dependencia)
- ✅ Desactivar un módulo no borra datos
- ✅ Los módulos base no se pueden desactivar

### Archivos adjuntos

- ✅ Subir archivo de texto a una tarea
- ✅ Se guarda con nombre aleatorio fuera de public/
- ✅ Archivo .php rechazado
- ✅ Doble extensión peligrosa rechazada
- ✅ Contenido que no coincide con la extensión rechazado
- ✅ Descarga con permisos y cabeceras seguras
- ✅ Sin sesión no se descarga el archivo
- ✅ Archivo de un proyecto privado ajeno: no descargable

### Preferencias, exportación y respaldo

- ✅ Preferencia de animaciones «desactivadas» se aplica
- ✅ Valor de preferencia inválido rechazado
- ✅ Exportar tareas a CSV
- ✅ CSV neutraliza fórmulas
- ✅ Exportar el espacio a JSON
- ✅ Copia de seguridad SQL completa (administración de la instalación)
- ✅ Un integrante no puede crear copias completas
- ✅ La descarga de copias no permite recorrer rutas
- ✅ Actualizar base sin migraciones pendientes no hace nada

### Importación desde Borealis v0.2

- ✅ Administración › Datos ofrece la importación
- ✅ Un integrante no puede importar
- ✅ Archivo que no es de Borealis rechazado
- ✅ Importación desde el ZIP de la versión anterior
- ✅ Checklists como subtareas, comentarios, equipos y sprints
- ✅ Personas: nuevas creadas y la existente reutilizada por correo
- ✅ Se hizo copia de seguridad antes de importar
- ✅ Las claves de tarea se conservan (LEG-101)
- ✅ Estados propios del proyecto con su categoría
- ✅ Código repetido recibe un número nuevo sin chocar
- ✅ Solo un sprint activo por proyecto
- ✅ Clave repetida y clave inválida se ajustan con aviso
- ✅ Tipo y etiquetas pasan a etiquetas
- ✅ Módulos activados según los datos (sprints, estimaciones, calendario)
- ✅ Solo se importa el archivo válido (los peligrosos o ausentes se avisan)
- ✅ El archivo importado se descarga con su contenido
- ✅ La tarea importada se ve y su HTML queda escapado
- ✅ El tablero del proyecto importado carga
- ✅ Importar los mismos datos dos veces se rechaza
- ✅ Las personas importadas no pueden entrar hasta tener contraseña

### Protección contra fuerza bruta

- ✅ Tras varios fallos, el acceso se bloquea temporalmente

### Horas, cierre de tareas y horas semanales

- ✅ Registrar horas en formato 1:30
- ✅ Registrar horas con coma decimal (2,5 = 150 min)
- ✅ Horas no válidas, más de 24 h o en el futuro: rechazadas
- ✅ La responsable del proyecto registra horas de una participante
- ✅ Una colaboradora no puede registrar horas a nombre de otra persona
- ✅ Observación no puede registrar horas
- ✅ La tarea suma las horas registradas (90 + 150 + 45 = 285 min)
- ✅ La tarea muestra el total y el reparto por persona
- ✅ No se puede borrar el registro de otra persona sin ser responsable
- ✅ Borrar un registro propio (recuperable)
- ✅ Crear tarea con horas estimadas
- ✅ Al finalizar se ofrece dejar comentario y horas
- ✅ Cierre vacío: se pide algo o pulsar Omitir
- ✅ Cierre con comentario y horas guardados juntos
- ✅ Si las horas fallan no se guarda tampoco el comentario
- ✅ Definir horas semanales de una persona
- ✅ Horas semanales fuera de rango rechazadas
- ✅ Un integrante no cambia las horas de otra persona
- ✅ Cada persona define sus horas en su perfil

### Control de sprints

- ✅ Iniciar sprint con fechas
- ✅ El sprint muestra capacidad, planificado y gráfico de avance
- ✅ Alerta de sobrecarga cuando una persona tiene más horas que su capacidad
- ✅ Capacidad fijada a mano y alerta de sobrecarga del sprint
- ✅ Capacidad negativa rechazada
- ✅ Capacidad vacía vuelve al cálculo automático

### Exportar reportes

- ✅ Exportar horas del proyecto (CSV)
- ✅ El registro borrado no aparece en la exportación
- ✅ Exportar tareas incluye horas estimadas y registradas
- ✅ Informe imprimible del proyecto
- ✅ Exportaciones del espacio: proyectos, tareas y horas
- ✅ Informe imprimible del espacio
- ✅ Exportación desconocida: 404
- ✅ Las horas exportadas respetan la visibilidad (invitada no ve horas de otros proyectos)
- ✅ Sin acceso al proyecto no se exportan sus horas

### Cuentas fantasma

- ✅ Solo la administración de la instalación crea fantasmas
- ✅ Un fantasma no puede ser propietario
- ✅ Crear cuenta fantasma en el equipo
- ✅ Un fantasma no puede iniciar sesión con contraseña
- ✅ No se puede entrar como una persona real
- ✅ La administración ve la sección de fantasmas
- ✅ «Entrar como» cambia a la cuenta fantasma y muestra el aviso
- ✅ Lo que se hace como fantasma queda a su nombre
- ✅ Como fantasma no se accede a Administración ni se encadenan suplantaciones
- ✅ «Volver a mi cuenta» recupera la sesión de la administración
- ✅ Volver sin estar usando un fantasma: rechazado
- ✅ Un integrante no puede borrar fantasmas
- ✅ Borrar todos los fantasmas hace antes una copia de seguridad
- ✅ El fantasma queda anonimizado y sin acceso
- ✅ Sus tareas quedan sin responsable y sus comentarios se conservan

### Apariencia del proyecto

- ✅ Cambiar fondo y color del proyecto
- ✅ El encabezado usa el fondo elegido
- ✅ Fondo desconocido rechazado
- ✅ Crear equipo no te saca de la página (devuelve el enlace para abrirlo)

### 1.2 · Correo más estricto

- ✅ Correos mal escritos rechazados con un mensaje claro (7 casos)
- ✅ Un dominio típico mal escrito sugiere el correcto (gmail.con → gmail.com)
- ✅ La invitación también valida el correo
- ✅ Un correo válido con subdominio y «+» se acepta

### 1.2 · Administración edita cuentas

- ✅ La administración cambia nombre, correo y cargo de otra persona
- ✅ La persona entra con el correo nuevo
- ✅ No se puede poner un correo que ya usa otra cuenta
- ✅ Al editar también se valida el correo
- ✅ Un integrante no puede editar cuentas ajenas
- ✅ La lista de usuarios ofrece «Editar cuenta»

### 1.2 · Invitar por enlace

- ✅ Crear invitación devuelve el enlace y el mensaje para copiar
- ✅ El enlace de invitación abre el formulario de alta
- ✅ «Nuevo enlace» genera otro enlace para una invitación pendiente
- ✅ El enlace anterior deja de valer y el nuevo funciona

### 1.2 · Equipos como integrantes del proyecto

- ✅ Añadir un equipo completo da acceso a sus integrantes
- ✅ Ajustes › Integrantes muestra el equipo participante y a su gente
- ✅ Quien entra al equipo después también accede (acceso en vivo)
- ✅ Integrantes por equipo pueden crear tareas con el rol elegido
- ✅ Solo quien gestiona el proyecto puede añadir equipos

### 1.2 · Varios responsables o un equipo por tarea

- ✅ Asignar dos responsables: el primero es el principal
- ✅ Ambas personas reciben aviso
- ✅ La corresponsable ve la tarea en «Mi trabajo»
- ✅ Asignar una tarea a un equipo
- ✅ Integrantes del equipo ven la tarea del equipo en «Mi trabajo»
- ✅ La tarjeta muestra el equipo y varios responsables
- ✅ El CSV de tareas lista a todos los responsables y al equipo
- ✅ Quitar responsables deja solo a los elegidos
- ✅ Equipo inexistente rechazado

### 1.2 · Mover personas en el tablero (polivalente)

- ✅ Sin proyecto ni equipo polivalente no se pueden mover personas
- ✅ Con el equipo marcado polivalente, una persona del equipo se mueve de una tarjeta a otra
- ✅ El tablero muestra la fila de personas arrastrables
- ✅ Quien no está en un equipo polivalente no se mueve si el proyecto no es polivalente
- ✅ Con el proyecto polivalente, cualquiera del proyecto se puede sumar a una tarjeta
- ✅ Sin acceso al proyecto no se puede mover a nadie

### 1.2 · Perfil de participantes con sus horas

- ✅ El perfil muestra las horas de la semana, del período y por proyecto
- ✅ El período del perfil se puede cambiar
- ✅ Las horas de proyectos que no puedes ver no se muestran
- ✅ Los participantes enlazan a su perfil

### 1.2 · Exportar e importar proyectos

- ✅ Exportar proyecto descarga un .json completo
- ✅ Solo quien gestiona el proyecto puede exportarlo
- ✅ Importar crea un proyecto nuevo con siglas propias
- ✅ Se conservan tareas, subtareas, responsables, equipo, horas y comentarios
- ✅ El equipo participante y la marca polivalente se conservan
- ✅ Los números de tarea se mantienen (PROY-1, PROY-2…)
- ✅ Personas que no existen aquí se informan y no rompen la importación
- ✅ Un archivo que no es una exportación de Borealis se rechaza
- ✅ Sin permiso para crear proyectos no se puede importar
- ✅ Exportar con archivos también funciona

### 1.3 · Horas disponibles por persona y sprint

- ✅ Definir las horas disponibles de una persona en un sprint
- ✅ Solo quien gestiona sprints cambia la disponibilidad
- ✅ Horas disponibles negativas se rechazan
- ✅ Asignar 35 h de 40 h disponibles
- ✅ Una tarea de 8 h con 5 h libres se rechaza con el motivo
- ✅ La tarea rechazada queda sin cambios
- ✅ Alcanzar exactamente el límite (40 de 40 h) se permite
- ✅ Tablero del sprint: la persona pasa a «Sin disponibilidad» y no se puede arrastrar
- ✅ Una tarea sin horas estimadas no se bloquea: queda pendiente de estimar
- ✅ El sprint muestra la carga y la tarea pendiente de estimar
- ✅ Poner horas a esa tarea cuando ya no caben se rechaza
- ✅ Subir las horas de una tarea por encima de lo disponible se rechaza
- ✅ Retirar a la persona de una tarea (arrastrar fuera)
- ✅ Recupera 5 h y vuelve a «Disponibles»
- ✅ Reasignar entre tareas: de C (10 h) a D (8 h)
- ✅ Un movimiento que no cabe se rechaza entero: sigue en la tarea de origen
- ✅ Con dos responsables, la tarea reparte sus horas (15 h → 7,5 h cada una)
- ✅ La carga de la persona baja a 25,5 h (10 + 7,5 + 8)
- ✅ Bajar la disponibilidad por debajo de la carga avisa y no borra tareas
- ✅ El sprint marca la sobrecarga de esa persona
- ✅ Estando por encima, se le puede quitar trabajo
- ✅ Estando por encima, no se le puede dar más
- ✅ Vaciar la disponibilidad vuelve al cálculo automático (sin horas semanales: sin límite)
- ✅ Sin límite definido, se puede asignar
- ✅ Crear una tarea ya asignada también respeta las horas
- ✅ Pasar al sprint una tarea que no cabe se rechaza; fuera del sprint no cuenta

### 1.4 · Archivos del proyecto

- ✅ Todo proyecto tiene «Archivos», aunque no use Documentos
- ✅ Subir una imagen y un documento al proyecto
- ✅ Vista de cuadrícula con miniatura de la imagen
- ✅ Vista de lista con formato, tamaño y fecha
- ✅ Cambiar el nombre visible conserva la extensión
- ✅ Un nombre con extensión peligrosa se rechaza
- ✅ Quien no participa en el proyecto no puede ver ni renombrar sus archivos
- ✅ La imagen se abre en el navegador y el resto se descarga
- ✅ Eliminar un archivo lo quita de la lista

### 1.4 · Sprints por estado

- ✅ Sprints agrupados en Activo, Planificados y Finalizados
- ✅ Sin sprint activo se muestra el siguiente planificado con su detalle

### 1.4 · Tareas: el nombre se edita dentro

- ✅ En la lista el nombre es texto que abre la tarea (no un campo)
- ✅ Dentro de la tarea el nombre sí se edita
- ✅ Renombrar no cambia la clave ni las relaciones

### 1.4 · Apariencia y tutorial por persona

- ✅ El fondo elegido se guarda y se aplica solo a esa persona
- ✅ Un fondo que no existe se rechaza
- ✅ Perfil › Apariencia muestra las 6 temáticas y el predeterminado
- ✅ Volver al estilo predeterminado
- ✅ Una persona nueva ve el tutorial al entrar
- ✅ Tras omitirlo no vuelve a empezar solo (y es por persona)
- ✅ «Ver el tutorial otra vez» lo vuelve a activar

### Recorrido de todas las pantallas

- ✅ Administración: las 38 pantallas cargan sin errores
- ✅ Observación: sus pantallas cargan sin errores
- ✅ Observación: Ajustes del proyecto prohibidos
- ✅ Ruta inexistente: página 404 propia

### 1.4.1 · Seguridad de cuentas, roles, importación, búsqueda e inicio de sesión

- ✅ Caso 1: una invitada y un integrante no pueden crear espacios (petición directa)
- ✅ Caso 1: la instalación nueva deja la creación libre de espacios desactivada
- ✅ Caso 1: con app.allow_workspace_creation = true sí se permite
- ✅ Caso 1: al volver a desactivarla se rechaza de nuevo
- ✅ Preparación: administradora local de otro espacio
- ✅ Caso 1: la administración local no ve el formulario «Crear espacio»
- ✅ Caso 2: correo existente → invitación, sin membresía activa
- ✅ Caso 2: no cambia la contraseña ni el perfil de la cuenta existente
- ✅ Caso 2: el mensaje no muestra ninguna contraseña
- ✅ Caso 2: aceptar la invitación con una contraseña equivocada no incorpora
- ✅ Caso 2: la persona se incorpora solo al aceptar con su contraseña
- ✅ Caso 3: la administración local no restablece la contraseña de una cuenta compartida
- ✅ Caso 3: tampoco cambia su nombre global
- ✅ Caso 3: ni de alguien que no pertenece a su espacio
- ✅ Caso 4: nadie local cambia la contraseña ni el correo de la administración de la instalación
- ✅ Cadena C1 de 1.4.0 reproducida: bloqueada en cada paso
- ✅ Caso 7: la administración local crea cuentas nuevas y restablece la contraseña de las suyas
- ✅ Caso 7: edita la cuenta y cambia el rol de quien solo está en su espacio
- ✅ Caso 7: la administración de la instalación sí restablece cuentas compartidas
- ✅ Preparación: rol personalizado con members.manage y sin roles.manage
- ✅ Caso 5: sin roles.manage no se convierte (ni convierte a nadie) en Administrador
- ✅ Caso 7: ese rol sí da de alta cuentas con un rol sin más permisos que el suyo
- ✅ Caso 5: con roles.manage tampoco: no amplía su rol, no crea roles superiores, no asigna ni edita Administrador
- ✅ Caso 5: tampoco suspende a quien tiene permisos que ella no tiene
- ✅ Caso 6: cambiar el propio rol se rechaza (también a la propietaria)
- ✅ Caso 7: la propietaria sigue asignando y quitando el rol Administrador
- ✅ Caso 8: solo el archivo válido se importa; los otros cuatro se rechazan
- ✅ Caso 8: en disco queda exactamente un archivo nuevo
- ✅ Caso 8: si la importación falla no deja proyecto ni archivos huérfanos
- ✅ Caso 9: la búsqueda de una invitada solo muestra personas de sus proyectos
- ✅ Caso 9: una invitada no encuentra personas por correo; la administración sí
- ✅ Caso 9: equipos y perfiles fuera de su alcance no aparecen ni se abren
- ✅ Caso 7: una invitada sí abre el perfil de quien comparte proyecto con ella
- ✅ Caso 7: un integrante sigue encontrando a todo el espacio
- ✅ Caso 10: desde la IP atacante, tras 5 fallos los intentos se frenan (429)
- ✅ Caso 10: esa IP queda frenada incluso con la contraseña correcta
- ✅ Caso 10: la persona atacada sigue entrando desde su propia IP
- ✅ Caso 10: una IP que prueba muchas cuentas queda bloqueada (30 fallos)
- ✅ Caso 10: los registros de más de un día se limpian
- ✅ Caso 10: un correo inexistente tarda lo mismo que uno real (mismo algoritmo)
- ✅ Script de revisión bin/audit-accounts.php se ejecuta y no modifica nada

### 1.5 · Capacidad agotada — Trabajo pendiente

- ✅ Preparación: personas con horas semanales (Juan 15 h)
- ✅ Preparación: tareas, sprint activo que termina esta semana y otro planificado
- ✅ Con 10 h de 15 h registradas no hay alerta
- ✅ Al llegar a 15 h con tareas pendientes se crea la alerta
- ✅ Cuenta las horas de todos sus proyectos una sola vez (10 h + 5 h = 15 h)
- ✅ Tareas comprometidas: vencen esta semana o están en un sprint activo que termina esta semana (4); no la terminada ni la vencida antes
- ✅ Se notifica una vez a la administración (y no a Juan)
- ✅ Más horas no duplican la alerta ni la notificación
- ✅ Inicio de la administración: «Juan Capacidad ha utilizado 16 h de sus 15 h disponibles… 4 tareas pendientes»
- ✅ Distingue horas consumidas de trabajo estimado y marca lo no estimado (sin inventar horas)
- ✅ La tarea compartida cuenta solo su parte (6 h entre 2 = 3 h)
- ✅ Las vencidas de semanas anteriores aparecen aparte
- ✅ Se muestra en Equipos, Alertas de capacidad, el proyecto, el sprint y el perfil de la persona
- ✅ Juan (integrante) no ve el panel ni la página de alertas
- ✅ Ver la alerta no la resuelve
- ✅ La responsable de un proyecto afectado ve la alerta
- ✅ Un integrante no puede resolver alertas (403)
- ✅ Mantener pendiente exige una justificación
- ✅ Mantener pendiente con justificación resuelve y registra quién y qué
- ✅ Si después se le compromete más trabajo, la alerta se reabre y se avisa de nuevo a la responsable del proyecto (quien lo ve en pantalla no recibe aviso de sí mismo)
- ✅ Autorizar 0 horas se rechaza
- ✅ Autorizar horas adicionales: registra quién y cuántas, y resuelve
- ✅ Las horas adicionales no se registran como horas trabajadas
- ✅ Al agotar también las horas adicionales (18 h) la alerta se reabre
- ✅ No se puede actuar sobre una tarea que no está en la alerta
- ✅ Revisar la estimación cambia la tarea y resuelve la alerta
- ✅ Reprogramar dentro de la misma semana se rechaza
- ✅ Reprogramar una tarea a otra fecha: cambia la tarea y la alerta sigue abierta por las demás
- ✅ Reprogramar al sprint siguiente
- ✅ Reasignar a alguien sin horas disponibles se rechaza
- ✅ El formulario de reasignar ofrece solo personas con horas disponibles
- ✅ Reasignar a una persona con horas libres
- ✅ En una tarea compartida solo se sustituye a Juan; la otra persona sigue
- ✅ Mientras queda trabajo, la alerta sigue abierta
- ✅ Al cambiar la persona responsable desde la tarea se recalcula y se resuelve sola («ya no hay trabajo pendiente»)
- ✅ Ninguna acción completó tareas automáticamente
- ✅ Historial completo de la alerta
- ✅ La página de historial muestra quién resolvió y qué se decidió
- ✅ Cada decisión queda en el registro de actividad
- ✅ Sin horas semanales definidas no hay alerta (no se inventa una capacidad)
- ✅ Horas agotadas pero todo terminado: sin alerta
- ✅ Horas agotadas con solo tareas vencidas de semanas anteriores: sin alerta
- ✅ Las tareas siguen en su estado: nada se cerró solo
- ✅ La migración 007 se puede repetir sin errores ni pérdida de datos

### 1.6 · Saludo, caché y temas

- ✅ El saludo usa el nombre real («Carlos»), no «Usuario»
- ✅ El saludo del servidor sigue la hora de la instalación (el navegador lo ajusta a la hora local)
- ✅ Las páginas no se guardan en cachés intermedias (Cache-Control: no-store, Vary: Cookie)
- ✅ Una cuenta llamada «Usuario» se saluda con su identificador de cuenta
- ✅ Cada sesión recibe su propio saludo (sin mezclar personas)
- ✅ Los seis temas definen el fondo del área principal (no solo la barra lateral)
- ✅ La barra lateral tiene un desplazador fino y discreto
- ✅ El tema es por persona y se conserva

### 1.6 · Mis tareas (personales y privadas)

- ✅ La página Tareas abre en «Mis tareas» con una lista inicial
- ✅ Crear tarea personal con prioridad, fecha límite y notas
- ✅ Crear subtarea
- ✅ Las subtareas no admiten otro nivel (validación clara)
- ✅ Reordenar arrastrando guarda el orden
- ✅ Nadie más puede reordenar la lista de otra persona
- ✅ La administración no ve las tareas personales de otra persona
- ✅ Ni la administración puede leer, editar ni borrar una tarea personal ajena (404)
- ✅ Las tareas personales no aparecen en búsquedas, reportes ni proyectos de nadie
- ✅ Los reportes del espacio no incluyen tareas personales
- ✅ Completar una tarea completa también sus subtareas
- ✅ «Completadas» muestra las tareas personales terminadas
- ✅ Las completadas salen de la lista abierta
- ✅ No se puede borrar la última lista
- ✅ Mover una tarea a otra lista

### 1.6 · Tareas de equipo (sistema existente)

- ✅ «Asignadas a mí» muestra tareas de proyectos, con su sprint
- ✅ «Equipo» muestra las tareas abiertas de los proyectos donde participa
- ✅ Las tareas de equipo siguen vinculadas a su proyecto y sprint
- ✅ Una persona invitada no ve en «Equipo» tareas de proyectos ajenos

### 1.6 · Calendario general

- ✅ Vista Día
- ✅ Vista Semana
- ✅ Vista Mes
- ✅ Navegación con «Hoy», anterior y siguiente
- ✅ El calendario funciona sin Google configurado
- ✅ Calendarios de ejemplo (Equipo, Proyectos, Reuniones, Eventos) y uno personal por persona
- ✅ El vencimiento de una tarea aparece en el calendario (sin copiarla)
- ✅ El inicio y el fin del sprint aparecen en el calendario
- ✅ Cambiar la fecha de la tarea actualiza el calendario
- ✅ Crear la reunión semanal de los lunes a las 10:00
- ✅ Se guarda en UTC (10:00 en Ciudad de México = 16:00 UTC)
- ✅ Con lugar, enlace de videollamada, proyecto y participantes
- ✅ Los participantes reciben un aviso
- ✅ La repetición semanal aparece cada lunes del mes
- ✅ Un participante la ve en su calendario
- ✅ El detalle muestra la hora local, participantes y quién puede editar
- ✅ Crear un evento de todo el día de dos días
- ✅ En la vista semana los eventos de todo el día van arriba, distinguidos de las reuniones
- ✅ Validación: el final debe ser posterior al inicio
- ✅ Validación: el enlace de videollamada debe ser https
- ✅ Editar un evento
- ✅ Arrastrar un evento a otro día conserva su duración
- ✅ Una reunión que se repite no se mueve arrastrando (se pide abrirla)
- ✅ «Eventos» es de solo lectura para los integrantes
- ✅ Un participante sin permiso de edición la ve, pero no la cambia
- ✅ El calendario personal es privado, también frente a la administración
- ✅ Una persona invitada no ve calendarios compartidos a los que no se la añadió
- ✅ Al añadirla con acceso de lectura, lo ve
- ✅ Ocultar y volver a mostrar un calendario (se recuerda por persona)
- ✅ Ocultar los vencimientos de tareas como capa
- ✅ Crear un calendario compartido con color: los demás lo ven
- ✅ Solo quien lo administra puede eliminarlo
- ✅ Al eliminarlo, sus eventos pasan al calendario elegido
- ✅ O se eliminan junto con él (quedan ocultos, recuperables en la base)
- ✅ Recordatorio dentro de la aplicación 30 minutos antes
- ✅ El recordatorio no se duplica
- ✅ Cancelar una reunión la deja visible como cancelada y avisa
- ✅ Eliminar un evento lo oculta del calendario
- ✅ Horario: 10:00 en Nueva York antes del cambio de horario se guarda como 14:00 UTC
- ✅ Horario: 10:00 en Nueva York después del cambio se guarda como 15:00 UTC
- ✅ Horario: Reunión semanal de los lunes: 4 repeticiones en 4 semanas
- ✅ Horario: Siempre a las 10:00 hora local, también tras el cambio de horario
- ✅ Horario: Todas caen en lunes
- ✅ Horario: «Hasta» corta la repetición
- ✅ Horario: Mensual el día 31: salta los meses que no lo tienen
- ✅ Horario: Evento de todo el día: fechas sin desplazamiento por zona horaria
- ✅ Horario: El mismo evento de todo el día no cambia de fecha en otra zona
- ✅ Horario: Un evento que termina justo al empezar el rango no aparece en él

### 1.6 · Google Calendar y Google Tasks

- ✅ Sin configurar Google: no se finge la conexión; se explica que falta configurarla
- ✅ Configurado: el perfil ofrece «Conectar con Google» y explica los permisos
- ✅ Autorización real con OAuth: redirige al consentimiento con state, PKCE y acceso sin conexión
- ✅ Permisos mínimos: correo, lista de calendarios, eventos y tareas (no todo Google Calendar)
- ✅ Una respuesta con «state» falso se rechaza
- ✅ Si la persona no da permiso, se informa y no se conecta
- ✅ Conexión completada: cuenta, correo y estado guardados
- ✅ Los tokens se guardan cifrados en el servidor
- ✅ La clave de cifrado está en storage/ con permisos 0600
- ✅ El navegador nunca recibe los tokens
- ✅ Elegir calendarios: se listan los de la cuenta de Google
- ✅ Importa los eventos de los calendarios elegidos (con varias páginas)
- ✅ Zona horaria: 09:00 −06:00 de Google se guarda como 15:00 UTC
- ✅ Sincronizar varias veces no duplica (identificadores estables de Google)
- ✅ La sincronización es incremental (syncToken)
- ✅ Los calendarios de Google se distinguen de los internos
- ✅ Cambios hechos en Google llegan a Borealis
- ✅ Crear un evento en un calendario de Google lo crea también en Google
- ✅ …y la siguiente sincronización no lo duplica
- ✅ Conflicto: si cambió en Google mientras tanto, gana Google y se avisa
- ✅ Un calendario de Google de solo lectura no admite eventos nuevos
- ✅ Si Google invalida la sincronización (410), se rehace completa sin duplicar
- ✅ Si Google falla, el error se ve y el calendario interno sigue funcionando
- ✅ Importar Google Tasks: la lista y sus tareas, con subtareas y fechas
- ✅ Sincronizar las tareas de nuevo no las duplica
- ✅ Cambios y tareas nuevas de Borealis se envían a Google Tasks
- ✅ Conflicto en una tarea: gana Google y se informa
- ✅ Borrar en Borealis no borra nada en Google ni la vuelve a importar
- ✅ Una tarea eliminada en Google se quita de Borealis
- ✅ Las tareas de Google Tasks también son privadas
- ✅ Token caducado: se renueva solo con el refresh token
- ✅ Permiso revocado en Google: error visible y opción de volver a conectar
- ✅ Volver a conectar restablece la cuenta
- ✅ Desconectar: borra los tokens, oculta los calendarios de Google y no elimina nada en Google
- ✅ Tras desconectar, las tareas importadas siguen en Borealis como lista normal
- ✅ La migración 008 se puede repetir sin errores ni pérdida de datos

### 1.7 · Enlaces de los mensajes de cuenta

- ✅ Enlace: Inicio de sesión con app.url «https://gepdev.bonfyr.cl/borealis» y ruta base «/borealis»
- ✅ Enlace: Inicio de sesión con app.url «https://gepdev.bonfyr.cl» y ruta base «/borealis»
- ✅ Enlace: Inicio de sesión con app.url «https://gepdev.bonfyr.cl/borealis/» y ruta base «/borealis/»
- ✅ Enlace: Inicio de sesión con app.url «https://example.org» y ruta base «»
- ✅ Enlace: Inicio de sesión con app.url «http://127.0.0.1:8080» y ruta base «»
- ✅ Enlace: Inicio de sesión con app.url «https://gepdev.bonfyr.cl/borealis» y ruta base «/borealis» (sin URL amigables)
- ✅ Enlace: Invitación: la carpeta aparece una sola vez
- ✅ Enlace: Enlace copiado de una tarea: la carpeta aparece una sola vez
- ✅ Enlace: Dirección de regreso de Google: la carpeta aparece una sola vez
- ✅ Enlace: Cada módulo de JavaScript se importa con la versión (?v=1.12.0)
- ✅ El mensaje de cuenta creada trae el enlace exacto de inicio de sesión
- ✅ Ese enlace abre la página de inicio de sesión
- ✅ Con los datos del mensaje se entra a Borealis
- ✅ El enlace de invitación tiene la dirección pública una sola vez

### 1.7 · Horas semanales al crear la cuenta

- ✅ Crear una cuenta con «Horas semanales disponibles» las guarda (15 h)
- ✅ Más de 168 horas: se rechaza con el motivo y no se crea la cuenta
- ✅ Dejarlas vacías sigue siendo posible (sin límite)
- ✅ Las horas indicadas en la invitación se aplican al aceptarla (12 h)
- ✅ El formulario de crear cuenta tiene el campo «Horas semanales disponibles»
- ✅ Sprint de dos semanas: 15 h por semana dan 30 h de capacidad
- ✅ Una asignación que supera esas 30 h se rechaza con el motivo
- ✅ Hasta 30 h se puede asignar
- ✅ Cambiar después las horas semanales desde la administración
- ✅ El sprint ya terminado conserva sus 30 h (no se recalcula el historial)
- ✅ El sprint por venir usa la nueva disponibilidad (sin copia guardada), y no se copian sprints en los que no participa
- ✅ …y muestra 20 h/sem × 2 sem
- ✅ El sprint terminado explica que son las horas que tenía

### 1.7 · Solo los proyectos donde participa cada persona

- ✅ Sin participar: no aparece en Proyectos ni en el inicio
- ✅ …ni en la búsqueda
- ✅ …ni en el calendario ni en las tareas del equipo
- ✅ Con enlace directo o por la API: no existe para ella (404) y no se puede cambiar
- ✅ Al añadirla como integrante aparece en su lista al momento y puede entrar
- ✅ Al retirarla desaparece de su lista y el enlace deja de funcionar
- ✅ Participar a través de un equipo del proyecto también cuenta
- ✅ La administración ve todos los proyectos (también los que no integra) y puede abrirlos
- ✅ «Solo donde participo» deja a la administración solo con los suyos
- ✅ Otra persona no ve el proyecto que creó Nora
- ✅ Quien crea un proyecto lo ve (queda como integrante)

### 1.7 · Fotografía de perfil

- ✅ Subir una fotografía la guarda
- ✅ Se guarda pequeña y cuadrada en WebP (96 × 96 para listas)
- ✅ Y una de 256 × 256 para el perfil
- ✅ Se puede guardar en caché (su dirección cambia con cada fotografía)
- ✅ Aparece en el menú de la cuenta
- ✅ Aparece en las tarjetas y en las listas de responsables
- ✅ Sin iniciar sesión no se puede ver la fotografía
- ✅ Un archivo que no es una imagen se rechaza
- ✅ Cambiarla reemplaza la anterior (la vieja ya no se sirve ni queda guardada)
- ✅ Eliminarla vuelve a mostrar las iniciales

### 1.7 · Menú lateral

- ✅ «Calendario» está en el menú lateral con su icono y queda marcado al abrirlo
- ✅ La barra lateral ya no dibuja una barra de desplazamiento (sigue desplazándose)

### 1.7.1 · Mis tareas, Completadas, Nueva tarea y tutorial

- ✅ «Mis tareas» muestra las tareas de proyectos asignadas a la persona
- ✅ Y por separado sus tareas personales
- ✅ El enlace antiguo «Asignadas a mí» sigue funcionando (Mis tareas, solo de proyectos)
- ✅ Las personales siguen siendo privadas (otra persona no las ve)
- ✅ «Completadas» usa una cuadrícula real (antes faltaba la clase .grid y las tarjetas se pegaban)
- ✅ Si una parte no se puede cargar, la página no queda en blanco: se explica y lo demás se ve
- ✅ «Nueva tarea» ofrece «Tiempo estimado» y «Sprint»
- ✅ Crear con tiempo estimado lo guarda junto con el sprint (6 h, Sprint 2)
- ✅ Al abrir la tarea aparecen las 6 h estimadas
- ✅ Horas estimadas negativas: se rechazan con el motivo en el campo
- ✅ Cada página sabe en qué proyecto mostrar el paso «Sprints» del tutorial
- ✅ El tutorial se carga con la versión en la dirección (la caché no sirve el antiguo)

### 1.8.1 · Fechas en día/mes/año

- ✅ El CSV de tareas escribe las fechas como dd/mm/aaaa
- ✅ …y los momentos (creada, finalizada) como dd/mm/aaaa hh:mm en la hora local
- ✅ El CSV de horas escribe la fecha como dd/mm/aaaa
- ✅ Las páginas cargan el campo de fecha dd/mm/aaaa
- ✅ El servidor sigue exigiendo la fecha completa en formato interno (no adivina 09/11)
- ✅ Al actualizar, quien tenía tareas u horas en un proyecto sin ser integrante pasa a colaborar en él
- ✅ La migración 009 se puede repetir sin errores ni pérdida de datos

### 1.8 · Preparación

- ✅ Tres personas, un proyecto con sprints (Dani y Eli) y un equipo (Dani y Eli); Fer queda fuera

### 1.8 · Calendario personal y de equipo

- ✅ Cada persona tiene su calendario personal
- ✅ No se puede compartir un calendario con un equipo del que no formas parte
- ✅ Un calendario de equipo queda ligado a su equipo
- ✅ Un calendario personal nunca queda ligado a un equipo (el dato se ignora)
- ✅ El equipo ve la reunión del calendario de equipo
- ✅ …pero no la cita del calendario personal de Dani
- ✅ Ni abriéndola ni cambiándola por la API
- ✅ Quien no es del equipo no ve el calendario de equipo ni su reunión
- ✅ …ni puede crear eventos en él
- ✅ Vista «Personal»: solo los calendarios personales (con candado) y sus eventos
- ✅ Vista «Equipo»: solo los de equipo
- ✅ La elección se recuerda al volver al calendario
- ✅ Vista «Ambos»: los dos a la vez, cada uno con su color
- ✅ Al crear un evento se elige el calendario, agrupado en «Personal» y «De equipo»
- ✅ Los hitos del proyecto (inicio y entrega) aparecen en el calendario de equipo
- ✅ …y se pueden ocultar como capa
- ✅ Quien no participa en el proyecto no ve sus hitos

### 1.8 · Centro de notificaciones y preferencias

- ✅ Perfil › Notificaciones: categorías, anticipación y móvil
- ✅ Una anticipación inventada se rechaza
- ✅ Si apaga «Nuevas asignaciones», no recibe ese aviso
- ✅ Al encenderla vuelve a recibirlo
- ✅ «Tareas por vencer» respeta la anticipación elegida (3 días: sí; 5 días: todavía no)
- ✅ Una tarea vencida avisa una sola vez, aunque se abra Borealis muchas veces
- ✅ Una tarea completada no avisa
- ✅ Cambiar la fecha de entrega avisa al responsable (una vez por cambio)
- ✅ …pero no si la tarea ya está completada
- ✅ Un cambio importante del proyecto (su entrega) avisa a quienes participan, una vez por cambio
- ✅ El centro de notificaciones filtra por tipo
- ✅ La campana muestra cuántas hay sin leer
- ✅ Marcar todo como leído las deja en cero

### 1.8 · Sprints en dos etapas, calendario y avisos

- ✅ Etapa 1: basta con nombre, objetivo y fechas estimadas
- ✅ «Sprints» reúne los sprints de los proyectos, con lo que falta planificar
- ✅ …y solo de los proyectos donde participas
- ✅ Etapa 2: planificación con tareas, responsables, estimación y prioridad
- ✅ Las tareas añadidas aparecen en la planificación con sus horas
- ✅ El inicio previsto del sprint está en el calendario
- ✅ Un sprint que empieza mañana avisa a quienes participan
- ✅ Discord: una dirección que no es un webhook se rechaza
- ✅ Discord: solo quien administra el proyecto lo configura
- ✅ Discord: el webhook se guarda cifrado, nunca en claro
- ✅ Discord: «Enviar prueba» publica en el canal
- ✅ «Todo a mano» muestra el estado de Discord sin enseñar el webhook
- ✅ Iniciar el sprint avisa al equipo del proyecto
- ✅ …y lo publica una vez en Discord, sin menciones masivas
- ✅ Al llegar la fecha de fin, el sprint NO se cierra solo
- ✅ …se avisa una vez que falta revisarlo y cerrarlo
- ✅ …y se ve como «pendiente de cierre» en Sprints y en el calendario
- ✅ La página del sprint explica que no se cierra solo
- ✅ Cerrarlo es una acción de una persona: avisa con el resumen de completadas y pendientes
- ✅ …y se comparte en Discord
- ✅ El día del cierre real queda marcado en el calendario
- ✅ Discord: si el webhook ya no existe se explica cómo arreglarlo
- ✅ …y un fallo de Discord no impide trabajar (el cambio se guarda)

### 1.8 · «Todo a mano»: enlaces e integraciones

- ✅ Un enlace de Drive se reconoce como Drive
- ✅ Solo se aceptan direcciones web (https://)
- ✅ Quien solo colabora no cambia los enlaces del proyecto
- ✅ …pero sí los ve, marcados como «Enlace directo», distintos de las integraciones
- ✅ Quien no participa no ve los enlaces del proyecto ni puede añadirlos
- ✅ GitHub: un repositorio público muestra issues, pull requests y commits recientes
- ✅ GitHub: un repositorio privado sin token explica que falta un token
- ✅ …con un token de solo lectura funciona, y el token se guarda cifrado
- ✅ …un nombre de repositorio inválido se rechaza
- ✅ Sin conexión con GitHub ni Discord, Borealis sigue funcionando y lo dice
- ✅ Desconectar GitHub y Discord y quitar el enlace los borra (también los secretos)

### 1.8 · Notificaciones en el móvil (Web Push)

- ✅ El servidor tiene su clave pública VAPID (P-256)
- ✅ La clave privada no se guarda en claro
- ✅ Una suscripción a un servicio que no es seguro (http) se rechaza
- ✅ Unas claves incompletas se rechazan
- ✅ Activar en el móvil guarda el dispositivo
- ✅ Activarlo otra vez no duplica el dispositivo
- ✅ «Enviar una prueba» llega cifrada (aes128gcm) y se puede descifrar con las claves del navegador
- ✅ El mensaje va firmado con VAPID (ES256) para ese servicio, con caducidad
- ✅ Una nueva asignación llega al móvil al momento, con su enlace
- ✅ Al activar un dispositivo no le llega de golpe lo anterior, y cada aviso se envía una sola vez
- ✅ Una categoría apagada para el móvil no llega al móvil, pero sí a la campana
- ✅ Una tarea completada no se notifica al móvil
- ✅ El cron (bin/notify.php) crea los recordatorios de quien no tiene Borealis abierto, sin repetirlos
- ✅ Si un aviso llega sin contenido, el móvil puede pedir los últimos
- ✅ Un dispositivo que se dio de baja (410) se quita solo
- ✅ Desactivar en el móvil quita el dispositivo
- ✅ Service worker y manifiesto (para instalar en iPhone/iPad y Android) se sirven

### 1.8 · Actualización

- ✅ La migración 010 se puede repetir sin errores ni pérdida de datos

### 1.9 · Preparación

- ✅ Dos personas en un proyecto nuevo

### 1.9 · Hora de entrega

- ✅ Una tarea puede tener hora de entrega (opcional)
- ✅ Una hora sin fecha se rechaza con el motivo
- ✅ Una hora inventada (25:00) se rechaza
- ✅ Quitar la fecha quita también la hora
- ✅ Cambiar la hora avisa a quien es responsable, con la hora
- ✅ El tablero muestra la hora junto a la fecha
- ✅ La ficha de la tarea tiene el campo de hora

### 1.9 · Google Calendar: conectar y elegir calendario

- ✅ Gabi conecta su cuenta de Google (OAuth con PKCE, sin contraseña)
- ✅ Perfil › Google Calendar: estado «Conectado» y el apartado para enviar el trabajo
- ✅ Solo se ofrecen los calendarios donde puede crear eventos (no los de solo lectura)
- ✅ Antes de elegir, el estado es «apagado»
- ✅ Elegir un calendario de solo lectura se rechaza
- ✅ Hay que elegir al menos qué enviar

### 1.9 · Google Calendar: tareas en el calendario, sin duplicados

- ✅ Al elegir el calendario se envían sus tareas con fecha y se confirma
- ✅ El evento lleva la clave y el nombre de la tarea
- ✅ …el proyecto, el tiempo estimado, una descripción breve y el enlace directo a Borealis
- ✅ Con hora: un bloque que termina a la hora de entrega y dura lo estimado (15:30–17:30, hora de la instalación)
- ✅ Solo fecha: evento de todo el día
- ✅ Marcado como de Borealis, sin ocupar la agenda y sin recordatorios repetidos de Google
- ✅ También las tareas en que figura como corresponsable
- ✅ No se envían tareas sin fecha ni tareas de otras personas
- ✅ Los eventos propios de Gabi siguen ahí, intactos
- ✅ Al cambiar la tarea en Borealis se actualiza el mismo evento, al momento y sin duplicarlo
- ✅ Sincronizar sin cambios no vuelve a escribir en Google
- ✅ Cambiar la fecha mueve el evento
- ✅ Al completarla se quita del calendario (opción por defecto) y nada más se toca
- ✅ Si deja de ser responsable, el evento se quita de su calendario
- ✅ Con «Dejarla, marcada con ✓», la completada queda en el calendario con ✓
- ✅ Si Borealis pierde el registro de un evento ya creado, Google responde «ya existe» y se actualiza sin duplicar
- ✅ Si el evento se borró del todo en Google, el siguiente cambio lo vuelve a crear una sola vez

### 1.9 · Google Calendar: opciones, privacidad y errores

- ✅ «Sprints y entregas»: el sprint como evento de varios días y la entrega del proyecto
- ✅ «Solo título, proyecto y enlace»: no viaja la descripción ni el estado
- ✅ Cambiar de calendario mueve los eventos de Borealis (salen del anterior, entran en el nuevo)
- ✅ Si Google pide esperar (cuota), Borealis sigue funcionando, guarda el motivo y programa un reintento
- ✅ …y el perfil lo muestra
- ✅ El cron respeta la pausa del reintento (no insiste antes de tiempo)
- ✅ Pasada la pausa, el cron la envía (sin tener Borealis abierto) y vuelve a «Funcionando»
- ✅ Otra persona no ve nada de la conexión de Gabi
- ✅ …ni puede sincronizar sin cuenta propia
- ✅ Sin sesión no hay acceso
- ✅ El navegador nunca recibe los tokens de Google
- ✅ Los registros de errores no contienen tokens
- ✅ «Dejar de enviar» quita solo los eventos de Borealis del calendario

### 1.9 · Google Calendar: desconectar y reconectar

- ✅ Desconectar sin quitar: los tokens se borran y los eventos se quedan en Google
- ✅ Reconectar y volver a elegir el mismo calendario no duplica los eventos
- ✅ Si Google retira el permiso: «Error de autorización» y «Volver a conectar»; Borealis sigue funcionando
- ✅ Al reconectar se pone al día solo lo que cambió
- ✅ «Desconectar y quitar sus eventos»: se quitan solo los de Borealis y se desconecta

### 1.9 · Preferencias de notificaciones

- ✅ Perfil: avisos antes de la entrega, zona horaria, horario de silencio y correo
- ✅ Por defecto: 24 horas y 30 minutos antes; resumen semanal apagado; correo encendido
- ✅ Una zona horaria inventada se rechaza
- ✅ Una hora de silencio inventada se rechaza
- ✅ Las preferencias se guardan por persona (zona, silencio, correo, resumen semanal)
- ✅ …y no cambian las de otra persona

### 1.9 · Recordatorios: 24 h y 30 min, sin Borealis abierto

- ✅ 30 horas antes: todavía nada
- ✅ 24 horas antes: un aviso, en la hora de su zona (Santiago: 13:00), una sola vez
- ✅ 30 minutos antes: el segundo aviso («vence en 25 minutos»), también una sola vez
- ✅ Pasada la hora: «vencida», una sola vez
- ✅ Creada 20 minutos antes de vencer: llega solo el aviso de 30 minutos, no un aluvión
- ✅ Si cambia la hora, el recordatorio anterior queda con la hora vieja (ya no se envía, ver «Correo») y llega uno con la nueva
- ✅ Una tarea completada no genera recordatorios
- ✅ Resumen semanal: el lunes por la mañana, una vez, solo a quien lo activó
- ✅ Discrepancia de planificación para la administración: horas completas con tareas pendientes

### 1.9 · Correo

- ✅ Sin «mail» en la configuración: no se envía nada y el perfil lo dice
- ✅ El cron envía los avisos por SMTP autenticado y cifrado (STARTTLS), con certificado verificado
- ✅ Varios avisos juntos llegan en un solo correo, cada uno con su enlace directo
- ✅ Un recordatorio de una hora que cambió ya no se envía por correo (sí el de la hora nueva)
- ✅ Lo ya leído en Borealis no se envía por correo
- ✅ El correo no incluye la contraseña SMTP en ninguna parte
- ✅ Cada aviso se envía una sola vez
- ✅ Gabi no recibe correos de los avisos de Hugo
- ✅ Horario de silencio: el aviso queda en la campana y el correo espera
- ✅ …y sale al terminar el silencio
- ✅ Con «Por correo» apagado no se envía nada
- ✅ Si el servidor de correo no responde, el aviso no se pierde: queda para el siguiente intento
- ✅ …y se envía cuando vuelve
- ✅ «Enviar correo de prueba» es solo para la administración de la instalación
- ✅ La administración puede enviar un correo de prueba
- ✅ Contraseña SMTP incorrecta: se informa el motivo sin mostrar la contraseña
- ✅ Si el servidor no ofrece cifrado, Borealis no envía la contraseña sin cifrar

### 1.9 · Aplicación instalable (PWA)

- ✅ Manifiesto con id, nombre, icono propio (también «maskable») y modo aplicación
- ✅ El service worker solo guarda la página «Sin conexión» (nada de proyectos, sesiones ni personas)
- ✅ La página «Sin conexión» existe y no tiene datos de nadie
- ✅ Icono de 512 px para instalar

### 1.9 · Actualización

- ✅ La migración 011 se puede repetir sin errores ni pérdida de datos
- ✅ Reglas · Por defecto: 24 horas y 30 minutos antes
- ✅ Reglas · Dos días antes: todavía ningún aviso
- ✅ Reglas · 23 h 59 min antes: el aviso de 24 horas
- ✅ Reglas · 29 minutos antes: el de 30 minutos
- ✅ Reglas · Si ya pasaron ambos momentos (tarea creada tarde), solo el más cercano
- ✅ Reglas · Sin momentos elegidos: nunca avisa
- ✅ Reglas · Texto: «vence mañana a las 17:00»
- ✅ Reglas · Texto: «vence en 25 minutos (a las 17:00)»
- ✅ Reglas · Texto: «vence hoy a las 17:00» con más de hora y media
- ✅ Reglas · Solo fecha: la víspera, «24 horas antes» = «vence mañana»
- ✅ Reglas · Solo fecha: el mismo día, los avisos de horas se juntan en uno («hoy»)
- ✅ Reglas · Solo fecha: dos días antes, nada (con los valores por defecto)
- ✅ Reglas · Solo fecha con «3 días antes»: avisa desde tres días antes
- ✅ Reglas · Una semana antes, con «1 semana»
- ✅ Reglas · Id de evento válido para Google (base32hex, 5 a 1024 caracteres)
- ✅ Reglas · El mismo elemento da siempre el mismo id (no se duplica al repetir)
- ✅ Reglas · Otra persona, otra tarea u otro calendario dan otro id
- ✅ Las reglas de recordatorios se comprobaron sin servidor

### 1.10 · Enlaces de Drive y GitHub en las tareas

- ✅ Una tarea nueva guarda la carpeta de Drive y el repositorio de GitHub
- ✅ Se acepta el enlace SSH de GitHub y se guarda como https
- ✅ Un enlace a una rama o carpeta del repositorio se conserva (en https)
- ✅ Se rechaza con el motivo: Drive de otro servicio
- ✅ Se rechaza con el motivo: Drive con javascript:
- ✅ Se rechaza con el motivo: Drive con dominio falso
- ✅ Se rechaza con el motivo: GitHub sin repositorio
- ✅ Se rechaza con el motivo: GitHub de otro servicio
- ✅ Se rechaza con el motivo: GitHub con usuario y contraseña
- ✅ Se rechaza con el motivo: GitHub con HTML
- ✅ Un enlace rechazado no cambia lo guardado
- ✅ El historial dice que cambió el repositorio de GitHub
- ✅ La ficha de la tarea muestra la sección «Enlaces de trabajo» con los dos campos
- ✅ Cada enlace tiene su botón «Copiar» con el enlace guardado
- ✅ Y un botón para abrirlo en otra pestaña (sin dar acceso a la ventana de Borealis)
- ✅ Quien solo puede ver la tarea ve y copia los enlaces, pero no puede cambiarlos
- ✅ …y la API tampoco le deja cambiarlos
- ✅ La API de la tarea incluye los dos enlaces
- ✅ Duplicar una tarea copia sus enlaces
- ✅ Vaciar el campo quita el enlace
- ✅ Sin enlaces, la sección sigue lista para pegar uno
- ✅ Exportar el proyecto incluye los enlaces de las tareas
- ✅ Importar un proyecto conserva los enlaces válidos y descarta los que no lo son

### 1.11 · Proyectos planificados, tareas atrasadas y fuera de plazo

- ✅ Migración 013: el estado «planned» existe y tasks.completed_due_date también
- ✅ La migración 013 se puede repetir sin errores ni cambios
- ✅ Se crea un proyecto en estado «Planificado»
- ✅ El listado filtra por «Planificado» y lo muestra con su estado
- ✅ Los ajustes del proyecto ofrecen los cuatro estados
- ✅ Pasa de «Planificado» a «Activo» y queda en el historial
- ✅ Un estado que no existe se rechaza (archivar sigue siendo aparte)
- ✅ Al crear solo se admite «Activo» o «Planificado»
- ✅ Quien no participa no puede cambiar el estado manipulando la petición
- ✅ Una tarea vencida aparece en «Vencidas» con los días de retraso
- ✅ …y conserva su estado de trabajo («En progreso» + atrasada, sin estado nuevo)
- ✅ La tarjeta del tablero muestra «3 días de retraso»
- ✅ La ficha de la tarea muestra el retraso junto a la fecha
- ✅ Al finalizarla se guardan la fecha real y la fecha límite que tenía
- ✅ Cambiar la fecha después no borra la fecha límite original
- ✅ «Finalizadas» la marca «Fuera de plazo · 3 días» y ya no está entre las pendientes
- ✅ …y deja de estar en «Vencidas»
- ✅ La ficha dice «fuera de plazo (fecha límite original: …)»
- ✅ Reabrirla la deja pendiente otra vez (sin fecha de finalización)
- ✅ Una tarea finalizada antes de su fecha dice «A tiempo»
- ✅ Mover la tarjeta a la columna final también guarda la fecha límite que tenía
- ✅ «Hoy» muestra lo que vence hoy, y no lo de mañana ni lo vencido
- ✅ «Próximas» empieza mañana: la de hoy no aparece en las dos
- ✅ Quien deja de ser responsable recibe «Ya no eres responsable de …»; quien la recibe, «Te asignaron …»
- ✅ Con varios responsables también: quien sale de la lista recibe un solo aviso
- ✅ Los retrasos no se avisan a la administración (solo a la persona responsable)
- ✅ Exportar el proyecto incluye la fecha límite con la que se finalizó

### 1.12 · Monitoreo de cumplimiento y carga de trabajo

- ✅ Migración 014: cinco tablas nuevas y cuatro columnas en tareas
- ✅ La migración 014 se puede repetir sin errores
- ✅ Escenario 1: una tarea vencida aparece en Monitoreo › Cumplimiento › Atrasadas con sus días de retraso
- ✅ …y cuenta en el indicador «Tareas atrasadas» del resumen, que enlaza a la lista
- ✅ El resumen muestra el estado del proyecto con su motivo (sin cambiar el estado del proyecto)
- ✅ La ficha de la tarea muestra «Seguimiento» con la puntualidad «Atrasada» y ofrece registrar el motivo
- ✅ Se registra el motivo del retraso con comentario, acción correctiva, autor y fecha
- ✅ Un motivo fuera de la lista se rechaza
- ✅ Escenario 9: reprogramar dos veces deja las dos fechas anteriores en el historial
- ✅ …y el motivo registrado se conserva con la fecha límite que tenía entonces
- ✅ La ficha muestra «La fecha límite cambió 2 veces» y el motivo con su autor
- ✅ Escenario 2: una tarea completada fuera del plazo aparece en «Completadas fuera del plazo» con sus días
- ✅ …y ya no figura entre las atrasadas
- ✅ Escenario 3: al finalizar con 12 h de 5 h estimadas, la respuesta indica +7 h y +140 %
- ✅ …y Monitoreo › Tiempos lo muestra como «+7 h · +140 %»
- ✅ Una tarea sin estimación no divide por cero: se marca «Sin estimación» y no entra en los totales
- ✅ El mismo registro de horas enviado dos veces seguidas no se duplica
- ✅ Corregir un registro de horas cambia los minutos y deja el antes y el después en el historial
- ✅ Alguien ajeno al proyecto no puede corregir horas de otros
- ✅ Una corrección fuera de rango (más de 24 h) se rechaza
- ✅ Escenario 4: 22 h planificadas con 15 h de capacidad se ven como «Sobrecarga» con 7 h por encima
- ✅ …y el motor de reglas crea la alerta de sobrecarga explicada (+7 h) y avisa a la persona
- ✅ La ejecución queda registrada (última revisión correcta)
- ✅ Escenario 7: ejecutar la revisión dos veces más no crea alertas ni avisos repetidos
- ✅ La tarea vencida tiene su alerta (advertencia, nivel 1) con el porqué
- ✅ Sin revisar en 2 días, la alerta sube a nivel 2 y avisa a quien gestiona el proyecto
- ✅ …sin repetir el aviso en la siguiente ejecución
- ✅ Alguien ajeno no puede marcarla como revisada
- ✅ Quien gestiona el proyecto la marca como revisada (con nota y en el historial)
- ✅ Una alerta revisada ya no escala
- ✅ Escenario 5: con 15 de 15 h registradas y trabajo comprometido pendiente se abre la alerta de capacidad
- ✅ …y Monitoreo › Riesgos y alertas la enlaza para decidir qué hacer
- ✅ Se registra «Base de datos bloquea a Pantalla de pagos»
- ✅ El bloqueo guarda desde cuándo y quién lo marcó
- ✅ Escenario 6: «Pantalla de pagos» aparece en «Dependencias en riesgo» porque la tarea de la que depende está bloqueada
- ✅ …y ninguna fecha cambió
- ✅ Hay alerta de bloqueo (para quien gestiona) y de dependencia (para la responsable de la tarea afectada)
- ✅ Una dependencia circular directa se rechaza
- ✅ …y también una indirecta (A → B → C → A)
- ✅ Una tarea no puede depender de sí misma
- ✅ La misma relación no se duplica
- ✅ No se puede crear una dependencia con una tarea de un proyecto donde no se puede editar
- ✅ Al desbloquear, las alertas de bloqueo y de dependencia se resuelven solas
- ✅ Escenario 8: quien no participa recibe 404 al pedir el monitoreo de ese proyecto por URL
- ✅ …también en el informe CSV
- ✅ …y sus alertas solo muestran lo suyo (nada del proyecto ajeno)
- ✅ …ni la carga de personas con las que no comparte proyecto
- ✅ …ni puede registrar motivos en sus tareas
- ✅ Solo la administración cambia los umbrales
- ✅ Los umbrales de carga deben ir de menor a mayor
- ✅ La administración guarda los umbrales (solo en el espacio, sin tocar config/)
- ✅ Administración › Ajustes muestra el formulario de umbrales
- ✅ Sin ranking de personas: ninguna vista ordena ni puntúa por productividad
- ✅ Al iniciar el sprint se guarda el compromiso original (3 tareas planificadas)
- ✅ Lo que entra después del inicio queda como «añadida»
- ✅ Un sprint con la fecha de fin pasada y pendientes genera su alerta (nunca se cierra solo)
- ✅ El resumen muestra el sprint activo con planificadas, añadidas y su riesgo
- ✅ Escenario 10: al cerrarlo incompleto se guarda qué se completó y qué volvió al backlog
- ✅ …sin perder el compromiso original (3 planificadas + 1 añadida)
- ✅ El informe del período muestra el resumen del sprint cerrado: 3 planificadas, 1 añadida, 1 completada, 3 al backlog
- ✅ Al cerrar el sprint su alerta se resuelve sola
- ✅ El informe CSV se descarga con retrasos, motivos y desviaciones del proyecto
- ✅ …y no incluye proyectos que esa persona no ve
- ✅ Las seis vistas de Monitoreo cargan para administración, gestión, colaboración y alguien ajeno
- ✅ El menú principal tiene «Monitoreo» (y Reportes sigue disponible dentro)

## Navegador (Chromium)

### Instalación desde el navegador

- ✅ Instalación termina en el inicio con sesión iniciada
- ✅ El tutorial empieza solo en el primer ingreso
- ✅ Cada paso señala un control real de la pantalla
- ✅ El tutorial pasa de una página a otra y sigue (Inicio → Proyectos)
- ✅ «Anterior» vuelve al paso previo, también en otra página
- ✅ Tras una interrupción (recarga) continúa en el mismo paso
- ✅ Omitido, no vuelve a aparecer al recargar
- ✅ Se puede repetir desde el menú de la cuenta (empieza en el paso 1)
- ✅ Recorre Inicio, Proyectos, Tareas, Sprints, Calendario y Configuración
- ✅ Sin proyectos todavía, «Sprints» muestra un ejemplo sin tocar datos
- ✅ El último paso ofrece «Finalizar»
- ✅ Al terminar se cierra
- ✅ Terminado, no vuelve a aparecer

### Proyectos en la interfaz

- ✅ Sin nombre, el formulario no se envía (validación nativa)
- ✅ Clic fuera del modal no lo cierra ni borra lo escrito
- ✅ Al crear: aviso con enlace y se permanece en la misma pantalla
- ✅ El proyecto aparece en la barra lateral sin recargar
- ✅ Renombrado: título y barra lateral cambian sin recargar
- ✅ Se permanece en Ajustes tras guardar
- ✅ El nombre persiste al recargar
- ✅ Con nombre vacío no se muestra «Guardado»

### Tablero y tareas

- ✅ Tres tareas creadas con Enter; el campo queda listo para la siguiente
- ✅ La tarjeta queda en la nueva columna tras recargar
- ✅ Menú «Columna siguiente» mueve la tarjeta
- ✅ Clic en la tarjeta abre el panel lateral
- ✅ La URL refleja la tarea abierta (enlace compartible)
- ✅ Editar el título en el panel actualiza la tarjeta del tablero
- ✅ Título vacío: error visible y se restaura el valor guardado
- ✅ Comentario publicado aparece en el panel
- ✅ Subtarea añadida en el panel
- ✅ Escape cierra el panel
- ✅ El tablero conserva su desplazamiento
- ✅ Al finalizar una tarea se ofrece comentario de cierre y horas
- ✅ El comentario y las horas de cierre se guardan
- ✅ Al finalizar todas las tareas se ofrece el resumen del proyecto
- ✅ El panel de la tarea muestra las horas del cierre
- ✅ Registrar más horas actualiza el total sin recargar
- ✅ El color del proyecto usa la paleta nueva
- ✅ El encabezado del proyecto usa el fondo elegido
- ✅ La pestaña activa tiene el indicador deslizante debajo
- ✅ El informe imprimible se abre con su botón de imprimir
- ✅ El botón «Horas (CSV)» descarga un archivo CSV

### Lista y filtros

- ✅ Alta rápida en la lista
- ✅ El filtro de búsqueda reduce la lista
- ✅ Seleccionar muestra la barra de acciones masivas

### Preferencias

- ✅ La preferencia de movimiento se aplica

### Importación desde v0.2

- ✅ La importación informa el resultado y permanece en la pantalla
- ✅ Los proyectos importados aparecen en Proyectos

### Novedades 1.2

- ✅ El cuadro de la invitación trae el enlace (no queda vacío)
- ✅ «Nuevo enlace» en invitaciones pendientes muestra un enlace para copiar
- ✅ El navegador sugiere gmail.com antes de enviar
- ✅ Un correo sin dominio completo no pasa
- ✅ El nombre editado aparece en la lista de usuarios
- ✅ El equipo aparece como participante del proyecto
- ✅ Desde la tarea se suman responsables y un equipo
- ✅ Sin polivalente no aparece la fila de personas
- ✅ Con un equipo polivalente aparece la fila de personas
- ✅ Arrastrar a una persona de una tarjeta a otra la mueve
- ✅ Arrastrar desde la fila de personas la suma como responsable
- ✅ El perfil de una persona muestra sus horas
- ✅ Exportar descarga un archivo .json
- ✅ El proyecto importado aparece en Proyectos

### Novedades 1.3

- ✅ Indicador «Guardando…» mientras se guarda
- ✅ El selector sigue abierto durante y después de guardar
- ✅ Se guardan todos los clics, en orden (4 marcadas y 1 desmarcada)
- ✅ Al recargar, los responsables siguen ahí
- ✅ Con clics cada 150, 250 y 300 ms se guardan los 4 y se siguen viendo marcados
- ✅ La fila muestra horas usadas y capacidad de cada persona
- ✅ Asignar arrastrando hasta el límite exacto (30 + 10 = 40 h)
- ✅ Al llegar al límite pasa a «Sin disponibilidad» y no se puede arrastrar
- ✅ Retirar arrastrando a la fila de personas
- ✅ Recupera las horas y vuelve a «Disponibles»
- ✅ Reasignar arrastrando de una tarjeta a otra
- ✅ Una tarea de 8 h con 5 h libres se rechaza con el motivo y sin cambios
- ✅ Al recargar, el tablero muestra lo mismo que la base de datos
- ✅ El sprint muestra horas disponibles editables por persona

### Novedades 1.4

- ✅ En la lista el nombre no es un campo editable
- ✅ Renombrar dentro guarda sin cerrar el panel
- ✅ Al cerrar, la lista muestra el nombre nuevo
- ✅ Se ven los grupos Activo, Planificados y Finalizados
- ✅ Elegir un sprint muestra su detalle
- ✅ Cuadrícula con miniatura para la imagen
- ✅ Cambiar el nombre visible
- ✅ Vista de lista con formato y tamaño
- ✅ Eliminar pide confirmación y quita el archivo
- ✅ Elegir un fondo lo aplica al instante
- ✅ Se mantiene en otras pantallas y al volver
- ✅ Volver al predeterminado

### Novedades 1.5

- ✅ Las ilustraciones de los temas se sirven (6 imágenes WebP)
- ✅ Cada tema cambia la barra lateral (6 temas + original distintos)
- ✅ Cada tema tiene su variante del símbolo de Borealis
- ✅ Texto de la barra lateral con contraste AA (≥ 4,5:1) en todos los temas
- ✅ Cambiar de tema no recarga la página
- ✅ El elemento activo del menú usa el color del tema
- ✅ El tema se conserva al iniciar sesión de nuevo
- ✅ Con «reducir movimiento» la barra cambia sin transición
- ✅ El tema original queda exactamente como antes
- ✅ Preparación: Gil registra 1 h de 1 h con una tarea pendiente
- ✅ El inicio muestra la alerta con el mensaje claro
- ✅ Al resolver, la alerta desaparece del panel sin recargar
- ✅ El historial muestra la decisión y quién la tomó

### Novedades 1.6

- ✅ El saludo usa el nombre real y la hora local (3 zonas horarias)
- ✅ Ninguna zona muestra «Usuario»
- ✅ Con poca altura, la barra lateral no dibuja ninguna barra de desplazamiento ni deja hueco
- ✅ Sigue desplazándose con la rueda del ratón o el trackpad
- ✅ Con el teclado (Tab) la última opción entra en la vista
- ✅ Cada tema cambia el fondo del área principal y la cabecera (no solo la barra lateral)
- ✅ Sin parpadeo: el tema llega en el HTML (no se aplica después con JavaScript)
- ✅ Añadir tareas personales con Enter
- ✅ El panel de notas sigue abierto después de guardarse solo
- ✅ Añadir una subtarea sin que se cierre el panel
- ✅ Prioridad y fecha límite se guardan solas
- ✅ Arrastrar para reordenar y el orden se conserva
- ✅ Completadas: aparece la tarea terminada
- ✅ Vista «Asignadas a mí» disponible
- ✅ Vista «Equipo» disponible
- ✅ Vista mes con leyenda que distingue reunión, evento, tarea y vencimiento
- ✅ El vencimiento de la tarea aparece en el calendario
- ✅ Hacer clic en un día abre «Nuevo evento» con esa fecha
- ✅ Vista semana: el evento está en su hora
- ✅ Hacer clic en una hora libre propone esa hora
- ✅ Abrir un evento muestra su detalle editable
- ✅ Editar no cierra el panel por sí solo
- ✅ Guardar cierra el diálogo y aplica el cambio
- ✅ Vista día
- ✅ Ocultar un calendario quita sus eventos de la vista
- ✅ …y se recuerda al recargar
- ✅ Mostrarlo de nuevo lo superpone con los demás
- ✅ «Nueva reunión» propone el calendario Reuniones
- ✅ Elegir «Cada semana» muestra «Hasta»
- ✅ Se repite cada lunes (y se distingue como reunión)
- ✅ El detalle ofrece «Unirse» con el enlace de videollamada
- ✅ Arrastrar la tarea a otro día cambia su vencimiento y el calendario lo refleja
- ✅ Hacer clic en la tarea abre su detalle
- ✅ El calendario nuevo aparece con su color
- ✅ Eliminar pide confirmación
- ✅ Se elimina
- ✅ Los elementos del calendario mantienen contraste AA (≥ 4,5:1) en los temas
- ✅ Móvil: /calendar sin desplazamiento horizontal de la página
- ✅ Móvil: /calendar?view=week sin desplazamiento horizontal de la página
- ✅ Móvil: /calendar?view=day sin desplazamiento horizontal de la página
- ✅ Móvil: /tasks sin desplazamiento horizontal de la página
- ✅ Móvil: el diálogo de evento cabe en la pantalla
- ✅ Móvil: el tutorial se muestra como hoja inferior legible
- ✅ El perfil explica que Google es opcional y no está configurado

### Novedades 1.7

- ✅ Sin fotografía se ven las iniciales
- ✅ Al elegir la imagen se abre la herramienta de recorte
- ✅ Se guarda y aparece en el menú de la cuenta sin recargar
- ✅ El recorte elegido es el que se guarda (el círculo amarillo queda al centro)
- ✅ Eliminarla vuelve a las iniciales
- ✅ El formulario pide «Horas semanales disponibles»
- ✅ El mensaje para copiar trae el enlace de inicio de sesión de esta instalación
- ✅ La lista muestra las 15 h y se pueden cambiar ahí mismo
- ✅ Escritorio: «Calendario» con su icono, marcado al estar en la sección
- ✅ Móvil: se abre desde el menú y lleva al calendario

### Novedades 1.7.1

- ✅ Cada módulo de JavaScript se pide con la versión (la caché del servidor no puede servir el tutorial antiguo)
- ✅ Sin poder guardar en el servidor, «Siguiente» abre Proyectos y el tutorial continúa
- ✅ La dirección queda limpia (sin ?tour=) tras abrir la página
- ✅ Mientras dura, la página no responde a clics (no puede cambiar información real)
- ✅ Proyectos → Tareas
- ✅ Recargar a mitad conserva el paso aunque el servidor no lo guardara
- ✅ Tareas → Sprints (en un proyecto real)
- ✅ Sprints → Calendario
- ✅ «Anterior» vuelve a la página del paso previo
- ✅ «Reiniciar» vuelve al paso 1 en Inicio
- ✅ Omitido, no reaparece
- ✅ El formulario «Nueva tarea» tiene «Tiempo estimado» y «Sprint» a la vista
- ✅ La tarea se guarda con 6 horas estimadas
- ✅ Al abrir la tarea, «Estimado» muestra las 6 h
- ✅ Horas no válidas: no se crea y se explica en el campo
- ✅ «Mis tareas» lista las tareas de proyectos asignadas a la persona
- ✅ Y, por separado, las personales (privadas)
- ✅ El enlace antiguo «Asignadas a mí» abre Mis tareas filtrado por proyectos
- ✅ Filtro «Personales» muestra solo las privadas
- ✅ Escritorio: dos columnas con separación, sin superponerse
- ✅ Las filas no tocan el borde de la tarjeta
- ✅ Móvil: una columna, una debajo de la otra con separación y sin desbordar
- ✅ Móvil: «Mis tareas» sin desplazamiento horizontal

### Novedades 1.8

- ✅ El calendario ofrece «Ambos / Personal / Equipo»
- ✅ «Personal» muestra solo los calendarios personales
- ✅ «Equipo» muestra solo los de equipo
- ✅ Nuevo calendario: «De equipo» pide el equipo; «Personal» no lo ofrece
- ✅ Móvil: el calendario con el selector no desborda
- ✅ Perfil › Notificaciones: categorías, anticipación y móvil
- ✅ Guardar desde la página aplica la elección (capacidad apagada, 3 días, 24 h y 30 min antes)
- ✅ Al volver, la elección sigue ahí
- ✅ El centro de notificaciones filtra por tipo
- ✅ La campana tiene contador o queda sin número cuando no hay pendientes
- ✅ Página «Sprints» con sus tarjetas
- ✅ Etapa 1: con nombre, proyecto, objetivo y fechas se crea
- ✅ Aparece en «Sprints» con lo que falta planificar
- ✅ Etapa 2: la planificación del sprint está a la vista
- ✅ Distingue «Enlace directo» de «Integración»
- ✅ Añadir un enlace de Drive desde la página
- ✅ El enlace aparece como acceso directo
- ✅ Móvil: «Todo a mano» no desborda

### Fechas dd/mm/aaaa (1.8.1)

- ✅ Con el navegador en inglés, el campo de fecha pide dd/mm/aaaa (no mm/dd/aaaa)
- ✅ Junto al campo hay un botón para elegir en el calendario
- ✅ Al escribir los números se añaden las barras (09/11/2026)
- ✅ Se guarda como 9 de noviembre y se vuelve a mostrar 09/11/2026
- ✅ Una fecha que no existe (31/02/2026) se rechaza con el motivo y no se guarda
- ✅ El diálogo de evento también muestra la fecha como dd/mm/aaaa

### Novedades 1.9

- ✅ «Nueva tarea» ofrece la hora de entrega (opcional) junto a la fecha
- ✅ La tarea se guarda con su hora
- ✅ La ficha muestra la hora y permite cambiarla
- ✅ La hora se escribe en 24 horas (hh:mm), sin AM/PM (Chromium en es-MX mostraba 04:30 p. m.)
- ✅ Escribir «915» guarda 09:15
- ✅ Una hora inexistente (25:00) se rechaza sin guardar
- ✅ Perfil › Google Calendar: pasos claros y botón para conectar
- ✅ Tras autorizar en Google vuelve al perfil, conectado
- ✅ El selector ofrece solo calendarios donde puede escribir
- ✅ «Empezar a enviar» confirma que funciona
- ✅ El estado muestra «Funcionando» y cuántos eventos hay
- ✅ Móvil: el apartado de Google Calendar no desborda
- ✅ «Desconectar y quitar sus eventos» desde el perfil
- ✅ Horario de silencio y zona horaria visibles
- ✅ Sin correo configurado, se dice claramente
- ✅ Móvil: las preferencias de notificación no desbordan
- ✅ Perfil › «Borealis como aplicación» explica cómo instalarlo
- ✅ La página «Sin conexión» se sirve

### Novedades 1.10

- ✅ La ficha de la tarea tiene la sección «Enlaces de trabajo»
- ✅ Pegar los enlaces los guarda al salir del campo (y los deja en https)
- ✅ Tras guardar, el campo muestra el enlace completo
- ✅ «Copiar» pone el enlace del repositorio en el portapapeles
- ✅ …y lo confirma con un aviso
- ✅ «Copiar» de Drive copia el enlace de la carpeta
- ✅ Cada enlace se puede abrir en otra pestaña
- ✅ Un enlace que no es de GitHub se rechaza con el motivo y vuelve el anterior
- ✅ También desde el panel lateral del tablero se copian
- ✅ En el móvil la sección cabe sin desplazamiento horizontal

### Novedades 1.11

- ✅ La API informa los días de retraso sin cambiar el estado
- ✅ La tarjeta del tablero muestra «4 días de retraso»
- ✅ Mi trabajo › «Hoy» es la primera pestaña y muestra lo que vence hoy
- ✅ «Vencidas» muestra el retraso junto a la fecha
- ✅ «Finalizadas» señala la terminada fuera de plazo
- ✅ La ficha muestra el retraso junto a «Vence»
- ✅ Un proyecto creado como «Planificado» aparece con ese estado
- ✅ En el móvil, Mi trabajo con el retraso cabe sin desplazamiento horizontal

### Novedades 1.12

- ✅ Al finalizar con 12 h de 5 h estimadas se informa +7 h (+140 %)
- ✅ El menú lleva a «Monitoreo»
- ✅ El resumen muestra los indicadores enlazados y el estado de cada proyecto
- ✅ Pulsar «Tareas atrasadas» abre la lista de atrasadas
- ✅ La carga muestra la sobrecarga con las horas por encima
- ✅ Tiempos compara estimado y registrado (+7 h · +140 %)
- ✅ Riesgos y alertas explica cada alerta y permite marcarla como revisada
- ✅ Marcar una alerta como revisada la deja con quién la revisó
- ✅ El informe se descarga en CSV
- ✅ La ficha de la tarea tiene «Seguimiento» con la desviación de horas
- ✅ Con una desviación importante el formulario del motivo aparece abierto (opcional)
- ✅ El motivo queda registrado con autor y fecha
- ✅ En el móvil, Monitoreo › summary cabe sin desplazamiento horizontal
- ✅ En el móvil, Monitoreo › compliance cabe sin desplazamiento horizontal
- ✅ En el móvil, Monitoreo › workload cabe sin desplazamiento horizontal
- ✅ En el móvil, Monitoreo › times cabe sin desplazamiento horizontal
- ✅ En el móvil, Monitoreo › risks cabe sin desplazamiento horizontal
- ✅ En el móvil, Monitoreo › report cabe sin desplazamiento horizontal

### Diseño adaptable

- ✅ escritorio 1440×900: sin desplazamiento horizontal de página
- ✅ portatil 1280×800: sin desplazamiento horizontal de página
- ✅ tableta 820×1180: sin desplazamiento horizontal de página
- ✅ tableta: menú lateral accesible con botón
- ✅ tableta: el menú lateral se abre
- ✅ movil 390×844: sin desplazamiento horizontal de página
- ✅ movil: menú lateral accesible con botón
- ✅ movil: el menú lateral se abre
- ✅ La tecla / enfoca la búsqueda
- ✅ El elemento enfocado muestra un indicador visible

### Errores de JavaScript

- ✅ Sin errores de JavaScript en la consola durante todo el recorrido

## Capturas

En `docs/capturas/`: escritorio (1440×900), portátil (1280×800), tableta (820×1180) y móvil (390×844) de Inicio, Mi trabajo, Proyectos, Resumen, Tablero, Lista, Calendario y Equipos, más el menú lateral en tableta y móvil, el detalle de tarea, el resumen al completar un proyecto y la importación. Novedades 1.1: cierre de tarea con comentario y horas, horas en el detalle, apariencia del proyecto, informe imprimible, control del sprint, reportes con horas, barra lateral y aviso de cuenta fantasma (archivos que empiezan por `11-`). Novedades 1.2: invitación con enlace, editar cuenta, equipo en el proyecto, varios responsables, personas en el tablero, perfil con horas e importación (archivos que empiezan por `12-`). Novedades 1.3: responsables seguidos, tablero con horas y «Sin disponibilidad», horas por persona en el sprint (archivos que empiezan por `13-`). Novedades 1.4: tutorial, sprints por estado, archivos en cuadrícula y lista, Apariencia y fondo Medieval (archivos que empiezan por `14-`). Novedades 1.12: Monitoreo (resumen, cumplimiento, carga, tiempos, alertas, informe), «Seguimiento» en la ficha, umbrales y móvil (archivos que empiezan por `40-`).

## Cómo repetirlas

Ver la sección *Pruebas* del README. Requieren una base de datos **vacía**; nunca las ejecutes sobre datos reales.

## Actualización desde 1.0 (comprobación manual)

No forma parte de las suites automáticas; se hizo a mano el 2026-10-29 y se anota tal cual:

1. Se instaló la 1.0.0 del ZIP entregado en una base vacía y se llenó con su propia suite (143/143 correctas: 15 tareas, 6 personas, archivos, sprints, importación v0.2).
2. Se copiaron `config/config.php` y `storage/uploads/` a la 1.1.0 y se ejecutó `php bin/migrate.php`: hizo la copia `pre-update`, aplicó `003_v11` y conservó las 15 tareas.
3. Con la 1.1 sobre esa base, 14 pantallas (inicio, proyectos, tablero, sprints, reportes, informes imprimibles, ajustes, administración, perfil…) cargaron sin errores de PHP ni de JavaScript.

**1.1.1:** con los archivos nuevos y la base todavía en 1.0, se comprobó en el navegador que tras iniciar sesión aparece «Actualización pendiente» (antes: Error 500 en todas las páginas), que el botón crea la copia `pre-update`, aplica `003_v11`, conserva las 15 tareas y que después Proyectos, Datos y Sprints cargan bien. Las dos suites se repitieron con 1.1.1: 201/201 y 47/47.

## Actualización desde 1.1.1 (comprobación de 1.2.0)

1. Se instaló la 1.1.1 del ZIP entregado en una base vacía y se llenó con su propia suite (201/201 correctas: 18 tareas, 6 proyectos, personas, equipos, horas, sprints, fantasmas).
2. Se copiaron encima los archivos de 1.2.0 conservando `config/config.php` y `storage/`.
3. Al iniciar sesión apareció «Actualización pendiente» (sin Error 500). El botón creó la copia `pre-update`, aplicó `004_v12` y conservó las 18 tareas.
4. Después cargaron sin errores Inicio, Mi trabajo, Equipos, Usuarios, Reportes, el perfil de una persona y, para los 6 proyectos, Resumen, Tablero, Lista, Ajustes › Integrantes y Exportar proyecto.

## Actualización desde 1.2.1 (comprobación de 1.3.0)

1. Se instaló la 1.2.1 del ZIP entregado en una base vacía y se llenó con su propia suite (249/249 correctas: 28 tareas, 9 proyectos).
2. Se copiaron encima los archivos de 1.3.0 conservando `config/config.php` y `storage/`.
3. Al iniciar sesión apareció «Actualización pendiente». El botón creó la copia `pre-update`, aplicó `005_v13` (tabla `sprint_capacity`) y conservó las 28 tareas.
4. Después cargaron sin errores Inicio, Mi trabajo, Equipos, Usuarios, Reportes, un perfil y, para los 9 proyectos, Resumen, Tablero, Lista, Ajustes › Integrantes y Exportar; Sprints en los 2 proyectos que tienen el módulo activo.

## Migración 006 (1.4.0)

`006_v14` solo marca como visto el tutorial para las personas que ya existían. Se aplicó sobre una copia de la tabla de personas de la base de pruebas: conservó las preferencias que había (`motion`), funcionó con preferencias vacías o no válidas y no dejó a nadie sin marcar. No se repitió la actualización completa desde el ZIP de 1.3.0 en el navegador.

## Actualización desde 1.4.1 (comprobación de 1.5.0)

Sobre una copia de la base de pruebas del navegador de 1.4.1 (10 personas, 7 proyectos, 20 tareas, horas, archivos y notificaciones) se pusieron los archivos de 1.5.0 con su `config.php`:

1. Al entrar, la administración vio la pantalla «Actualización pendiente» con `007_v15` (respuesta 503).
2. Con otra sesión de MariaDB reteniendo el bloqueo de migraciones, «Actualizar» respondió con el aviso «Otra actualización de la base de datos está en curso…» y no aplicó nada (seguían 6 migraciones).
3. Liberado el bloqueo, «Actualizar» creó la copia previa y aplicó `007_v15`: se crearon `capacity_alerts`, `capacity_alert_events` y `capacity_extras`.
4. Los recuentos de 15 tablas (personas, espacios, membresías, proyectos, tareas, responsables, sprints, capacidad de sprint, archivos, horas, comentarios, actividad, equipos, invitaciones, notificaciones) quedaron idénticos; solo `schema_migrations` pasó de 6 a 7.
5. Cargaron 53 pantallas sin errores; las 5 respuestas 404 son «Sprints» en proyectos con ese módulo desactivado (igual que en 1.4.1). La página nueva «Alertas de capacidad» cargó («No hay alertas abiertas»). Administración › Datos muestra 1.5.0 y 7 migraciones.

## Actualización desde 1.5.1 (comprobación de 1.6.0)

Sobre una copia de la base de la prueba de actualización anterior (10 personas, 7 proyectos, 20 tareas, sprints, horas, archivos, comentarios, notificaciones) con los archivos de 1.5.1 instalados desde su ZIP:

1. Con 1.5.1 cargaron 57 pantallas; las únicas respuestas 404 eran «Sprints» en proyectos con ese módulo desactivado y la página `/tasks`, que aún no existía.
2. Se pusieron los archivos de 1.6.0 conservando `config/config.php` y `storage/`. Al entrar se vio «Actualización pendiente» con `008_v16` (respuesta 503).
3. «Actualizar» creó la copia previa y aplicó `008_v16`.
4. Los recuentos de 17 tablas quedaron idénticos; solo `schema_migrations` pasó de 7 a 8.
5. Cargaron 57 pantallas, incluidas Tareas, Equipo y el calendario en día, semana y mes, sin errores; quedan las mismas 404 de «Sprints» con el módulo desactivado. Administración › Datos muestra 1.6.0 y 8 migraciones. Al abrir el calendario se crearon los calendarios de ejemplo.

## Actualización desde 1.6.0 (comprobación de 1.7.0)

Sobre una copia de la base de la prueba anterior ya actualizada a 1.6.0 (10 personas, 7 proyectos, 20 tareas, sprints, horas, archivos, comentarios, notificaciones y los calendarios de 1.6):

1. Con 1.6.0 cargaron 57 pantallas; las únicas respuestas 404 son «Sprints» en proyectos con ese módulo desactivado (igual que antes).
2. Se pusieron los archivos de 1.7.0 conservando `config/config.php` y `storage/`. Al entrar se vio «Actualización pendiente» con `009_v17` (respuesta 503).
3. «Actualizar» creó la copia previa y aplicó `009_v17`.
4. Los recuentos de 19 tablas quedaron idénticos (solo `schema_migrations` pasó de 8 a 9) y `project_members` siguió en 13. En estos datos nadie tenía tareas u horas en proyectos ajenos fuera de sus equipos, así que no se añadió ningún colaborador (los 4 casos posibles ya participaban por un equipo).
5. Cargaron las mismas 57 pantallas sin errores; quedan las mismas 404 de «Sprints». Administración › Datos muestra 1.7.0 y 9 migraciones.
6. Efecto del cambio de privacidad en estos datos: los 7 proyectos estaban marcados «Todo el espacio puede verlo»; tras la actualización, entre 5 y 9 personas por proyecto dejan de verlos porque no participan en ellos. La administración sigue viéndolos todos.

## Actualización desde 1.11.0 (comprobación de 1.12.0)

Base con los datos de la suite de 1.11.0 (90 tareas, 24 proyectos, 17 registros de horas, 14 sprints). Se copiaron los archivos de 1.12.0 sin tocar `config/` ni `storage/` y se ejecutó `php bin/migrate.php`: hizo la copia `pre-update`, aplicó `014_v112` y una segunda ejecución dijo «La base de datos está al día». No se perdió ninguna fila; los 2 sprints activos recibieron su compromiso como «sin dato de origen». `php bin/notify.php` terminó con código 0 y creó 9 alertas (5 vencidas, 1 sobrecarga, 1 desviación, 1 sin estimación, 1 sprint vencido) y 7 avisos. Después se ejecutó el SQL de reversión del README (sin errores, 90 tareas intactas, la base volvió a `013_v111`) y la migración se aplicó otra vez sin problemas.

## Actualización desde 1.10.0 (comprobación de 1.11.0)

Base de 1.10.0 llenada por su propia suite de integración (84 tareas, 23 proyectos, 7 comentarios, 17 registros de horas, 199 avisos, 9 tareas finalizadas). Con los archivos de 1.11.0 y su `config.php`: `php bin/migrate.php --status` muestra `013_v111` pendiente; `php bin/migrate.php` crea la copia `pre-update` y la aplica; una segunda ejecución dice «La base de datos está al día». Los recuentos son idénticos después; el estado del proyecto admite `planned`; las 5 tareas finalizadas que tenían fecha quedaron con `completed_due_date`. Reversión: el SQL de README › «Volver a 1.10.0» deja la base en 1.10.0 sin migraciones pendientes y con los mismos recuentos; al volver a aplicar 1.11.0 la migración se aplica de nuevo sin errores.

## Actualización desde 1.8.1 (comprobación de 1.9.0)

Sobre la base que dejó la suite de integración de 1.8.1 (26 personas, 20 proyectos, 64 tareas, 13 sprints, 17 registros de horas, 15 eventos en 18 calendarios, 136 notificaciones; 47 tablas), con los archivos de 1.9.0 recién descomprimidos del ZIP, conservando `config/config.php` y `storage/app.key`:

1. `php bin/migrate.php` mostró una migración pendiente (`011_v19`), creó la copia `pre-update` y la aplicó.
2. Los recuentos de las 47 tablas quedaron idénticos; solo `schema_migrations` pasó de 10 a 11 y apareció la tabla nueva `google_exports` (vacía).
3. Repetir `php bin/migrate.php` respondió «La base de datos está al día».
4. `php bin/notify.php` terminó sin errores (creó 1 aviso pendiente de esos datos).
5. Con sesión de administración cargaron Inicio, Perfil, Mi trabajo, Tareas, Calendario, Notificaciones, Proyectos, Equipos, Sprints, «Todo a mano», Administración › Datos y el resumen, tablero, lista y sprints de varios proyectos (200; la única 404 es «Sprints» en un proyecto con ese módulo desactivado, como antes). Sin errores de PHP en el registro.
