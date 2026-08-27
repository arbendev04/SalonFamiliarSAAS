# 16-AUDIT.md — Audit

## Objetivo

Definir cómo el sistema deja rastro inmutable de toda acción sensible sobre datos de negocio, de forma transversal a los 22 módulos, para poder responder siempre "quién hizo qué, cuándo, con qué valor anterior y nuevo, y por qué".

## Alcance

Incluye: el concepto de acción auditable, la entidad `audit_logs`, la lista de acciones que el sistema debe auditar obligatoriamente, el mecanismo de registro automático vía una capa de servicio transversal, y la consulta de historial de auditoría.

No incluye: la decisión de si una acción concreta es válida o debe permitirse (esa validación vive en el módulo de origen que dispara la acción); el detalle de RBAC de quién puede leer auditoría (`audit.read`, definido en [06-AUTHORIZATION.md](./06-AUTHORIZATION.md)); el detalle del acceso cross-tenant de `SUPER_ADMIN` como excepción de aislamiento (vive en [15-MULTI-TENANCY.md](./15-MULTI-TENANCY.md), aunque ese acceso también debe auditarse, como se detalla más abajo).

## Conceptos

- **Acción auditable**: cualquier operación de negocio sensible que crea, modifica, elimina lógicamente, aprueba, rechaza o cierra/reabre un recurso relevante para la trazabilidad legal u operativa del sistema.
- **Valor anterior / valor nuevo**: snapshot del estado del recurso antes y después de la acción auditada, para poder reconstruir el cambio exacto sin depender de inferencias.
- **Motivo**: justificación textual asociada a la acción, obligatoria para las acciones que lo requieran (por ejemplo, un ajuste de asistencia o una reapertura de nómina).
- **Usuario**: el `user` que ejecutó la acción (o, en el caso de acceso cross-tenant de `SUPER_ADMIN`, el `user` que impersona/accede).
- **Entidad afectada**: el tipo y el identificador del recurso de negocio sobre el que se ejecutó la acción (por ejemplo, `payroll_entries` + su `id`).

## Entidades

| Entidad | Propósito | Notas de `05-DATABASE.md` |
|---|---|---|
| `audit_logs` | Bitácora inmutable de acciones sensibles: `id`, `company_id`, `user_id`, `action`, `entity_type`, `entity_id`, `old_value`, `new_value`, `reason`, `ip_address`, `created_at` | Aislamiento DIRECTO; sin soft-delete; **INMUTABLE — nunca editable ni borrable** |

Consultar [04-DOMAIN-MODEL.md](./04-DOMAIN-MODEL.md) y [05-DATABASE.md](./05-DATABASE.md) para el detalle completo de columnas, índices y constraints.

## Reglas

### Lista completa de acciones obligatoriamente auditadas

De acuerdo con el brief, deben quedar registradas en `audit_logs` como mínimo las siguientes acciones, sin excepción:

- Crear un recurso de negocio sensible.
- Modificar un recurso de negocio sensible.
- Eliminar lógicamente (soft-delete) un recurso de negocio sensible.
- Aprobar (ej. aprobación de un ajuste de asistencia, de una ausencia/permiso, de horas extra).
- Rechazar (ej. rechazo de un ajuste de asistencia, de una ausencia/permiso, de horas extra).
- Cerrar nómina (`payroll.close`).
- Reabrir nómina (`payroll.reopen`).
- Ajustar asistencia (`attendance.adjust`, ver [07-ATTENDANCE.md](./07-ATTENDANCE.md)).
- Modificar salario (cambios en `salary_history`, ver [04-DOMAIN-MODEL.md](./04-DOMAIN-MODEL.md)).
- Modificar contrato (cambios en `employment_contracts`, ver [10-PAYROLL.md](./10-PAYROLL.md) y el flujo de determinación de contrato vigente).
- Cambiar permisos (asignación o revocación de `role`, cambios en `role_permissions`, ver [06-AUTHORIZATION.md](./06-AUTHORIZATION.md)).
- Enrolar o eliminar datos biométricos de un empleado (`biometrics.enroll`, `biometrics.delete_data`, ver [12-BIOMETRICS.md](./12-BIOMETRICS.md)).
- Cualquier acceso cross-tenant de `SUPER_ADMIN` (ver [15-MULTI-TENANCY.md](./15-MULTI-TENANCY.md)).

### Inmutabilidad

- `audit_logs` es **estrictamente INMUTABLE**: una vez insertada una fila, nunca se edita ni se borra, bajo ninguna circunstancia ni rol (ver ADR-010 en [23-DECISIONS.md](./23-DECISIONS.md) sobre la política general de soft-delete vs hard-delete, que excluye explícitamente a `audit_logs` de cualquier forma de eliminación).
- Corregir un dato de negocio nunca implica editar el `audit_log` correspondiente a la acción original: se genera una nueva acción auditable (por ejemplo, un nuevo ajuste) que queda como una fila adicional, preservando ambas.

## Flujos

### Registro automático de auditoría

- El registro de auditoría se realiza mediante una **capa de servicio transversal**, no de forma manual y dispersa dentro de cada módulo. Cualquier módulo que ejecute una acción de la lista anterior invoca esta capa común, que es responsable de construir y persistir la fila de `audit_logs` con `user_id`, `action`, `entity_type`/`entity_id`, `old_value`/`new_value`, `reason` (cuando aplique) e `ip_address`.
- Esto evita que cada uno de los 22 módulos reimplemente su propia lógica de auditoría de forma inconsistente.

### Consulta de historial

- El historial de auditoría se consulta vía `GET /audit/logs` (ver [18-API.md](./18-API.md)), con filtros por entidad, usuario, fecha y empresa.
- El acceso a esta consulta está protegido por el permiso `audit.read`: `SUPER_ADMIN` puede consultar cross-tenant; `COMPANY_OWNER` y `ADMIN` solo pueden consultar la auditoría de su propia empresa (ver matriz RBAC en [06-AUTHORIZATION.md](./06-AUTHORIZATION.md)).

## Casos normales

- Un `HR_MANAGER` modifica el salario de un empleado (`salary_history`): la capa transversal registra automáticamente una fila en `audit_logs` con el valor anterior y el nuevo.
- Un `PAYROLL_MANAGER` cierra un periodo de nómina: se registra la transición `CLOSE` con `closed_by`/`closed_at` como parte del `new_value`.

## Casos especiales

- **Auditoría de acciones de `SUPER_ADMIN` (impersonación)**: todo acceso cross-tenant de `SUPER_ADMIN`, incluida una eventual impersonación de otro usuario para dar soporte, debe quedar registrado en `audit_logs` con el motivo del acceso, de forma indistinguible en rigor de cualquier otra acción auditada — no existe una vía de acceso cross-tenant que quede fuera de este registro (ver contradicción 3 resuelta en el blueprint aprobado, y su detalle operativo en [15-MULTI-TENANCY.md](./15-MULTI-TENANCY.md)).
- **Acciones batch/masivas**: **PENDING DECISION** — el blueprint no resuelve si una acción masiva (por ejemplo, aprobar en lote varias solicitudes de ausencia) debe generar un `audit_log` por cada entidad individual afectada o un único log agregado que referencie el lote. Hasta que se decida, no debe asumirse ninguna de las dos opciones como comportamiento por defecto.

## Errores

- **Qué pasa si falla la escritura del audit log**: decisión conservadora ya tomada (ADR-018 en [23-DECISIONS.md](./23-DECISIONS.md)) — si la escritura en `audit_logs` falla por cualquier motivo, la transacción de negocio completa debe **abortar**. El sistema nunca debe continuar una operación sensible sin dejar rastro auditado; es preferible que la acción de negocio no ocurra a que ocurra sin auditoría.

## Seguridad

- `audit_logs` es de **solo lectura** para los roles autorizados mediante el permiso `audit.read`; no existe ninguna operación de escritura expuesta a un usuario final sobre esta tabla, más allá de la inserción automática realizada por la capa de servicio transversal.
- **Nunca editable por nadie, ni siquiera `SUPER_ADMIN`**: no existe ningún rol ni mecanismo administrativo que permita modificar o borrar una fila de `audit_logs` ya insertada.
- **Retención de logs de auditoría**: **PENDING DECISION** — el brief no especifica durante cuánto tiempo deben conservarse los registros de `audit_logs` ni si existe algún mecanismo de archivado tras cierto plazo.

## Dependencias

- Todos los módulos del sistema escriben en `audit_logs` de forma transversal a través de la capa de servicio común descrita en Flujos.
- Referencia especial a [10-PAYROLL.md](./10-PAYROLL.md): las transiciones `CALCULATE`, `APPROVE`, `CLOSE`, `REOPEN`, `ADJUST` de un periodo de nómina generan `audit_logs` (ver flujo de cierre y corrección posterior en el blueprint aprobado).
- Referencia especial a [07-ATTENDANCE.md](./07-ATTENDANCE.md): toda creación de `attendance_adjustments` y su aprobación/rechazo genera `audit_logs`, preservando el valor original y el corregido.
- [06-AUTHORIZATION.md](./06-AUTHORIZATION.md): define el permiso `audit.read` y quién puede consultar el historial.
- [15-MULTI-TENANCY.md](./15-MULTI-TENANCY.md): define el detalle del acceso cross-tenant de `SUPER_ADMIN` que este módulo audita.
- [05-DATABASE.md](./05-DATABASE.md): define columnas, índices y la garantía de inmutabilidad a nivel de esquema para `audit_logs`.

## Criterios de aceptación

- Cada una de las acciones listadas en la sección Reglas genera exactamente el/los registros de `audit_logs` correspondientes, con `user_id`, valor anterior, valor nuevo (cuando aplique) y motivo (cuando sea obligatorio para esa acción).
- No existe ningún camino de código, endpoint o rol capaz de editar o borrar una fila existente de `audit_logs`.
- Si la escritura de un `audit_log` falla, la operación de negocio que lo originó no queda persistida (aborta la transacción completa).
- Todo acceso cross-tenant de `SUPER_ADMIN` queda registrado en `audit_logs` sin excepción.
- La consulta `GET /audit/logs` respeta el aislamiento de tenant y el permiso `audit.read` definidos en [06-AUTHORIZATION.md](./06-AUTHORIZATION.md).
