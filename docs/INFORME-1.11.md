# Borealis 1.11.0 — Informe del «Prompt maestro, Fase A»

**Fecha:** 17/11/2026 · **Base:** Borealis 1.10.0 · **Resultado:** Borealis 1.11.0

## Por qué 1.11.0 y no 1.5.0

El prompt pide entregar «Borealis 1.5.0», pero esa versión ya existe (alertas de capacidad y temas, octubre de 2026) y después vinieron 1.6 a 1.10. Ponerle 1.5.0 a una versión nueva confundiría las copias de seguridad, la pantalla de actualización y el historial de cambios. Por eso esta versión es la **1.11.0**, la siguiente a 1.10.0.

## Resumen

Se revisó cada sección del prompt contra el código real de 1.10.0 (no contra la descripción). **La gran mayoría ya estaba hecha y probada**, así que se conservó tal cual. Se implementaron solo cinco cosas que faltaban de verdad:

1. Estado de proyecto **«Planificado»**.
2. **Días de retraso** en las tareas vencidas, sin crear un estado nuevo.
3. **«Fuera de plazo»**: la fecha límite que tenía la tarea al finalizarla queda guardada.
4. Pestaña **«Hoy»** en Mi trabajo.
5. Aviso **«Ya no eres responsable de …»** al quitarle una tarea a alguien.

Pruebas reales, instalando desde el ZIP: integración **798/798** y navegador **249/249** (antes de los cambios, 1.10.0 daba 771/771 y 241/241). Actualizar una base 1.10 con datos funciona sin perder registros, y la reversión se probó.

---

## Sección por sección: qué se siguió y qué no

### 1. Contexto y principios
**Se siguió como criterio, sin cambios propios.** Borealis ya cumple los principios: PHP 8.2+ sin frameworks, MySQL/MariaDB, nada de Node, Docker ni procesos residentes en el servidor (el cron de HestiaCP es opcional). Lo nuevo sigue esas reglas: no hay dependencias nuevas y no cambia el diseño.

### 2. Misión de la versión
**Se siguió.** Solo hay cambios del gestor de proyectos. No se hizo nada de monitoreo individual, productividad ni análisis predictivo.

### 3. Gestión de proyectos
- **Ya existía (se conservó):** crear, renombrar, descripción, inicio y entrega, responsable, añadir y quitar integrantes y equipos, progreso, archivar y restaurar, ver sus tareas. Al editar en Ajustes del proyecto se guarda sin recargar, con un aviso discreto, y la persona sigue dentro del proyecto (no vuelve al inicio).
- **Faltaba (se hizo):** el estado **«Planificado»**. Había tres estados: Activo, En pausa y Completado.
- **No se siguió al pie de la letra: «Archivado» como estado.** En Borealis archivar ya es una acción aparte (`archived_at`) que conserva el estado que tenía el proyecto. Convertirlo en un estado más perdería ese dato y rompería el filtro de archivados, los permisos de solo lectura y la restauración. Se dejó como está, como pide el propio prompt («no reemplaces los estados sin estudiar cómo se almacenan»).

### 4. Gestión de tareas
- **Ya existía (se conservó):** crear rápido (solo el título es obligatorio, y «Más opciones» para el resto), responsable y varios responsables o un equipo, prioridad, fecha y hora límite, **tiempo estimado desde la creación** (desde 1.7.1), descripción, subtareas ligadas a la tarea principal (no cuentan doble en el progreso), comentarios, historial, finalizar, reabrir, sprint y proyecto.
- **Estados: no se cambiaron.** Cada proyecto ya trae «Por hacer, En progreso, En revisión y Finalizado», y sus columnas se pueden personalizar. «Bloqueada» es una marca aparte que convive con el estado (una tarea puede estar «En progreso» y bloqueada a la vez). Equivalen a los cinco estados del prompt; reemplazarlos rompería los tableros que ya usa el equipo.

### 5. Tareas que no se completan a tiempo
- **Caso A (próxima a vencer):** ya existía (fecha resaltada y recordatorios configurables de 1 semana a 30 minutos antes, 1.9).
- **Caso B (vencida):** ya existía la marca «Vencida», la pestaña «Vencidas» y un aviso único a quien es responsable. **Faltaba (se hizo):** «**N días de retraso**» junto a la fecha, en el tablero, Mi trabajo, Mis tareas y la ficha. La tarea **conserva su estado**: no se creó un estado «Atrasada».
- **Caso C (finalizada fuera de plazo):** la fecha real de finalización ya se guardaba. **Faltaba (se hizo):** guardar la fecha límite que tenía en ese momento (columna nueva `completed_due_date`), para que cambiarla después no borre el retraso. La ficha dice «fuera de plazo (fecha límite original: …)» y «Finalizadas» muestra «Fuera de plazo · N días» o «A tiempo». Al reabrirla vuelve a quedar pendiente.
- **Restricciones respetadas:** los retrasos no se avisan a la administración (hay una prueba que lo comprueba) y no hay puntuaciones por persona.

### 6. Sprints
**Ya existía; no se tocó.** Los sprints son opcionales (es un módulo por proyecto). Ya se puede crear un sprint en dos etapas (primero lo mínimo, después la planificación, desde 1.8), añadir y crear tareas, reordenar, ver el progreso y la capacidad, finalizar y consultar los anteriores. Al finalizar, las pendientes se trasladan a otro sprint planificado o vuelven al backlog, y **nunca se marcan como completadas**.
**No se siguió: «conservar la asociación histórica con el sprint finalizado».** Una tarea abierta que se quedara en un sprint cerrado desaparecería del backlog y del tablero. El sprint ya guarda su propio resumen (comprometidas y completadas) y el historial registra el traslado, así que el dato histórico no se pierde.

### 7. Calendario
**Ya existía; no se tocó.** Tiene vistas «Personal / Equipo / Ambos» y un calendario dentro de cada proyecto. Muestra fechas límite, inicio y fin de sprints, entregas de proyecto, eventos y reuniones. Google Calendar funciona en una dirección (Borealis → Google, 1.9), sin duplicados (id estable más una marca privada), con errores de autorización visibles, tokens cifrados que nunca llegan al navegador, sin tocar eventos ajenos y con reintentos que no bloquean Borealis. Google Tasks se sincroniza en las dos direcciones con «Mis tareas» personales (1.6), sin duplicar (id de Google). **No se añadió ninguna integración nueva.** Pendiente fuera del código: probarlo con un cliente OAuth real (`docs/CONFIGURACION-1.9.md`).

### 8. Mis tareas
- **Ya existía:** «Mi trabajo» (Abiertas, Pendientes, En proceso, Vencidas, Próximas y Finalizadas) y «Mis tareas» (asignadas y personales agrupadas por Vencidas, Para hoy, 7 días, etc.). Son filtros: ninguna tarea se duplica en la base de datos.
- **Faltaba (se hizo):** la pestaña **«Hoy»**, la primera, para responder «¿qué tengo que hacer hoy?». «Próximas» empieza ahora mañana, así una tarea no aparece en las dos.
- **No se siguió: reducir la tabla a cinco datos.** Mi trabajo ya muestra título, proyecto, estado, prioridad y fecha, y nada más (el resto está al abrir la tarea).

### 9. Equipos y permisos
**Ya existía; se verificó y no se cambió.** Hay roles de espacio (propietario, administración, miembro, invitado) y de proyecto (responsable, colaborador, observador), más equipos con coordinación. Es un modelo más completo que los tres roles del prompt, así que no se impuso. Los permisos se comprueban en PHP (`Policy::authorizeProject`) antes de cada operación, no en JavaScript. Hay pruebas de manipulación de peticiones desde 1.4.1, y una nueva en 1.11: quien no participa no puede cambiar el estado de un proyecto ajeno.

### 10. Notificaciones
- **Ya existía:** nueva asignación, cambio de fecha, por vencer, vencida (una vez), inicio y fin de sprint, entrega de proyecto. Todo es configurable por categoría, se agrupa y no se duplica. Hay correo SMTP (1.9), push web (1.8, probado contra un servicio simulado y **sin probar en un teléfono real**, como ya constaba) y horario de silencio.
- **Faltaba (se hizo):** «**Ya no eres responsable de …**» cuando a alguien le quitan una tarea (forma parte de «cambios relevantes en responsabilidades»). Usa la categoría «Nuevas asignaciones», llega un solo aviso por cambio y nunca de tareas finalizadas.

### 11. Experiencia visual
**Ya existía; no se cambió el diseño.** Los seis temas se aplican al fondo, la barra lateral y el símbolo, con el contraste medido (1.5.1). El tutorial navega de verdad entre páginas (corregido en 1.7.1, con pruebas). Los indicadores nuevos son pequeñas etiquetas con el mismo estilo que «Bloqueada». En el móvil se comprobó que no aparece desplazamiento horizontal.

### 12. Seguridad y estabilidad
**Se auditó lo que tocan los cambios; no hacía falta otra auditoría completa** (la de 1.4.1 está en el informe de seguridad, y desde entonces cada versión pasa sus pruebas de permisos). En 1.11: todas las consultas nuevas usan parámetros preparados, los textos nuevos pasan por `e()`, la API valida el estado contra una lista cerrada (se rechaza un valor inventado), el CSRF sigue igual y no se exponen errores internos. La migración solo añade y se puede repetir. No hay credenciales en el ZIP.

### 13. Arquitectura preparada para el futuro
**Se siguió con medida.** La única estructura nueva es `tasks.completed_due_date`. Sirve hoy para «fuera de plazo» y deja listo el análisis de cumplimiento y estimaciones (ya existen `estimate_hours`, `time_entries`, `completed_at` y el historial de actividad). No se añadieron tablas ni servicios «por si acaso».

### 14. Metodología
Se hizo en el orden pedido: auditoría (este documento y `docs/ESTADO.md`), plan (las cinco piezas de arriba), desarrollo, validación con pruebas automáticas reales y entrega.

### 15. Restricciones absolutas
**Todas respetadas:** no se reconstruyó nada, no se quitó ninguna función, no se perdieron datos (actualización probada con una base con datos), el diseño es el mismo, no hay frameworks nuevos ni IA, no hay vigilancia laboral y no se tocó producción. No se pidieron credenciales.

### 16. Resultado esperado
Los once puntos ya eran posibles en 1.10. Con 1.11, los puntos 9 (detectar trabajos atrasados) y 8 (revisar las tareas personales: «Hoy») quedan más directos.

---

## Entrega

| Pedido del prompt | Dónde |
|---|---|
| 1. Proyecto actualizado | `borealis-1.11.0.zip` |
| 2. Versión | 1.11.0 (ver arriba por qué no es 1.5.0) |
| 3. Migraciones SQL | `database/migrations/013_v111.sql` (solo añade y se puede repetir) |
| 4. Instalación en HestiaCP | README › Actualizaciones › «De 1.10.0 a 1.11.0» |
| 5. Registro de cambios | `CHANGELOG.md` |
| 6. Problemas detectados | Abajo |
| 7. Pruebas y resultados | `docs/PRUEBAS.md` y `docs/ESTADO.md` |
| 8. Pendientes | Abajo |
| 9. Reversión | README › «Volver a 1.10.0 (reversión)» |

### Cómo actualizar en HestiaCP
1. Administración › Datos › **Crear copia ahora**.
2. Sube el contenido de `borealis/` del ZIP sobre la instalación (Administrador de archivos de HestiaCP o FTP). **No borres ni cambies `config/config.php` ni lo que hay dentro de `storage/`**. El ZIP no trae `config.php` ni archivos dentro de `storage/` (solo `.htaccess` y carpetas vacías), así que no los sobrescribe.
3. Entra como administración: Borealis muestra «Actualización pendiente: 013_v111». Pulsa **Actualizar base de datos** (antes crea otra copia por su cuenta). También se puede usar `php bin/migrate.php`, si tienes consola.
4. No hay que cambiar ni el cron ni la configuración.

### Problemas detectados
- Ningún fallo nuevo: la base 1.10.0 pasó 771/771 y 241/241 antes de empezar.
- Con el modelo anterior, al cambiar la fecha de una tarea ya finalizada se perdía la evidencia de que se terminó tarde. Se corrigió con `completed_due_date`.
- «Próximas» incluía lo que vence hoy. Ahora empieza mañana, porque «Hoy» tiene su propia pestaña.

### Pruebas realizadas
- **Integración HTTP:** 798/798 (27 nuevas en el grupo «1.11 · …»: migración repetida, estado Planificado, rechazo de estados inválidos y de cambios hechos por alguien ajeno, retraso, fuera de plazo, cambio de fecha posterior, reabrir, mover en el tablero, Hoy/Próximas, avisos de responsabilidad, nada a la administración, exportación).
- **Navegador (Chromium):** 249/249 (8 nuevas en «Novedades 1.11», con capturas `39-*.png` en escritorio y móvil).
- **Actualización 1.10.0 → 1.11.0 con datos:** 84 tareas, 23 proyectos, comentarios, horas y avisos iguales antes y después; la migración aplicada dos veces; las 5 tareas finalizadas con fecha quedaron con su fecha original.
- **Reversión:** el SQL del README, ejecutado sobre esa base, deja 1.10.0 sin migraciones pendientes y sin perder filas. Volver a actualizar después funciona.

### No probado
El servidor real de HestiaCP con Nginx, MySQL 8, Safari y Firefox, y la reversión en tu servidor. Siguen pendientes de 1.9: Google con un cliente OAuth real, un buzón SMTP real y push en un teléfono real.

### Pendiente (para una fase siguiente, no hecho a propósito)
- Fase de monitoreo, informes avanzados y recomendaciones, como pide el prompt (los datos ya quedan preparados).
- Elegir «crear un sprint nuevo» directamente desde el diálogo de cierre (hoy primero se crea el sprint planificado y después se elige).
