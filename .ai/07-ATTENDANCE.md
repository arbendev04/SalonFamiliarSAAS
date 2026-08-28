# 07-ATTENDANCE.md — Asistencia

## Objetivo

Definir el módulo de **Attendance**: la captura, el almacenamiento inmutable y el mecanismo de corrección de los eventos de asistencia real de los empleados (marcaciones), y su ciclo de vida hasta quedar disponibles como entrada para el motor de cálculo de tiempo.

Este módulo es el guardián de un principio no negociable del sistema (ver [AGENTS.md](./AGENTS.md), regla 7, y ADR-003 en [23-DECISIONS.md](./23-DECISIONS.md)): **lo que realmente ocurrió nunca se borra ni se sobrescribe**. Toda corrección deja rastro junto al dato original.

## Alcance

**Este módulo cubre:**
- La creación de `attendance_events` a partir de cualquier fuente (biométrica, web, móvil, QR, manual, API, dispositivo).
- El mecanismo de ajuste (`attendance_adjustments`) sobre eventos ya registrados.
- Las reglas de inmutabilidad, deduplicación y tolerancia a desorden que protegen la integridad del histórico.

**Este módulo NO cubre:**
- La identificación del empleado a partir del payload crudo de un dispositivo biométrico, el enrolamiento, ni la seguridad/privacidad de los datos biométricos — ver [12-BIOMETRICS.md](./12-BIOMETRICS.md).
- El cálculo de tiempo ordinario, extra candidato o faltante a partir de los eventos — ver [09-TIME-CALCULATION.md](./09-TIME-CALCULATION.md).
- El pago de horas extra o su tarifa — ver [10-PAYROLL.md](./10-PAYROLL.md).
- El registro de auditoría en sí (mecanismo transversal) — ver [16-AUDIT.md](./16-AUDIT.md).

## Conceptos

- **Evento de asistencia (`attendance_event`)**: hecho puntual e inmutable de que un empleado marcó algo en un instante determinado. Es la unidad atómica de verdad de este módulo.
- **Fuente (`source`)**: origen que generó el evento. Valores: `BIOMETRIC`, `WEB`, `MOBILE`, `QR`, `MANUAL`, `API`, `DEVICE`. La fuente no cambia las reglas de inmutabilidad ni de deduplicación; solo documenta el origen para auditoría y soporte. La fichada manual vía `WEB` puede llegar con retraso cuando se creó sin conexión (ADR-037 en [23-DECISIONS.md](./23-DECISIONS.md), ver [19-FRONTEND.md](./19-FRONTEND.md)): esos eventos traen un identificador cliente único para deduplicar al sincronizar, con el mismo criterio de "no descartar en silencio" que ya aplica a `biometric_raw_events` en [12-BIOMETRICS.md](./12-BIOMETRICS.md).
- **Tipo de evento (`event_type`)**: `CLOCK_IN`, `BREAK_START`, `BREAK_END`, `CLOCK_OUT`. Un evento representa exactamente uno de estos tipos.
- **Ajuste (`attendance_adjustment`)**: mecanismo formal de corrección que preserva el valor original, agrega el valor corregido, y exige un motivo. Es la única vía autorizada para corregir un evento; nunca se corrige editando la fila original.
- **Registro derivado (`attendance_record`)**: resumen calculado por empleado y fecha, producido por el motor de [09-TIME-CALCULATION.md](./09-TIME-CALCULATION.md). No es responsabilidad de este módulo calcularlo, pero este módulo es quien provee sus insumos (eventos netos de ajustes).

## Entidades

El detalle completo de columnas, aislamiento y mutabilidad de cada tabla vive en [04-DOMAIN-MODEL.md](./04-DOMAIN-MODEL.md) y [05-DATABASE.md](./05-DATABASE.md); aquí solo se resume lo relevante al ciclo de vida del evento.

| Entidad | Rol en el ciclo de vida | Mutabilidad |
|---|---|---|
| `attendance_devices` | Dispositivo de marcación asociado a una sucursal. Detalle completo (proveedor, heartbeat, autorización) en [12-BIOMETRICS.md](./12-BIOMETRICS.md). | MUTABLE |
| `attendance_events` | Núcleo del módulo: el evento inmutable de marcación. | **INMUTABLE** — solo INSERT, nunca UPDATE/DELETE |
| `attendance_adjustments` | Mecanismo de corrección: referencia al evento original (o `null` si es "agregar evento faltante"), valor original, valor corregido, motivo, quién solicitó, quién aprobó, estado. | INMUTABLE una vez `APROBADO` (una re-corrección agrega una fila nueva, nunca edita la existente) |
| `attendance_records` | Resumen derivado por empleado+fecha, salida del motor de [09-TIME-CALCULATION.md](./09-TIME-CALCULATION.md). Catalogado aquí porque pertenece al dominio de Asistencia, pero su algoritmo de cálculo se documenta en `09`. | RECALCULABLE (caché derivado, nunca fuente de verdad) |
| `biometric_raw_events` | Staging de payloads crudos de dispositivo, previo a identificar al empleado. Detalle completo en [12-BIOMETRICS.md](./12-BIOMETRICS.md); aquí solo importa que es el punto de entrada de la fuente `BIOMETRIC` antes de convertirse en `attendance_event`. | INMUTABLE (append-only) |

## Reglas

1. **Inmutabilidad estricta de `attendance_events`.** Nunca se ejecuta `UPDATE` ni `DELETE` sobre esta tabla, sin excepción, sin importar el rol que lo solicite. Es el registro de lo que realmente ocurrió (ADR-003).
2. **Toda corrección pasa por `attendance_adjustments`.** Este es el patrón canónico "evento + ajuste" documentado una única vez en [04-DOMAIN-MODEL.md](./04-DOMAIN-MODEL.md) y formalizado en ADR-003/ADR-012 de [23-DECISIONS.md](./23-DECISIONS.md); aquí se describe su aplicación concreta a asistencia.
3. **Deduplicación de eventos equivalentes.** Si ya existe un `attendance_event` del mismo empleado, mismo `event_type`, dentro de la misma ventana de tiempo considerada equivalente, el evento entrante se descarta y se devuelve el existente como match — **no se crea un evento nuevo** (el esquema físico de `attendance_events` no tiene columna `status`, así que no hay una fila "marcada duplicada": el intento entrante simplemente no persiste).
   - **RESUELTO** (Fase 6, `AttendanceEventRecorder`): la ventana de equivalencia es de **1 minuto**, confirmada por el propietario del producto. No depende del proveedor biométrico porque la Fase 6 solo cubre fuentes `WEB`/`MANUAL`; se revisará cuando la Fase 12 (Biometría) conecte un proveedor real.
4. **Tolerancia a eventos fuera de secuencia.** Un evento que llega en un orden lógicamente incorrecto (por ejemplo, `BREAK_END` antes que `BREAK_START`) **no se rechaza**. Se acepta y se marca una anomalía de baja severidad que es consumida por el motor de [09-TIME-CALCULATION.md](./09-TIME-CALCULATION.md) al momento de calcular.
   - **PENDING DECISION**: el mecanismo exacto de inferencia del tipo de evento cuando el dispositivo no lo envía explícito depende del proveedor biométrico elegido; el detalle completo de esta inferencia vive en [12-BIOMETRICS.md](./12-BIOMETRICS.md).
5. **El motivo del ajuste es obligatorio.** Un `attendance_adjustment` sin `reason` es inválido y se rechaza en la capa de validación; no existe ajuste sin justificación escrita.
6. **El evento original nunca se pierde**, incluso cuando un ajuste lo invalida o lo reemplaza a efectos de cálculo (ver tipos de ajuste en Flujos).

## Flujos

### Flujo 1 — Registro de una fichada (normal, cualquier fuente)

Aplica a marcación manual (WEB, MOBILE, QR, MANUAL, API) y es el punto de convergencia del flujo biométrico (a) del blueprint una vez que el empleado ya fue identificado.

1. Se recibe un intento de marcación con: empleado (identificado o autenticado según la fuente), tipo de evento, fecha/hora, fuente.
   - Si la fuente es `BIOMETRIC`, los pasos previos (recepción del payload del lector, normalización vía `BiometricProvider`, staging en `biometric_raw_events`, identificación del empleado contra `biometric_identities`) se documentan íntegramente en [12-BIOMETRICS.md](./12-BIOMETRICS.md). Si no hay match de empleado, el evento se marca `UNMATCHED` y dispara una notificación de revisión manual; **nunca se descarta en silencio**.
2. **Deduplicación**: se verifica si ya existe un `attendance_event` equivalente (mismo empleado/tipo/ventana de tiempo). Si existe, el evento entrante se marca `DUPLICATE`, se enlaza al existente, y el flujo termina aquí sin nueva fila en `attendance_events`.
3. **Tolerancia a desorden**: si el tipo de evento no respeta la secuencia esperada (`CLOCK_IN → BREAK_START → BREAK_END → CLOCK_OUT`), no se rechaza; se acepta y se marca una anomalía de baja severidad en los metadatos del evento.
4. Con match e identificación exitosos (deduplicación y desorden ya evaluados), se crea el `attendance_event` inmutable, con `source` correspondiente a su origen real.
5. El evento queda disponible como entrada para el motor de [09-TIME-CALCULATION.md](./09-TIME-CALCULATION.md), que lo cruza con el turno planificado (`shift_assignments`+`shift_breaks` de [08-SHIFTS.md](./08-SHIFTS.md)) y las reglas laborales vigentes para producir/regenerar `attendance_records`. Si el exceso de tiempo trabajado supera la tolerancia configurada, se crea/actualiza un `overtime_records` en estado `DETECTED` (nunca pagable automáticamente) — ver [09-TIME-CALCULATION.md](./09-TIME-CALCULATION.md) y [10-PAYROLL.md](./10-PAYROLL.md).

### Flujo 2 — Corrección manual de una asistencia (mecanismo de ajuste)

1. Un usuario con permiso `attendance.adjust` detecta un problema (marcación faltante, hora errónea, evento que no debería contar).
2. Se crea una fila en `attendance_adjustments`: referencia al evento original (`original_event_id`, o `null` si es "agregar evento faltante"), `original_value` (snapshot del estado antes del ajuste), `corrected_value`, `reason` (obligatorio, sin excepción), y `status` inicial (`PENDING` o auto-aprobado según política de rol).
   - **RESUELTO** (ADR-032 en [23-DECISIONS.md](./23-DECISIONS.md)): `SUPER_ADMIN`, `COMPANY_OWNER`, `ADMIN` y `HR_MANAGER` auto-aprueban su propio ajuste (queda `APPROVED` de inmediato, siempre auditado). `SUPERVISOR` solo puede *solicitar* (`status = PENDING`); alguien con `attendance.approve_adjustment` debe aprobar o rechazar esa solicitud después.
3. Si el ajuste requiere aprobación, un segundo usuario con permiso `attendance.approve_adjustment` lo aprueba o rechaza.
4. Al aprobar, el evento original en `attendance_events` **nunca se edita ni se borra**. Según el tipo de ajuste:
   - **`MODIFY`**: el motor de cálculo usa el `corrected_value` como valor autoritativo para ese evento; el evento original permanece intacto en la tabla, visible para historial y auditoría.
   - **`ADD`**: se inserta un nuevo `attendance_event` con `source=MANUAL`, referenciando el ajuste que le dio origen en sus metadatos (usado, por ejemplo, cuando falta un `CLOCK_OUT` completo).
   - **`INVALIDATE`**: el evento original se excluye del cálculo — la exclusión se representa mediante la propia fila de ajuste, nunca borrando ni modificando el evento — pero permanece físicamente en la tabla.
   - **PENDING DECISION**: `attendance_adjustments` no tiene una cadena "supersedes" — nada impide que dos ajustes distintos (`MODIFY`/`INVALIDATE`) terminen `APPROVED` apuntando al mismo `original_event_id` (por ejemplo, una segunda corrección sobre un evento ya corregido antes). El blueprint no define cuál de los dos prevalece a efectos de cálculo. Hasta que se resuelva, el motor de [09-TIME-CALCULATION.md](./09-TIME-CALCULATION.md) implementa el criterio más simple y explícito posible: **gana el ajuste `APPROVED` más reciente por `created_at`**, nunca una fusión campo a campo entre ambos.
5. La aprobación (o el auto-aprobado) registra automáticamente una entrada en `audit_logs` con usuario, valor anterior, valor nuevo y motivo — ver [16-AUDIT.md](./16-AUDIT.md). Si el registro de auditoría falla, la transacción de negocio se aborta (ADR-018); el ajuste no queda aplicado sin su rastro correspondiente.
6. Se recalculan los `attendance_records` afectados por el ajuste (invocando el motor de [09-TIME-CALCULATION.md](./09-TIME-CALCULATION.md)).
7. **Si el periodo de nómina correspondiente ya está `CLOSED`** (ver [10-PAYROLL.md](./10-PAYROLL.md)): el recálculo de asistencia/horas extra ocurre igualmente (son datos derivados y siempre recalculables), pero **no modifica automáticamente `payroll_entries` cerrados**. En su lugar, genera una señal explícita de "ajuste de nómina pendiente" que se resuelve exclusivamente a través del mecanismo de `payroll_adjustments` descrito en [10-PAYROLL.md](./10-PAYROLL.md). Este módulo nunca escribe directamente sobre una entrada de nómina cerrada.

## Casos normales

- Ciclo completo de un día típico: `CLOCK_IN → BREAK_START → BREAK_END → CLOCK_OUT`, en orden, desde una única fuente (por ejemplo `BIOMETRIC` o `WEB`), sin anomalías ni duplicados.
- Un ajuste simple y aprobado sin fricción: por ejemplo, corregir la hora de un `CLOCK_IN` mal registrado por el reloj del dispositivo (`MODIFY`).

## Casos especiales

- **Turno nocturno**: el evento `attendance_event` guarda siempre fecha y hora completas (`event_datetime`); este módulo no necesita decidir a qué "fecha laboral" pertenece un evento nocturno — esa atribución es responsabilidad del motor de cálculo y se documenta en [09-TIME-CALCULATION.md](./09-TIME-CALCULATION.md) junto con [08-SHIFTS.md](./08-SHIFTS.md).
- **Turno que cruza medianoche**: igual que el caso anterior — el `CLOCK_IN` y el `CLOCK_OUT` pueden tener fechas de calendario distintas; ambos eventos se registran con su `event_datetime` real, sin ajuste artificial en Attendance.
- **Olvido de marcar salida**: no existe `CLOCK_OUT` para el turno. Se resuelve mediante un ajuste tipo `ADD` (Flujo 2) que inserta el evento faltante con `source=MANUAL`, nunca alterando o simulando el evento en `attendance_events` original inexistente.
- **Doble marcación accidental**: el empleado marca dos veces el mismo tipo de evento en un lapso muy corto (por ejemplo, dos `CLOCK_IN` seguidos por un lector lento). El segundo evento se detecta como `DUPLICATE` en el Flujo 1, paso 2, y se enlaza al primero — no requiere ajuste manual.
- **Sincronización offline de un dispositivo**: un dispositivo que estuvo desconectado envía en batch eventos ya ocurridos, con `received_at` muy posterior al `event_datetime` real embebido en el payload. Estos eventos se procesan igual que cualquier otro (deduplicación + tolerancia a desorden), usando siempre el `event_datetime` real del payload, no el momento de recepción. El detalle del pipeline de staging y sincronización vive en [12-BIOMETRICS.md](./12-BIOMETRICS.md).

## Errores

- **Evento sin empleado identificado (`UNMATCHED`)**: nunca se descarta en silencio; se conserva en `biometric_raw_events` (o su equivalente de staging para otras fuentes) y dispara una notificación de revisión manual (ver [17-NOTIFICATIONS.md](./17-NOTIFICATIONS.md)).
- **Evento duplicado**: no es un error bloqueante; se marca `DUPLICATE` y se enlaza, según la regla de deduplicación.
- **Ajuste sin motivo**: rechazado explícitamente por la capa de validación. `reason` es un campo obligatorio en `attendance_adjustments`, nunca opcional ni con valor por defecto.

## Seguridad

- **`attendance.adjust`**: permiso requerido para crear un ajuste (solicitarlo). Según la matriz RBAC de [06-AUTHORIZATION.md](./06-AUTHORIZATION.md), lo poseen `SUPER_ADMIN`, `COMPANY_OWNER`, `ADMIN`, `HR_MANAGER`, y `SUPERVISOR` (este último solo para solicitar, ver ADR-032).
- **`attendance.approve_adjustment`**: permiso requerido para aprobar/rechazar un ajuste creado por `SUPERVISOR`. Los roles con auto-aprobación (`SUPER_ADMIN`/`COMPANY_OWNER`/`ADMIN`/`HR_MANAGER`) no necesitan este permiso para sus propios ajustes.
- **Auditoría obligatoria**: toda corrección (creación, aprobación, rechazo de un ajuste) queda registrada en `audit_logs` sin excepción; si el registro de auditoría falla, la transacción de negocio se aborta (ADR-018, [16-AUDIT.md](./16-AUDIT.md)).
- Ningún rol, incluyendo `SUPER_ADMIN`, puede ejecutar `UPDATE`/`DELETE` directo sobre `attendance_events` — esta restricción es de nivel de esquema/servicio, no solo de UI.

## Dependencias

- [08-SHIFTS.md](./08-SHIFTS.md): provee el turno planificado (`shift_assignments`+`shift_breaks`) contra el cual se comparan los eventos reales.
- [09-TIME-CALCULATION.md](./09-TIME-CALCULATION.md): consume los `attendance_events` (netos de ajustes) para producir `attendance_records`.
- [12-BIOMETRICS.md](./12-BIOMETRICS.md): detalle completo del Device Gateway, `BiometricProvider`, staging en `biometric_raw_events`, y seguridad/privacidad biométrica. *(Documento en redacción paralela por otro agente.)*
- [16-AUDIT.md](./16-AUDIT.md): mecanismo transversal de auditoría que registra cada ajuste. *(Documento en redacción paralela por otro agente.)*
- [06-AUTHORIZATION.md](./06-AUTHORIZATION.md): define los permisos `attendance.adjust`/`attendance.approve_adjustment` y la matriz RBAC completa.
- [04-DOMAIN-MODEL.md](./04-DOMAIN-MODEL.md) / [05-DATABASE.md](./05-DATABASE.md): esquema autoritativo de todas las tablas mencionadas.
- [10-PAYROLL.md](./10-PAYROLL.md): consumidor de la señal de "ajuste de nómina pendiente" cuando el periodo ya está `CLOSED`.

## Criterios de aceptación

- [ ] Ninguna operación `UPDATE`/`DELETE` puede ejecutarse contra `attendance_events`, verificado a nivel de esquema/servicio.
- [ ] Todo `attendance_adjustment` creado tiene `reason` no vacío; los intentos sin motivo son rechazados antes de persistir.
- [ ] Un evento equivalente a uno ya existente se marca `DUPLICATE` y se enlaza; no se crea una segunda fila en `attendance_events`.
- [ ] Un evento fuera de secuencia se acepta (no se rechaza) y queda marcado con una anomalía de baja severidad.
- [ ] Un evento `UNMATCHED` dispara notificación de revisión manual y nunca se descarta.
- [ ] Un ajuste aprobado sobre un periodo de nómina `CLOSED` genera la señal de "ajuste de nómina pendiente" y no modifica `payroll_entries` directamente.
- [ ] Toda creación/aprobación/rechazo de ajuste tiene exactamente una entrada correspondiente en `audit_logs`.
- [ ] Los tres tipos de ajuste (`MODIFY`, `ADD`, `INVALIDATE`) están implementados y cada uno preserva el evento original intacto.
