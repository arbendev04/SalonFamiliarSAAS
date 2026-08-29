# 04 — Modelo de Dominio

## Objetivo

Catalogar de forma completa y canónica las ~52 entidades del sistema, organizadas por bloque funcional, con su propósito y sus relaciones clave. Documentar en un único lugar los tres patrones de diseño que se repiten a lo largo de todo el dominio (para que el resto de los archivos `.ai/` los referencien en vez de redefinirlos), las reglas de integridad transversales, el ciclo de vida (máquina de estados) de las entidades con estado, y los errores de modelado que deben evitarse.

Este documento es la referencia semántica del dominio. La traducción de estas entidades a tablas físicas de PostgreSQL (columnas, tipos, aislamiento de tenant, mutabilidad) vive en [05-DATABASE.md](./05-DATABASE.md); ambos documentos deben mantenerse consistentes en nombres y relaciones.

## Alcance

Cubre: catálogo completo de entidades por bloque, patrones canónicos reutilizados, reglas de integridad transversales, máquinas de estado de las entidades con ciclo de vida relevante, casos especiales de modelado y errores de modelado a evitar.

No cubre: columnas físicas, tipos de dato SQL, índices, constraints de base de datos (ver [05-DATABASE.md](./05-DATABASE.md)), ni reglas de negocio específicas de un módulo (ver el archivo `NN-*.md` correspondiente).

## Conceptos

Antes del catálogo de entidades, se documentan aquí — una única vez — los tres patrones canónicos que se repiten en múltiples entidades del dominio. Todo archivo `.ai/` que mencione estos patrones debe enlazar a esta sección en vez de redefinirlos.

### Patrón 1: "Effective-dated lookup" (búsqueda de la versión vigente en una fecha)

**Definición**: cuando una entidad puede tener múltiples versiones válidas a lo largo del tiempo, y el sistema necesita resolver "¿cuál versión aplica en la fecha X?", se modela con un rango de vigencia (`effective_from` / `effective_to`, o `start_date` / `end_date` según la entidad) en vez de un único valor mutable. La búsqueda de la versión vigente consiste en encontrar la fila donde `effective_from <= fecha_consultada AND (effective_to IS NULL OR effective_to >= fecha_consultada)`.

**Entidades que usan este patrón**:

- `employment_contracts` (contrato vigente de un empleado a una fecha — ver flujo (d) más abajo).
- `salary_history` (revisión salarial vigente dentro de un contrato).
- `employee_schedules` (plantilla de jornada asignada a un empleado en un rango de fechas).
- `labor_rule_versions` (versión de una regla laboral vigente en un rango de fechas).
- `social_security_affiliations` (afiliación vigente de un empleado a una entidad de seguridad social).

**Regla general del patrón**: nunca debe existir ambigüedad — es decir, dos versiones vigentes simultáneamente para la misma entidad padre (mismo empleado, mismo contrato, misma regla) — sin que eso sea un error de datos explícito. Ver "Reglas de integridad transversales" más abajo y la Regla 4 del flujo (d) en [10-PAYROLL.md](./10-PAYROLL.md): si el motor de Payroll detecta cero o más de un contrato solapado sin cierre correcto, debe rechazar el cálculo con un error bloqueante, nunca adivinar.

### Patrón 2: "Evento + ajuste" (nunca UPDATE directo sobre históricos)

**Definición**: cuando un dato histórico ya fue producido (un evento de asistencia ocurrido, una liquidación de nómina cerrada), el sistema nunca lo modifica con un UPDATE directo. En su lugar, el dato original permanece intacto y una entidad de "ajuste" separada registra la corrección, preservando siempre el valor original, el valor corregido, el motivo y quién la solicitó/aprobó.

**Entidades que usan este patrón**:

- `attendance_events` (inmutable) + `attendance_adjustments` (mecanismo de corrección) — ver flujo (b) más abajo y [07-ATTENDANCE.md](./07-ATTENDANCE.md).
- `payroll_entries`/`payroll_entry_lines` cerrados (inmutables una vez `CLOSED`) + `payroll_adjustments` (corrección posterior) — ver flujo (c) más abajo y [10-PAYROLL.md](./10-PAYROLL.md).

**Regla general del patrón** (registrada como **ADR-003** y generalizada en **ADR-012**, ver [23-DECISIONS.md](./23-DECISIONS.md)): el registro original nunca se edita ni se borra; toda corrección es una fila nueva que referencia al original. Esto garantiza trazabilidad completa ante auditorías, disputas laborales o correcciones de errores de terceros (fabricante de dispositivo, error humano de digitación), sin perder nunca el rastro de qué pasó realmente.

### Patrón 3: "Estado derivado/recalculable" (no es fuente de verdad)

**Definición**: algunas entidades no almacenan hechos primarios, sino el resultado de un cálculo aplicado sobre hechos primarios de otras tablas. Estas entidades pueden regenerarse por completo en cualquier momento a partir de sus fuentes, y su valor almacenado es una optimización (caché), nunca la fuente de verdad.

**Entidad que usa este patrón**: `attendance_records` (**ADR-014**). Es la salida del motor de Time Calculation al cruzar `shift_assignments` planificados, `attendance_events` reales, `labor_rule_versions` vigentes y `novelty_records` aplicables. La fuente de verdad siempre son los eventos (`attendance_events`) y sus ajustes (`attendance_adjustments`), nunca `attendance_records` en sí misma.

**Consecuencia de diseño**: cualquier cambio en los eventos fuente (un ajuste aprobado, una nueva regla laboral vigente) debe poder disparar una regeneración completa de `attendance_records` para el rango afectado, sin pérdida de información, porque nunca se edita `attendance_records` directamente como si fuera un hecho primario.

## Entidades

Catálogo completo de las ~52 entidades organizado en los mismos 10 bloques del blueprint aprobado.

### Bloque 1: Tenancy / Acceso

| Entidad | Propósito | Relaciones clave |
|---|---|---|
| `companies` | Raíz de tenant; una empresa cliente del SaaS | — |
| `branches` | Sucursal/local de una empresa | `company_id → companies` |
| `users` | Identidad global de persona que inicia sesión (no pertenece a una sola empresa) | — |
| `user_company_memberships` | Vincula un usuario a una empresa con un rol; habilita "1 cuenta → N empresas" | `user_id → users`, `company_id → companies`, `role_id → roles` |
| `roles` | Rol de sistema (global, `company_id NULL`) o rol custom de una empresa | `company_id → companies` (nullable) |
| `permissions` | Catálogo global de permisos atómicos (no tenant-scoped) | — |
| `role_permissions` | Asigna permisos a un rol | `role_id → roles`, `permission_id → permissions` |
| `auth_tokens` | Sesiones activas / tokens de refresco | `user_id → users` |

Ver detalle completo de reglas y flujos en [06-AUTHORIZATION.md](./06-AUTHORIZATION.md) y [15-MULTI-TENANCY.md](./15-MULTI-TENANCY.md).

### Bloque 2: Empleados

| Entidad | Propósito | Relaciones clave |
|---|---|---|
| `employees` | Persona trabajadora dentro de una empresa | `company_id`, `branch_id → branches` (default/actual) |
| `positions` | Cargo/puesto (catálogo por empresa) | `company_id` |
| `employment_contracts` | Contrato histórico de un empleado (nunca se sobrescribe) | `employee_id → employees`, `position_id → positions` |
| `salary_history` | Revisiones de salario dentro de un contrato (aumentos, sin crear contrato nuevo) | `contract_id → employment_contracts` |
| `payroll_information` | Datos bancarios/fiscales para pago (sensible, separado de `employees`) | `employee_id → employees` (1:1) |
| `biometric_identities` | Vínculo entre empleado y su identificador biométrico en un proveedor | `employee_id → employees` |

### Bloque 3: Jornadas y Turnos

| Entidad | Propósito | Relaciones clave |
|---|---|---|
| `work_schedule_templates` | Plantilla de jornada (reglas generales) | `company_id` |
| `work_schedule_days` | Regla por día de semana dentro de una plantilla | `template_id → work_schedule_templates` |
| `employee_schedules` | Asignación de una plantilla a un empleado por rango de fechas (effective-dated) | `employee_id`, `template_id` |
| `shifts` | Instancia concreta de turno en una fecha | `company_id`, `branch_id`, `template_id` (nullable si es manual) |
| `shift_assignments` | Relación trabajador+turno+fecha | `shift_id → shifts`, `employee_id → employees` |
| `shift_breaks` | Ventana de descanso PLANIFICADA dentro de un turno | `shift_id → shifts` |

### Bloque 4: Asistencia

| Entidad | Propósito | Relaciones clave |
|---|---|---|
| `attendance_devices` | Dispositivo de marcación (biométrico u otro) | `company_id`, `branch_id` |
| `device_heartbeats` | Historial de estado/latido de un dispositivo | `device_id → attendance_devices` |
| `attendance_events` | Evento inmutable de marcación (CLOCK_IN/BREAK_START/BREAK_END/CLOCK_OUT) | `company_id`, `employee_id`, `device_id` (nullable) |
| `attendance_adjustments` | Mecanismo de corrección que preserva valor original/corregido | `original_event_id` (nullable), `employee_id` |
| `attendance_records` | Resumen derivado/recalculable por empleado+fecha (salida del motor de cálculo) | `employee_id`, fecha |
| `biometric_raw_events` | Staging de payloads crudos del dispositivo antes de identificar empleado | `device_id`, `matched_attendance_event_id` (nullable) |

`attendance_events` implementa el Patrón 2 (evento + ajuste, lado "evento"). `attendance_adjustments` implementa el Patrón 2 (lado "ajuste"). `attendance_records` implementa el Patrón 3 (estado derivado/recalculable).

### Bloque 5: Motor de Cálculo de Tiempo

| Entidad | Propósito | Relaciones clave |
|---|---|---|
| `labor_rules` | Definición de una regla laboral configurable (tipo + empresa o default global) | `company_id` (nullable = default de plataforma) |
| `labor_rule_versions` | Versión de una regla vigente en un rango de fechas | `labor_rule_id → labor_rules` |
| `time_calculation_runs` | Traza de auditoría de cada corrida del motor (para debug/soporte) | `employee_id`, `rule_version_id` |

`labor_rule_versions` implementa el Patrón 1 (effective-dated lookup). Ver [09-TIME-CALCULATION.md](./09-TIME-CALCULATION.md) para el algoritmo que las consume.

### Bloque 6: Horas extra y Novedades

| Entidad | Propósito | Relaciones clave |
|---|---|---|
| `overtime_records` | Hora extra con estado (detectada→solicitada→autorizada/rechazada→pagada) | `employee_id`, `shift_id` (nullable) |
| `leave_types` | Catálogo de tipos de permiso/ausencia (vacaciones, incapacidad, etc.) | `company_id` (nullable = catálogo del sistema) |
| `leave_records` | Solicitud de ausencia/permiso/vacación con flujo de aprobación | `employee_id`, `leave_type_id` |
| `absence_records` | Efecto real registrado de una ausencia sobre un día calendario (justificada o no) | `employee_id`, `leave_record_id` (nullable si no hay solicitud previa) |
| `novelty_types` | Catálogo unificador de tipos de novedad configurables | `company_id` (nullable) |
| `novelty_records` | Registro "paraguas" que Payroll/Time Calculation consultan; referencia a la entidad especializada de origen | `employee_id`, `novelty_type_id`, `source_type`+`source_id` (polimórfico hacia `leave_records`/`overtime_records`/`attendance_adjustments`) |
| `holidays` | Calendario de festivos (nacional/empresa) | `company_id` (nullable = calendario base) |

`novelty_records` resuelve la Contradicción #2 (ver más abajo): es una tabla de solo lectura de consumo generada automáticamente al aprobar la entidad especializada de origen; no duplica el flujo de aprobación de `leave_records`, `overtime_records` o `attendance_adjustments`, solo los expone de forma unificada para que Time Calculation y Payroll no tengan que conocer cada entidad especializada por separado.

### Bloque 7: Nómina

| Entidad | Propósito | Relaciones clave |
|---|---|---|
| `payroll_periods` | Periodo de nómina (semanal/quincenal/mensual) con estado | `company_id` |
| `payroll_entries` | Liquidación de un empleado en un periodo | `payroll_period_id`, `employee_id`, `contract_id` |
| `payroll_entry_lines` | Línea de detalle (devengo o deducción) | `payroll_entry_id`, `concept_id → payroll_concept_definitions` |
| `payroll_concept_definitions` | Catálogo de conceptos salariales (fijo/fórmula/por hora) | `company_id` (nullable = sistema) |
| `payroll_deduction_plans` | Acuerdo de deducción recurrente (préstamo, embargo) | `employee_id` |
| `payroll_adjustments` | Corrección posterior a cierre, nunca sobrescribe | `payroll_entry_id`, `applied_in_period_id` |

`payroll_entries`/`payroll_entry_lines` implementan el Patrón 2 (lado "evento" cerrado) una vez que `payroll_periods.status = CLOSED`. `payroll_adjustments` implementa el Patrón 2 (lado "ajuste"). Ver [10-PAYROLL.md](./10-PAYROLL.md).

### Bloque 8: Seguridad Social

| Entidad | Propósito | Relaciones clave |
|---|---|---|
| `social_security_entities` | Entidad externa (fondo/EPS/ARL-equivalente, agnóstico de país) | `company_id` (nullable) |
| `social_security_affiliations` | Afiliación histórica de un empleado a una entidad | `employee_id`, `entity_id` |
| `social_security_contributions` | Aporte calculado por periodo | `payroll_entry_id`, `entity_id` |
| `social_security_concept_definitions` | Catálogo de conceptos de aporte | `company_id` (nullable) |

`social_security_affiliations` implementa el Patrón 1 (effective-dated lookup). Ver [11-SOCIAL-SECURITY.md](./11-SOCIAL-SECURITY.md).

### Bloque 9: Empleados (transversal — Biometría)

La entidad `biometric_identities` ya está listada en el Bloque 2. Su detalle operativo completo (enrolamiento, staging, seguridad y privacidad biométrica) vive en [12-BIOMETRICS.md](./12-BIOMETRICS.md).

### Bloque 10: Reportes / PDF / Notificaciones / Auditoría / Configuración

| Entidad | Propósito | Relaciones clave |
|---|---|---|
| `generated_documents` | Registro de cada PDF generado (comprobante, reporte) | `company_id`, `reference_entity_type/id` |
| `notification_templates` | Plantilla de notificación por canal/evento | `company_id` (nullable) |
| `notification_logs` | Historial de envíos | `company_id`, `recipient` |
| `audit_logs` | Bitácora inmutable de acciones sensibles | `company_id`, `user_id`, `entity_type/id` |
| `company_settings` | Configuración por empresa (1:1) | `company_id` |
| `system_settings` | Configuración global de plataforma (solo SUPER_ADMIN) | — |

**Total: ~52 tablas.** Esta lista amplía deliberadamente la base dada por el brief original para cubrir versionado de salario, catálogo de novedades, staging biométrico, versionado de reglas laborales y trazabilidad de cálculo, sin agregar nada que no se derive directamente del blueprint aprobado.

## Reglas

### Reglas de integridad transversales

- **No solapamiento de contratos activos**: un empleado no puede tener dos `employment_contracts` vigentes simultáneamente (mismo `employee_id`, rangos de fecha que se intersectan) sin que uno de ellos tenga un `end_date` que cierre correctamente antes de que empiece el otro. Ver constraint recomendado en [05-DATABASE.md](./05-DATABASE.md).
- **No solapamiento de afiliaciones activas del mismo tipo**: análogamente, un empleado no debería tener dos `social_security_affiliations` vigentes simultáneamente a la misma `social_security_entities.type` sin un cierre correcto — salvo el caso especial de múltiples afiliaciones históricas simultáneas a *distintos tipos* de entidad (ver "Casos especiales de modelado" más abajo).
- **Toda entidad tenant-scoped debe resolver su `company_id`**, ya sea de forma directa o heredada (ver [05-DATABASE.md](./05-DATABASE.md) para la definición exacta de DIRECTO/HEREDADO/GLOBAL); ninguna entidad de negocio queda sin aislamiento de tenant salvo los catálogos globales explícitamente marcados como tales.
- **Los registros que implementan el Patrón 2 (evento + ajuste) nunca se editan ni se borran una vez creados en su estado terminal**; toda corrección pasa por su entidad de ajuste correspondiente.
- **`attendance_records` nunca se trata como fuente de verdad** en ninguna consulta de auditoría o disputa: la fuente de verdad siempre son `attendance_events` + `attendance_adjustments` aprobados.
- **Las entidades `HISTORIAL` (effective-dated) no deben tener huecos de cobertura ambiguos** en su rango de vigencia para el mismo padre: si el motor de cálculo no encuentra ninguna versión vigente en una fecha donde se esperaría una, es un error de datos que debe reportarse explícitamente (ver "Errores de modelado a evitar").

### Ciclo de vida (máquinas de estado)

**`employment_contracts`**:

```
VIGENTE ──(terminación / nuevo contrato)──> TERMINADO
```

Un contrato está vigente mientras `end_date IS NULL` o `end_date` es una fecha futura/actual. Se considera terminado cuando se le asigna un `end_date` pasado. Nunca se reabre un contrato terminado; un cambio de condiciones (ascenso, cambio de tipo de contrato) siempre implica cerrar el actual y crear uno nuevo — la revisión de salario dentro del mismo contrato usa `salary_history`, no un nuevo contrato.

**`payroll_periods`**:

```
OPEN ──(calcular)──> CALCULATED ──(cerrar directo)──────────────────────> CLOSED
                            └──(aprobar, opcional)──> APPROVED ──(cerrar)──┘
CLOSED ──(reabrir, auditado)──> REOPENED ──(cerrar de nuevo)──> CLOSED
```

- `OPEN`: periodo creado, aún no calculado.
- `CALCULATED`: el motor generó/regeneró `payroll_entries`+`payroll_entry_lines`; mientras el periodo esté en `OPEN` o `CALCULATED`, es libremente recalculable porque no hay historial que proteger todavía.
- `APPROVED`: paso intermedio **opcional** antes del cierre (**RESUELTO**, ADR-034 en [23-DECISIONS.md](./23-DECISIONS.md)): `payroll.close` puede ejecutarse directo desde `CALCULATED`, sin pasar por `APPROVED` (ver flujo (c), paso 3, en [10-PAYROLL.md](./10-PAYROLL.md)).
- `CLOSED`: `payroll_entries`/`payroll_entry_lines` pasan a ser de solo lectura a nivel de aplicación (Patrón 2); se registra `closed_by`/`closed_at`. **Corrección posterior por defecto** (ADR-026 en [23-DECISIONS.md](./23-DECISIONS.md)): se inyecta una línea compensatoria en el próximo periodo abierto del mismo empleado (`payroll_adjustments`), sin transicionar el periodo cerrado a `REOPENED`.
- `REOPENED`: solo alcanzable desde `CLOSED` mediante una acción privilegiada y auditada; permite corrección y vuelve a requerir cierre. El evento de cierre anterior queda preservado en `audit_logs`. Desde ADR-026, este camino **ya no es el default** — queda reservado para casos excepcionales que el "ajuste en periodo siguiente" no pueda resolver razonablemente.

**`overtime_records`**:

```
DETECTED ──(solicitud)──> REQUESTED ──(decisión)──> AUTHORIZED ──(liquidación)──> PAID
                                    └──(decisión)──> REJECTED
```

- `DETECTED`: el motor de Time Calculation identificó un exceso de tiempo sobre la tolerancia configurada; no implica pago automático.
- `REQUESTED`: un empleado o supervisor solicita formalmente que la hora extra detectada se reconozca.
- `AUTHORIZED` / `REJECTED`: decisión de un rol con permiso `overtime.authorize`.
- `PAID`: la hora extra autorizada fue incluida en una liquidación de `payroll_entries`.

**`leave_records`**:

```
(solicitud) ──> PENDING ──(decisión)──> APPROVED
                       └──(decisión)──> REJECTED
```

Una vez `APPROVED`, el registro pasa de ser libremente mutable a comportarse como Patrón 2 (AJUSTE): cualquier corrección posterior a la aprobación debe quedar auditada, no simplemente sobrescrita. La aprobación de `leave_records` dispara la generación automática de un `novelty_records` correspondiente (ver Bloque 6) y, si corresponde, de un `absence_records`.

**PENDING DECISION**: `leave_records` no tiene ninguna constraint (ni `EXCLUDE USING gist` en Postgres, ni validación equivalente en `StoreLeaveRecordRequest`) que impida dos filas `APPROVED` con rangos `date_from`/`date_to` solapados para el mismo empleado — a diferencia de `employment_contracts` (Fase 4, `EXCLUDE USING gist` + `StoreEmploymentContractRequest::withValidator()` de respaldo para sqlite) y de `ShiftAssignment::overlapsForEmployee()` (validación a nivel de servicio). Como cada `leave_records` `APPROVED` genera un `novelty_records` que espeja su estado (Contradicción #2), esto significa que dos `novelty_records` `approved` pueden cubrir legítimamente la misma fecha del mismo empleado hoy. Hasta que se resuelva, `NoveltyRecordLookup` (Fase 8) implementa el mismo criterio ya usado para la ambigüedad equivalente de `07-ATTENDANCE.md` (Flujo 2, punto 4): **gana la novedad `approved` más reciente por `created_at`**, nunca una fusión entre ambas.

### Casos especiales de modelado

- **Contrato partido a mitad de un periodo de nómina** (ejemplo: promoción o cambio de tipo de contrato el día 8 de una quincena): el esquema debe soportar prorrateo mediante múltiples `payroll_entry_lines` con distinto `contract_id` dentro de la misma `payroll_entry`. El esquema lo permite estructuralmente como decisión de diseño ya tomada; el algoritmo exacto de prorrateo depende de legislación no definida (**PENDING DECISION** — ver detalle completo en [10-PAYROLL.md](./10-PAYROLL.md), flujo (d)).
- **Empleado con múltiples afiliaciones de seguridad social históricas**: un empleado puede tener más de una fila en `social_security_affiliations` a lo largo del tiempo (cambio de fondo/entidad) y, en un punto del tiempo, más de una afiliación vigente simultánea si corresponden a *distintos tipos* de entidad (ejemplo: una afiliación de salud y una de pensión vigentes al mismo tiempo, cada una a una entidad distinta). Lo que nunca debe ocurrir es más de una afiliación vigente simultánea al mismo tipo de entidad sin cierre correcto — eso es el mismo tipo de ambigüedad que un contrato solapado.

### Errores de modelado a evitar

- **Mezclar planificado con real en la misma tabla**: `shifts`/`shift_assignments`/`shift_breaks` (planificado) nunca deben fusionarse con `attendance_events`/`attendance_records` (real). El motor de Time Calculation es precisamente el que cruza ambos mundos; si se mezclan en el modelo, se pierde la capacidad de auditar desviaciones entre lo planificado y lo ocurrido.
- **Mutar históricos directamente**: cualquier UPDATE directo sobre `attendance_events`, `audit_logs`, o `payroll_entries`/`payroll_entry_lines` ya `CLOSED` es un error de modelado y de implementación — viola el Patrón 2 y los ADR-003/ADR-012/ADR-014.
- **Duplicar el flujo de aprobación en `novelty_records`**: esta tabla es de solo lectura de consumo; nunca debe tener su propio flujo de aprobación independiente del de `leave_records`/`overtime_records`/`attendance_adjustments` que la originan (ver Contradicción #2).
- **Tratar `attendance_records` como si fuera un hecho primario editable**: violaría el Patrón 3; cualquier corrección debe entrar por el lado de los eventos/ajustes, nunca editando el registro derivado directamente.
- **Asumir que un rango effective-dated nunca tiene huecos ni solapamientos sin validarlo explícitamente**: el effective-dated lookup solo es confiable si la capa de negocio garantiza (o al menos detecta y reporta) la ausencia de ambigüedad en cada momento del tiempo.

## Flujos

Los flujos end-to-end completos (fichada biométrica, corrección de asistencia, cierre de nómina, determinación de contrato aplicable) se documentan en detalle en los archivos de módulo correspondientes: [07-ATTENDANCE.md](./07-ATTENDANCE.md), [09-TIME-CALCULATION.md](./09-TIME-CALCULATION.md), [10-PAYROLL.md](./10-PAYROLL.md) y [12-BIOMETRICS.md](./12-BIOMETRICS.md). Este documento solo define las entidades y patrones que esos flujos manipulan.

## Casos normales

- Alta de un empleado nuevo: se crea `employees`, luego un `employment_contracts` inicial con su `salary_history` base, y opcionalmente `payroll_information` y `biometric_identities`.
- Consulta de "contrato vigente hoy" de un empleado: effective-dated lookup sobre `employment_contracts` con la fecha actual.
- Aprobación de una ausencia: `leave_records` pasa a `APPROVED`, se genera `novelty_records` y, si aplica, `absence_records`, sin duplicar lógica de aprobación.

## Casos especiales

Ver "Casos especiales de modelado" en la sección Reglas.

## Errores

Ver "Errores de modelado a evitar" en la sección Reglas. A nivel de negocio, el caso más crítico es: si Payroll detecta cero o más de un `employment_contracts` vigente sin cierre correcto para un empleado en el rango de un periodo, el cálculo de ese empleado debe rechazarse con un error bloqueante explícito ("contrato ambiguo para el periodo"), nunca adivinar cuál usar.

## Seguridad

Este documento no define controles de seguridad directamente (ver [20-SECURITY.md](./20-SECURITY.md) y [15-MULTI-TENANCY.md](./15-MULTI-TENANCY.md)). Es relevante para seguridad que el Patrón 2 (evento + ajuste) garantice trazabilidad completa: ningún dato sensible de asistencia o nómina puede alterarse sin dejar rastro de quién, cuándo y por qué.

## Dependencias

- [05-DATABASE.md](./05-DATABASE.md): traduce cada entidad de este catálogo a su tabla física, con columnas, tipo de aislamiento de tenant y mutabilidad concretos.
- [03-ARCHITECTURE.md](./03-ARCHITECTURE.md): matriz de módulos que son dueños de cada grupo de entidades.
- [07-ATTENDANCE.md](./07-ATTENDANCE.md), [09-TIME-CALCULATION.md](./09-TIME-CALCULATION.md), [10-PAYROLL.md](./10-PAYROLL.md): consumen el Patrón 2 y el Patrón 3 documentados aquí.
- [23-DECISIONS.md](./23-DECISIONS.md): ADR-003, ADR-012, ADR-013, ADR-014, ADR-015 formalizan las decisiones de este documento.

## Criterios de aceptación

- Las ~52 entidades están catalogadas, organizadas en los mismos 10 bloques del blueprint, sin entidades faltantes ni renombradas respecto al blueprint aprobado.
- Los tres patrones canónicos (effective-dated lookup, evento + ajuste, estado derivado/recalculable) están documentados una única vez aquí, con la lista exacta de entidades que los usan, y el resto de archivos `.ai/` los referencian por enlace en vez de redefinirlos.
- Las máquinas de estado de `employment_contracts`, `payroll_periods`, `overtime_records` y `leave_records` están completas y usan los mismos nombres de estado que el blueprint (`OPEN`, `CALCULATED`, `APPROVED`, `CLOSED`, `REOPENED`; `DETECTED`, `REQUESTED`, `AUTHORIZED`, `REJECTED`, `PAID`).
- Todo `PENDING DECISION` heredado del blueprint aparece literalmente marcado como tal, sin resolución inventada.
