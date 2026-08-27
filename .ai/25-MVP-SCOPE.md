# 25-MVP-SCOPE.md — Alcance mínimo viable para producción

## Objetivo

Definir qué subconjunto del sistema descrito en el resto de `.ai/` debe construirse **primero**, para que SalonFamiliarSAAS pueda salir a producción y ser usado de verdad por una empresa real, y qué queda deliberadamente para después. [24-ROADMAP.md](./24-ROADMAP.md) describe la visión completa del sistema (16 fases); este documento no la reemplaza — la **prioriza**.

## Alcance

Aplica a la ejecución de las Fases 1 a 15 del roadmap. No cambia ninguna decisión de arquitectura, modelo de dominio o ADR ya tomado — el sistema se sigue diseñando completo (ver [04-DOMAIN-MODEL.md](./04-DOMAIN-MODEL.md)) para que agregar lo que queda fuera del MVP después no requiera rediseño ni migraciones destructivas. Lo único que cambia es el **orden y el corte de qué se construye primero**.

## Principio rector

**Construir solo lo mínimo necesario para que una empresa real pueda operar en producción de punta a punta — dar de alta empleados, armar turnos, fichar asistencia, calcular nómina, cerrarla y pagar — y agregar el resto de forma incremental después.** No se construyen módulos, integraciones o funciones "por si acaso" antes de que el corte de producción esté funcionando y desplegado (consistente con YAGNI, ya establecido como principio general del proyecto).

Regla operativa para cualquier agente que implemente: **priorizar siempre las tareas marcadas `[MVP]` sobre las marcadas `[POST-MVP]`**, incluso si el roadmap las presenta en otro orden. No implementar una tarea `[POST-MVP]` antes de que el corte `[MVP]` completo esté funcionando en producción, salvo pedido explícito del propietario del producto.

## Criterio de "listo para producción" (Definition of Done del MVP)

El MVP está completo cuando una empresa (panadería) puede, de punta a punta, sin intervención manual fuera del sistema:

1. Crear su cuenta y configurar su empresa (una sola empresa por cuenta, ver ADR-024).
2. Dar de alta trabajadores, cargos y contratos.
3. Definir jornadas/plantillas de turno y asignar turnos a los trabajadores.
4. Registrar asistencia real (entrada, descansos, salida) por web/manual — **sin biometría todavía**.
5. Ver que el sistema calcula correctamente horas ordinarias, extras y faltantes a partir de lo planificado vs. lo marcado.
6. Aprobar/rechazar novedades básicas (ausencias, permisos, horas extra).
7. Calcular y cerrar una quincena de nómina completa, incluyendo aportes de seguridad social.
8. Descargar el comprobante de nómina en PDF.
9. Todo lo anterior aislado por empresa (multi-tenant) y con las acciones sensibles quedando auditadas.

Si estos 9 puntos funcionan en producción, el MVP está cumplido — el resto es incremental.

## Corte por fase del roadmap

| Fase | Corte | Detalle |
|---|---|---|
| 0 — Documentación | ✅ Completa | Ya hecha (este mismo corpus `.ai/`). |
| 1 — Foundation | `[MVP]` | Completa, sin recortes — es la base de todo lo demás. |
| 2 — Auth/usuarios | `[MVP]` | Login, roles, permisos, multi-empresa por membership. Sin MFA (ADR-031, ya pospuesto). |
| 3 — Companies/Employees | `[MVP]` | Completa. |
| 4 — Contracts | `[MVP]` | Completa, incluyendo `salary_history`. |
| 5 — Schedules/Shifts | `[MVP]` | Completa: plantillas, turnos, asignaciones, nocturnos/cruce de medianoche/partidos (son parte del negocio real de una panadería, no un extra). |
| 6 — Attendance | `[MVP]` parcial | **MVP**: eventos vía `WEB`/`MANUAL`, mecanismo de ajuste completo (nunca se recorta, es el corazón de la confiabilidad del sistema). **POST-MVP**: la capa de PWA/offline de la fichada manual (ADR-037) — para el MVP alcanza con que la fichada funcione online; el modo offline se agrega después. |
| 7 — Time Calculation Engine | `[MVP]` | Completa, con su cobertura de tests alta (90%, ver [21-TESTING.md](./21-TESTING.md)) — es un motor crítico, no se recorta. |
| 8 — Overtime/Novedades | `[MVP]` parcial | **MVP**: ciclo de vida de horas extra y las novedades esenciales (ausencia, permiso, vacaciones, incapacidad, festivo). **POST-MVP**: catálogo extendido de tipos de novedad configurables por empresa más allá de los esenciales. |
| 9 — Payroll | `[MVP]` | Completa, con su cobertura de tests alta (90%) — es el otro motor crítico y la razón de ser del producto, no se recorta. |
| 10 — Social Security | `[MVP]` parcial | **MVP**: cálculo correcto de aportes (una vez validados los porcentajes reales, ver `PENDING DECISION` en [11-SOCIAL-SECURITY.md](./11-SOCIAL-SECURITY.md)) y su trazabilidad a la liquidación. **POST-MVP**: integración formal con DIAN/PILA (ya pospuesta explícitamente, ADR-030). |
| 11 — Reports/PDF | `[MVP]` parcial | **MVP**: únicamente el comprobante de nómina en PDF (es parte del flujo de pago, no un extra). **POST-MVP**: el resto de los reportes (asistencia, horas, costos laborales, historial de auditoría) y sus filtros avanzados. |
| 12 — Biometrics | `[POST-MVP]` completo | Ya se había decidido arrancar con un `BiometricProvider` mock (ADR-036); para el MVP de producción **ni siquiera el mock es necesario** — la fichada `WEB`/`MANUAL` de la Fase 6 alcanza para operar. El hardware biométrico real se conecta cuando el negocio lo requiera. |
| 13 — Audit/hardening | `[MVP]` parcial | **MVP**: el registro de auditoría transversal de acciones sensibles (crear/modificar/aprobar/cerrar nómina/ajustar asistencia/cambiar permisos) funcionando desde el día 1 — no es un extra, es parte del contrato de confianza del sistema (ver `AGENTS.md`). **POST-MVP**: hardening adicional de seguridad más allá de lo ya cubierto en [20-SECURITY.md](./20-SECURITY.md) (ej. revisiones de penetración, WAF avanzado). |
| 14 — Testing | `[MVP]` parcial | **MVP**: cobertura alta (90%) en Time Calculation y Payroll, y los casos obligatorios directamente relacionados con el corte MVP (multi-tenancy, permisos, auditoría, turnos nocturnos/cruce de medianoche). **POST-MVP**: cobertura extendida sobre módulos post-MVP (biometría real, reportes avanzados) a medida que se construyen. |
| 15 — Deployment | `[MVP]` | Completa — sin esto no hay producción. Laravel Cloud, plan Starter (ADR-021). |
| — Notifications | `[POST-MVP]` | El proveedor ya está decidido (Resend, ADR-039), pero el envío real de notificaciones no es indispensable para que el flujo de los 9 puntos de arriba funcione; se agrega apenas después del lanzamiento. |
| — DIAN/PILA, firma electrónica, app móvil, MFA | `[POST-MVP]` | Ya pospuestos explícitamente en ADR-030, ADR-028, ADR-029 y ADR-031 respectivamente — se reafirman aquí como fuera del corte de producción inicial. |

## Qué NO cambia por este documento

- El modelo de dominio y el esquema de base de datos ([04-DOMAIN-MODEL.md](./04-DOMAIN-MODEL.md), [05-DATABASE.md](./05-DATABASE.md)) se implementan **completos** desde el principio para las tablas que el corte MVP toca — no se crean tablas a medias ni se dejan columnas para "agregar después" de forma improvisada. Lo que se pospone es la **funcionalidad** (biometría real, reportes avanzados, notificaciones activas), no el modelo de datos que ya la contempla.
- Los límites de módulo de [03-ARCHITECTURE.md](./03-ARCHITECTURE.md) no cambian: un módulo `[POST-MVP]` (ej. Biometrics) sigue existiendo como módulo aparte en el código, simplemente no se activa/completa todavía.
- Ningún ADR se revierte por este documento.

## Errores a evitar

- Construir Biometrics, Notifications activas, o reportes avanzados **antes** de que el corte MVP de la tabla de arriba esté funcionando en producción.
- Interpretar "MVP" como excusa para bajar la calidad de los motores críticos (Time Calculation, Payroll) o para saltarse auditoría/multi-tenancy — esos tres NO se recortan bajo ninguna circunstancia, son el corazón de la confiabilidad del producto.
- Dejar de lado el modelo de datos completo de un módulo `[POST-MVP]` "para no complicar" el MVP — el esquema se diseña completo aunque la función se active después (evita migraciones destructivas cuando se retome ese módulo).

## Dependencias

- [24-ROADMAP.md](./24-ROADMAP.md): este documento prioriza sus fases, no las reemplaza.
- [AGENTS.md](./AGENTS.md): debe consultarse junto con este archivo antes de decidir qué implementar a continuación.
- [23-DECISIONS.md](./23-DECISIONS.md): ADR-041 registra esta estrategia de construcción.

## Criterios de aceptación

- Ninguna tarea `[POST-MVP]` se implementa antes de que los 9 puntos del "Criterio de listo para producción" funcionen end-to-end.
- Cada vez que se complete una fase o sub-alcance `[MVP]`, se marca como tal en este documento (ej. tachado o nota de fecha), para que quede claro qué falta del corte de producción.
- Si el propietario del producto pide explícitamente adelantar algo `[POST-MVP]`, se documenta la excepción aquí mismo con el motivo, en vez de romper el criterio en silencio.
