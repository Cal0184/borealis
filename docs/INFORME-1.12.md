# Informe — Borealis 1.12.0 (Fase B: Monitoreo de cumplimiento y carga de trabajo)

## Versión

El prompt pedía «Borealis 1.6.0». Esa versión ya existe (sprints y capacidad, octubre de 2026) y la instalación actual es la 1.11.0, así que esta entrega es la **1.12.0**. Reutilizar un número ya publicado haría que el actualizador y las migraciones (`schema_migrations`) se confundieran.

## Cómo se aplicó el prompt

Se comparó cada sección con el código real de 1.11.0. Lo que ya existía se reutilizó. No se reconstruyó nada desde cero, ninguna migración borra ni reescribe datos y no se usa infraestructura que HestiaCP no tenga (ni Node, ni Docker, ni procesos permanentes: solo PHP y el cron que ya existía).

| # | Sección del prompt | Qué se hizo | Por qué |
|---|---|---|---|
| 1 | Objetivo y alcance | **Seguido.** Menú «Monitoreo» con Resumen, Cumplimiento, Carga de trabajo, Tiempos, Riesgos y alertas, más Informes | Era lo que faltaba. «Reportes» deja su lugar en el menú a «Monitoreo», pero sus rutas siguen igual y se llega a ellas desde una pestaña, así que nada deja de funcionar. |
| 2 | Restricciones | **Seguido** | Sin rankings, sin puntuar a nadie, sin vigilancia (solo datos que la persona ya registra), sin migraciones destructivas, sin tocar `config/`. |
| 3 | Panel resumen y KPIs clicables | **Seguido** | Ocho indicadores. Cada uno abre la lista filtrada de sus registros. |
| 4 | Salud del proyecto | **Seguido, como indicador calculado** | En curso / Atención / Crítico / Sin datos suficientes, con el motivo en texto. **No** cambia el campo «estado» del proyecto (Planificado/Activo/En pausa/Completado, de 1.11), que sigue siendo una decisión de las personas. |
| 5 | Clases de puntualidad | **Seguido** | Se reutilizó `lateness()` de 1.11 y se añadieron «próxima a vencer» y «sin fecha». Son indicadores, no estados nuevos, para no romper los tableros. |
| 6 | Motivos de retraso | **Seguido** | Siete motivos, comentario, acción correctiva, autor y fecha. Es opcional: obligar a escribirlo frenaría el trabajo y llevaría a textos de relleno. |
| 7 | Historial de fechas límite | **Seguido** | Cada cambio de la fecha límite se guarda: fecha anterior, fecha nueva, quién y cuándo. |
| 8 | Estimado frente a registrado | **Seguido** | 5 h estimadas y 12 h registradas dan +7 h (+140 %). Sin estimación no se calcula nada (no se divide por cero). La mediana evita que un valor extremo distorsione el resultado. Las tareas terminadas sin horas se marcan «registro incompleto». |
| 9 | Registro manual de horas | **Ya existía; se completó** | El registro y el borrado ya existían. Se añadió la **corrección**, con el antes y el después en el historial, y la protección contra **duplicados**: el mismo registro enviado dos veces en 60 s no se duplica. No hay temporizador automático: se pedía evitar la vigilancia invasiva. |
| 10 | Capacidad por persona | **Ya existía; se reutilizó** | Las horas semanales (1.6), las horas extra por semana (1.5) y la capacidad por sprint (1.6) ya existían. No se creó otra tabla de capacidad por período, porque habría dos fuentes de verdad en conflicto. |
| 11 | Carga planificada en el tiempo | **Seguido** | Las horas pendientes se reparten en días laborables entre el inicio y la fecha límite, y se dividen entre las personas responsables. Las tareas vencidas cuentan en la semana actual. Las que no tienen estimación ni fecha se listan aparte, sin inventar horas. Se puede ver por persona, por proyecto, por semana o por sprint. Los umbrales se configuran. |
| 12 | Caso de 15 h con 3 tareas | **Ya existía (1.5)** | La alerta «capacidad agotada con trabajo pendiente», con sus 5 decisiones, ya funcionaba. Monitoreo la enlaza desde «Riesgos y alertas». No se duplicó. |
| 13 | Motor de alertas | **Seguido** | Usa reglas fijas y explicadas, sin IA, con tres severidades. No se repite gracias a una clave única por regla y sujeto. Las alertas se resuelven solas cuando desaparece la causa y se pueden marcar como revisadas con una nota. Escalado: nivel 1, la persona responsable; nivel 2, quien gestiona el proyecto (a los 2 días, o de inmediato si es crítica); nivel 3, la administración (crítica sin revisar a los 3 días; se puede desactivar). Si una persona recibe más de 3 avisos en una ejecución, se agrupan en uno solo. |
| 14 | Cron en HestiaCP | **Seguido, con el cron existente** | Lo ejecuta el mismo `bin/notify.php` cada 5 minutos, así que no hay que crear otro cron. Tiene un bloqueo por espacio (`storage/monitor-N.lock`), procesa lotes con límite, escribe los errores en stderr y en `monitor_runs`, y Monitoreo muestra la «última revisión». Sin cron, las reglas corren al abrir Monitoreo, como mucho cada 10 minutos. |
| 15 | Bloqueos | **Ya existía; se completó** | «Bloqueada» y su motivo ya existían. Se añadieron **desde cuándo** y **quién** la marcó. El estado es la casilla misma. |
| 16 | Dependencias | **Ya existía («bloquea a»); se completó** | Se añadió la validación de dependencias circulares (también las indirectas), relaciones duplicadas, una tarea consigo misma y permisos sobre la otra tarea. «Dependencias en riesgo» avisa, pero **nunca mueve fechas**. |
| 17 | Sprints | **Ya existía; se completó** | Ahora se guarda el compromiso original al iniciar el sprint, se marca lo añadido después y, al cerrar, qué se completó, qué se movió y qué volvió al backlog. Los sprints iniciados antes de 1.12 muestran «sin dato de origen»: el dato no se inventa. |
| 18 | Informes y CSV | **Seguido** | Informe por proyecto, sprint y período, con CSV. Los Reportes anteriores (proyectos, tareas, horas e imprimible) siguen igual. |
| 19 | Permisos | **Seguido** | Cada persona ve solo sus proyectos y la carga de quienes comparten proyecto con ella. La administración ve todo. Las URL y la API manipuladas responden 404 o 403. No hay rankings. |
| 20 | Pruebas obligatorias | **Seguido** | Los 10 escenarios están en `tests/integration/v112.php`, y hay un recorrido en el navegador con capturas (`40-*`). |
| 21 | Entregables | **Seguido** | ZIP, migración 014, cron para HestiaCP, changelog, pruebas, reversión y este informe. |
| 22 | Recomendaciones | Ver al final | |

**Ignorado a propósito:**
- **Puntuación de productividad o ranking de personas.** El propio prompt lo prohíbe y no se hizo.
- **Temporizador automático o seguimiento de actividad.** Sería vigilancia invasiva. Las horas siguen siendo las que cada persona registra.
- **Cambiar fechas automáticamente por dependencias.** El prompt pide no hacerlo. Solo se avisa.
- **Nueva tabla de capacidad por período.** Habría duplicado y contradicho la capacidad semanal y la del sprint, que ya existen.

## Base de datos

La migración `014_v112` **solo añade**:
- 4 columnas en `tasks`: `started_at`, `blocked_at`, `blocked_by` y `remaining_hours`.
- 5 tablas: `task_due_changes`, `task_explanations`, `sprint_commitments`, `monitor_alerts` y `monitor_runs`.

Se puede repetir sin errores; está probado ejecutándola dos veces. Las filas que ya existían quedan con NULL, que significa «no registrado».

## Instalación y actualización (HestiaCP)

1. Haz una copia de seguridad.
2. Sustituye los archivos **sin tocar `config/` ni `storage/`**.
3. Abre Borealis con la cuenta de administración y aplica la actualización de la base. También puedes ejecutar `php bin/migrate.php`.
4. El cron no cambia. En Cron Jobs, cada 5 minutos:
   `cd /home/USUARIO/web/DOMINIO/public_html/borealis && php bin/notify.php >/dev/null 2>&1`
5. Opcional: Administración › Ajustes › Umbrales de monitoreo, y las horas semanales de cada persona en Miembros.

Para volver atrás, sigue README › Actualizaciones › «Volver a 1.11.0» (incluye el SQL).

## Pruebas

Instalando desde el ZIP: **integración 863/863** y **navegador 267/267** (los 10 escenarios obligatorios están en el grupo «1.12 · Monitoreo…»). Detalle en `docs/PRUEBAS.md`. Están las pruebas de integración HTTP y la prueba en el navegador (Chromium), con capturas en `docs/capturas/40-*`. También se probó la actualización desde 1.11.0 con datos.

## Problemas encontrados y corregidos durante el trabajo

- El nombre de una restricción de la nueva tabla de compromisos de sprint chocaba con otro que ya existía desde 1.3. La instalación desde cero fallaba; se renombró y ya funciona.
- La revisión de reglas desde el navegador no corría (solo desde el cron). Corregido.
- Cerrar un sprint daba error 500 por una columna ambigua en el SQL nuevo. Corregido.
- Dos revisiones en el mismo segundo no resolvían las alertas. Ahora se resuelven por identificador, no por hora.
- Una dependencia circular directa se rechazaba como «duplicada». Ahora dice que formaría un círculo.
- La vista de alertas desbordaba en el móvil. Corregido.

## Recomendaciones para la siguiente fase

1. **Pronóstico del sprint:** comparar el ritmo de los sprints cerrados con lo que queda, ahora que se guarda el compromiso original.
2. **Capacidad por ausencias:** marcar vacaciones o días libres para que la carga se ajuste sola, en lugar de usar las horas extra en negativo.
3. **Resumen semanal por correo** a quien gestiona cada proyecto, con las alertas abiertas. Se puede montar sobre el correo y el cron que ya existen.
4. **Registro de horas desde el móvil** con un acceso directo en la aplicación instalable.
