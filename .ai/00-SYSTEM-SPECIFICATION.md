# 00 — Especificación del Sistema

## Objetivo

Definir, en un solo documento de referencia, qué es el sistema, cómo se relacionan sus 22 módulos, cuáles son sus principios rectores no negociables, y fijar el glosario canónico de términos que el resto de los archivos de `.ai/` reutilizan sin redefinir. Este archivo es el punto de entrada técnico del proyecto, junto con [AGENTS.md](./AGENTS.md).

## Alcance general del sistema

SalonFamiliarSAAS es un SaaS multiempresa (multi-tenant) de gestión laboral orientado a negocios con trabajadores por turnos. Cubre el ciclo completo desde la planificación de jornadas y turnos, pasando por la captura de asistencia real (incluyendo biometría), el cálculo de tiempo trabajado, hasta la liquidación de nómina, los aportes de seguridad social y la generación de comprobantes. El sistema se construye como monolito modular sobre PostgreSQL (ver [03-ARCHITECTURE.md](./03-ARCHITECTURE.md), ADR-001 y ADR-002 en [23-DECISIONS.md](./23-DECISIONS.md)).

## Resumen ejecutivo del producto

El producto resuelve la dificultad operativa de negocios con trabajadores por turnos —inicialmente panaderías— para controlar de forma confiable quién trabajó, cuándo, cuánto tiempo, y cuánto se le debe pagar por ello, manteniendo trazabilidad completa desde la jornada planificada hasta el neto pagado. El sistema está diseñado para servir múltiples empresas clientes (tenants) de forma aislada, con biometría como mecanismo de captura de asistencia, y con el backend como única fuente de verdad para todo cálculo de tiempo y dinero. Ver [01-VISION.md](./01-VISION.md) para el detalle de propuesta de valor y perfiles de usuario.

## Las 8 capas del principio fundamental

El sistema completo se explica como un pipeline de 8 capas, cada una consumiendo la salida de la anterior. Ninguna capa puede saltarse ni calcularse fuera de orden. Esta cadena es el principio organizador de todo el dominio y de la separación de módulos de la sección siguiente.

1. **Jornada planificada** (`work_schedule_templates`, `work_schedule_days`, `employee_schedules`): define las reglas generales de cuándo debería trabajar un empleado (ver [08-SHIFTS.md](./08-SHIFTS.md)).
2. **Turno asignado** (`shifts`, `shift_assignments`, `shift_breaks`): instancia concreta de la jornada planificada en una fecha específica, asignada a un trabajador (ver [08-SHIFTS.md](./08-SHIFTS.md)).
3. **Asistencia real**: lo que efectivamente ocurrió, capturado mediante marcación biométrica u otro medio (ver [12-BIOMETRICS.md](./12-BIOMETRICS.md)).
4. **Eventos de asistencia** (`attendance_events`, con su mecanismo de corrección `attendance_adjustments`): registro inmutable, evento por evento, de cada marcación real; es la fuente de verdad primaria de todo el pipeline (ver [07-ATTENDANCE.md](./07-ATTENDANCE.md)).
5. **Motor de cálculo de tiempo** (`labor_rules`, `labor_rule_versions`, `time_calculation_runs`): cruza turno planificado + eventos reales + reglas laborales vigentes para producir `attendance_records` (ver [09-TIME-CALCULATION.md](./09-TIME-CALCULATION.md)).
6. **Horas ordinarias / extras / faltantes**: la salida clasificada del motor de cálculo de tiempo, más el ciclo de vida de horas extra (`overtime_records`) y su interacción con novedades (ver [09-TIME-CALCULATION.md](./09-TIME-CALCULATION.md)).
7. **Novedades** (`novelty_types`, `novelty_records`, alimentadas por `leave_records`, `overtime_records`, `attendance_adjustments`): capa unificadora de eventos que afectan tiempo o dinero, consumida por Payroll y Time Calculation.
8. **Motor de nómina** (`payroll_periods`, `payroll_entries`, `payroll_entry_lines`): traduce tiempo y novedades a dinero mediante `payroll_concept_definitions`, aplicando deducciones (`payroll_deduction_plans`) y aportes de seguridad social (`social_security_contributions`), hasta llegar al neto a pagar y su comprobante en PDF (`generated_documents`) (ver [10-PAYROLL.md](./10-PAYROLL.md), [11-SOCIAL-SECURITY.md](./11-SOCIAL-SECURITY.md), [14-PDF.md](./14-PDF.md)).

Dentro de la capa 8, la secuencia interna es: **Conceptos salariales → Deducciones → Seguridad social → Neto a pagar → Comprobante/PDF/reportes**, en ese orden de dependencia.

## Mapa de los 22 módulos y sus dependencias

Los 22 módulos, agrupados por bloque funcional, con su dependencia directa aguas arriba (para el detalle de responsabilidades y límites de cada uno, ver [03-ARCHITECTURE.md](./03-ARCHITECTURE.md)):

- **Tenancy/Acceso**: Authentication, Authorization, Companies, Branches — sin dependencias de dominio, son la base de todos los demás módulos.
- **Empleados**: Employees, Positions, Employment Contracts — dependen de Companies/Branches.
- **Jornadas y Turnos**: Work Schedules, Shifts — dependen de Employees.
- **Asistencia**: Attendance, Biometrics — dependen de Shifts.
- **Cálculo**: Time Calculation — depende de Attendance y Shifts.
- **Extras y novedades**: Overtime, Absences/Leave — dependen de Time Calculation.
- **Nómina**: Payroll, Social Security — dependen de Employment Contracts, Time Calculation, Overtime, Absences/Leave.
- **Salida**: Reports, PDF, Notifications — dependen de los módulos de datos que reportan.
- **Transversales**: Audit, Settings — consumidos por todos los módulos anteriores, no dependen de ninguno de ellos.

## Principios rectores

- **Multi-tenant con aislamiento estricto**: todo dato tenant-scoped lleva `company_id`; nunca es posible cruzar datos entre empresas (ver [15-MULTI-TENANCY.md](./15-MULTI-TENANCY.md), ADR-006).
- **Inmutabilidad de eventos**: los eventos que representan hechos ya ocurridos (`attendance_events`, `audit_logs`) nunca se editan ni se borran; se corrigen mediante el patrón evento + ajuste (ver ADR-003, ADR-012).
- **Versionado por vigencia**: toda regla o dato que cambia con el tiempo (contratos, salarios, reglas laborales, afiliaciones) se resuelve con el patrón "effective-dated lookup" contra una fecha dada, nunca sobrescribiendo el valor anterior (ver [04-DOMAIN-MODEL.md](./04-DOMAIN-MODEL.md), ADR-007).
- **RBAC granular**: los permisos son atómicos y se agrupan en roles configurables, no hay lógica de autorización basada en nombres de rol hardcodeados (ver [06-AUTHORIZATION.md](./06-AUTHORIZATION.md), ADR-009).
- **Backend como única fuente de verdad para cálculos**: ningún cálculo de tiempo trabajado, horas extra, recargos, deducciones o neto a pagar ocurre en el frontend; el cliente solo presenta resultados ya calculados (ver [19-FRONTEND.md](./19-FRONTEND.md), ADR-004).

## Glosario canónico

Este es **el** glosario de referencia del proyecto. Los demás 25 archivos de `.ai/` deben usar estos términos exactamente como se definen aquí; ningún archivo debe redefinirlos ni introducir sinónimos que compitan con esta lista.

- **Empresa / Tenant**: cliente del SaaS, raíz de aislamiento multi-tenant. Tabla: `companies`.
- **Sucursal**: local operativo de una empresa, con su propia zona horaria. Tabla: `branches`.
- **Trabajador**: persona empleada por una empresa dentro del sistema. Tabla: `employees`. (Se usa "trabajador" y "empleado" de forma intercambiable en la documentación funcional; la tabla es siempre `employees`).
- **Cargo**: puesto o posición ocupada por un trabajador, catálogo por empresa. Tabla: `positions`.
- **Contrato**: relación laboral histórica entre un trabajador y la empresa, con fecha de inicio, fin y salario base; nunca se sobrescribe, se cierra y se abre uno nuevo. Tabla: `employment_contracts`.
- **Jornada planificada**: conjunto de reglas de cuándo debería trabajar un empleado (plantilla + reglas por día). Tablas: `work_schedule_templates`, `work_schedule_days`, `employee_schedules`.
- **Turno**: instancia concreta de trabajo planificado para una fecha determinada. Tabla: `shifts`.
- **Asignación de turno**: vínculo entre un turno concreto y el trabajador que debe cubrirlo. Tabla: `shift_assignments`.
- **Evento de asistencia**: registro inmutable de una marcación real (entrada, salida, inicio/fin de descanso). Tabla: `attendance_events`.
- **Ajuste de asistencia**: mecanismo de corrección que preserva el valor original y el corregido sin editar el evento fuente. Tabla: `attendance_adjustments`.
- **Registro de asistencia**: resumen derivado y recalculable de tiempo trabajado por empleado y fecha, salida del motor de cálculo de tiempo; nunca es fuente de verdad primaria. Tabla: `attendance_records`.
- **Hora ordinaria**: tiempo trabajado dentro de la jornada planificada y dentro de los límites definidos por las reglas laborales vigentes.
- **Hora extra**: tiempo trabajado que excede la jornada planificada más allá de la tolerancia configurada, sujeto a un ciclo de vida propio (detectada → solicitada → autorizada/rechazada → pagada). Tabla: `overtime_records`.
- **Novedad**: registro unificador que representa cualquier evento que afecta el cálculo de tiempo o de nómina (ausencia, permiso, vacación, incapacidad, extra, ajuste, festivo, descanso), generado a partir de una entidad especializada de origen. Tablas: `novelty_types`, `novelty_records`.
- **Periodo de nómina**: rango de fechas (semanal, quincenal o mensual) sobre el que se liquida nómina, con estado propio. Tabla: `payroll_periods`.
- **Liquidación / Entrada de nómina**: resultado de liquidar a un trabajador dentro de un periodo de nómina. Tabla: `payroll_entries`, con su detalle en `payroll_entry_lines`.
- **Concepto salarial**: definición de un componente de la liquidación (devengo o deducción), fijo, por fórmula o por hora. Tabla: `payroll_concept_definitions`.
- **Deducción**: descuento aplicado a una liquidación, ya sea puntual (línea de `payroll_entry_lines` de tipo deducción) o recurrente (`payroll_deduction_plans`).
- **Aporte de seguridad social**: monto calculado, a cargo del trabajador y/o del empleador, hacia una entidad de seguridad social externa. Tabla: `social_security_contributions`.
- **Regla laboral**: parámetro configurable que define cómo se calcula tiempo o dinero (tolerancias, redondeo, recargos), nunca hardcodeado. Tabla: `labor_rules`, con sus versiones vigentes en `labor_rule_versions`.
- **Versión de regla**: instancia de una regla laboral vigente durante un rango de fechas específico. Tabla: `labor_rule_versions`.
- **Auditoría**: registro inmutable de una acción sensible sobre el sistema, con usuario, valor anterior, valor nuevo y motivo. Tabla: `audit_logs`.
- **Permiso atómico**: unidad mínima e indivisible de autorización (ej. `attendance.adjust`, `payroll.close`), catálogo global. Tabla: `permissions`.
- **Rol**: colección configurable de permisos atómicos, de sistema o propia de una empresa. Tabla: `roles`.

## Fuera de alcance explícito de esta fase

Esta fase (Fase 0 del roadmap, ver [24-ROADMAP.md](./24-ROADMAP.md)) produce únicamente documentación. No se escribe código de producción, no se elige stack tecnológico definitivo, no se implementa ningún endpoint, motor de cálculo, migración de base de datos ni interfaz de usuario. El stack tecnológico de backend y frontend queda como:

**RESUELTO**: Stack tecnológico — backend PHP/Laravel, frontend Inertia.js/Vue, hosting Laravel Cloud (ver ADR-021 y ADR-022 en [23-DECISIONS.md](./23-DECISIONS.md)).

## Criterios de aceptación de esta documentación

- Los 27 archivos de `.ai/` existen y son consistentes entre sí (mismos nombres de entidades, tablas y módulos en todos).
- Ningún archivo redefine un término del glosario canónico de esta sección; todos lo referencian.
- Toda ambigüedad identificada en el blueprint aprobado está marcada literalmente como `**PENDING DECISION**` en el archivo correspondiente, sin resolución inventada.
- Cada uno de los 22 módulos tiene al menos un archivo `.ai/` responsable, según el mapa cruzado de [AGENTS.md](./AGENTS.md).
- Los 20 ADRs de [23-DECISIONS.md](./23-DECISIONS.md) y las 16 fases de [24-ROADMAP.md](./24-ROADMAP.md) están completos y enlazados desde este documento cuando corresponde.
