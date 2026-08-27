# 08-SHIFTS.md — Jornadas y Turnos

## Objetivo

Definir el módulo de **Shifts**: la representación de la jornada planificada de un empleado, desde la regla general reutilizable (plantilla) hasta la instancia concreta con fecha (turno) y su asignación a un trabajador. Este módulo produce el "planificado" contra el cual [09-TIME-CALCULATION.md](./09-TIME-CALCULATION.md) compara lo realmente ocurrido.

## Alcance

**Este módulo cubre:**
- Plantillas de jornada y sus reglas por día de la semana.
- Asignación de una plantilla a un empleado por rango de fechas.
- Generación y gestión de turnos concretos con fecha, y su asignación a empleados.
- Descansos planificados dentro de un turno.

**Este módulo NO cubre:**
- Los eventos reales de marcación ni su corrección — ver [07-ATTENDANCE.md](./07-ATTENDANCE.md).
- El cálculo de tiempo ordinario/extra/faltante a partir de comparar lo planificado con lo real — ver [09-TIME-CALCULATION.md](./09-TIME-CALCULATION.md).

## Conceptos

Tres conceptos que no deben confundirse entre sí:

- **WorkSchedule** (`work_schedule_templates` + `work_schedule_days`): la **regla general** de jornada de una empresa, expresada por día de la semana (por ejemplo, "de lunes a viernes 06:00–14:00, sábados 06:00–12:00"). No tiene fecha concreta; es reutilizable y se asigna a uno o más empleados.
- **Shift** (`shifts`): la **instancia concreta** de un turno en una fecha específica, con `start_datetime`/`end_datetime` reales. Puede originarse de una plantilla (generación masiva) o crearse manualmente (turno excepcional, cobertura puntual).
- **ShiftAssignment** (`shift_assignments`): la **relación** entre un turno concreto y el empleado que debe trabajarlo, en un estado (`ASSIGNED`, `CANCELLED`, etc.).
- **`shift_breaks`**: una ventana de descanso **planificada** dentro de un turno (por ejemplo, el almuerzo de 12:00 a 13:00). Es distinta del `BREAK_START`/`BREAK_END` real registrado en [07-ATTENDANCE.md](./07-ATTENDANCE.md); una es lo que se espera, la otra es lo que efectivamente ocurrió.

## Entidades

El esquema autoritativo completo (columnas, aislamiento, mutabilidad) vive en [04-DOMAIN-MODEL.md](./04-DOMAIN-MODEL.md) y [05-DATABASE.md](./05-DATABASE.md).

| Entidad | Propósito | Mutabilidad |
|---|---|---|
| `work_schedule_templates` | Plantilla de jornada, catálogo por empresa. | MUTABLE |
| `work_schedule_days` | Regla por día de semana (`day_of_week`, `start_time`, `end_time`, `crosses_midnight`) dentro de una plantilla. | MUTABLE |
| `employee_schedules` | Asignación de una plantilla a un empleado, por rango de fechas (`effective_from`/`effective_to`) — instancia del patrón "effective-dated lookup" documentado en [04-DOMAIN-MODEL.md](./04-DOMAIN-MODEL.md). | HISTORIAL |
| `shifts` | Instancia concreta de turno: `date`, `start_datetime`, `end_datetime`, `type`, `crosses_midnight`, `source` (plantilla o manual). | MUTABLE mientras es futuro; una vez pasado se trata como histórico de solo lectura salvo ajuste explícito |
| `shift_assignments` | Relación turno+empleado+estado. | MUTABLE (soft-delete al cancelar) |
| `shift_breaks` | Descanso planificado dentro de un turno (`planned_start`, `planned_end`, `paid`). | MUTABLE |

## Reglas

1. **Generación desde plantilla vs turnos manuales.** Un turno puede originarse automáticamente a partir de `work_schedule_days` de la plantilla vigente del empleado (`employee_schedules`) para un rango de fechas, o crearse manualmente sin plantilla asociada (campo `template_id` nulo en `shifts`, según [04-DOMAIN-MODEL.md](./04-DOMAIN-MODEL.md)). Ambos caminos producen filas de la misma tabla `shifts`; no hay una tabla paralela para turnos manuales.
2. **Soporte obligatorio de turnos nocturnos y turnos que cruzan medianoche.** `shifts` y `work_schedule_days` incluyen explícitamente la columna `crosses_midnight` para marcar este caso sin ambigüedad.
3. **Soporte obligatorio de turnos partidos.** Un turno partido (por ejemplo, 08:00–12:00 y 16:00–20:00 el mismo día) se modela como **un único turno** (`shifts`) cuyo `start_datetime`/`end_datetime` cubren el rango completo, con un `shift_breaks` que representa la ventana intermedia no trabajada. La diferencia entre un descanso corto (almuerzo) y un turno partido es de magnitud/duración del `shift_breaks`, no de modelado: ambos usan la misma estructura.
4. **Soporte obligatorio de distintos horarios por día.** `work_schedule_days` define una fila por `day_of_week`, permitiendo horarios distintos cada día dentro de la misma plantilla.
5. **Soporte obligatorio de cambios excepcionales de último momento.** Reasignar o modificar un turno ya generado es una operación de escritura normal sobre `shifts`/`shift_assignments`, sujeta a auditoría (ver Seguridad).
6. **No se permite el solapamiento de turnos para el mismo empleado en la misma ventana de tiempo.** Ver Errores.

## Flujos

1. **Crear plantilla de jornada**: se define `work_schedule_templates` (nombre, empresa) y sus `work_schedule_days` (uno o más por día de semana, con horario y `crosses_midnight` si aplica).
2. **Asignar plantilla a empleado**: se crea `employee_schedules` con `effective_from` (y opcionalmente `effective_to`), vinculando `employee_id` a `template_id`. Si el empleado ya tenía una plantilla vigente, la asignación anterior se cierra (patrón effective-dated, nunca se sobrescribe).
3. **Generar turnos desde plantilla para un rango de fechas**: por cada fecha del rango, se resuelve `work_schedule_days` correspondiente al día de semana según la plantilla vigente del empleado en esa fecha, y se crea (o regenera) la fila de `shifts` correspondiente, junto con su(s) `shift_breaks` planificado(s) y la `shift_assignment` al empleado.
4. **Asignar/reasignar empleado a un turno**: se crea o actualiza `shift_assignments` para vincular (o cambiar) el empleado responsable de un `shift` ya existente.
5. **Cambio excepcional de turno**: modificación puntual de un turno ya generado (horario, empleado asignado) fuera del ciclo normal de generación por plantilla, típicamente por cobertura de última hora.

## Casos normales

- Jornada estándar semanal aplicada de forma consistente (por ejemplo, panadería con horario 06:00–14:00 de lunes a viernes, generado automáticamente desde la plantilla para todo un mes).

## Casos especiales

- **Turno que cruza medianoche** (por ejemplo, 22:00→06:00): `shifts.date` almacena la fecha de referencia del turno (el día en que **inicia**); `start_datetime` y `end_datetime` llevan fecha y hora completas, de modo que `end_datetime` cae naturalmente en el día calendario siguiente. `crosses_midnight=true` marca explícitamente el caso para que reportes y el motor de [09-TIME-CALCULATION.md](./09-TIME-CALCULATION.md) lo traten sin ambigüedad, atribuyendo todo el turno a una única fecha laboral (la de `shifts.date`).
- **Turno partido** (dos bloques en el mismo día): un único `shift` con un `shift_breaks` que cubre la ventana intermedia no trabajada (ver Reglas, punto 3). Se distingue de un descanso normal solo por la duración de la ventana, no por una entidad distinta.
- **Doble turno el mismo día**: a diferencia del turno partido, aquí el empleado tiene **dos filas independientes en `shifts`** (con sus respectivas `shift_assignments`) en la misma fecha, típicamente por cobertura de otro turno o refuerzo puntual. La distinción con el turno partido es estructural: el turno partido es un solo compromiso con una pausa larga; el doble turno son dos compromisos separados. Ambos turnos deben ser no solapados en el tiempo (ver Errores).
- **Cambio de turno de último minuto**: se reasigna `shift_assignments` (la asignación anterior pasa a `CANCELLED`, se crea una nueva), quedando trazado en `audit_logs` ([16-AUDIT.md](./16-AUDIT.md)).

## Errores

- **Solapamiento de turnos para el mismo empleado en la misma ventana de tiempo**: se rechaza de forma explícita y bloqueante en el momento de crear o asignar el turno (al estilo del error de "contrato ambiguo" de [10-PAYROLL.md](./10-PAYROLL.md), flujo (d)) — nunca se permite silenciosamente que un empleado quede asignado a dos turnos que se superponen en el tiempo, incluyendo el caso de "doble turno" mal configurado con horas en común.

## Seguridad

- **`schedules/shifts.write`**: permiso requerido para crear/modificar plantillas, generar turnos, y asignar/reasignar empleados. Según la matriz RBAC de [06-AUTHORIZATION.md](./06-AUTHORIZATION.md): `SUPER_ADMIN`, `COMPANY_OWNER`, `ADMIN`, `HR_MANAGER` tienen acceso completo; `SUPERVISOR` tiene acceso limitado a su equipo; `PAYROLL_MANAGER`, `ACCOUNTANT` y `EMPLOYEE` no tienen este permiso.
- Todo cambio excepcional de turno queda auditado en `audit_logs` ([16-AUDIT.md](./16-AUDIT.md)), consistente con la regla transversal de que ninguna modificación operativa sensible ocurre sin rastro.

## Dependencias

- [04-DOMAIN-MODEL.md](./04-DOMAIN-MODEL.md) / [05-DATABASE.md](./05-DATABASE.md): esquema autoritativo de las seis entidades de este módulo.
- [07-ATTENDANCE.md](./07-ATTENDANCE.md): consumidor implícito — los turnos y descansos planificados aquí definidos son la referencia contra la que se comparan los eventos reales de asistencia.
- [09-TIME-CALCULATION.md](./09-TIME-CALCULATION.md): consumidor directo de `shift_assignments`+`shift_breaks` como "tiempo planificado" del algoritmo de cálculo.
- [06-AUTHORIZATION.md](./06-AUTHORIZATION.md): define el permiso `schedules/shifts.write` y su matriz completa.
- [16-AUDIT.md](./16-AUDIT.md): registro de cambios excepcionales de turno. *(Documento en redacción paralela por otro agente.)*

## Criterios de aceptación

- [ ] Generar turnos desde una plantilla para un rango de fechas produce una fila `shifts` por cada fecha, con `shift_breaks` y `shift_assignments` correctos, respetando el `work_schedule_days` vigente por día de semana.
- [ ] Un turno que cruza medianoche queda representado con `crosses_midnight=true` y con `start_datetime`/`end_datetime` en fechas de calendario distintas, atribuido a una única `shifts.date`.
- [ ] Un turno partido se representa como un único `shift` con un `shift_breaks` que cubre la ventana intermedia.
- [ ] Un doble turno el mismo día se representa como dos filas `shifts` independientes, no solapadas en el tiempo.
- [ ] Intentar asignar a un empleado dos turnos que se solapan en el tiempo es rechazado con un error explícito antes de persistir.
- [ ] Un cambio excepcional de turno genera exactamente una entrada correspondiente en `audit_logs`.
