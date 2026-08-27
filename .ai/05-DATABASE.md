# 05 — Base de Datos

## Objetivo

Definir el esquema físico de PostgreSQL para las ~52 tablas del sistema: convenciones de naming y tipos, estrategia de claves primarias, columnas estándar, el catálogo tabla por tabla completo (aislamiento de tenant, soft-delete, mutabilidad), la estrategia de aislamiento de tenant, la estrategia de soft-delete, los índices/constraints críticos, y la convención conceptual de migraciones.

Este documento traduce a esquema físico las entidades ya descritas semánticamente en [04-DOMAIN-MODEL.md](./04-DOMAIN-MODEL.md). Ambos documentos deben mantenerse consistentes en nombres de tabla y relaciones; ante cualquier cambio de modelo de datos, los dos archivos se actualizan juntos.

## Alcance

Cubre: convenciones transversales de esquema, catálogo completo tabla por tabla, estrategia de tenant isolation, estrategia de soft-delete, índices y constraints críticos, convención de migraciones a nivel conceptual, y tablas globales no tenant-scoped.

No cubre: el propósito de negocio de cada entidad (ver [04-DOMAIN-MODEL.md](./04-DOMAIN-MODEL.md)), ni reglas de negocio de un módulo específico (ver el archivo `NN-*.md` correspondiente).

## Conceptos

### Convenciones generales

- **Motor de base de datos**: PostgreSQL (**ADR-002**, ver [23-DECISIONS.md](./23-DECISIONS.md)). Decisión fijada por el blueprint, no sujeta a `PENDING DECISION`.
- **Estrategia de claves primarias**: UUID como PK en toda tabla tenant-scoped. **Decisión tomada** (no es un hueco de información pendiente): un ID secuencial autoincremental permitiría a un actor malicioso enumerar registros de otras empresas simplemente incrementando el identificador en una URL o payload (ejemplo: `/api/v1/employees/1043` → `/api/v1/employees/1044` cruzando de una empresa a otra). UUID hace ese vector de enumeración cross-tenant inviable en la práctica. Los catálogos globales (`permissions`, `system_settings`) pueden usar UUID también por consistencia, aunque su motivo de seguridad no aplique igual.
- **Columnas estándar** en toda tabla tenant-scoped (salvo excepciones documentadas por tabla en el catálogo):
  - `id` (UUID, PK)
  - `company_id` (FK a `companies`, salvo catálogos globales explícitamente marcados `GLOBAL`)
  - `created_at`, `updated_at` (timestamps)
  - `created_by` / `updated_by` (nullable, FK a `users`)
  - `deleted_at` (nullable, solo en tablas donde aplica soft-delete — ver más abajo)

### Leyenda de Aislamiento de tenant

- **DIRECTO**: la tabla tiene su propia columna `company_id` poblada directamente al insertar la fila.
- **HEREDADO**: el aislamiento se deriva por FK hacia una tabla padre que sí tiene `company_id` directo (ejemplo: `work_schedule_days` hereda de `work_schedule_templates`). Recomendación de este documento (**ADR-006**): denormalizar `company_id` también en las tablas `HEREDADO` como defensa en profundidad, en vez de depender exclusivamente del JOIN hacia el padre para filtrar por tenant.
- **GLOBAL**: la tabla no está delimitada por tenant; es un catálogo compartido por toda la plataforma (ejemplo: `permissions`, `users`, `system_settings`). Una tabla puede ser `DIRECTO/GLOBAL` cuando admite tanto un registro de plataforma (`company_id NULL`) como un registro propio de una empresa (`company_id` poblado) — ese matiz se anota explícitamente en el catálogo.

### Leyenda de Mutabilidad

- **INMUTABLE**: solo INSERT; nunca UPDATE ni DELETE una vez creada la fila. Ejemplos extremos: `attendance_events`, `audit_logs`.
- **HISTORIAL**: la tabla es mutable en el sentido de que se agregan filas nuevas a lo largo del tiempo, pero nunca se sobrescribe una versión ya vigente: se cierra un rango (`end_date`/`effective_to`) y se abre uno nuevo. Implementa el patrón "effective-dated lookup" (ver [04-DOMAIN-MODEL.md](./04-DOMAIN-MODEL.md)).
- **AJUSTE**: mutable con normalidad mientras el registro padre está en estado abierto; una vez que el padre se cierra (ejemplo: `payroll_periods.status = CLOSED`), la fila deja de aceptar UPDATE directo y solo se corrige insertando una fila en su tabla de ajuste correspondiente (patrón "evento + ajuste").
- **MUTABLE**: CRUD normal, sujeta a soft-delete donde aplique.

## Entidades

Este documento no repite el propósito de negocio de cada entidad — ver [04-DOMAIN-MODEL.md](./04-DOMAIN-MODEL.md) para eso. A continuación, el catálogo físico completo organizado en los mismos bloques.

## Reglas

### Catálogo tabla por tabla

#### Bloque 1: Tenancy / Acceso

| Tabla | Columnas clave | Aislamiento | Soft-delete | Mutabilidad | Nota |
|---|---|---|---|---|---|
| `companies` | id, legal_name, tax_id, status | — (es el tenant) | Sí | MUTABLE | Nunca hard-delete: hay históricos colgando de toda la aplicación |
| `branches` | id, company_id, name, timezone | DIRECTO | Sí | MUTABLE | |
| `users` | id, email, password_hash, mfa_enabled | GLOBAL | Sí | MUTABLE | Identidad no pertenece a una empresa (ADR-013) |
| `user_company_memberships` | id, user_id, company_id, role_id, status | DIRECTO | Sí (revocar ≠ borrar) | MUTABLE | Unique compuesto `(user_id, company_id)` |
| `roles` | id, company_id (nullable), name, is_system | DIRECTO/GLOBAL | Sí | MUTABLE | `company_id NULL` = rol de plataforma |
| `permissions` | id, code, module, description | GLOBAL | No | INMUTABLE (catálogo versionado por release) | |
| `role_permissions` | role_id, permission_id | HEREDADO | No | MUTABLE | PK compuesta |
| `auth_tokens` | id, user_id, token_hash, expires_at, revoked_at | GLOBAL (por user) | No (expira) | MUTABLE | |

#### Bloque 2: Empleados

| Tabla | Columnas clave | Aislamiento | Soft-delete | Mutabilidad | Nota |
|---|---|---|---|---|---|
| `employees` | id, company_id, branch_id, full_name, national_id, status | DIRECTO | Sí | MUTABLE | `status=INACTIVE` en vez de delete |
| `positions` | id, company_id, title, department | DIRECTO | Sí | MUTABLE | |
| `employment_contracts` | id, employee_id, position_id, contract_type, start_date, end_date, base_salary, status | HEREDADO+DIRECTO recomendado | No (se cierra con `end_date`) | HISTORIAL | Nunca UPDATE de contrato vigente; se cierra y se crea uno nuevo |
| `salary_history` | id, contract_id, effective_from, effective_to, base_salary, reason | HEREDADO | No | HISTORIAL | |
| `payroll_information` | id, employee_id, bank_account_enc, tax_regime | HEREDADO+DIRECTO | Sí | MUTABLE | Datos sensibles, cifrado en reposo |
| `biometric_identities` | id, employee_id, provider, external_ref, enrolled_at, status | HEREDADO+DIRECTO | Sí (revocar plantilla) | MUTABLE | Eliminar plantilla ≠ eliminar `attendance_events` históricos — ver [12-BIOMETRICS.md](./12-BIOMETRICS.md) |

#### Bloque 3: Jornadas y Turnos

| Tabla | Columnas clave | Aislamiento | Soft-delete | Mutabilidad |
|---|---|---|---|---|
| `work_schedule_templates` | id, company_id, name | DIRECTO | Sí | MUTABLE |
| `work_schedule_days` | id, template_id, day_of_week, start_time, end_time, crosses_midnight | HEREDADO | No | MUTABLE |
| `employee_schedules` | id, employee_id, template_id, effective_from, effective_to | HEREDADO+DIRECTO | No | HISTORIAL |
| `shifts` | id, company_id, branch_id, date, start_datetime, end_datetime, type, crosses_midnight, source | DIRECTO | Sí | MUTABLE mientras futuro; pasado se trata como histórico de solo lectura salvo ajuste |
| `shift_assignments` | id, shift_id, employee_id, status | HEREDADO+DIRECTO | Sí (cancelar) | MUTABLE |
| `shift_breaks` | id, shift_id, planned_start, planned_end, paid | HEREDADO | No | MUTABLE |

#### Bloque 4: Asistencia

| Tabla | Columnas clave | Aislamiento | Soft-delete | Mutabilidad | Nota |
|---|---|---|---|---|---|
| `attendance_devices` | id, company_id, branch_id, provider, external_device_id, status, last_heartbeat_at | DIRECTO | Sí | MUTABLE | |
| `device_heartbeats` | id, device_id, status, received_at | HEREDADO | No | INMUTABLE (log) | |
| **`attendance_events`** | id, company_id, employee_id, event_type, event_datetime, source, device_id, metadata, created_at | DIRECTO | **No — nunca se borra** | **INMUTABLE** | Núcleo del principio "nunca eliminar silenciosamente"; solo INSERT, sin excepción |
| `attendance_adjustments` | id, original_event_id (nullable), employee_id, type, original_value, corrected_value, reason, requested_by, approved_by, status | DIRECTO | No | INMUTABLE una vez `APROBADO` (una re-corrección agrega una fila nueva) | |
| `attendance_records` | id, employee_id, date, planned_json, worked_json, ordinary_minutes, overtime_candidate_minutes, missing_minutes, rule_version_id, calculated_at | DIRECTO | No | RECALCULABLE (caché derivado, se regenera completo desde eventos) | Ver Patrón 3 en [04-DOMAIN-MODEL.md](./04-DOMAIN-MODEL.md) |
| `biometric_raw_events` | id, device_id, external_event_id, payload, received_at, processing_status, matched_attendance_event_id | HEREDADO | No | INMUTABLE (staging append-only) | |

#### Bloque 5: Motor de cálculo / Reglas

| Tabla | Columnas clave | Aislamiento | Soft-delete | Mutabilidad |
|---|---|---|---|---|
| `labor_rules` | id, company_id (nullable), rule_type, name | DIRECTO/GLOBAL | Sí | MUTABLE (solo metadata; los parámetros viven versionados en `labor_rule_versions`) |
| `labor_rule_versions` | id, labor_rule_id, effective_from, effective_to, parameters (jsonb), created_by | HEREDADO | No | HISTORIAL — nunca editar una versión ya vigente/usada en cálculos pasados |
| `time_calculation_runs` | id, employee_id, date, rule_version_id, inputs_hash, output_ref | DIRECTO | No | INMUTABLE (traza de auditoría) |

#### Bloque 6: Overtime / Novedades

| Tabla | Columnas clave | Aislamiento | Soft-delete | Mutabilidad | Nota |
|---|---|---|---|---|---|
| `overtime_records` | id, employee_id, shift_id, detected_minutes, requested_minutes, authorized_minutes, status | DIRECTO | No | AJUSTE | Cambios de estado auditados; considerar tabla de transición si se requiere trazabilidad fina de cada cambio de estado |
| `leave_types` | id, company_id (nullable), code, name | DIRECTO/GLOBAL | Sí | MUTABLE | |
| `leave_records` | id, employee_id, leave_type_id, date_from, date_to, status, approved_by, document_ref | DIRECTO | Sí (cancelar) | MUTABLE hasta aprobar, luego AJUSTE | |
| `absence_records` | id, employee_id, date, leave_record_id (nullable), justified, source | DIRECTO | No | MUTABLE con historial de cambios vía `audit_logs` | |
| `novelty_types` | id, company_id (nullable), code, affects_time_calc, affects_payroll | DIRECTO/GLOBAL | Sí | MUTABLE | |
| `novelty_records` | id, employee_id, novelty_type_id, date_from, date_to, source_type, source_id, status | DIRECTO | No | MUTABLE/derivado — se genera al aprobar la entidad de origen | No tiene flujo de aprobación propio |
| `holidays` | id, company_id (nullable), date, name | DIRECTO/GLOBAL | Sí | MUTABLE | |

#### Bloque 7: Nómina

| Tabla | Columnas clave | Aislamiento | Soft-delete | Mutabilidad | Nota |
|---|---|---|---|---|---|
| `payroll_periods` | id, company_id, period_type, start_date, end_date, status, closed_by, closed_at | DIRECTO | No | HISTORIAL | `status`: `OPEN→CALCULATED→APPROVED→CLOSED→REOPENED` |
| **`payroll_entries`** | id, payroll_period_id, employee_id, contract_id, status, gross_total, deductions_total, net_total | HEREDADO+DIRECTO | No | **AJUSTE una vez que el periodo padre está `CLOSED`** | Libremente recalculable mientras el periodo esté `OPEN`/`CALCULATED` |
| **`payroll_entry_lines`** | id, payroll_entry_id, concept_id, quantity, rate, amount, type | HEREDADO | No | **AJUSTE (igual que la entrada padre)** | |
| `payroll_concept_definitions` | id, company_id (nullable), code, type, calculation_method | DIRECTO/GLOBAL | Sí | MUTABLE | |
| `payroll_deduction_plans` | id, employee_id, concept_id, total_amount, installments, remaining | DIRECTO | Sí | MUTABLE | |
| `payroll_adjustments` | id, payroll_entry_id, original_value, corrected_value, reason, created_by, applied_in_period_id | DIRECTO | No | INMUTABLE (append-only) | |

#### Bloque 8: Seguridad Social

| Tabla | Columnas clave | Aislamiento | Soft-delete | Mutabilidad |
|---|---|---|---|---|
| `social_security_entities` | id, company_id (nullable), type, name, code | DIRECTO/GLOBAL | Sí | MUTABLE |
| `social_security_affiliations` | id, employee_id, entity_id, affiliation_number, start_date, end_date | DIRECTO | No | HISTORIAL |
| `social_security_contributions` | id, payroll_entry_id, entity_id, base_amount, employee_amount, employer_amount | HEREDADO+DIRECTO | No | INMUTABLE por ser derivado de una liquidación (sigue las reglas de `payroll_entries`) |
| `social_security_concept_definitions` | id, company_id (nullable), code, name | DIRECTO/GLOBAL | Sí | MUTABLE |

#### Bloque 9: Reportes / PDF / Notificaciones / Auditoría / Configuración

| Tabla | Columnas clave | Aislamiento | Soft-delete | Mutabilidad | Nota |
|---|---|---|---|---|---|
| `generated_documents` | id, company_id, type, reference_entity_type/id, storage_ref, generated_by, generated_at, version | DIRECTO | No | INMUTABLE | Una corrección genera versión nueva, nunca sobrescribe el PDF anterior |
| `notification_templates` | id, company_id (nullable), channel, event_code, body | DIRECTO/GLOBAL | Sí | MUTABLE | |
| `notification_logs` | id, company_id, channel, event_code, status, sent_at | DIRECTO | No | INMUTABLE (log) | |
| **`audit_logs`** | id, company_id, user_id, action, entity_type, entity_id, old_value, new_value, reason, ip_address, created_at | DIRECTO | No | **INMUTABLE — nunca editable ni borrable** | Ninguna capa del sistema, ni siquiera `SUPER_ADMIN`, tiene un endpoint para editar o borrar filas de esta tabla |
| `company_settings` | id, company_id, timezone, default_currency, default_period_type | DIRECTO | No | MUTABLE | |
| `system_settings` | id, key, value | GLOBAL | No | MUTABLE (solo `SUPER_ADMIN`) | |

### Estrategia de tenant isolation

Registrada como **ADR-006** (ver [23-DECISIONS.md](./23-DECISIONS.md)): `company_id` se denormaliza en cada tabla tenant-scoped, incluidas las de aislamiento `HEREDADO`, como defensa en profundidad — en vez de exigir siempre un JOIN hacia la tabla padre para poder filtrar por tenant. Esta decisión se tomó explícitamente en vez de dos alternativas más comunes en sistemas SaaS:

- **Schema-per-tenant**: descartado por el costo operativo de migraciones y mantenimiento a escala (una migración de esquema debe aplicarse a N esquemas).
- **Database-per-tenant**: descartado por el mismo motivo, agravado, y porque dificulta reportes cross-branch dentro de una misma empresa.

La denormalización de `company_id` permite que toda consulta de negocio pueda filtrar directamente por `company_id` sin depender de la profundidad del JOIN, y habilita a futuro evaluar PostgreSQL Row-Level Security (RLS) como una capa adicional de aplicación del filtro a nivel de motor de base de datos (evaluación futura, no comprometida para la fase inicial). El detalle de las tres capas de validación de tenant (API, servicio, base de datos) vive en [15-MULTI-TENANCY.md](./15-MULTI-TENANCY.md).

### Estrategia de soft-delete

- **Cuándo sí**: entidades operativas de catálogo o configuración cuyo borrado físico rompería integridad referencial de históricos que las referencian (`employees`, `branches`, `positions`, `roles`, catálogos de tipo `leave_types`/`novelty_types`/`holidays`/`payroll_concept_definitions`/`social_security_*`, `notification_templates`, `attendance_devices`, `biometric_identities`, `work_schedule_templates`, `shifts`/`shift_assignments` mientras son de naturaleza operativa). El soft-delete cambia `status`/`deleted_at`, nunca borra la fila.
- **Cuándo no**: tablas que ya son `INMUTABLE`, `HISTORIAL` o `AJUSTE` por naturaleza no llevan `deleted_at` porque su ciclo de vida se gestiona con estado (`end_date`, `status`) o directamente no se editan nunca — agregar soft-delete a una tabla `INMUTABLE` sería contradictorio: si algo nunca se borra, tampoco necesita marcarse como borrado.
- **Por qué nunca hard-delete en históricos**: `attendance_events`, `audit_logs`, `payroll_entries`/`payroll_entry_lines` cerrados y cualquier tabla `INMUTABLE`/`AJUSTE` nunca se eliminan físicamente, ni siquiera mediante soft-delete, porque son la fuente de verdad legal y operativa ante disputas laborales, auditorías o requerimientos de organismos de control. Un hard-delete sobre estas tablas es un error de implementación tan grave como un UPDATE directo sobre ellas (ver **ADR-010**).
- El soft-delete de una entidad referenciada por históricos (ejemplo: `employees.status = INACTIVE`) nunca debe romper la resolución de reportes o comprobantes pasados: los reportes históricos siguen resolviendo el registro por su `id` sin importar su estado activo/inactivo actual (ver Contradicción #5 en [04-DOMAIN-MODEL.md](./04-DOMAIN-MODEL.md)).

### Índices y constraints críticos

- **Unique compuesto `(company_id, code)`** en todo catálogo tenant-scoped que tenga un código de negocio (ejemplo: `positions`, `leave_types`, `novelty_types`, `payroll_concept_definitions`, `social_security_concept_definitions`), para evitar duplicados de código dentro de la misma empresa sin bloquear que dos empresas distintas usen el mismo código.
- **Índice `(employee_id, event_datetime)`** en `attendance_events`: es la consulta más frecuente del sistema (reconstruir la jornada de un empleado en una fecha) y debe ser eficiente incluso con volumen alto de eventos históricos inmutables.
- **Índice `(company_id, status)`** en `payroll_periods`: soporta la consulta operativa habitual ("periodos abiertos de mi empresa") sin escanear todo el histórico de periodos cerrados.
- **Constraint contra `employment_contracts` solapados sin cierre correcto para el mismo empleado**: debe impedirse a nivel de base de datos que existan dos contratos vigentes simultáneos del mismo `employee_id`. Se recomienda modelar el rango de vigencia como `daterange` y aplicar `EXCLUDE USING gist` para que PostgreSQL rechace el solapamiento directamente en el motor, en vez de depender únicamente de una validación a nivel de servicio. Si el equipo de implementación determina que esta expresión no es viable con el resto del stack de migraciones elegido, la validación debe reforzarse obligatoriamente a nivel de servicio como mínimo aceptable.
- **Unique `(user_id, company_id)`** en `user_company_memberships`: un usuario no puede tener dos membresías activas a la misma empresa.

### Tablas globales no tenant-scoped

Catálogo `GLOBAL` (no llevan `company_id` en absoluto, o lo llevan como nullable para admitir tanto un registro de plataforma como uno propio de empresa):

- `users` (identidad global, ver ADR-013).
- `permissions` (catálogo atómico de permisos, versionado por release de la plataforma, no por empresa).
- `auth_tokens` (asociado a `users`, no a una empresa).
- `system_settings` (configuración de plataforma, solo editable por `SUPER_ADMIN`).
- Catálogos con doble naturaleza `DIRECTO/GLOBAL` (`roles`, `leave_types`, `novelty_types`, `holidays`, `payroll_concept_definitions`, `social_security_entities`, `social_security_concept_definitions`, `notification_templates`, `labor_rules`): cuando `company_id IS NULL`, la fila es un default de plataforma disponible para todas las empresas; cuando `company_id` está poblado, es una personalización propia de esa empresa que prevalece sobre el default.

### Convención de migraciones (nivel conceptual)

- Las migraciones son **versionadas y ordenadas**, nunca se edita una migración ya aplicada en cualquier entorno compartido (staging o producción); una corrección se expresa como una migración nueva.
- Ninguna migración debe ser **destructiva sin backup previo verificado**: eliminar una columna o tabla en producción requiere una estrategia de respaldo confirmada antes de ejecutarse. El detalle operativo de backups/rollback de despliegue vive en [22-DEPLOYMENT.md](./22-DEPLOYMENT.md).
- Toda migración que afecte una tabla `INMUTABLE` o `HISTORIAL` (agregar una columna, por ejemplo) debe evaluarse contra el volumen esperado de filas históricas antes de aplicarse, para evitar bloqueos largos de tabla en producción.
- La herramienta de migraciones es el sistema nativo de migraciones de Laravel (`php artisan migrate`), consistente con el stack fijado en ADR-022 (ver [03-ARCHITECTURE.md](./03-ARCHITECTURE.md)).

## Flujos

Este documento no define flujos de negocio. Ver el archivo `NN-*.md` del módulo correspondiente para el flujo que produce o consume cada tabla.

## Casos normales

- Una tabla nueva de catálogo tenant-scoped se crea con las columnas estándar (`id` UUID, `company_id`, `created_at`, `updated_at`, `created_by`/`updated_by`, `deleted_at` si aplica soft-delete) y un unique compuesto `(company_id, code)` si tiene código de negocio.
- Una consulta de reporte filtra siempre por `company_id` de forma explícita, incluso en tablas `HEREDADO`, aprovechando la denormalización de ADR-006.

## Casos especiales

- Una tabla `HEREDADO` que necesita alto volumen de consultas directas (ejemplo: `work_schedule_days`, `shift_breaks`) debe evaluar si conviene denormalizar `company_id` de inmediato o diferirlo; el ADR-006 lo recomienda como default, no como obligación absoluta en cada caso, pero apartarse del default requiere justificación explícita en el PR que introduce la tabla.
- Un catálogo con doble naturaleza `DIRECTO/GLOBAL` (ejemplo: `holidays`) requiere que la capa de negocio combine el default de plataforma (`company_id NULL`) con la personalización de la empresa (`company_id` poblado) sin duplicar filas ni perder la referencia al default cuando la empresa no personalizó nada.

## Errores

- **Olvidar `company_id` en una tabla nueva** que debería ser tenant-scoped es el error más grave posible en este esquema: abre la puerta a fuga de datos entre empresas. Toda tabla nueva debe justificar explícitamente por qué es `GLOBAL` si no lleva `company_id`.
- **Permitir hard-delete en `attendance_events`, `audit_logs` o `payroll_entries`/`payroll_entry_lines` cerrados** es un error crítico de implementación; ningún endpoint, script de mantenimiento o acceso administrativo debe exponer esa capacidad.
- **Editar directamente una fila `HISTORIAL` ya vigente** (por ejemplo, cambiar el `end_date` de un `employment_contracts` ya cerrado para "arreglarlo") en vez de crear la corrección correspondiente rompe la trazabilidad que el patrón effective-dated garantiza.
- **Ignorar el constraint anti-solapamiento de contratos** dejando que la validación viva solo en el frontend (que además está prohibido de contener lógica de negocio) es un error de seguridad de datos, no solo de UX.

## Seguridad

- El aislamiento de tenant a nivel de base de datos (`company_id` denormalizado) es la tercera de las tres capas de validación descritas en [15-MULTI-TENANCY.md](./15-MULTI-TENANCY.md) (API, servicio, base de datos); nunca es la única línea de defensa.
- `payroll_information` y cualquier columna que almacene datos bancarios/fiscales se cifran en reposo (ver [20-SECURITY.md](./20-SECURITY.md)); el catálogo de esta sección ya lo anota en la nota de la tabla.
- `audit_logs` es de solo escritura para el resto del sistema: ningún rol, incluido `SUPER_ADMIN`, tiene una vía de edición o borrado expuesta.

## Dependencias

- [04-DOMAIN-MODEL.md](./04-DOMAIN-MODEL.md): propósito de negocio, patrones canónicos y máquinas de estado de cada entidad catalogada aquí.
- [15-MULTI-TENANCY.md](./15-MULTI-TENANCY.md): estrategia completa de aislamiento de tenant en las tres capas.
- [23-DECISIONS.md](./23-DECISIONS.md): ADR-002 (PostgreSQL), ADR-006 (denormalización de `company_id`), ADR-010 (soft-delete vs hard-delete).
- [22-DEPLOYMENT.md](./22-DEPLOYMENT.md): estrategia de backups previa a migraciones destructivas.
- Cada archivo `NN-*.md` de módulo: consume las tablas de su bloque correspondiente.

## Criterios de aceptación

- Las ~52 tablas están catalogadas con columnas clave, tipo de aislamiento (DIRECTO/HEREDADO/GLOBAL), soft-delete (Sí/No) y mutabilidad (INMUTABLE/HISTORIAL/AJUSTE/MUTABLE) explícitos, sin ambigüedad.
- `attendance_events`, `audit_logs`, `payroll_entries`/`payroll_entry_lines` (una vez `CLOSED`), y las demás tablas `HISTORIAL`/`INMUTABLE`/`AJUSTE` están marcadas de forma inequívoca y consistente con [04-DOMAIN-MODEL.md](./04-DOMAIN-MODEL.md).
- La estrategia de UUID como PK está documentada como decisión tomada (no `PENDING DECISION`), con su justificación de seguridad explícita.
- Los índices y constraints críticos mencionados en el blueprint aprobado (unique `(company_id, code)`, índice `(employee_id, event_datetime)`, índice `(company_id, status)` en `payroll_periods`, constraint anti-solapamiento de contratos, unique `(user_id, company_id)`) están todos presentes.
- Ningún nombre de tabla o columna se desvía de los usados en el blueprint aprobado o en [04-DOMAIN-MODEL.md](./04-DOMAIN-MODEL.md).
