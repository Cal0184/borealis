# Análisis — fórmulas, reglas y parámetros (Borealis 1.13.0)

Este documento explica cómo se calcula cada cifra de **Análisis**. La interfaz muestra lo mismo bajo «¿Cómo se calcula?», y el informe, el CSV y las pruebas usan las mismas funciones (`app/Services/Analysis/Metrics.php`). Por eso una cifra significa lo mismo en todas partes.

**Principios:** se describe y no se explica la causa. Una asociación entre dos datos no demuestra que uno cause el otro. Nunca se puntúa ni se ordena a personas. Ninguna cifra se calcula con menos registros de los configurados («Sin datos suficientes»). Nada de Análisis cambia datos, salvo «Aplicar escenario», que pide confirmación, queda auditado y se puede revertir.

## Quién ve qué

- **Administración** (permiso «Ver y administrar todos los proyectos»): todos los proyectos.
- **Quien gestiona un proyecto**: ese proyecto, con su carga y sus escenarios.
- **Integrante**: los proyectos donde participa. Las vistas por persona se agregan y solo se muestra el detalle por persona cuando hay más de una.
- Un proyecto o sprint que no se puede ver responde «no existe» (404), también por URL y API.
- Solo la administración del espacio cambia los parámetros (Administración › Ajustes › Parámetros de análisis). Cada cambio queda en la auditoría con el valor anterior y el nuevo.

## Indicadores históricos

Período por defecto: los últimos 6 meses (configurable). Cada indicador muestra su numerador y su denominador («15 de 20»), los registros incluidos y los excluidos.

### Cumplimiento de plazos

- **Fórmula:** finalizadas a tiempo ÷ finalizadas con fecha límite válida × 100
- **Qué cuenta:** Tareas (y subtareas con fecha propia) finalizadas en el período cuya fecha límite al finalizar está registrada. «A tiempo» = finalizada el mismo día de su fecha límite o antes.
- **Qué queda fuera:** Tareas pendientes (nunca se mezclan), finalizadas sin fecha límite y finalizadas antes de 1.11 (no guardaron la fecha que tenían al cerrarse).

### Finalizadas fuera de plazo

- **Fórmula:** finalizadas después de su fecha límite ÷ finalizadas con fecha límite válida × 100
- **Qué cuenta:** Las mismas tareas que el cumplimiento de plazos.
- **Qué queda fuera:** Las mismas que el cumplimiento de plazos.

### Desviación de estimaciones

- **Fórmula:** mediana de (horas registradas − horas estimadas) ÷ horas estimadas × 100
- **Qué cuenta:** Tareas principales finalizadas en el período con estimación mayor que 0 y al menos un registro de horas.
- **Qué queda fuera:** Sin estimación, sin horas registradas (registro incompleto) y estimaciones menores que el mínimo configurado en Monitoreo. Se usa la mediana para que un valor extremo no la distorsione.

### Frecuencia de reprogramaciones

- **Fórmula:** cambios de una fecha límite existente ÷ tareas con fecha límite
- **Qué cuenta:** Cambios registrados en el historial de fechas (desde 1.12) dentro del período, en tareas de los proyectos analizados.
- **Qué queda fuera:** Poner la primera fecha a una tarea que no tenía (no es reprogramar). Cambios anteriores a 1.12: no se registraban.

### Duración de bloqueos

- **Fórmula:** mediana de (desbloqueo − bloqueo) en días
- **Qué cuenta:** Bloqueos que terminaron en el período, según el historial de actividad (marcó como bloqueada → desbloqueó).
- **Qué queda fuera:** Bloqueos que siguen abiertos (se cuentan aparte, con su antigüedad) y bloqueos sin su inicio registrado.

### Cumplimiento de compromisos de sprint

- **Fórmula:** tareas comprometidas al iniciar que se completaron ÷ tareas comprometidas al iniciar × 100
- **Qué cuenta:** Sprints cerrados en el período. Desde 1.12 se usa el compromiso guardado al iniciar; antes, el recuento que se guardó al cerrar.
- **Qué queda fuera:** Trabajo añadido después de iniciar (se muestra aparte) y sprints sin dato de compromiso.

### Evolución del trabajo pendiente

- **Fórmula:** pendientes al final de cada semana = creadas hasta ese día − finalizadas hasta ese día
- **Qué cuenta:** Tareas principales de los proyectos analizados.
- **Qué queda fuera:** Tareas eliminadas (no queda constancia de cuándo dejaron de estar pendientes).

### Carga planificada frente a capacidad

- **Fórmula:** horas pendientes estimadas de la semana − capacidad de la semana
- **Qué cuenta:** Horas restantes de tareas abiertas con estimación y fecha (o sprint), repartidas entre sus responsables y en los días laborables hasta su fecha límite, como en Monitoreo › Carga. Capacidad = horas semanales de cada persona + horas extra de esa semana.
- **Qué queda fuera:** Tareas sin estimación o sin fecha (se listan aparte, sin inventar horas) y personas sin horas semanales configuradas.

## Tendencias

Las series se agrupan por mes (o por semana en períodos de hasta 10 semanas). Se informa una tendencia solo si hay **al menos 3 períodos** con el mínimo de registros. El cambio es la pendiente de mínimos cuadrados proyectada sobre la serie. Por debajo del «cambio mínimo» (10 puntos) se dice «estable». Con menos datos aparece: «No hay datos suficientes para identificar una tendencia confiable.»

## Patrones

Patrones detectados (con el número de registros, la confianza y, cuando relacionan dos datos, la aclaración de que es una coincidencia observada que no demuestra la causa):
- Las tareas de una etiqueta suelen superar su estimación (mediana por encima del 25 %).
- Una etapa (épica) acumula más reprogramaciones que el resto.
- Los sprints de un proyecto suelen cerrar con trabajo comprometido pendiente (menos del 80 % completado).
- Los cambios de alcance (trabajo añadido después de iniciar) coinciden con menos cumplimiento.
- Lo comprometido suele superar la capacidad del sprint.

Nunca se atribuyen a personas.

## Estimaciones

`Estimates::suggest` busca tareas **del mismo proyecto**, con las mismas etiquetas (o sin etiquetas si la tarea no tiene), finalizadas, con estimación y horas registradas. Prefiere las de tamaño parecido (de la mitad al doble de la estimación). Aparta los valores extremos con las vallas de Tukey a 3 × el rango intercuartílico, solo con 5 valores o más y con un rango mínimo del 10 % de la mediana; los apartados se cuentan. Con menos tareas que el mínimo (5) no sugiere nada.

- **Sugerencia** = estimación × mediana de (registrado ÷ estimado), redondeada a 0,5 h.
- **Texto:** «Las tareas similares finalizaron con una desviación habitual de aproximadamente X h sobre su estimación inicial.»
- **Confianza:** Media desde el mínimo de registros, Alta desde 3 veces el mínimo.
- Es solo una sugerencia: nunca cambia la estimación. El botón «Usar» solo rellena el campo y la persona decide si guarda.

## Capacidad futura

Por semana (4 semanas por defecto): la carga planificada es lo mismo que Monitoreo › Carga. La capacidad son las horas semanales más las horas extra autorizadas para esa semana. Si la carga supera la capacidad:

> La carga planificada supera la capacidad disponible en N horas. Se recomienda revisar fechas, prioridades o distribución del trabajo.

Las alternativas que se proponen son mover fechas, trasladar tareas de menor prioridad (se indican cuáles cubren la diferencia), redistribuir entre quienes tienen margen, reducir el alcance o revisar estimaciones. **Nunca se propone trabajar más horas.**

## Riesgo (reglas, no probabilidad)

Cada sprint activo o planificado y cada proyecto activo suma puntos por factores explicados.

**Sprint:**

| Factor | Puntos |
|---|---|
| La fecha de fin ya pasó y quedan tareas pendientes | 3 |
| Horas pendientes > capacidad restante (≥ 130 % de la capacidad: 3) | 2 o 3 |
| Tareas bloqueadas (1: 1 punto; 2 o más: 2) | 1 o 2 |
| Tareas que dependen de otra bloqueada o vencida | 1 |
| Quedan pocos días (los de «próxima a vencer» de Monitoreo) y más de la mitad sigue pendiente | 1 |
| Tareas reprogramadas 3 veces o más | 1 |
| *Estimación:* con la desviación habitual del proyecto, lo pendiente superaría la capacidad | 1 |

**Proyecto:**

| Factor | Puntos |
|---|---|
| La entrega ya pasó y quedan tareas abiertas | 3 |
| Horas pendientes > horas de sus responsables hasta la entrega (≥ 130 %: 3) | 2 o 3 |
| Tareas vencidas (una cuarta parte o más de 4 o más abiertas: 2) | 1 o 2 |
| Tareas bloqueadas | 1 |
| Dependencias en riesgo | 1 |
| La mitad o más de lo finalizado en el período terminó fuera de plazo | 1 |

**Nivel:** desde 4 puntos es «Riesgo elevado», desde 2 «Riesgo moderado» y por debajo «Riesgo bajo». Es «Sin datos suficientes» cuando nadie responsable tiene horas configuradas, cuando la mitad o más de las tareas abiertas no tiene estimación o cuando el sprint no tiene fecha de fin. Cada nivel se explica en una frase con las cifras, por ejemplo: «Este sprint presenta riesgo elevado porque mantiene 22 h estimadas de trabajo pendiente y dispone de 12 h de capacidad planificada (10 h por encima) y contiene una tarea bloqueada.» Nunca se da un porcentaje de probabilidad.

## Recomendaciones

Cada recomendación muestra el problema, la evidencia (cifras), la acción sugerida, el impacto esperado, la confianza y un enlace al lugar donde se actúa. Nunca cambia datos por sí sola.

## Escenarios

En «Escenarios» se puede probar, para un sprint activo o planificado, trasladar tareas a otro sprint o al backlog, cambiar estimaciones, fechas, responsables o prioridades, cambiar la capacidad de una persona y suponer que se desbloquea una tarea. **La simulación no escribe nada en la base**: se calcula en memoria y se muestran «Estado actual» y «Escenario simulado» lado a lado.

**Aplicar** (solo quien gestiona el sprint):
1. Vista previa de cada cambio.
2. Se comprueban los permisos.
3. Se comprueba que los datos no cambiaron desde la simulación (huella; si cambiaron: «Vuelve a simular»).
4. Se pide una confirmación explícita.
5. Todo se aplica en una transacción con las mismas reglas que a mano (incluido el límite de capacidad del sprint).
6. Queda en la auditoría con el valor anterior y el nuevo de cada cambio.

El desbloqueo supuesto nunca se aplica. **Revertir** deshace el escenario en orden inverso si nada de lo que tocó cambió después; si algo cambió, lo dice y no toca nada.

## Calidad de los datos

En Resumen se listan, con ejemplos, los datos que limitan el análisis: tareas abiertas sin estimación (y cuántas están en un sprint activo), finalizadas con estimación pero sin horas, finalizadas sin fecha límite registrada, fechas inconsistentes (inicio posterior al vencimiento), finalizadas antes de su creación, posibles duplicados, horas registradas en días futuros, sprints sin fechas y sprints cerrados sin el compromiso inicial guardado (anteriores a 1.12).

## Parámetros (Administración › Ajustes › Parámetros de análisis)

| Parámetro | Por defecto | Rango |
|---|---|---|
| Período analizado por defecto | 6 meses | 1–36 |
| Registros mínimos para una conclusión | 5 | 3–50 |
| Cambio mínimo para hablar de tendencia | 10 puntos | 1–100 |
| Desviación habitual desde | 25 % sobre lo estimado | 5–500 |
| Reprogramada repetidamente desde | 3 cambios de fecha | 2–20 |
| Sobrecarga alta desde | 130 % de la capacidad | 101–500 |
| Riesgo moderado desde | 2 puntos | 1–20 |
| Riesgo elevado desde | 4 puntos | 2–30 (mayor que el moderado) |
| Semanas que mira Planificación | 4 | 1–12 |

También se usan dos umbrales de Monitoreo: «próxima a vencer» (días) y la estimación mínima para medir la desviación.

## Rendimiento

Los resultados se guardan en `analysis_cache` junto con una huella de los datos de los que salen: tareas, horas, sprints, capacidad, cambios de fecha, responsables, etiquetas, parámetros y el día. Si algo cambia, la huella no coincide y se recalcula, así que nunca se muestra un dato viejo. No hace falta cron. La tabla se puede vaciar en cualquier momento.
