# 10-PAYROLL.md — Motor de Nómina

## Objetivo

Definir el motor de liquidación de nómina como única fuente de verdad para calcular lo devengado, lo deducido y el neto a pagar de cada empleado en cada periodo, garantizando que ninguna cifra monetaria se calcule fuera del backend, que el cierre de un periodo sea inmutable a nivel de aplicación, y que toda corrección posterior quede trazada sin sobrescribir históricos.

## Alcance

- El motor de nómina corre **exclusivamente en backend**. Nunca en frontend — es la fuente de verdad única para cifras monetarias (ver [19-FRONTEND.md](./19-FRONTEND.md), regla no negociable #5 de [AGENTS.md](./AGENTS.md)).
- Consume, sin recalcularlos ni reinterpretarlos:
  - Contratos y salarios vigentes (`employment_contracts`, `salary_history` — ver [04-DOMAIN-MODEL.md](./04-DOMAIN-MODEL.md)).
  - Asistencia ya calculada (`attendance_records`, producida por el motor de [09-TIME-CALCULATION.md](./09-TIME-CALCULATION.md)).
  - Horas extra ya autorizadas (`overtime_records` en estado autorizado — nunca horas en estado `DETECTED` o `REQUESTED`).
  - Novedades aprobadas (`novelty_records`, generadas al aprobar `leave_records`/`overtime_records`/`attendance_adjustments`).
  - Deducciones vigentes (`payroll_deduction_plans`).
  - Seguridad social (`social_security_affiliations`, aportes calculados vía [11-SOCIAL-SECURITY.md](./11-SOCIAL-SECURITY.md)).
- Fuera de alcance de este archivo:
  - Cómo se calcula el tiempo ordinario/extra/faltante (vive en [09-TIME-CALCULATION.md](./09-TIME-CALCULATION.md); Payroll solo traduce ese tiempo a dinero).
  - Cómo se gestionan afiliaciones y se calculan aportes de seguridad social en detalle (vive en [11-SOCIAL-SECURITY.md](./11-SOCIAL-SECURITY.md); Payroll dispara ese cálculo al cerrar y consume su resultado).
  - Cómo se renderiza el comprobante en PDF (vive en [14-PDF.md](./14-PDF.md); Payroll solo dispara la generación desde una entrada `CLOSED`).

## Conceptos

- **Periodo de nómina** (`payroll_periods`): rango de fechas con un `period_type`. Los tres tipos soportados son `WEEKLY`, `BIWEEKLY` (quincenal) y `MONTHLY`. La **quincena debe estar soportada desde el día 1** del proyecto, no como una extensión futura (ADR-008); el modelo de `payroll_periods` es genérico para los tres tipos, sin lógica especial hardcodeada por tipo.
- **Concepto salarial** (`payroll_concept_definitions`): unidad atómica de cálculo, clasificada como **devengo** (aumenta lo que se le paga al empleado: salario base, horas extra, recargos, comisiones) o **deducción** (disminuye el neto: aportes de seguridad social a cargo del empleado, préstamos, embargos). Cada concepto tiene un `calculation_method` (fijo, por fórmula, por hora) definido en el catálogo, nunca en código de aplicación.
- **Devengado / Deducido / Neto**: `payroll_entries.gross_total` (suma de líneas de tipo devengo), `payroll_entries.deductions_total` (suma de líneas de tipo deducción), `payroll_entries.net_total = gross_total - deductions_total`. Estos tres totales son siempre derivados de `payroll_entry_lines`, nunca un valor introducido directamente.

## Entidades

| Entidad | Rol en el motor |
|---|---|
| `payroll_periods` | Contenedor del ciclo de liquidación de una empresa; su `status` gobierna qué operaciones son válidas (ver Flujos). |
| `payroll_entries` | Liquidación de un empleado dentro de un periodo; referencia el/los `contract_id` aplicable(s). |
| `payroll_entry_lines` | Detalle línea a línea de cada concepto devengado o deducido dentro de una `payroll_entry`. |
| `payroll_concept_definitions` | Catálogo de conceptos salariales disponibles (globales o por empresa). |
| `payroll_deduction_plans` | Acuerdo de deducción recurrente y programada (préstamo, embargo) que Payroll consulta al calcular. |
| `payroll_adjustments` | Único mecanismo válido de corrección sobre una `payroll_entry` ya perteneciente a un periodo `CLOSED`. |

El esquema completo columna por columna, tipos, aislamiento por `company_id` y reglas de mutabilidad de cada una de estas tablas vive en [05-DATABASE.md](./05-DATABASE.md); este archivo no las repite, solo documenta el comportamiento del motor sobre ellas.

## Reglas

### Determinación de qué contrato/salario aplica a un periodo dado

Este es el flujo (d) del blueprint de diseño, transcrito sin alteraciones:

1. Dado un `employee_id` y el rango de un periodo (`payroll_period.start_date` a `payroll_period.end_date`), se buscan los `employment_contracts` del empleado donde `start_date <= period.end_date AND (end_date IS NULL OR end_date >= period.start_date)`. Esto es una aplicación directa del patrón "effective-dated lookup" documentado en [04-DOMAIN-MODEL.md](./04-DOMAIN-MODEL.md).
2. **Caso borde — contrato partido a mitad de periodo** (ej. una promoción efectiva el día 8 de una quincena): el esquema debe soportar el prorrateo mediante **múltiples `payroll_entry_lines` con distinto `contract_id`** dentro de la misma `payroll_entry`. Esto ya es una decisión de diseño tomada: el esquema lo permite estructuralmente.
   - **RESUELTO (Fase 9, decisión del propietario del producto — no un hecho legal validado)**: el prorrateo usa **días calendario** — `tarifa_diaria = salario_mensual / días_del_mes`, multiplicada por los días calendario de cada sub-rango. Es un criterio provisional explícito, elegido por ser el más simple y estándar en sistemas de nómina, no una fórmula legal colombiana confirmada; si se valida una fórmula distinta contra asesoría profesional, esto se revisa. Caso borde documentado: cuando un sub-rango cruza un fin de mes, la tarifa diaria usa el mes de la fecha de INICIO del sub-rango (lectura literal más simple, ver `PayrollCalculationService::resolveDailyRate()`).
3. El salario a usar en cada sub-rango (o en el periodo completo, si no hay partición) se resuelve con el patrón "effective-dated lookup" contra `salary_history`, tomando la revisión vigente a esa fecha; si no existe una revisión específica para el sub-rango, se usa `employment_contracts.base_salary`.
4. **Si se detectan cero o más de un contrato solapado** para el empleado en el periodo (dato corrupto: falta de cierre correcto de un contrato anterior, o ausencia total de contrato vigente), el motor de Payroll **debe rechazar el cálculo de ese empleado con un error bloqueante explícito** ("contrato ambiguo para el periodo"). Nunca se adivina cuál contrato es el correcto ni se promedia entre ellos.

### Traducción de reglas de tiempo a conceptos monetarios

- Las reglas laborales de tiempo (jornada ordinaria, recargo nocturno, recargo dominical/festivo, horas extra) se calculan como **tiempo** en [09-TIME-CALCULATION.md](./09-TIME-CALCULATION.md) contra `attendance_records`.
- Payroll traduce ese tiempo a **dinero** consumiendo las mismas tablas `labor_rules`/`labor_rule_versions` que usa Time Calculation (nunca una copia paralela de los mismos porcentajes — ver ADR-020 y la regla no negociable #6 de [AGENTS.md](./AGENTS.md)). Un `labor_rule_version.parameters` que define, por ejemplo, un recargo nocturno como porcentaje de tiempo, es la misma fuente que Payroll usa para calcular el monto pagable de ese recargo como `payroll_entry_line`.
- Ningún porcentaje de recargo, tarifa de hora extra, ni tasa alguna se hardcodea en el motor de Payroll. Si una `labor_rule_version` vigente para la fecha del periodo no existe, es un error bloqueante (ver sección Errores), nunca un valor por defecto inventado.

### Cierre inmutable

- Una vez que `payroll_periods.status = CLOSED`, las filas de `payroll_entries` y `payroll_entry_lines` que pertenecen a ese periodo son **de solo lectura a nivel de aplicación**.
- Cualquier corrección posterior pasa exclusivamente por `payroll_adjustments`. **Nunca se edita la entrada cerrada** (ADR-012, y regla no negociable #8 de [AGENTS.md](./AGENTS.md)).
- Esta regla aplica también de forma transitiva: un recálculo de `attendance_records`/`overtime_records` disparado por un ajuste de asistencia posterior al cierre (ver flujo (b) en [07-ATTENDANCE.md](./07-ATTENDANCE.md)) nunca se propaga automáticamente a una `payroll_entry` `CLOSED`; genera una señal explícita de "ajuste de nómina pendiente" que un usuario autorizado resuelve mediante el flujo de este archivo.

## Flujos

### Ciclo completo: calcular → aprobar → cerrar → reabrir/ajustar

Este es el flujo (c) del blueprint de diseño, transcrito sin alteraciones:

1. `PAYROLL_MANAGER` (o rol equivalente con permiso `payroll.calculate`) abre un `payroll_periods` en estado `OPEN` para el rango correspondiente (ej. una quincena).
2. **Calcular**: el motor recorre `employment_contracts` vigentes en el rango, cruza `attendance_records`, `overtime_records` autorizados, `novelty_records` aprobadas y `labor_rule_versions` vigentes, además de disparar el cálculo de seguridad social (ver [11-SOCIAL-SECURITY.md](./11-SOCIAL-SECURITY.md)) → genera o regenera `payroll_entries` + `payroll_entry_lines`, dejando el periodo en `CALCULATED`. Mientras el periodo esté en `OPEN` o `CALCULATED`, es **libremente recalculable**: no hay historial que proteger todavía, cada recálculo reemplaza el resultado anterior sin necesidad de `payroll_adjustments`.
3. **Aprobar** (permiso `payroll.approve`): si un empleado o rol revisa y aprueba el cálculo antes del cierre.
   - **RESUELTO** (ADR-034 en [23-DECISIONS.md](./23-DECISIONS.md)): el paso de aprobación es **opcional**. `payroll.close` puede ejecutarse directamente desde `CALCULATED` sin pasar por `APPROVED`; el estado `APPROVED` sigue disponible en el esquema para quien quiera usarlo como revisión intermedia, pero no es un gate obligatorio del flujo.
4. **Cerrar** (permiso `payroll.close`, operación muy sensible): transiciona `status = CLOSED`, registrando `closed_by` y `closed_at`. A partir de este punto, `payroll_entries`/`payroll_entry_lines` de ese periodo son de solo lectura a nivel de aplicación (ver regla de Cierre inmutable arriba).
5. **Generación de comprobantes PDF**: solo se generan desde entradas en estado `CLOSED` (ver [14-PDF.md](./14-PDF.md)). **RESUELTO** (ADR-035 en [23-DECISIONS.md](./23-DECISIONS.md)): no existe un PDF "borrador" a partir de una entrada `CALCULATED`.
6. **Corrección post-cierre**: se crea una fila en `payroll_adjustments` referenciando la `payroll_entry` afectada; **nunca se edita la entrada cerrada**. El esquema soporta dos mecanismos:
   - **Reapertura auditada**: un rol privilegiado (`ADMIN`/`COMPANY_OWNER`, permiso `payroll.reopen`) reabre el periodo (`status = REOPENED`), corrige, y vuelve a cerrarlo — generando un nuevo `closed_at`; el evento de cierre anterior queda preservado en `audit_logs`, no se pierde.
   - **Ajuste en periodo siguiente**: se inyecta una línea compensatoria en el próximo periodo abierto del mismo empleado, sin tocar nunca el periodo ya cerrado.
   - **RESUELTO** (ADR-026 en [23-DECISIONS.md](./23-DECISIONS.md)): el comportamiento **por defecto** es "ajuste en periodo siguiente" — un periodo cerrado no se reabre salvo excepción justificada. La reapertura auditada sigue soportada por el esquema (`payroll_periods.status = REOPENED`) para los casos que realmente la ameriten, pero deja de ser el camino estándar.
7. **Todas las transiciones** (`CALCULATE`, `APPROVE`, `CLOSE`, `REOPEN`, `ADJUST`) generan `audit_logs` de forma obligatoria (ver sección Seguridad).

## Casos normales

- Periodo estándar (semanal, quincenal o mensual) con un único `employment_contract` vigente durante todo el rango, asistencia completa registrada, sin horas extra ni novedades: el cálculo produce una `payroll_entry` por empleado con líneas de salario base, aportes de seguridad social a cargo del empleado (deducción) y neto resultante, sin necesidad de prorrateo ni intervención manual.

## Casos especiales

- **Contrato partido a mitad de periodo**: ver regla "Determinación de qué contrato/salario aplica a un periodo dado" arriba. El esquema soporta múltiples `payroll_entry_lines` con distinto `contract_id`; el algoritmo de prorrateo quedó **RESUELTO** (días calendario, ver arriba).
- **Empleado sin asistencia registrada en el periodo** (ej. ingresó después del cierre de asistencia, o hubo una falla de captura): **RESUELTO (Fase 9, decisión del propietario del producto)** — se bloquea el cálculo de ESE empleado específico con un error explícito (`NoAttendanceOrNoveltyDataException`), mismo criterio que "contrato ambiguo" (se rechaza sin abortar el resto del lote), nunca se asume/rellena en cero. La cobertura verificada es de existencia (al menos un `attendance_record` o `novelty_record` aprobado en el rango), no de cobertura día por día — una verificación más granular no está exigida por ningún criterio de aceptación y arriesgaría inventar un umbral de completitud no especificado.
- **Periodo que incluye un día festivo** (`holidays`): el tiempo correspondiente al festivo ya fue calculado como tal por [09-TIME-CALCULATION.md](./09-TIME-CALCULATION.md) (recargo dominical/festivo como tiempo); Payroll solo traduce ese tiempo ya calculado a la línea monetaria correspondiente usando la `labor_rule_version` vigente. Payroll no vuelve a consultar `holidays` directamente para decidir si un día es festivo — eso ya está resuelto aguas arriba, en el registro de tiempo.
  - **Alcance recortado en la implementación de Fase 9**: `TimeCalculationEngine` nunca llegó a producir esa magnitud de tiempo festivo/dominical (ver Fase 7/8 en `26-PROGRESS.md`) — no existe ningún `festivo_minutes`/`dominical_minutes` que traducir todavía. Payroll Fase 9 liquida únicamente salario base prorrateado, horas extra autorizadas y deducciones fijas; la línea de recargo festivo/dominical queda pendiente de que Time Calculation produzca esa magnitud primero (bloqueado por el mismo `PENDING DECISION` de legislación de `09-TIME-CALCULATION.md`, línea 21).
  - **Aporte a seguridad social también fuera de esta fase**: Seguridad Social (Fase 10) todavía no existe — el "caso normal" de una línea de deducción por aportes descrito arriba en este documento no se implementa hasta esa fase.
  - **Tratamiento monetario por tipo de novedad no consultado**: `novelty_types.affects_payroll` sigue en `false` para los 4 esenciales (sembrado en Fase 8) — el tratamiento legal exacto de cómo se paga cada tipo de licencia (ej. incapacidad con régimen especial de pago compartido empleador/EPS en Colombia) no está validado y no se inventa aquí.

## Errores

- **Contrato ambiguo para el periodo** (bloqueante): cero o más de un `employment_contract` solapado sin cierre correcto para el empleado en el rango del periodo. El cálculo de ese empleado específico se rechaza explícitamente; no bloquea el cálculo del resto de empleados del periodo, pero el periodo no puede cerrarse mientras exista al menos un empleado con este error sin resolver.
- **Regla laboral vigente no encontrada**: no existe una `labor_rule_version` vigente para la fecha del periodo y el tipo de regla requerido por un concepto salarial. Es un error bloqueante para ese concepto/empleado, nunca se sustituye por un valor por defecto.
- **Intento de modificar un periodo ya `CLOSED` sin pasar por `payroll_adjustments`**: cualquier intento de `UPDATE`/`DELETE` directo sobre `payroll_entries`/`payroll_entry_lines` de un periodo `CLOSED` debe ser rechazado a nivel de aplicación (ver antipatrón explícito en [AGENTS.md](./AGENTS.md)).

## Seguridad

- `payroll.close` es una operación muy sensible: cierra el periodo y activa la generación oficial de comprobantes. Requiere un permiso especial dedicado (no basta con `payroll.calculate` ni `payroll.approve`).
  - **RESUELTO** (ADR-027 en [23-DECISIONS.md](./23-DECISIONS.md)): un solo rol autorizado basta para cerrar (sin maker-checker obligatorio). Ver la matriz de roles en [06-AUTHORIZATION.md](./06-AUTHORIZATION.md).
- Toda transición de estado del periodo (`CALCULATE`, `APPROVE`, `CLOSE`, `REOPEN`, `ADJUST`) genera un registro en `audit_logs` de forma obligatoria, con usuario, valor anterior, valor nuevo y motivo (para `REOPEN`/`ADJUST`, el motivo es un campo requerido, no opcional). Si el registro de auditoría falla, la transacción de negocio completa debe abortar (ADR-018, ver [16-AUDIT.md](./16-AUDIT.md)).
- El acceso a `payroll_information` (datos bancarios/fiscales) consumido indirectamente durante el cálculo permanece gobernado por [20-SECURITY.md](./20-SECURITY.md); Payroll nunca expone esos datos sensibles fuera de lo estrictamente necesario para generar el pago/comprobante.

## Dependencias

- [04-DOMAIN-MODEL.md](./04-DOMAIN-MODEL.md) — entidades, patrones canónicos (effective-dated lookup, evento+ajuste), ciclo de vida de `PayrollPeriod`.
- [05-DATABASE.md](./05-DATABASE.md) — esquema exacto de las seis tablas de este módulo, aislamiento e índices.
- [09-TIME-CALCULATION.md](./09-TIME-CALCULATION.md) — origen de `attendance_records` y del marco general de `labor_rules`/`labor_rule_versions` que Payroll traduce a dinero.
- [11-SOCIAL-SECURITY.md](./11-SOCIAL-SECURITY.md) — cálculo de aportes disparado durante el cierre del periodo.
- [14-PDF.md](./14-PDF.md) — generación de comprobantes oficiales a partir de entradas `CLOSED`.

## Criterios de aceptación

- [ ] El motor determina el contrato/salario aplicable a cualquier periodo dado usando exclusivamente `employment_contracts` + `salary_history`, sin adivinar en caso de solapamiento o ausencia de contrato.
- [ ] Un contrato partido a mitad de periodo produce múltiples `payroll_entry_lines` con distinto `contract_id` dentro de la misma `payroll_entry` (el algoritmo de prorrateo exacto queda fuera de este criterio mientras sea `PENDING DECISION`).
- [ ] Ningún porcentaje de recargo, tarifa de hora extra o tasa de aporte está hardcodeado en el motor; todos se resuelven contra `labor_rule_versions` vigente por fecha.
- [ ] Una vez `CLOSED`, ningún camino del código permite `UPDATE`/`DELETE` directo sobre `payroll_entries`/`payroll_entry_lines` de ese periodo; toda corrección pasa por `payroll_adjustments`.
- [ ] Cada transición de estado del periodo genera exactamente un registro correcto en `audit_logs`.
- [ ] El cálculo de un periodo con al menos un empleado con contrato ambiguo rechaza explícitamente a ese empleado sin abortar el cálculo de los demás.
- [ ] Ningún cálculo de nómina se ejecuta ni se replica en el frontend.
