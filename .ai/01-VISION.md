# 01 — Visión del Producto

## Objetivo del producto

Dar a negocios con trabajadores por turnos una plataforma única donde la jornada planificada, la asistencia real y la nómina resultante estén siempre conectadas y sean siempre auditables, eliminando la reconciliación manual entre "lo que se planificó", "lo que realmente pasó" y "lo que se pagó". Ver el pipeline completo de 8 capas en [00-SYSTEM-SPECIFICATION.md](./00-SYSTEM-SPECIFICATION.md).

## Problema que resuelve

Los negocios con trabajadores por turnos —el caso de referencia inicial son las panaderías— tienen especial dificultad para controlar turnos, asistencia y nómina porque:

- Los turnos suelen cruzar medianoche, tener horarios partidos o cambiar con poca anticipación.
- La asistencia real se registra de forma manual o en sistemas desconectados de la planificación, generando discrepancias silenciosas entre "el turno que debía cubrirse" y "quién realmente trabajó y cuánto tiempo".
- El cálculo de horas ordinarias, extras y recargos (nocturno, dominical/festivo) se hace manualmente o con hojas de cálculo, con alto riesgo de error y sin trazabilidad de por qué se pagó un monto determinado.
- Una vez pagada la nómina, corregir un error retroactivamente suele significar sobrescribir cifras sin dejar rastro de qué cambió y por qué.

## Perfiles de usuario objetivo

- **Dueño de panadería (`COMPANY_OWNER`)**: necesita visibilidad total de su negocio, control de costos de personal y confianza en que la nómina se calcula correctamente sin tener que auditarla manualmente.
- **Gerente / RRHH (`HR_MANAGER`)**: gestiona empleados, contratos, turnos y aprueba ausencias; necesita que el sistema le muestre discrepancias entre lo planificado y lo real sin que él tenga que calcularlas.
- **Supervisor de turno (`SUPERVISOR`)**: opera el día a día en la sucursal, autoriza horas extra de su equipo y corrige marcaciones erróneas dentro de los límites de su permiso.
- **Trabajador (`EMPLOYEE`)**: marca su propia asistencia, consulta su horario asignado, sus horas trabajadas y su comprobante de pago, sin visibilidad sobre datos de otros trabajadores.

Los roles exactos y su matriz de permisos completa viven en [06-AUTHORIZATION.md](./06-AUTHORIZATION.md).

## Propuesta de valor

Un solo sistema donde jornada planificada, turno asignado, asistencia real, cálculo de tiempo y nómina forman una cadena continua y auditable (ver las 8 capas en [00-SYSTEM-SPECIFICATION.md](./00-SYSTEM-SPECIFICATION.md)), de modo que:

- Ninguna cifra de nómina aparece "de la nada": siempre se puede rastrear hasta los eventos de asistencia y las reglas laborales vigentes que la produjeron.
- Ninguna corrección borra historia: los ajustes y correcciones dejan rastro completo (ver ADR-003, ADR-012 en [23-DECISIONS.md](./23-DECISIONS.md)).
- El sistema sirve a una empresa con múltiples sucursales y turnos complejos (nocturnos, partidos, que cruzan medianoche) sin fricción adicional.

## Principios de diseño

- **Separar planificado, real y calculado.** Turno planificado, evento de asistencia real y registro de tiempo calculado son tres capas distintas, nunca se mezclan ni se sobrescriben entre sí (ver [00-SYSTEM-SPECIFICATION.md](./00-SYSTEM-SPECIFICATION.md)).
- **Todo auditable.** Toda acción sensible sobre datos de asistencia, nómina o permisos queda registrada de forma inmutable (ver [16-AUDIT.md](./16-AUDIT.md)).
- **Escalable a otros rubros.** Aunque el caso de referencia inicial son las panaderías, el modelo de dominio (turnos, jornadas, reglas laborales configurables) no asume ninguna particularidad exclusiva de panaderías; está diseñado para generalizarse a cualquier negocio con trabajadores por turnos.

## Mercado inicial y expansión futura

- **Mercado inicial**: panaderías, como caso de uso de referencia para validar el modelo de turnos, asistencia y nómina.
- **Expansión futura**: restaurantes, comercios y otras empresas organizadas por turnos, reutilizando el mismo modelo de dominio sin cambios estructurales, solo configuración (plantillas de jornada, reglas laborales, conceptos salariales propios de cada empresa).

## Fuera de alcance v1

- Facturación del propio SaaS a las empresas clientes (billing del producto en sí) no está definida en el brief.
- Soporte multi-país o multi-legislación simultáneo: **RESUELTO** — la v1 apunta exclusivamente a Colombia (ver ADR-023 en [23-DECISIONS.md](./23-DECISIONS.md)); multi-país queda como expansión futura explícita, no como parte del alcance actual.
- App móvil nativa: **RESUELTO** — no habrá app móvil nativa en la v1, solo web responsive (ver ADR-029 en [23-DECISIONS.md](./23-DECISIONS.md)).

## Métricas de éxito

**PARCIALMENTE RESUELTO**: el propietario del producto identificó **precisión — reducción de errores de pago** (medida como cantidad de `payroll_adjustments` post-cierre por periodo; menos ajustes = cálculo más confiable) como la métrica de mayor interés. El resto del marco de métricas de éxito (adopción, eficiencia operativa, retención) sigue **PENDING DECISION**, a definir cuando el producto tenga uso real que medir.

**RESUELTO**: Existencia de app móvil nativa vs. solo web responsive — solo web responsive en la v1 (ver ADR-029 en [23-DECISIONS.md](./23-DECISIONS.md)). La estrategia de modo offline del frontend web sigue como `PENDING DECISION` independiente (ver [19-FRONTEND.md](./19-FRONTEND.md)).

## Relación con el resto de la documentación

Este documento fija el "por qué" del producto. El "qué" exacto se detalla módulo por módulo en [02-REQUIREMENTS.md](./02-REQUIREMENTS.md), el "cómo" arquitectónico en [03-ARCHITECTURE.md](./03-ARCHITECTURE.md), y el orden de construcción en [24-ROADMAP.md](./24-ROADMAP.md). El glosario de términos usado en este documento es el fijado en [00-SYSTEM-SPECIFICATION.md](./00-SYSTEM-SPECIFICATION.md).
