# 11-SOCIAL-SECURITY.md — Seguridad Social

## Objetivo

Definir el módulo de afiliaciones y aportes de seguridad social como un dominio **separado de `Employee`**, con reglas de cálculo siempre trazables a versiones de reglas vigentes y a la liquidación de nómina que las generó — nunca a porcentajes fijos en código de aplicación.

## Alcance

- Este módulo es explícitamente **independiente de la entidad `Employee`**: los datos de afiliación y aporte nunca se mezclan como columnas dentro de `employees`. `employees` solo se referencia como FK.
- Cubre: catálogo de entidades externas (fondo/EPS/ARL-equivalente), historial de afiliación de cada empleado a esas entidades, y el cálculo de aportes generado al cerrar un periodo de nómina.
- **RESUELTO** (ADR-023 en [23-DECISIONS.md](./23-DECISIONS.md)): la legislación objetivo de la v1 es **Colombia exclusivamente**. Este módulo puede modelar catálogos concretos (EPS, ARL, fondo de pensión, Caja de Compensación Familiar) cuando se implemente — pero los nombres exactos de entidades, tipos de aporte y porcentajes siguen sujetos a validación profesional explícita antes de codificarse (ver regla no negociable en [AGENTS.md](./AGENTS.md)); este ADR resuelve el país, no los valores legales concretos.
- Fuera de alcance de este archivo:
  - Cómo se calcula el neto a pagar del empleado en su totalidad (vive en [10-PAYROLL.md](./10-PAYROLL.md); este módulo solo produce los aportes que Payroll consume como líneas de deducción/carga).
  - Cómo se determina el contrato/salario base sobre el que se calculan los aportes (vive en [10-PAYROLL.md](./10-PAYROLL.md)).

## Conceptos

- **Entidad externa** (`social_security_entities`): fondo, EPS, ARL o equivalente al que un empleado puede estar afiliado. Modelada de forma agnóstica de país mediante un campo `type` configurable, no un enum cerrado a un régimen específico.
- **Afiliación histórica** (`social_security_affiliations`): vínculo entre un empleado y una entidad externa, con `start_date`/`end_date`, siguiendo el patrón "effective-dated lookup" documentado en [04-DOMAIN-MODEL.md](./04-DOMAIN-MODEL.md) — igual que `employment_contracts` o `employee_schedules`.
- **Aporte calculado** (`social_security_contributions`): monto resultante de aplicar una regla vigente sobre una base de cálculo, para una liquidación (`payroll_entries`) concreta.
- **Base de cálculo**: el monto sobre el cual se calcula un aporte (típicamente derivado del salario devengado en la liquidación). El brief no define qué conceptos salariales integran esa base en ningún régimen específico; esa definición vive, igual que cualquier otro parámetro legal, en reglas vigentes versionadas (ver Reglas), nunca hardcodeada.

## Entidades

| Entidad | Rol en el módulo |
|---|---|
| `social_security_entities` | Catálogo de entidades externas a las que un empleado puede afiliarse (global o por empresa, según `company_id` nullable). |
| `social_security_affiliations` | Historial de afiliación de un empleado a una entidad, con vigencia por fecha. |
| `social_security_contributions` | Aporte calculado y trazado a la `payroll_entry` que lo originó. |
| `social_security_concept_definitions` | Catálogo de conceptos de aporte (ej. distintos tipos de aporte dentro de una misma entidad), análogo en propósito a `payroll_concept_definitions`. |

El esquema completo (columnas, tipos, aislamiento, mutabilidad) de estas cuatro tablas vive en [05-DATABASE.md](./05-DATABASE.md); este archivo documenta su comportamiento funcional, no las repite.

## Reglas

- **Los aportes se calculan siempre a partir de reglas vigentes versionadas, nunca de porcentajes hardcodeados en código.** Esto es una extensión directa del mismo principio aplicado a `labor_rules`/`labor_rule_versions` en [09-TIME-CALCULATION.md](./09-TIME-CALCULATION.md) y [10-PAYROLL.md](./10-PAYROLL.md) (ADR-007, ADR-020): ningún porcentaje de aporte, tope, ni fórmula de base de cálculo se escribe directamente en el motor de cálculo. Aunque la legislación objetivo ya es Colombia (ADR-023), los porcentajes y fórmulas exactas siguen sin validarse profesionalmente (ver Alcance): no existe ningún valor de ejemplo en este documento ni debe existir ninguno en el código hasta esa validación.
- **Un aporte (`social_security_contributions`) siempre queda trazable a la liquidación (`payroll_entries`) que lo generó.** Esto no es opcional: la FK `payroll_entry_id` es obligatoria en `social_security_contributions` (ver [05-DATABASE.md](./05-DATABASE.md), donde la tabla está marcada como `INMUTABLE por ser derivado de una liquidación`, siguiendo las mismas reglas de mutabilidad que `payroll_entries`). Un aporte nunca se calcula ni se persiste de forma aislada, desconectado de un periodo de nómina concreto.
- Un empleado puede tener **como máximo una afiliación activa por entidad/tipo en una fecha dada**; la resolución de cuál afiliación aplica para un cálculo usa el mismo patrón "effective-dated lookup" que `employment_contracts`.

## Flujos

1. **Afiliar a un empleado a una entidad**: se crea una fila en `social_security_affiliations` con `start_date` (y `end_date` nulo mientras esté activa). Un cambio de entidad no sobrescribe la afiliación anterior: se cierra la vigente (se le asigna `end_date`) y se abre una nueva fila, preservando el historial completo — mismo patrón que el cierre de un `employment_contract`.
2. **Calcular aportes al cerrar un periodo de nómina** (integrado con el paso "Calcular" del flujo de cierre de [10-PAYROLL.md](./10-PAYROLL.md)): durante el cálculo de una `payroll_entry`, el motor de Payroll consulta la afiliación vigente del empleado a la fecha del periodo, resuelve la base de cálculo y la regla de aporte vigente, y genera las filas correspondientes en `social_security_contributions`, enlazadas a esa `payroll_entry`. Este cálculo ocurre en el mismo ciclo `OPEN → CALCULATED → CLOSED` de Payroll; no tiene un ciclo de vida propio independiente del periodo de nómina.
3. **Reportar aportes por periodo**: consulta de solo lectura (expuesta vía [13-REPORTS.md](./13-REPORTS.md)) que agrega `social_security_contributions` por periodo, entidad o empleado, respetando el aislamiento por `company_id`.

## Casos normales

- Empleado con una única afiliación activa, vigente durante todo el periodo de nómina que se está calculando: el aporte se calcula una sola vez por entidad, con la base de cálculo derivada de la liquidación del periodo.

## Casos especiales

- **Empleado con múltiples afiliaciones históricas a lo largo del tiempo** (ej. cambió de entidad hace dos años): el cálculo de un periodo siempre resuelve la afiliación vigente **a la fecha del periodo que se está liquidando**, no la afiliación actual del empleado al momento de ejecutar el cálculo. Las afiliaciones cerradas permanecen consultables para efectos de reportes históricos, nunca se eliminan.
- **Cambio de entidad de afiliación a mitad de un periodo de nómina**: igual que el caso de contrato partido a mitad de periodo en [10-PAYROLL.md](./10-PAYROLL.md), el esquema debe soportar más de una fila de `social_security_contributions` para el mismo empleado y periodo, una por cada entidad vigente en su respectivo sub-rango.
  - **PENDING DECISION**: si el prorrateo del aporte en este caso sigue el mismo criterio (aún no definido) que el prorrateo salarial de contrato partido en [10-PAYROLL.md](./10-PAYROLL.md), o si depende de una regla propia de la entidad de seguridad social. Ninguna de las dos está resuelta por el brief.
    - **Criterio provisional implementado** (Fase 10, `PayrollCalculationService::socialSecurityContributionLines()`): `base_amount` de cada sub-rango = `base_total_del_periodo × (días_calendario_del_sub-rango / días_calendario_del_periodo)` — el mismo criterio de días calendario ya usado para el prorrateo salarial de contrato partido en [10-PAYROLL.md](./10-PAYROLL.md), aplicado por simplicidad de implementación, no porque exista una razón legal para asumir que ambos prorrateos deban coincidir. Sigue explícitamente **sin resolver**: esto es una decisión de ingeniería para poder calcular algo hoy, no una validación profesional de que la seguridad social colombiana efectivamente prorratea así.

## Errores

- **Cálculo de aporte sin afiliación activa vigente para la fecha**: si al calcular una `payroll_entry` no existe ninguna `social_security_affiliation` vigente para el empleado en la fecha del periodo (o el sub-rango correspondiente), el cálculo de aportes para ese empleado se rechaza con un error explícito. No se asume una entidad por defecto ni se omite el aporte en silencio — es coherente con el mismo principio de "nunca adivinar" aplicado al contrato ambiguo en [10-PAYROLL.md](./10-PAYROLL.md).

## Seguridad

- Acceso restringido mediante el permiso `social_security.manage` (ver matriz de roles en [06-AUTHORIZATION.md](./06-AUTHORIZATION.md)): la gestión de afiliaciones y la consulta de aportes calculados son datos sensibles asociados a identidad y salario del empleado.
- Toda alta, cierre o modificación de una `social_security_affiliation`, así como cualquier cálculo de `social_security_contributions`, es una acción sensible que genera `audit_logs` de forma obligatoria (ver [16-AUDIT.md](./16-AUDIT.md)); si el registro de auditoría falla, la operación debe abortar (ADR-018).

## Dependencias

- [04-DOMAIN-MODEL.md](./04-DOMAIN-MODEL.md) — patrón "effective-dated lookup" reutilizado para afiliaciones.
- [05-DATABASE.md](./05-DATABASE.md) — esquema exacto de las cuatro tablas de este módulo.
- [10-PAYROLL.md](./10-PAYROLL.md) — disparador del cálculo de aportes durante el cierre de un periodo, y consumidor de `social_security_contributions` como líneas de deducción/carga en la liquidación.

## Criterios de aceptación

- [x] Ningún porcentaje ni tasa de aporte de seguridad social existe hardcodeado en el motor de cálculo; todos se resuelven contra reglas vigentes versionadas (`labor_rules`/`labor_rule_versions` reutilizadas, `rule_type = 'SOCIAL_SECURITY_' . code`).
- [x] Toda fila de `social_security_contributions` tiene una `payroll_entry_id` no nula que la enlaza a la liquidación que la generó.
- [x] Un empleado con múltiples afiliaciones históricas resuelve siempre la afiliación vigente a la fecha del periodo calculado, no la afiliación actual (`SocialSecurityAffiliation::activeFor()`).
- [x] Un cambio de entidad a mitad de periodo produce múltiples filas de `social_security_contributions` correctamente atribuidas a cada sub-rango (el criterio exacto de prorrateo queda `PENDING DECISION`, ver Casos especiales — se implementó un criterio provisional de días calendario).
- [x] Un cálculo sin afiliación activa vigente para la fecha se rechaza explícitamente, nunca se omite en silencio (`NoActiveSocialSecurityAffiliationException`, bloquea solo al empleado afectado).
- [x] El acceso a datos de afiliación y aporte respeta `social_security.manage` y queda auditado en `audit_logs`.
