# Informe — Borealis 1.13.0 (Fase C: Análisis y decisiones)

## Versión

El prompt pedía «Borealis 1.7.0». Ese número ya existe (fotografía de perfil y visibilidad por proyecto, octubre de 2026) y la versión actual es la 1.12.0, así que esta entrega es la **1.13.0**. Repetir un número ya publicado confundiría al actualizador y a las migraciones (`schema_migrations`).

## Cómo se aplicó el prompt

Primero se comparó cada sección del prompt con el código de 1.12.0. Lo que ya existía se reutilizó y nada se reconstruyó desde cero. La migración solo añade, y no se usa nada que HestiaCP no tenga: solo PHP y MySQL/MariaDB, sin Node, sin servicios externos y sin cron nuevo.

| # | Sección del prompt | Qué se hizo | Por qué |
|---|---|---|---|
| 1 | Sección «Análisis» con 6 pestañas | **Seguido.** Resumen, Tendencias, Estimaciones, Planificación, Escenarios e Informes, en el menú justo después de Monitoreo | Monitoreo (1.12) responde «qué pasa ahora»; Análisis, «qué ha pasado y qué conviene decidir». Se mantienen separados para no mezclar las alertas con el historial. |
| 2 | Indicadores históricos con fórmula y denominador | **Seguido** | Cumplimiento de plazos, fuera de plazo, desviación de estimaciones, reprogramaciones, bloqueos, compromisos de sprint, trabajo pendiente y carga frente a capacidad. Cada uno muestra «15 de 20», los registros excluidos y «¿Cómo se calcula?». Fórmulas en `docs/ANALISIS-1.13.md`. |
| 3 | Fuentes de datos | **Reutilizadas** | La puntualidad usa la fecha guardada al finalizar (1.11). Las reprogramaciones salen del historial de fechas (1.12), los sprints del compromiso guardado al iniciar (1.12) y los bloqueos del historial de actividad. La carga es la misma de Monitoreo › Carga. No se creó ninguna tabla de datos duplicada. |
| 4 | Tendencias | **Seguido** | Solo con 3 períodos que tengan el mínimo de registros. Si no, dice: «No hay datos suficientes para identificar una tendencia confiable.» |
| 5 | Patrones sin causalidad ni culpables | **Seguido** | Se describen por etiqueta, etapa, proyecto o sprint, nunca por persona. Cuando relacionan dos datos, aclaran que es una coincidencia y no prueba la causa. |
| 6 | Motor de estimaciones | **Seguido** | Usa tareas comparables (mismo proyecto y mismas etiquetas, de tamaño parecido), aparta los valores extremos y necesita al menos 5. Es opcional («Consultar el historial» y la ficha). **Nunca cambia la estimación sola:** el botón «Usar» solo rellena el campo. |
| 7 | Capacidad futura (58 h frente a 40 h) | **Seguido, sobre la capacidad existente** | Usa las horas semanales (1.6) y las horas extra por semana (1.5). Muestra «supera la capacidad disponible en 18 horas…» con alternativas: fechas, prioridades, redistribuir, reducir alcance o revisar estimaciones. **Nunca propone trabajar más horas.** |
| 8 | Riesgo por reglas | **Seguido** | Bajo, moderado, elevado o «Sin datos suficientes», según puntos por factores explicados (tabla en `docs/ANALISIS-1.13.md`) y umbrales configurables. Cada nivel se explica en una frase con cifras. **No da probabilidades.** |
| 9 | Recomendaciones | **Seguido** | Cada una muestra problema, evidencia, acción, impacto esperado, confianza y enlace. Ninguna cambia datos. |
| 10 | Simulador de escenarios | **Seguido** | Por sprint se puede trasladar tareas, cambiar estimación, fecha, responsable, prioridad o capacidad y suponer un desbloqueo. Se calcula en memoria y **no escribe en la base**. |
| 11 | Aplicar un escenario | **Seguido** | Vista previa, permiso de gestión del sprint, comprobación de que los datos no cambiaron, confirmación explícita, una sola transacción con las mismas reglas que a mano y auditoría de cada valor antes y después. **Revertir** deshace todo si nadie tocó esos datos después; si alguien los cambió, lo explica y no toca nada. El desbloqueo supuesto nunca se aplica. |
| 12 | Análisis de sprints | **Seguido** | Compara los sprints cerrados: comprometido, añadido después, completado y trasladado. Los anteriores a 1.12 se señalan como tales, porque el compromiso original no se guardaba. |
| 13 | Informes ejecutivos | **Seguido** | Filtros por proyecto, sprint, equipo, «solo lo mío» y período. Vista imprimible (y PDF con «Guardar como PDF») y CSV. |
| 14 | Explicabilidad | **Seguido** | «¿Cómo se calcula?» en cada indicador, factores con puntos en cada riesgo y muestra usada en cada sugerencia. |
| 15 | Parámetros configurables | **Seguido** | Administración › Ajustes › Parámetros de análisis: 9 valores con límites. Solo los cambia la administración y cada cambio queda en la auditoría con el valor anterior y el nuevo. |
| 16 | Calidad de los datos | **Seguido** | Lista con ejemplos lo que limita el análisis: tareas sin estimación, sin horas, sin fecha registrada, fechas incoherentes, posibles duplicados, horas en días futuros y sprints sin fechas o sin compromiso. |
| 17 | Privacidad | **Seguido** | Sin rankings ni puntuaciones. Usa la misma visibilidad que Monitoreo, y un proyecto o sprint ajeno responde 404 (página, API, CSV, imprimible y simulador). Un integrante no ve el desglose de otras personas. |
| 18 | Rendimiento | **Seguido, sin cron** | Los resultados se guardan con una huella de los datos y se recalculan solo cuando algo cambia. Se probó con 1 500 tareas y 3 000 registros de horas: menos de 4 s la primera vez y menos de 1 s después. Se añadieron 3 índices. |
| 19 | Pruebas A–J | **Seguido** | Están en `tests/integration/v113.php` y `tests/unit/analysis.php`, y hay un recorrido en el navegador con capturas `41-*`. |
| 20 | Entregables | **Seguido** | ZIP, migración 015, changelog, documentación de fórmulas, pruebas, reversión y este informe. |

**Ignorado a propósito:**
- **Porcentajes de probabilidad o predicciones.** El prompt prohíbe predecir sin evidencia y los datos de un equipo pequeño no dan para eso. Solo se usan reglas explicadas, y la única proyección («con la desviación habitual del proyecto…») se marca como **estimación**.
- **Cambios automáticos.** Ni las estimaciones, ni las fechas, ni las recomendaciones cambian datos. Solo «Aplicar escenario» los cambia, con confirmación, auditoría y reversión.
- **Otra tabla de capacidad.** Ya existen las horas semanales, las horas extra por semana y la capacidad por sprint. Una cuarta fuente las contradiría.
- **IA externa o servicios de terceros.** El prompt no los permite y HestiaCP no los necesita.
- **Rankings o puntuación de productividad.** Prohibido por el prompt.
- **Cron para precalcular.** No hace falta: se calcula al abrir y se guarda hasta que cambian los datos. Así no hay otra tarea programada que mantener.
- **Volver a hacer Monitoreo, la carga, los compromisos de sprint o el historial de fechas.** Ya existían desde 1.11 y 1.12, y se reutilizan.

## Base de datos

La migración `015_v113` **solo añade**:
- 2 tablas: `analysis_cache` (resultados guardados; se puede vaciar cuando quieras) y `scenario_applications` (escenarios aplicados, con cada valor antes y después).
- 3 índices: `ix_task_completed` en `tasks`, `ix_tdc_changed` en `task_due_changes` e `ix_act_entity` en `activity`.

Se puede repetir sin errores (probado dos veces). No modifica ninguna fila existente.

## Instalación y actualización (HestiaCP)

1. Haz una copia de seguridad.
2. Sustituye los archivos **sin tocar `config/` ni `storage/`**.
3. Entra con la cuenta de administración y aplica la actualización de la base (Administración › Datos › Actualizar base de datos), o ejecuta `php bin/migrate.php`.
4. **No hay cron nuevo.** El de `bin/notify.php` sigue igual.
5. Opcional: Administración › Ajustes › Parámetros de análisis. Para que la capacidad y el riesgo tengan datos, cada persona necesita sus horas semanales en Miembros.

Para volver atrás, sigue README › Actualizaciones › «Volver a 1.12.0» (incluye el SQL).

## Pruebas

Instalando desde el ZIP: **integración 949/949** y **navegador 289/289**. Las pruebas A–J de la Fase C están en el grupo «1.13 · Análisis y decisiones» y en `tests/unit/analysis.php` (21 comprobaciones sin servidor). Detalle en `docs/PRUEBAS.md`.

- **Simulador:** se comprueba que la base de datos queda idéntica antes y después de simular.
- **Aplicar:** se rechaza sin permiso (403), sin confirmación (422) o si los datos cambiaron (409). Si se aplica, queda auditado y se puede revertir.
- **Rendimiento:** con 1 500 tareas y 3 000 registros de horas cada pestaña responde en menos de 4 s la primera vez y en menos de 1 s después.
- **Actualización:** se probó desde 1.12.0 con datos. La migración se aplicó dos veces sin perder tareas, proyectos, horas ni sprints, y las seis pestañas abren sin errores.
- **Capturas:** `docs/capturas/41-*`.

## Problemas encontrados y corregidos durante el trabajo

- La regla de valores extremos (1,5 × rango intercuartílico) descartaba tareas normales, incluidas las del propio ejemplo del prompt. Ahora usa 3 ×, solo con 5 valores o más y con un margen mínimo.
- Un integrante veía la tabla de carga por persona aunque solo hubiera una (él). Ahora solo se desglosa con dos personas o más.
- Después de aplicar un escenario la página se recargaba entera y el aviso de confirmación desaparecía. Ahora solo se actualiza la sección.
- Revertir un escenario que devuelve horas a un sprint ya lleno daba un error poco claro. Ahora dice «No se puede revertir: …» con el motivo del límite de capacidad.
- En el móvil, la tabla de capacidad de la pestaña Informes desbordaba 7 px. Corregido.
- Ajustes de diseño del Resumen: las fechas ya no ocupan todo el ancho, y la tarjeta de capacidad solo se marca en rojo si la sobrecarga es de esta semana. Los proyectos sin datos ya no llenan «Riesgos relevantes»: se indica cuántos son.

## Pendiente de probar

En el servidor real de HestiaCP, con Apache y MySQL 8; en Safari y Firefox; y con el historial real de un equipo (las pruebas usan datos generados).

## Recomendaciones para la siguiente fase

1. **Ausencias y días libres** para que la capacidad futura se ajuste sola.
2. **Resumen semanal por correo** con los riesgos elevados y las recomendaciones, usando el correo y el cron que ya existen.
3. **Plantillas de escenario** («retrasar una semana», «quitar lo de menor prioridad») para simular con un clic.
