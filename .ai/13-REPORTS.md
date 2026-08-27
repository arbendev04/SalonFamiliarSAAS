# 13-REPORTS.md — Reports

## Objetivo

Definir cómo el sistema expone información agregada y filtrable a partir de datos ya calculados por otros módulos, para que empresas, sucursales, supervisores y trabajadores puedan consultar asistencia, tiempo, nómina y seguridad social sin necesidad de acceder directamente a las tablas operativas.

## Alcance

Incluye: los reportes mínimos requeridos (asistencia diaria, asistencia por trabajador, horas trabajadas, horas extras, horas faltantes, ausencias, turnos, nómina, costos laborales, seguridad social, historial de modificaciones), sus filtros comunes, y las reglas de tenant isolation y RBAC de datos personales aplicadas a la lectura de reportes.

No incluye: el cálculo de ninguna cifra (tiempo, dinero, aportes) — todo valor mostrado en un reporte proviene ya calculado de su módulo de origen; la generación de archivos PDF de esos reportes, que vive en [14-PDF.md](./14-PDF.md); la definición de los permisos atómicos en sí, que vive en [06-AUTHORIZATION.md](./06-AUTHORIZATION.md); los endpoints exactos, que se listan en [18-API.md](./18-API.md).

## Conceptos

- **Reporte**: consulta de solo lectura sobre datos ya persistidos y calculados por su módulo dueño, presentada de forma agregada o tabular y sujeta a filtros.
- **Exportación**: materialización de un reporte en un formato descargable (ej. PDF vía [14-PDF.md](./14-PDF.md), u otro formato — el catálogo exacto de formatos de exportación no PDF es **PENDING DECISION**).
- **Periodo abierto vs cerrado**: un reporte de nómina puede ejecutarse sobre un `payroll_period` en cualquier estado; el reporte debe indicar explícitamente si el periodo consultado está `OPEN`/`CALCULATED` (datos parciales o recalculables) o `CLOSED` (datos definitivos), sin mezclar ambos estados sin distinción visual.

## Entidades

Reports no posee tablas propias. Es un módulo de solo lectura que consulta las tablas de sus módulos de origen:

| Reporte | Tablas fuente principales | Módulo dueño de la cifra |
|---|---|---|
| Asistencia diaria | `attendance_records`, `attendance_events` | [07-ATTENDANCE.md](./07-ATTENDANCE.md) |
| Asistencia por trabajador | `attendance_records`, `employees` | [07-ATTENDANCE.md](./07-ATTENDANCE.md) |
| Horas trabajadas | `attendance_records` (`ordinary_minutes`) | [09-TIME-CALCULATION.md](./09-TIME-CALCULATION.md) |
| Horas extras | `attendance_records` (`overtime_candidate_minutes`), `overtime_records` | [09-TIME-CALCULATION.md](./09-TIME-CALCULATION.md), Overtime |
| Horas faltantes | `attendance_records` (`missing_minutes`) | [09-TIME-CALCULATION.md](./09-TIME-CALCULATION.md) |
| Ausencias | `absence_records`, `leave_records` | Absences / Leave |
| Turnos | `shifts`, `shift_assignments` | [08-SHIFTS.md](./08-SHIFTS.md) |
| Nómina | `payroll_periods`, `payroll_entries`, `payroll_entry_lines` | [10-PAYROLL.md](./10-PAYROLL.md) |
| Costos laborales | `payroll_entries`, `payroll_entry_lines`, `social_security_contributions` | [10-PAYROLL.md](./10-PAYROLL.md), [11-SOCIAL-SECURITY.md](./11-SOCIAL-SECURITY.md) |
| Seguridad social | `social_security_contributions`, `social_security_affiliations` | [11-SOCIAL-SECURITY.md](./11-SOCIAL-SECURITY.md) |
| Historial de modificaciones | `audit_logs` | [16-AUDIT.md](./16-AUDIT.md) |

Consultar [04-DOMAIN-MODEL.md](./04-DOMAIN-MODEL.md) y [05-DATABASE.md](./05-DATABASE.md) para el detalle completo de columnas de cada tabla fuente.

## Reglas

- Reports es **estrictamente de solo lectura**: ningún endpoint de este módulo ejecuta un `INSERT`, `UPDATE` ni `DELETE` sobre datos de negocio, ni recalcula tiempo, horas extra, deducciones o neto a pagar. Toda cifra mostrada es la que ya produjo su módulo dueño.
- Todo reporte respeta **tenant isolation**: nunca devuelve filas de una `company_id` distinta a la de la membership activa del usuario (ver [15-MULTI-TENANCY.md](./15-MULTI-TENANCY.md)).
- Todo reporte respeta el **RBAC de datos personales** definido en [06-AUTHORIZATION.md](./06-AUTHORIZATION.md): un `EMPLOYEE` solo ve reportes de su propio historial (asistencia, horas, nómina propia), salvo que su rol tenga un permiso explícito de visibilidad ampliada (`Y (equipo)` para `SUPERVISOR`, visibilidad total para `HR_MANAGER`/`ADMIN`/`COMPANY_OWNER`/`SUPER_ADMIN`, según la matriz RBAC).
- Los filtros soportados de forma transversal a todos los reportes son: empresa (implícita por membership activa), sucursal, trabajador, rango de fechas, departamento (vía `positions`), cargo (`positions`) y periodo (`payroll_periods` para los reportes de nómina/costos/seguridad social).

## Flujos

### Generar/exportar un reporte

1. El cliente invoca el endpoint del reporte correspondiente (ver [18-API.md](./18-API.md)) con los filtros aplicables.
2. El backend valida el permiso `reports.read` (o `reports.export` si la operación es de exportación) y aplica automáticamente el scoping de tenant y de visibilidad personal descrito en Reglas.
3. El backend consulta directamente las tablas fuente ya calculadas (sin recomputar nada) y arma la respuesta agregada/tabular.
4. Si el reporte se solicita como exportación, se delega la materialización del archivo a [14-PDF.md](./14-PDF.md) (u otro formato, **PENDING DECISION**).

## Casos normales

- Un `HR_MANAGER` consulta el reporte de asistencia diaria de una sucursal para el día en curso.
- Un `EMPLOYEE` consulta su propio reporte de horas trabajadas del mes.
- Un `PAYROLL_MANAGER` exporta el reporte de nómina de un periodo ya `CLOSED`.

## Casos especiales

- **Reporte multi-sucursal**: un usuario con visibilidad sobre varias `branches` de la misma `company` (ej. `ADMIN`, `COMPANY_OWNER`) puede solicitar un reporte agregado que combine varias sucursales; el reporte debe permitir desglosar por sucursal, no solo mostrar el total consolidado.
- **Periodo de nómina todavía abierto con datos parciales**: un reporte de nómina o de costos laborales solicitado sobre un `payroll_period` en estado `OPEN` o `CALCULATED` debe indicar explícitamente que las cifras son provisionales y recalculables, para no confundirlas con un reporte sobre un periodo `CLOSED` (dato definitivo).

## Errores

- **Filtro inválido**: combinación de filtros contradictoria o valor fuera de dominio (ej. `branch_id` que no pertenece a la `company` activa, `employee_id` fuera del alcance de visibilidad del rol) responde con error de validación (ver catálogo de códigos en [18-API.md](./18-API.md)).
- **Rango de fechas excesivo**: un rango de fechas que exceda el límite operativo definido para un reporte (para evitar consultas prohibitivamente costosas) responde con error de validación; el límite exacto por reporte es **PENDING DECISION**.

## Seguridad

- Los permisos que gobiernan este módulo son `reports.read` (consulta) y `reports.export` (exportación), definidos en la matriz RBAC de [06-AUTHORIZATION.md](./06-AUTHORIZATION.md).
- Un `EMPLOYEE` ve solo lo propio en todo reporte, sin excepción, salvo el permiso ampliado explícito de su rol.
- Ninguna respuesta de reporte debe filtrar datos de otra `company_id`, incluso en caso de error o filtro malformado.

## Dependencias

- [07-ATTENDANCE.md](./07-ATTENDANCE.md), [08-SHIFTS.md](./08-SHIFTS.md), [09-TIME-CALCULATION.md](./09-TIME-CALCULATION.md), [10-PAYROLL.md](./10-PAYROLL.md), [11-SOCIAL-SECURITY.md](./11-SOCIAL-SECURITY.md), [16-AUDIT.md](./16-AUDIT.md): módulos dueños de las cifras que este módulo únicamente lee.
- [14-PDF.md](./14-PDF.md): materialización de un reporte en documento descargable.
- [06-AUTHORIZATION.md](./06-AUTHORIZATION.md): permisos `reports.read`/`reports.export` y RBAC de visibilidad personal.
- [15-MULTI-TENANCY.md](./15-MULTI-TENANCY.md): tenant isolation aplicado a toda consulta.
- [18-API.md](./18-API.md): endpoints, filtros comunes y catálogo de errores.

## Criterios de aceptación

- Ningún endpoint de Reports modifica datos de negocio ni recalcula tiempo, dinero o aportes.
- Todo reporte respeta tenant isolation: cero filas de otra `company_id` en cualquier respuesta.
- Un `EMPLOYEE` que consulta cualquier reporte recibe únicamente su propia información, salvo permiso ampliado explícito.
- Un reporte sobre un `payroll_period` no `CLOSED` distingue visualmente que sus cifras son provisionales.
- Los reportes mínimos listados en Entidades están disponibles con, al menos, los filtros comunes descritos en Reglas.
