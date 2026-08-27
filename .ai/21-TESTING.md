# 21-TESTING.md — Testing

## Objetivo

Definir la estrategia de pruebas del sistema: qué tipos de test existen y cuándo usar cada uno, qué módulos requieren cobertura obligatoria más alta por su riesgo de negocio, el catálogo completo de casos de prueba obligatorios exigidos por el brief original, la convención de datos de prueba (fixtures/factories), y el flujo de integración continua (CI) que bloquea un merge si la cobertura cae por debajo del umbral definido.

## Alcance

Incluye: la diferencia conceptual entre pruebas unitarias, de integración y end-to-end (E2E) y cuándo aplica cada una en este proyecto; la estrategia de cobertura por módulo, con énfasis obligatorio en el Motor de Cálculo de Tiempo ([09-TIME-CALCULATION.md](./09-TIME-CALCULATION.md)) y el Motor de Nómina ([10-PAYROLL.md](./10-PAYROLL.md)); el catálogo textual completo de casos obligatorios exigidos por el brief; la convención conceptual de fixtures/factories por dominio; el flujo de CI que se ejecuta en cada pull request; y el criterio de bloqueo de merge por cobertura insuficiente.

No incluye: la implementación de un test específico; la configuración detallada del proveedor de CI/CD (ver [22-DEPLOYMENT.md](./22-DEPLOYMENT.md)).

**RESUELTO**: framework de testing — **Pest** (construido sobre PHPUnit, es el estándar idiomático actual para proyectos Laravel nuevos), con los helpers de testing de Laravel (factories, `RefreshDatabase`) para integración.

## Conceptos

- **Prueba unitaria**: verifica una única función, método o unidad de lógica de negocio en aislamiento total, sin base de datos real ni llamadas a red. En este proyecto se usa principalmente para el algoritmo del Motor de Cálculo de Tiempo (turnos nocturnos, cruce de medianoche, tolerancias, redondeo) y para las fórmulas de conceptos salariales/deducciones del Motor de Nómina, donde cada regla debe poder verificarse de forma determinista con datos de entrada controlados.
- **Prueba de integración**: verifica que dos o más componentes reales colaboran correctamente, típicamente contra una base de datos PostgreSQL real (o equivalente de test) y a través de la capa de servicio, sin mockear el almacenamiento. En este proyecto se usa para flujos completos dentro de un módulo o entre módulos adyacentes: crear un `attendance_adjustment` y verificar que el evento original permanece inmutable, cerrar un periodo de nómina y verificar que `payroll_entries` queda de solo lectura, verificar aislamiento entre dos empresas, verificar que un permiso ausente deniega una acción.
- **Prueba end-to-end (E2E)**: verifica un flujo de negocio completo tal como lo recorrería un usuario o un dispositivo real, atravesando API, capa de servicio y base de datos (y, cuando aplique, el Device Gateway biométrico) de punta a punta. En este proyecto se reserva para los flujos críticos documentados en el blueprint aprobado: fichada biométrica → cálculo de tiempo, corrección manual de asistencia, y cierre de un periodo de nómina con corrección posterior.
- **Cuándo usar cada una**: una unidad de cálculo aislada (una función del Motor de Cálculo de Tiempo o del Motor de Nómina) se prueba primero con pruebas unitarias exhaustivas; la interacción entre esa unidad y el resto del sistema (persistencia, permisos, aislamiento de tenant) se prueba con pruebas de integración; y solo los flujos de negocio completos de mayor riesgo se prueban también end-to-end. No se duplica exhaustivamente en E2E lo que ya está cubierto por unitarias e integración.

## Entidades

Este archivo no define entidades de dominio ni tablas nuevas (ver el catálogo completo en [04-DOMAIN-MODEL.md](./04-DOMAIN-MODEL.md) y [05-DATABASE.md](./05-DATABASE.md)). Define, en cambio, los siguientes artefactos conceptuales de testing, reutilizados de forma consistente en todo el proyecto:

| Artefacto | Propósito |
|---|---|
| Fixture | Conjunto de datos de prueba realistas y reutilizables para una entidad de dominio (ver convención de fixtures/factories en Reglas) |
| Factory | Función/constructor que genera una instancia válida de una entidad de dominio con valores por defecto sensatos, sobreescribibles por el test |
| Caso obligatorio | Escenario de prueba que el catálogo de esta sección exige cubrir sin excepción antes de mergear código que lo afecte |
| Suite de pruebas | Agrupación de tests por módulo o por tipo (unitaria/integración/E2E) |
| Reporte de cobertura | Salida de la corrida de tests que mide qué porcentaje del código está cubierto, usada como gate de CI |

## Reglas

### Estrategia de cobertura por módulo

- El **Motor de Cálculo de Tiempo** ([09-TIME-CALCULATION.md](./09-TIME-CALCULATION.md)) y el **Motor de Nómina** ([10-PAYROLL.md](./10-PAYROLL.md)) son los módulos de mayor riesgo de negocio del sistema (dinero mal calculado y/o mal pagado, incumplimiento legal); por lo tanto tienen el requisito de cobertura más alto y obligatorio de todo el proyecto. Ningún cambio a estos dos motores se mergea sin que todos los casos obligatorios de la sección siguiente relacionados con ellos estén cubiertos y en verde (ver regla no negociable #10 de [AGENTS.md](./AGENTS.md)).
- El resto de los módulos sigue el umbral general de cobertura definido en Criterios de aceptación.

### Convención de fixtures/factories

Los datos de prueba se generan mediante factories conceptuales por dominio, con valores realistas (no placeholders vacíos ni genéricos), para que un caso de prueba sea legible como un escenario de negocio real. Como mínimo, el proyecto debe tener una factory por cada una de estas entidades base, por ser las más reutilizadas entre módulos:

- **Empresa** (`companies`): datos legales mínimos válidos, usada como raíz de cualquier test que requiera aislamiento multi-tenant.
- **Empleado** (`employees`): asociado a una empresa, con contrato vigente por defecto.
- **Turno** (`shifts`): capaz de generar tanto un turno diurno estándar como uno que cruza medianoche, para no duplicar lógica de construcción en cada test especial.
- **Evento de asistencia** (`attendance_events`): capaz de generar `CLOCK_IN`/`BREAK_START`/`BREAK_END`/`CLOCK_OUT` en cualquier combinación y orden, incluyendo secuencias fuera de orden, para los casos especiales de [07-ATTENDANCE.md](./07-ATTENDANCE.md).
- **Periodo de nómina** (`payroll_periods`): capaz de generar un periodo en cualquiera de sus estados (`OPEN`, `CALCULATED`, `CLOSED`, `REOPENED`), para no reconstruir manualmente el flujo de cierre en cada test que solo necesita un periodo ya cerrado.

### Casos obligatorios

El siguiente catálogo, exigido explícitamente por el brief original, debe estar cubierto por al menos un test (unitario y/o de integración, según corresponda) antes de considerar cerrada cualquier tarea que lo afecte, sin excepción:

- Turnos nocturnos.
- Turnos que cruzan medianoche.
- Descansos dentro de una jornada.
- Horas extras en cada estado de su ciclo de vida: detectada, solicitada, autorizada, rechazada, pagada (ver [09-TIME-CALCULATION.md](./09-TIME-CALCULATION.md), [10-PAYROLL.md](./10-PAYROLL.md)).
- Ausencias.
- Cálculo de nómina completo, incluyendo el caso de contrato partido a mitad de periodo (ver flujo de determinación de contrato/salario en [10-PAYROLL.md](./10-PAYROLL.md)).
- Deducciones.
- Aportes de seguridad social (ver [11-SOCIAL-SECURITY.md](./11-SOCIAL-SECURITY.md)).
- Multi-tenancy: aislamiento entre 2 o más empresas, sin fuga de datos (ver [15-MULTI-TENANCY.md](./15-MULTI-TENANCY.md)).
- Permisos/RBAC: acceso denegado correctamente cuando falta el permiso requerido (ver [06-AUTHORIZATION.md](./06-AUTHORIZATION.md)).
- Auditoría: cada acción sensible genera exactamente un log correcto en `audit_logs` (ver [16-AUDIT.md](./16-AUDIT.md)).

Ninguno de estos casos es opcional ni puede omitirse por falta de tiempo; forman parte de la Definición de Hecho de cualquier tarea que los toque (ver checklist en [AGENTS.md](./AGENTS.md)).

## Flujos

### CI en cada pull request

En cada pull request se ejecuta, en este orden:

1. **Lint**: verificación de estilo y errores estáticos.
2. **Tests unitarios**: toda la suite unitaria, incluyendo el 100% de los casos obligatorios que apliquen a nivel unitario.
3. **Tests de integración**: toda la suite de integración contra una base de datos de test real.
4. **Verificación de cobertura**: si la cobertura resultante cae por debajo del umbral mínimo definido (ver Criterios de aceptación), el pipeline bloquea el merge (ver Errores).

Las pruebas E2E de los flujos críticos completos se ejecutan como parte de la validación de las fases del roadmap que las requieren explícitamente (ver Fase 14 en [24-ROADMAP.md](./24-ROADMAP.md)); su integración exacta dentro del pipeline de CI/CD se describe en [22-DEPLOYMENT.md](./22-DEPLOYMENT.md).

## Casos normales

- Un cambio en el Motor de Cálculo de Tiempo que agrega o modifica una regla pasa la suite unitaria completa de [09-TIME-CALCULATION.md](./09-TIME-CALCULATION.md), incluyendo los casos obligatorios de turno nocturno, cruce de medianoche y descansos, antes de mergearse.
- Un ajuste de asistencia (`attendance_adjustment`) se aprueba mientras el periodo de nómina correspondiente todavía está `OPEN`: el test de integración verifica que `attendance_records` se recalcula correctamente reflejando el valor corregido, sin alterar el `attendance_event` original (ver [07-ATTENDANCE.md](./07-ATTENDANCE.md)).

## Casos especiales

- **Marcaciones biométricas concurrentes/simultáneas**: dos o más eventos de marcación llegan al sistema en una ventana de tiempo muy corta (mismo empleado desde dispositivos redundantes, o distintos empleados en el mismo instante). El test de integración debe verificar que la deduplicación descrita en [07-ATTENDANCE.md](./07-ATTENDANCE.md) y [12-BIOMETRICS.md](./12-BIOMETRICS.md) evita crear `attendance_events` duplicados bajo condiciones de carrera, y que ninguna escritura concurrente corrompe el registro de staging `biometric_raw_events`.
- **Recálculo de asistencia tras un ajuste aprobado cuando el periodo de nómina ya está `CLOSED`**: el test de integración debe verificar que `attendance_records`/`overtime_records` sí se recalculan, pero que `payroll_entries` cerrados **no** se modifican automáticamente; en su lugar se genera la señal de "ajuste de nómina pendiente" que se resuelve vía `payroll_adjustments` (ver la contradicción 1 resuelta en el blueprint aprobado, y el flujo de cierre en [10-PAYROLL.md](./10-PAYROLL.md)).

## Errores

- **Bloqueo de merge por cobertura insuficiente**: si la cobertura de tests cae por debajo del umbral mínimo definido (ver Criterios de aceptación), el pipeline de CI debe bloquear el merge automáticamente; no existe un mecanismo de override manual documentado para saltarse este bloqueo.

## Seguridad

- El **aislamiento de tenant** (multi-tenancy) es un caso de prueba **obligatorio, no opcional**, para cualquier módulo o endpoint que toque datos tenant-scoped: todo pull request que afecte una tabla o endpoint con `company_id` debe incluir al menos un test de integración que verifique explícitamente que no hay fuga de datos entre dos o más empresas (ver [15-MULTI-TENANCY.md](./15-MULTI-TENANCY.md)).
- Las pruebas de **permisos/RBAC** deben verificar tanto el camino de acceso concedido como el de acceso denegado; un test que solo verifica el camino feliz de un endpoint protegido por permiso no se considera cobertura suficiente para ese endpoint (ver [06-AUTHORIZATION.md](./06-AUTHORIZATION.md)).
- Las pruebas de **auditoría** deben verificar que cada acción sensible genera exactamente un registro correcto en `audit_logs` (ni cero, ni duplicado), incluyendo el caso en que, según ADR-018 en [23-DECISIONS.md](./23-DECISIONS.md), la transacción de negocio debe abortar si la escritura del log falla.

## Dependencias

- Todos los módulos del sistema, ya que cada uno aporta al menos un caso obligatorio o un requisito de cobertura.
- Especialmente [09-TIME-CALCULATION.md](./09-TIME-CALCULATION.md) y [10-PAYROLL.md](./10-PAYROLL.md), por concentrar el mayor riesgo de negocio y el requisito de cobertura más alto.
- [07-ATTENDANCE.md](./07-ATTENDANCE.md) y [12-BIOMETRICS.md](./12-BIOMETRICS.md): casos de concurrencia y deduplicación.
- [15-MULTI-TENANCY.md](./15-MULTI-TENANCY.md) y [06-AUTHORIZATION.md](./06-AUTHORIZATION.md): casos obligatorios de aislamiento y permisos.
- [16-AUDIT.md](./16-AUDIT.md): caso obligatorio de auditoría.
- [22-DEPLOYMENT.md](./22-DEPLOYMENT.md): el pipeline de CI descrito aquí es la puerta de entrada al pipeline de CI/CD de despliegue.
- [AGENTS.md](./AGENTS.md): checklist de Definición de Hecho que exige cumplir esta estrategia de testing antes de cerrar una tarea.

## Criterios de aceptación

- Cada uno de los casos obligatorios listados en Reglas tiene al menos un test correspondiente, pasando en CI.
- `09-TIME-CALCULATION.md` y `10-PAYROLL.md` alcanzan el nivel de cobertura más alto exigido por el proyecto.
- El pipeline de CI ejecuta lint, tests unitarios y tests de integración en cada pull request, y bloquea el merge si la cobertura cae por debajo del umbral mínimo.
- El aislamiento de tenant y el RBAC tienen al menos un test obligatorio en cualquier pull request que los afecte.
- Cada acción sensible auditada genera exactamente un `audit_log` correcto, verificado por un test dedicado.

**RESUELTO**: Umbral de cobertura mínima — **80% global** (estándar ya adoptado por el propietario del producto en sus reglas de ingeniería) y **90% en los motores críticos** (`09-TIME-CALCULATION.md` y `10-PAYROLL.md`), dado su riesgo de negocio desproporcionado frente al resto del sistema.
