# Estado de funciones — Borealis 1.15.0

**Criterio:** una función aparece como *Implementado y probado* solo si una prueba automatizada la ejerció de verdad y pasó (ver [PRUEBAS.md](PRUEBAS.md)). Que su pantalla abra sin errores no cuenta como probarla. Lo que solo se revisó leyendo el código aparece como *pendiente de prueba*.

| Estado | Significado |
|---|---|
| ✅ Implementado y probado | Lo cubre una prueba automática que pasó |
| 🟡 Implementado, pendiente de prueba | Existe y su pantalla carga, pero ninguna prueba ejercita su comportamiento |
| 🔧 En desarrollo | Parcial |
| ⏳ Pendiente | No incluido todavía |

## Novedades de 1.15

| Función | Estado | Notas |
|---|---|---|
| Campana en vivo con la página abierta (`/api/notifications/status`), sin alargar la sesión, sin navegar con sesión vencida | ✅ | Integración «1.15 · Avisos con la página abierta» y navegador |
| Confirmaciones propias que no bloquean (sin `window.confirm`), protección de cambios conservada | ✅ | Navegador «1.15 · Confirmaciones…» |
| El service worker no navega una ventana con cambios sin guardar | 🟡 | Código revisado; probarlo exige un push real del sistema operativo |
| Notificaciones del sistema con el navegador cerrado | 🟡 | Depende del navegador y del sistema (INFORME-1.15 § 3); no se puede probar aquí |
| Motor interno de avisos: recordatorios y avisos al teléfono sin cron, sin sesión y sin pestañas abiertas | ✅ | Prueba real con Nginx + PHP-FPM (`tests/fpm/engine.sh`, 12/12); la entrega final por Google, Apple o Mozilla no se puede probar aquí |
| Perfil rediseñado (cabecera, índice de secciones, una columna) y menú de cuenta sin «Apariencia» duplicado | ✅ | Integración y navegador (las secciones y sus formularios siguen probándose); revisión visual con capturas 64, 65 y 69 |
| Ficha de tarea rediseñada: panel más ancho, secciones compactas, actividad en pestañas que se recuerdan | ✅ | Navegador (horas, seguimiento y estimaciones se usan desde sus pestañas); capturas 66, 67 y 68 |
| Registro maestro: accesos, intentos rechazados, resultado en la huella, tipos, agrupación, páginas por n.º, JSON y CSV | ✅ | Integración «1.15 · Registro maestro» y navegador |
| Errores del servidor y avisos no entregados en el registro | 🟡 | El resumen diario y los fallos de entrega se escriben en la tarea programada; el error del servidor no se provoca en las pruebas |
| Conservación con `bin/audit-archive.php` (la cadena sigue desde el último archivado) | ✅ | Integración: opciones, mínimo y continuación de la cadena; el archivado real de filas antiguas no se ejerce |
| Barra lateral por áreas según el rol, grupos recordados, modo compacto, favoritos, recientes, barra inferior móvil | ✅ | Integración y navegador |
| Tareas › Equipo: tabla, lista, tablero, agrupación, filtros, vista recordada, vistas guardadas | ✅ | Integración y navegador |
| Detalle de tarea en tres zonas con horas reales y progreso | ✅ | Integración y navegador |
| Seguir tareas (sin duplicar avisos) y decisiones | ✅ | Integración |
| Automatizaciones: tarea vencida, bloqueo resuelto, horas superadas y bloqueo prolongado | ✅ | Integración (sin repetición, pausa, permisos, registro) |
| Automatizaciones: proyecto terminado, prioridades de la semana, informe de sprint | 🟡 | Implementadas con la misma base; sin prueba propia de su condición |
| Actualización 1.14 → 1.15 | ✅ | Integración (migración 017 vuelta a aplicar; integridad correcta) |

## Novedades de 1.14

| Función | Estado | Notas |
|---|---|---|
| Tarea programada por consola (`bin/notify.php`) con registro de cada ejecución | ✅ | Integración «1.14 · Tarea programada» |
| Dirección `/cron/run?token=…`: clave, 403, cabecera, sin sesión, cambio de clave, sin repetir en menos de 1 minuto | ✅ | Integración |
| Respaldo por visitas (tras responder, cada 5 minutos como mucho) | ✅ | Probado con `web_fallback => 'always'` en el servidor integrado; el camino de PHP-FPM (`fastcgi_finish_request`) no se probó con un PHP-FPM real |
| Administración › Notificaciones: estado, comando para HestiaCP y envíos fallidos; aviso en el perfil | ✅ | Integración y navegador |
| Registro por aviso y dispositivo, reintentos de errores pasajeros, error permanente sin reintento, sin duplicados | ✅ | Contra el servicio simulado (`/push/flaky`, `/push/badauth`) |
| Aviso leído o tarea completada mientras espera el reintento: no se envía | ✅ | Integración |
| Renovación de la suscripción (`pushsubscriptionchange`) sin sesión, probando la anterior | ✅ | API en integración; el evento del navegador no se puede provocar en Chromium automatizado |
| Envío al dispositivo activado por defecto, respetando a quien lo apaga; reparación en la migración | ✅ | Integración y actualización desde 1.13 |
| Propuesta de permiso en el inicio | 🟡 | Se comprueba que aparece; el permiso real del navegador no se concede en las pruebas |
| Registro de actividad con categoría, antes, después, motivo y origen | ✅ | Integración |
| Filtros por persona, proyecto, categoría, acción y período; CSV | ✅ | Integración y navegador |
| Integridad: modificación, eliminación y huella borrada detectadas | ✅ | Integración |
| Sin contraseñas, tokens, claves ni datos de dispositivos en el registro | ✅ | Integración |
| Historial del calendario (eventos y fechas de tareas con motivo) | ✅ | Integración |
| Historial de la sincronización con Google (cuántos cambios) | 🟡 | Solo revisado en el código |
| Estimaciones: administración, trabajador, acordado, real y diferencia; motivo obligatorio; revisión y acuerdo; historial | ✅ | Integración y navegador |
| Alertas «superó el tiempo acordado» y «ampliación importante de la estimación» | ✅ | Integración |
| Trabajos rápidos: crear seguidos, asignar, horas, carga, permisos, sin aparecer entre los proyectos | ✅ | Integración y navegador |
| Carga de la semana al elegir responsable | ✅ | Integración (API) y navegador |
| «Crear y añadir otra» en «Nueva tarea» | ✅ | Navegador |
| Nuevo proyecto con responsable, entrega, integrantes y objetivo | ✅ | Integración y navegador |
| Carta Gantt: tareas, subtareas, hitos, dependencias, retrasos, plan original y actual, escalas, línea base, permisos | ✅ | Integración y navegador (también en el móvil) |
| Análisis por semana, mes, trimestre y año, filtro por persona, «¿Qué ocurrió en el período?» | ✅ | Integración y navegador |
| Actualización 1.13 → 1.14 (estimaciones, plan original, preferencias) | ✅ | Integración (migración 016 vuelta a aplicar sobre datos) |

## Novedades de 1.13

| Función | Estado | Notas |
|---|---|---|
| Menú «Análisis» con 6 pestañas, filtros por proyecto, sprint, equipo, «solo lo mío» y período | ✅ | Integración «1.13 · …» y navegador «Novedades 1.13» |
| Indicadores con fórmula, numerador y denominador (15 de 20 = 75 %) | ✅ | Prueba A (unidad e integración) |
| Desviación de estimaciones por categoría y sugerencia con tareas parecidas, sin valores extremos | ✅ | Prueba B; nunca cambia la estimación |
| Tendencias solo con datos suficientes («No hay datos suficientes…») | ✅ | Prueba C |
| Capacidad futura: 58 h frente a 40 h → «supera … en 18 horas» con alternativas | ✅ | Prueba D |
| Riesgo por reglas explicado, sin probabilidades, y «Sin datos suficientes» | ✅ | Prueba E |
| Simulador sin escribir en la base | ✅ | Prueba F (se comprueba la base antes y después) |
| Aplicar escenario: vista previa, permisos, datos sin cambios, confirmación, transacción, auditoría | ✅ | Prueba G; 403 para colaboradores, 422 sin confirmar, 409 si los datos cambiaron |
| Revertir escenario (y rechazo si algo cambió después) | ✅ | Integración y navegador |
| Recomendaciones con evidencia, acción, impacto, confianza y enlace | ✅ | Integración (prueba D) |
| Informe ejecutivo imprimible y CSV | ✅ | Integración y navegador |
| Permisos: sin rankings, 404 para proyectos y sprints ajenos (página, API, CSV, imprimible y simulador) | ✅ | Prueba H |
| Parámetros de análisis (solo administración, con auditoría) | ✅ | Integración y navegador |
| Caché con huella de los datos (se invalida al cambiar una estimación o una fecha) | ✅ | Prueba I |
| Rendimiento: 1 500 tareas y 3 000 registros de horas, < 4 s la primera vez y < 1 s después | ✅ | Prueba J |
| Móvil sin desplazamiento horizontal en las 6 pestañas | ✅ | Navegador |

## Novedades de 1.12

| Función | Estado | Notas |
|---|---|---|
| Menú «Monitoreo»: Resumen con 8 indicadores enlazados, estado calculado de cada proyecto, alertas, próximos compromisos, carga y sprints | ✅ | Integración «1.12 · …» y navegador «Novedades 1.12» |
| Cumplimiento: atrasadas, próximas, dentro del plazo, completadas dentro o fuera, sin fecha, bloqueadas | ✅ | Integración (escenarios 1 y 2) y navegador |
| Motivos de retraso o desviación (comentario, acción correctiva, autor, fecha) e historial de fechas límite | ✅ | Integración (escenario 9) y navegador |
| Estimado frente a registrado (+7 h, +140 %), sin dividir por cero, mediana | ✅ | Integración (escenario 3) y navegador |
| Corrección de horas con historial y protección contra duplicados | ✅ | Integración |
| Carga planificada por semana frente a capacidad; vistas por persona, proyecto y sprint | ✅ | Integración (escenario 4) y navegador |
| Alertas: severidad, sin duplicados, resolución automática, «Marcar revisada», escalado a nivel 2 | ✅ | Integración (escenario 7 y escalado) |
| Escalado a nivel 3 (administración) | 🟡 | Mismo código que el nivel 2; sin prueba propia |
| Dependencias: circular, duplicada, consigo misma, otro proyecto; riesgo sin cambiar fechas | ✅ | Integración (escenario 6) |
| Compromiso original del sprint y resumen al cerrar | ✅ | Integración (escenario 10) |
| Informe del período y CSV, con permisos | ✅ | Integración y navegador |
| Permisos por URL y API; sin rankings | ✅ | Integración (escenario 8) |
| Umbrales en Administración › Ajustes | ✅ | Integración y navegador |
| Reglas desde el cron `bin/notify.php` | ✅ | Integración (ejecutado desde la consola); cron real de HestiaCP sin probar |
| Migración 014 repetible; actualización desde 1.11 con datos | ✅ | Integración y prueba de actualización |

## Novedades de 1.11

| Función | Estado | Notas |
|---|---|---|
| Estado de proyecto «Planificado» (crear, cambiar, filtrar), además de Activo, En pausa y Completado | ✅ | Integración «1.11 · …» y navegador «Novedades 1.11» |
| Un estado inventado o manipulado se rechaza; quien no participa no puede cambiarlo | ✅ | Integración |
| «N días de retraso» en tablero, Mi trabajo, Mis tareas y ficha, sin cambiar el estado de la tarea | ✅ | Integración y navegador (tablero, ficha, Mi trabajo y móvil) |
| Fecha límite original guardada al finalizar; «Fuera de plazo · N días» / «A tiempo»; no se pierde al cambiar la fecha; reabrir la limpia | ✅ | Integración (finalizar, mover al tablero, reabrir, cambiar fecha) y navegador |
| Lo mismo al cambiar la categoría o borrar una columna con tareas | 🟡 | Mismo código (actualización SQL); sin prueba propia |
| Mi trabajo › «Hoy» (primera pestaña); «Próximas» desde mañana, sin solaparse | ✅ | Integración y navegador |
| Aviso «Ya no eres responsable de …» (responsable principal y varios responsables) | ✅ | Integración |
| Los retrasos no se avisan a la administración | ✅ | Integración |
| API de la tarea con `completed_at`, `completed_due_date` y `punctuality`; exportar con la fecha original | ✅ | Integración y navegador |
| Migración 013 repetible; actualización de una base 1.10 con datos | ✅ | Integración (013 dos veces) y prueba de actualización (84 tareas, 23 proyectos, nada perdido) |
| Reversión a 1.10 (SQL del README) | ✅ | Ejecutada a mano sobre la base actualizada: 1.10.0 queda sin migraciones pendientes y sin perder filas; volver a actualizar después funciona. No probada en el servidor de Cal |

## Novedades de 1.10

| Función | Estado | Notas |
|---|---|---|
| Sección «Enlaces de trabajo» en la ficha de la tarea (carpeta de Drive y repositorio de GitHub) | ✅ | Integración «1.10 · …» y navegador «Novedades 1.10» (página de la tarea, panel lateral y móvil) |
| Botón «Copiar» de cada enlace | ✅ | Navegador: lee el portapapeles real de Chromium tras pulsar |
| Copiar en una instalación con http sin certificado (vía alternativa) | 🟡 | Las pruebas corren en 127.0.0.1, que el navegador trata como seguro |
| Comprobación y normalización de los enlaces (https, SSH de GitHub, rechazo de otros sitios) | ✅ | Integración |
| Solo lectura para quien solo puede ver la tarea | ✅ | Integración (página y API) |
| Historial, duplicar, exportar e importar con enlaces | ✅ | Integración |

## Novedades de 1.9

**Servicios externos:** Google, el correo y los avisos al teléfono se probaron contra servidores locales que imitan sus respuestas (`tests/fake-google/server.php`, `tests/fake-services/smtp.php`, `tests/fake-services/server.php`). Eso prueba lo que Borealis envía y cómo reacciona a errores, no que Google, un servidor de correo real o un teléfono real lo acepten. Esas pruebas reales están pendientes (ver la columna Notas).

| Función | Estado | Notas |
|---|---|---|
| Hora de entrega opcional en las tareas (crear, cambiar, quitar, validación, aviso del cambio, tablero y ficha) | ✅ | Integración y navegador |
| Horas siempre en 24 horas (hh:mm), también con el navegador en un idioma de 12 horas; «915» se guarda como 09:15 y «25:00» se rechaza | ✅ | Navegador (es-MX) |
| Google Calendar: elegir un calendario con permiso de escritura (los de solo lectura se rechazan) | ✅ | Integración con Google simulado y navegador |
| Envío de tareas Borealis → Google: clave, nombre, proyecto, estimación, descripción breve y enlace; con hora o de todo el día | ✅ | Integración con Google simulado |
| Sin duplicados: el mismo evento se actualiza al cambiar la tarea, al perder el registro (409) o al reconectar | ✅ | Integración con Google simulado |
| Nunca se tocan eventos que no creó Borealis | ✅ | Integración (los eventos propios siguen intactos al enviar, quitar y desconectar) |
| Completadas: se quitan del calendario o se dejan con ✓; quien deja de ser responsable pierde el evento | ✅ | Integración |
| Sprints y entregas de proyecto como eventos; modo «solo título, proyecto y enlace» | ✅ | Integración |
| Cambio de calendario, «Dejar de enviar», «Desconectar y quitar sus eventos» | ✅ | Integración |
| Cuota o caída de Google: Borealis sigue funcionando, muestra el motivo y reintenta solo con pausas crecientes | ✅ | Integración (cuota simulada y cron) |
| Permiso retirado en Google: «Error de autorización · Volver a conectar» | ✅ | Integración |
| Tokens de Google: nunca llegan al navegador ni a los registros | ✅ | Integración |
| Google Calendar con un cliente OAuth y una cuenta real | ⏳ | Requiere configurar Google Cloud (docs/CONFIGURACION-1.9.md) y probarlo en el servidor |
| Recordatorios 24 h y 30 min antes (configurables), vencidas, sin Borealis abierto (cron) | ✅ | Integración y prueba unitaria `tests/unit/reminders.php` |
| Zona horaria por persona | ✅ | Integración (Santiago frente a la zona de la instalación) |
| Horario de silencio: el aviso queda en la campana; el correo espera y sale al terminar | ✅ | Integración (correo) |
| Horario de silencio y agrupación (más de 3 avisos en uno) para los avisos al teléfono | 🟡 | Mismo código de horario que el correo; sin prueba propia del envío push |
| Cambiar la hora o completar la tarea cancela el recordatorio anterior | ✅ | Integración |
| Resumen semanal (apagado por defecto) | ✅ | Integración |
| Alerta a la administración: horas semanales completas con tareas planificadas pendientes, como discrepancia de planificación | ✅ | Integración |
| Correo por SMTP (STARTTLS, certificado verificado, sin contraseña en claro, agrupado, una sola vez, reintento si el servidor cae) | ✅ | Integración con servidor SMTP simulado |
| Correo de prueba para la administración, con el motivo del fallo | ✅ | Integración |
| Correo con un servidor real de HestiaCP y llegada a Gmail/Outlook | ⏳ | Requiere el buzón y `config.php` en el servidor |
| Avisos al teléfono real (Android, iPhone desde la pantalla de inicio) | ⏳ | Web Push ya existía en 1.8; sigue sin prueba con un teléfono real |
| PWA: manifiesto, icono, página «Sin conexión» y service worker que no guarda datos privados | ✅ | Integración y navegador |
| Instalar como aplicación en un teléfono real | 🟡 | Solo Chromium de escritorio y móvil emulado |
| Cron en HestiaCP | ⏳ | Probado con `php bin/notify.php` en local; falta crear la tarea en HestiaCP |

## Correcciones de 1.8.1

| Función | Estado | Notas |
|---|---|---|
| Campos de fecha en dd/mm/aaaa aunque el navegador esté en inglés, con botón de calendario | ✅ | Navegador con idioma en-US (Mis tareas y diálogo de evento) |
| Al escribir 09112026 se muestra 09/11/2026 y se guarda el 9 de noviembre | ✅ | Navegador (se recarga y se comprueba) |
| Fecha inexistente (31/02/2026) rechazada con el motivo, sin guardar | ✅ | Navegador |
| Rellenar fechas desde el código (clic en un día del calendario, editar un evento) las muestra en dd/mm/aaaa | ✅ | Navegador (diálogo de evento) |
| CSV de tareas, horas y proyectos con fechas dd/mm/aaaa y momentos dd/mm/aaaa hh:mm | ✅ | Integración (tareas y horas); el de proyectos usa la misma función, sin prueba propia |
| El servidor sigue exigiendo la fecha interna completa (no interpreta 09/11 por su cuenta) | ✅ | Integración |
| «Tiempo estimado» al crear con «+ Nueva tarea» | ✅ | Ya en 1.7.1 (ver abajo) |
| Campos de fecha en Safari/Firefox | 🟡 | Solo Chromium |

## Novedades de 1.8

| Función | Estado | Notas |
|---|---|---|
| Calendario «Ambos / Personal / Equipo», recordado por persona, con colores y candado en lo personal | ✅ | Integración y navegador (escritorio y móvil) |
| Lo personal es privado: otra persona no lo ve, ni lo abre ni lo cambia por la API | ✅ | Integración |
| Calendario de equipo ligado a un equipo: solo sus integrantes lo ven y crean en él; no se puede ligar a un equipo ajeno; uno personal nunca se liga a un equipo | ✅ | Integración |
| Selector de calendario agrupado al crear un evento; diálogo «Nuevo calendario» con equipo | ✅ | Integración y navegador |
| Hitos de proyectos en el calendario, como capa que se oculta; invisibles para quien no participa | ✅ | Integración |
| Preferencias de notificaciones por categoría y anticipación; se respetan al crear avisos | ✅ | Integración y navegador |
| Avisos: por vencer (según anticipación), vencida (una vez), cambio de fecha, entrega del proyecto, sprints; nunca de tareas completadas | ✅ | Integración |
| Centro de notificaciones filtrable por tipo, marcar como leídas | ✅ | Integración y navegador |
| Sprints en dos etapas (datos básicos → planificación), página «Sprints» | ✅ | Integración y navegador |
| Sprint en el calendario: inicio previsto, fin, «Fin (sin cerrar)» y cierre real | ✅ | Integración |
| Llegar a la fecha de fin no cierra el sprint; se avisa una vez; el cierre lo hace una persona y avisa con el resumen | ✅ | Integración |
| Web Push: suscripción, cifrado aes128gcm y firma VAPID verificados descifrando el mensaje | ✅ | Integración con un servicio de notificaciones simulado en local |
| Web Push: categorías del móvil, sin duplicados, sin tareas completadas, baja automática (410), cron `bin/notify.php` sin repeticiones | ✅ | Integración (servicio simulado) |
| Web Push en un teléfono real (Android/Chrome, iPhone con la app en la pantalla de inicio) | 🟡 | **No probado aquí**: necesita HTTPS real y un dispositivo |
| Discord por webhook: eventos por proyecto, una vez por evento, sin menciones, prueba, error explicado, guardado cifrado | ✅ | Integración con un webhook simulado en local |
| Discord en un canal real | 🟡 | No probado con Discord real |
| GitHub (solo lectura): issues, pull requests, commits; privado con token cifrado; errores en español | ✅ | Integración con una API simulada en local |
| GitHub con un repositorio real | 🟡 | No probado contra api.github.com |
| «Todo a mano»: enlaces directos separados de las integraciones, permisos (administra / ve / no ve) | ✅ | Integración y navegador |
| Sin conexión con GitHub/Discord, Borealis sigue funcionando | ✅ | Integración (dirección inaccesible) |
| Drive con su API (listar archivos) | ⏳ | Solo enlace directo: requiere el permiso `drive.readonly` de Google y su verificación |
| Calendario de Google compartido con un equipo | ⏳ | Los calendarios de Google conectados son personales (1.6); compartir uno con un equipo queda pendiente |
| Migración 010 repetible | ✅ | Integración |

## Correcciones de 1.7.1

| Función | Estado | Notas |
|---|---|---|
| El tutorial cambia de página de verdad (Inicio → Proyectos → Tareas → Sprints → Calendario → Configuración) | ✅ | Navegador: cada cambio de página comprobado por dirección y paso |
| Sigue funcionando aunque el servidor no pueda guardar el paso, y tras recargar | ✅ | Navegador (peticiones bloqueadas a propósito) |
| Anterior (también a otra página), Siguiente, Omitir, Reiniciar | ✅ | Navegador |
| Mientras dura, los clics en la página no hacen nada (no modifica datos) | ✅ | Navegador |
| Módulos de JavaScript con versión en su dirección (evita la caché vieja tras actualizar) | ✅ | `tests/unit/urls.php` y navegador. **No probado en el Nginx real de Cal**: es la causa más probable, no confirmada allí |
| «Mis tareas» muestra las tareas asignadas en proyectos y las personales por separado | ✅ | Integración y navegador |
| Si una parte no carga, se explica y el resto se ve (no queda en blanco) | ✅ | Integración (tabla renombrada durante la prueba) |
| «Completadas»: dos columnas con separación en escritorio, una en móvil, sin superposición | ✅ | Navegador mide las tarjetas en 1440 px y 390 px |
| «Tiempo estimado» y «Sprint» en «+ Nueva tarea»; se ve al abrir la tarea | ✅ | Integración y navegador |
| Tutorial en Safari/iPhone | 🟡 | Solo Chromium (incluido tamaño móvil) |

## Novedades de 1.7

| Función | Estado | Notas |
|---|---|---|
| Enlace correcto en el mensaje de cuenta creada e invitaciones, también con Borealis en una carpeta | ✅ | `tests/unit/urls.php` (6 combinaciones de `app.url` y ruta base) e integración (el enlace abre el inicio de sesión) |
| Cada persona ve solo sus proyectos (inicio, listado, búsqueda, calendario, tareas de equipo, enlace directo, API) | ✅ | Integración: sin participar, al añadir, al retirar y por equipo |
| La administración ve todos los proyectos y puede filtrar «Solo donde participo» | ✅ | |
| Al actualizar, quien tenía tareas u horas en un proyecto ajeno pasa a colaborar en él | ✅ | Integración y prueba de actualización |
| «Horas semanales disponibles» al crear la cuenta o invitar; editable después | ✅ | Integración y navegador |
| Capacidad del sprint = horas semanales × semanas; asignar por encima se rechaza | ✅ | Ejemplo de 15 h × 2 semanas = 30 h |
| Cambiar las horas semanales no recalcula los sprints terminados | ✅ | |
| Fotografía de perfil: subir, recortar, cambiar, eliminar; iniciales sin fotografía | ✅ | Navegador (recorte real) e integración (tamaños, caché, permisos, archivos viejos borrados) |
| Fotografía en menú, tarjetas y listas de responsables | ✅ | |
| «Calendario» en el menú lateral, marcado y en móvil | ✅ | Navegador |
| Barra lateral sin barra de desplazamiento, sigue desplazándose (rueda, teclado) | ✅ | Navegador; el gesto táctil no se probó en un dispositivo real |
| Fotografía de perfil en Safari y Firefox | 🟡 | Solo Chromium |

## Novedades de 1.6

| Función | Estado | Notas |
|---|---|---|
| Saludo con el nombre real y la hora local; respaldo con el identificador si la cuenta se llama «Usuario» | ✅ | Integración y navegador (3 zonas horarias) |
| Páginas sin caché intermedia (`no-store`, `Vary: Cookie`) | ✅ | |
| Desplazador fino de la barra lateral | — | Sustituido en 1.7: ya no se dibuja |
| Los seis temas cambian el fondo principal y la cabecera | ✅ | Navegador: 7 aspectos distintos; capturas por tema |
| Tutorial por varias páginas, continúa tras recargar, Anterior entre páginas, Omitir, Finalizar, repetir | ✅ | Navegador |
| Tutorial: ejemplos sin datos para Responsables y Sprints; móvil como hoja inferior | ✅ | Navegador |
| Mis tareas: listas, subtareas, orden, prioridad, fecha límite, notas; privadas | ✅ | Ni la administración las lee ni edita (404) |
| Asignadas a mí, Equipo, Completadas (tareas de proyecto con su sprint) | ✅ | |
| Calendario día, semana y mes; crear con clic en día u hora; editar sin cierres inesperados | ✅ | Navegador |
| Reuniones con participantes, enlace, lugar, proyecto, recordatorio y repetición | ✅ | Aviso a participantes y recordatorio sin duplicar |
| Varios calendarios: color, mostrar/ocultar, permisos, eliminar con destino de los eventos | ✅ | |
| Vencimientos, tareas personales y sprints en el calendario, sin duplicar registros | ✅ | Cambiar la fecha de la tarea mueve el calendario |
| Zonas horarias y cambio de horario | ✅ | `tests/unit/events-time.php` |
| Google: OAuth, tokens cifrados, elegir calendarios, sincronizar sin duplicar, conflictos, 410, errores visibles, revocación, desconectar | ✅ | **Solo contra el servidor simulado** `tests/fake-google/server.php` |
| Google real (cliente OAuth en Google Cloud, consentimiento, verificación de la app) | ⏳ | Requiere los pasos del README › Google en una instalación con HTTPS |
| Recordatorios por correo o notificación del sistema | ⏳ | Solo dentro de Borealis (sin cron) |
| Reordenar tareas en Google Tasks (el orden de Borealis no se envía a Google) | ⏳ | |
| Mover una sola repetición de un evento interno | ⏳ | Se cambia la serie completa |
| Calendario en Safari, Firefox y lectores de pantalla | 🟡 | Solo Chromium |

## Novedades de 1.5

| Función | Estado | Notas |
|---|---|---|
| Detectar «Capacidad agotada — Trabajo pendiente» (horas registradas ≥ horas semanales + adicionales, con tareas comprometidas abiertas) | ✅ | Varias semanas y proyectos; sin duplicar horas; tarea compartida cuenta su parte |
| Tareas comprometidas: vencen esa semana o sprint activo que termina esa semana; sin las terminadas ni las vencidas antes | ✅ | Las vencidas anteriores se listan aparte y no disparan la alerta |
| Sin horas semanales definidas o todo terminado: sin alerta | ✅ | |
| Panel en Inicio, Equipos, resumen y sprints del proyecto, perfil y página `/capacity` | ✅ | Integración y navegador |
| Quién la ve y quién la resuelve (administración del espacio y responsable del proyecto) | ✅ | Integrante: 403 al resolver y sin panel |
| Un solo aviso por alerta; ver la alerta no la cierra | ✅ | Se avisa otra vez solo si se reabre |
| Reprogramar (fecha posterior u otro sprint), reasignar (solo personas con horas libres), revisar estimación, autorizar horas adicionales, mantener con justificación | ✅ | Cada acción comprueba los permisos de la tarea como en cualquier otra pantalla |
| Reapertura si se compromete más trabajo o se agotan las horas adicionales | ✅ | |
| Recalcular al cambiar la persona responsable desde la tarea | ✅ | Se resuelve sola como «ya no hay trabajo pendiente» |
| Historial de decisiones y registro de actividad | ✅ | |
| Migración 007 repetible y actualización desde 1.4.1 con datos | ✅ | Bloqueo para dos actualizaciones a la vez probado a mano |
| Temas completos: fondo, barra lateral, variantes del símbolo, estados de navegación | ✅ | Navegador: 7 aspectos distintos, contraste ≥ 4,5:1 medido, sin recarga, se conserva al volver a entrar |
| Temas con «reducir movimiento» | ✅ | Sin transición |
| Ilustraciones de los temas (1.5.1): barra lateral de Medieval, Agua, Fuego, Cielo y Bosques; fondo de Tierra | ✅ | Se sirven y se revisaron en capturas; contraste calculado sobre las imágenes |
| Alertas de semanas pasadas que siguen abiertas | 🟡 | Se recalculan al abrir los paneles; sin prueba que cruce el cambio de semana |
| Temas en Safari, Firefox y lectores de pantalla | 🟡 | Solo Chromium |

## Seguridad 1.4.1

| Función | Estado | Notas |
|---|---|---|
| Crear espacios solo con permiso de la instalación (o `app.allow_workspace_creation`) | ✅ | Invitada e integrante rechazados por petición directa; la opción activada lo permite |
| Correo existente en «Crear cuenta» → invitación, sin membresía | ✅ | Aceptar con contraseña equivocada no incorpora; con la suya sí |
| Contraseña y datos de cuentas compartidas o de la instalación protegidos | ✅ | Administración local rechazada; la de la instalación sí puede |
| Cadena del ataque de 1.4.0 bloqueada | ✅ | Reproducida de principio a fin como invitada |
| Escalada con roles personalizados y cambio del propio rol | ✅ | Con y sin «Administrar roles» |
| Importación de proyectos con las mismas comprobaciones de archivos y sin huérfanos | ✅ | Incluye una importación que falla a propósito |
| Búsqueda, Equipos y perfiles limitados para invitados | ✅ | |
| Límite de intentos sin bloqueo de terceros | ✅ | Con IP distintas de 127.0.0.x |
| `bin/audit-accounts.php` | ✅ | Además, detectó el ataque reproducido sobre una base 1.4.0 |
| Bloqueo por cuenta desde muchas IP (50 fallos) | 🟡 | Revisado en el código; sin prueba con 50 IP |

## Novedades de 1.4

| Función | Estado | Notas |
|---|---|---|
| Un clic en la tarea la abre; el nombre solo se edita dentro | ✅ | Lista sin campos de nombre (servidor y Chromium); renombrar dentro guarda sin cerrar el panel y la lista muestra el nombre nuevo al cerrar |
| Arrastrar una tarjeta no abre la tarea | 🟡 | El navegador no envía clic tras arrastrar; sin prueba propia |
| Sprints agrupados en Activo, Planificados y Finalizados | ✅ | Servidor y Chromium (elegir un sprint muestra su detalle) |
| Detalle del sprint: completadas, pendientes, participantes, horas, acceso a sus tareas | ✅ | Comprobado en la respuesta del servidor |
| Archivos: subir, cuadrícula con miniaturas, lista con formato y tamaño | ✅ | Subida por selector en Chromium; arrastrar archivos desde el escritorio usa el mismo envío pero no se prueba con un arrastre real |
| Archivos: cambiar nombre, eliminar con confirmación, descargar | ✅ | |
| Archivos: permisos, tipos bloqueados, fuera de la carpeta pública | ✅ | Quien no participa no ve ni renombra sus archivos; `.php`, doble extensión y contenido falso rechazados. Se guardan en `storage/uploads`, fuera de `public/`, y solo se entregan tras comprobar el acceso |
| Tutorial la primera vez, Siguiente / Anterior / Omitir, guardado por persona, repetir | ✅ | Chromium de escritorio |
| Tutorial en móvil | 🟡 | Bajo 720 px la explicación pasa a una hoja inferior; revisado con captura a 390 px; sin prueba automática ni en un móvil real |
| Fondos personales (7) aplicados al instante y guardados por persona | ✅ | Servidor (valor no válido rechazado) y Chromium (se mantiene en otras pantallas y al recargar) |
| Legibilidad de los fondos | 🟡 | Revisado con capturas; sin medición automática de contraste |

## Novedades de 1.3

| Función | Estado | Notas |
|---|---|---|
| Guardado de responsables sin cerrar el selector ni perder clics | ✅ | Probado en Chromium con 700 ms de retraso de red: 5 clics seguidos, el selector sigue abierto y se guardan todos en orden; al recargar se mantienen. Regresión con clics cada 150, 250 y 300 ms |
| Indicador «Guardando… / Guardado / Error al guardar» | ✅ | «Guardando» y «Guardado» probados en el navegador. «Error al guardar» solo revisado leyendo el código (🟡) |
| Asignar, retirar y reasignar arrastrando en el tablero | ✅ | Los tres probados en Chromium. Mover sin espacio en destino deja a la persona donde estaba (servidor). Safari/Firefox y táctil sin probar |
| Horas disponibles por persona y sprint (fijadas o automáticas) | ✅ | Fijar, vaciar, permisos y valores no válidos |
| Validación de capacidad en el servidor | ✅ | Exceso rechazado con el motivo, límite exacto permitido, estimación, paso al sprint, creación ya asignada y movimiento; sobrecarga tras bajar horas avisa sin borrar |
| Reparto de horas entre varios responsables | ✅ | 15 h entre dos personas = 7,5 h cada una |
| Tareas sin estimar como «pendientes de estimar» | ✅ | |
| Personas disponibles / sin disponibilidad e indicador de carga | ✅ | Tablero (servidor y navegador) y página del sprint |
| Dos personas asignando a la vez las últimas horas | 🟡 | El sprint se bloquea mientras se comprueba y guarda; no hay prueba de concurrencia real |
| Horas libres en el selector de responsables | 🟡 | Se ven y desactivan a quien no cabe; ninguna prueba automática las comprueba |
| Un equipo sin personas nombradas no consume horas de nadie | 🟡 | Decisión de diseño; sin prueba propia |

## Novedades de 1.2

| Función | Estado | Notas |
|---|---|---|
| Validación de correo estricta con sugerencia de dominio | ✅ | Servidor (7 casos de correo mal escrito, sugerencia gmail.con → gmail.com, invitación y edición) y navegador (aviso antes de enviar) |
| La administración edita nombre, correo y cargo de otras cuentas | ✅ | Correo duplicado rechazado; un integrante no puede; probado en el navegador. El límite «otros espacios solo con administración de la instalación» no tiene prueba propia (🟡) |
| «Invitar por enlace» muestra el mensaje con el enlace | ✅ | Regresión probada en el navegador; el enlace abre el alta |
| «Nuevo enlace» para invitaciones pendientes | ✅ | El enlace anterior deja de valer |
| Equipos como participantes del proyecto (acceso en vivo) | ✅ | Acceso, alta posterior al equipo, permisos y vista de Ajustes |
| Varios responsables por tarea | ✅ | Principal + corresponsables, avisos, «Mi trabajo», CSV; probado en el navegador |
| Equipo responsable de una tarea | ✅ | Integrantes la ven en «Mi trabajo»; tarjeta muestra el equipo |
| Mover personas en el tablero (proyecto o equipo polivalente) | ✅ | Regla polivalente probada en servidor; arrastre entre tarjetas y desde la fila de personas probado en Chromium. Safari/Firefox y táctil sin probar |
| Perfil de participantes con horas | ✅ | Semana con capacidad, período, por proyecto; respeta visibilidad |
| Exportar e importar proyectos | ✅ | Ida y vuelta conserva tareas, números, subtareas, responsables, equipo, horas y comentarios; personas desconocidas informadas; permisos. Adjuntos embebidos: la exportación se probó, la importación de adjuntos no tiene prueba propia (🟡) |
| Equipo en el diálogo de «Nueva tarea» | ⏳ | Se elige después, en el detalle de la tarea |
| Filtro del tablero por equipo | ⏳ | El filtro «Asignadas a mí» ya incluye corresponsables y equipos |

## Novedades de 1.1

| Función | Estado | Notas |
|---|---|---|
| Registro de horas por tarea (1.5, 1,5, 1:30, 90m) y totales por persona | ✅ | Servidor y navegador; límites (24 h, sin fechas futuras) y permisos probados |
| Registrar horas de otra persona (solo responsable del proyecto) | ✅ | |
| Comentario y horas de cierre al finalizar una tarea | ✅ | Se guardan juntos o nada; probado en el navegador |
| Horas estimadas por tarea | ✅ | |
| Horas semanales por persona (Administración y perfil propio) | ✅ | Se ven también en la página del equipo (🟡 sin prueba) |
| Control del sprint: capacidad, planificado, registrado, gráfico de avance y alertas | ✅ | Probados capacidad automática, fijada a mano, alertas de sobrecarga por persona y del sprint |
| Exportar: informe imprimible/PDF y CSV de tareas, horas y proyectos | ✅ | Proyecto y espacio; las horas exportadas respetan la visibilidad. El PDF lo genera el navegador al imprimir |
| Cuentas fantasma: crear, «Entrar como», «Volver a mi cuenta», borrar todas | ✅ | Solo la administración de la instalación; no pueden iniciar sesión; no se puede entrar como una persona real; copia de seguridad antes de borrar |
| Siglas del proyecto editables con enlaces antiguos funcionando | ✅ | Las siglas antiguas quedan reservadas |
| Selector de color con paleta y fondos del encabezado | ✅ | |
| Pestañas del proyecto con indicador deslizante | ✅ | |
| Crear equipo sin salir de la página | ✅ | Probado en la API; el aviso con «Abrir equipo» solo se vio en capturas |
| Añadir a un equipo creando la cuenta, con mensaje para copiar | 🟡 | El mensaje de acceso está probado al crear cuentas; las pestañas del diálogo del equipo no |
| Nueva tarea: elegir responsable entre las personas del proyecto | 🟡 | |
| Botón «Abrir» en tarjetas y lista | ✅ | El navegador abre la tarea con él |
| Detalle de tarea reorganizado | 🟡 | Revisado en capturas |
| Barra lateral nueva (contadores, «Tu semana», avance de proyectos, equipos) | 🟡 | Sin desplazamiento horizontal en 4 tamaños (✅); su contenido no tiene prueba propia |
| Actualizar una instalación 1.0 con datos a 1.1 (pantalla «Actualización pendiente» o `bin/migrate.php`) | 🟡 | Ambos caminos hechos a mano con datos de prueba y sin pérdidas (ver PRUEBAS.md); sin prueba automática |

## Instalación, seguridad y datos

| Función | Estado | Notas |
|---|---|---|
| Instalador web (requisitos, conexión, migraciones, cuenta y espacio) | ✅ | Incluye error de credenciales sin perder lo escrito y negativa a reinstalar |
| Inicio y cierre de sesión, sesión protegida, CSRF | ✅ | |
| Bloqueo temporal por intentos fallidos | ✅ | |
| Contraseñas Argon2id | ✅ | |
| Cabeceras de seguridad (CSP, nosniff, frame) | ✅ | |
| Permisos en servidor (observación, colaboración, responsable, administración) | ✅ | |
| Aislamiento entre espacios de trabajo y proyectos privados | ✅ | Páginas, API, búsqueda, archivos |
| Validación de referencias manipuladas (estado, responsable, padre, fechas) | ✅ | |
| Subida de archivos segura (lista blanca, doble extensión, contenido real, fuera de la web) | ✅ | |
| Copia de seguridad SQL desde Administración | ✅ | |
| `bin/backup.php` (consola/cron) | 🟡 | Ejecutado a mano, sin prueba automática |
| Actualizador web de la base (con copia previa) | ✅ | Probado el caso «sin migraciones pendientes» |
| `bin/migrate.php` (con copia previa) | 🟡 | Ejecutado a mano aplicando la migración 002 correctamente; sin prueba automática |
| Exportar espacio (JSON) y tareas (CSV con fórmulas neutralizadas) | ✅ | |
| Importación desde Borealis v0.2 (pantalla Datos y `bin/import-v022.php`) | ✅ | Probada con datos de prueba y con el ZIP real de la v0.2.2 (12 tareas, claves BOR-101… conservadas). El historial de actividad de la v0.2 no se importa |
| Registro abierto (`allow_registration`) | 🟡 | Desactivado por defecto |

## Personas, equipos y roles

| Función | Estado | Notas |
|---|---|---|
| Alta de personas por la administración | ✅ | Correo repetido rechazado |
| Cambiar rol, suspender, restablecer contraseña | 🟡 | Probado que un integrante no puede ascenderse |
| Invitaciones por enlace y aceptación | 🟡 | No se envían correos: Borealis da el mensaje con el enlace para copiar y compartir |
| Roles personalizados con matriz de permisos | 🟡 | |
| Prioridades configurables | 🟡 | |
| Equipos: crear, renombrar, integrantes, coordinación, archivar/restaurar, asociar proyectos | ✅ | |
| Perfil de persona con su carga | 🟡 | |
| Varios espacios de trabajo y cambio entre ellos | ✅ | |
| Perfil propio y cambio de contraseña | 🟡 | |

## Proyectos y tareas

| Función | Estado | Notas |
|---|---|---|
| Crear proyecto solo con nombre | ✅ | |
| Renombrar y verlo en todas partes sin recargar | ✅ | Navegador y servidor |
| Archivar (solo lectura) y restaurar | ✅ | |
| Integrantes y roles de proyecto | ✅ | Observación probada a fondo |
| Columnas/estados propios, límite WIP | 🟡 | Mover columnas por menú sin prueba |
| Etiquetas | 🟡 | |
| Módulos por proyecto con dependencias; desactivar no borra datos | ✅ | |
| Tarea solo con título; editar campos; finalizar/reabrir | ✅ | |
| Subtareas, comentarios con @menciones y notificación | ✅ | Autocompletado de menciones sin prueba |
| Duplicar, eliminar (recuperable), edición masiva | ✅ | Edición masiva probada con prioridad |
| Relaciones entre tareas, bloqueo con motivo | 🟡 | |
| Historial de la tarea | 🟡 | |
| Texto con formato seguro (sin XSS) | ✅ | |

## Vistas

| Función | Estado | Notas |
|---|---|---|
| Tablero: arrastrar y soltar, mover por menú, alta rápida | ✅ | |
| Panel lateral de tarea (abrir, editar, comentar, subtarea, Esc, enlace en la URL) | ✅ | |
| Lista: alta rápida, filtros, selección y barra masiva | ✅ | |
| Backlog con orden por arrastre | 🟡 | |
| Sprints: crear, iniciar, finalizar con traslado | ✅ | |
| Calendario mensual/semanal y reprogramar arrastrando | 🟡 | Solo carga de página |
| Cronograma, épicas, documentación, carpetas | 🟡 | Solo carga de página |
| Reportes y reportes del espacio | 🟡 | Carga de página; las exportaciones sí están probadas (✅) |
| Inicio, Mi trabajo, búsqueda, notificaciones | 🟡 | Carga verificada; la búsqueda no muestra contenido privado (✅) |
| Celebración discreta y resumen al completar un proyecto | ✅ | |
| Preferencia de animaciones (completas, reducidas, desactivadas) | ✅ | |
| Diseño adaptable: escritorio, portátil, tableta y móvil | ✅ | Sin desplazamiento horizontal; menú lateral en pantallas pequeñas |
| Teclado: foco visible, «/» para buscar, Alt+flechas en el tablero | 🟡 | Foco y «/» probados; Alt+flechas no |

## Pendiente

| Función | Estado |
|---|---|
| Envío de correos (invitaciones, recuperar contraseña, avisos) | ⏳ |
| Recuperar contraseña sin ayuda de la administración | ⏳ |
| Autenticación en dos pasos | ⏳ |
| Colaboración en tiempo real (ver cambios de otros sin refrescar) | ⏳ |
| Tareas recurrentes | ⏳ |
| Cronómetro para registrar horas en vivo | ⏳ |
| Tokens de API para integraciones externas | ⏳ |
| Modo oscuro | ⏳ |

## Decisiones tomadas

- **MySQL/MariaDB únicamente** (no SQLite) para que la instalación sea una sola en hospedajes convencionales.
- **Borrado recuperable** de tareas, comentarios y archivos: dejan de verse, pero siguen en la base.
- **Siglas del proyecto:** cambiar el nombre no cambia `BOR-101`. Desde 1.1 las siglas se pueden cambiar a mano; las anteriores quedan reservadas y sus enlaces redirigen a las nuevas.
- **Prioridades por espacio de trabajo**, configurables en Administración.
- **En pantallas táctiles** las tarjetas se mueven con su menú (el arrastre táctil competía con el desplazamiento).
- **Importación v0.2:** las personas importadas no reciben contraseña; la administración se la asigna. Los ítems de checklist pasan a subtareas, y el *tipo* de tarea pasa a una etiqueta.
- **Imágenes:** se usa una ilustración propia del bosque. La v0.2.2 trae fotos (`assets/forest-*.jpg`); no se incorporaron porque no consta su licencia de uso.
- **Cuentas fantasma (1.1):** «Entrar como» existe solo para cuentas fantasma y solo para la administración de la instalación. Entrar como una persona real permitiría leer sus notificaciones y actuar en su nombre, así que no se ofrece. Al borrar fantasmas, su contenido se conserva a nombre de «Usuario eliminado» y sus horas se ocultan (borrado recuperable) salvo que se pida conservarlas.
- **Correo (1.1):** Borealis sigue sin enviar correos. Al crear una cuenta o una invitación muestra el mensaje listo para copiar. El envío por SMTP queda pendiente de que se decida usarlo y se tengan los datos del servidor de correo.
- **PDF (1.1):** el informe es una página preparada para imprimir; el PDF se obtiene con «Guardar como PDF» del navegador, sin instalar librerías en el servidor.
