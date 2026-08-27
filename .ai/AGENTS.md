# AGENTS.md — Guía Rectora para Agentes de IA

## Qué es la carpeta `.ai/`

`.ai/` es el cerebro documental del proyecto SalonFamiliarSAAS. Contiene 27 archivos que definen, de forma completa y sin ambigüedad, el dominio, la arquitectura, el modelo de datos, los módulos, los flujos críticos, las decisiones arquitectónicas, el roadmap y el alcance MVP del sistema **antes** de que exista una sola línea de código de producción.

Existe porque este proyecto será construido por múltiples agentes de IA, probablemente en sesiones distintas y sin memoria compartida entre sí. Sin una fuente de verdad escrita, cada agente tendería a redescubrir (o reinventar, de forma inconsistente) las mismas decisiones: cómo se calcula una hora extra, qué tabla es la fuente de verdad de la asistencia, qué permiso hace falta para cerrar una nómina. `.ai/` elimina esa reinvención: es el contrato que todo agente debe leer antes de escribir código y debe mantener actualizado si su trabajo cambia el dominio, la arquitectura o el modelo de datos.

Todo el contenido de `.ai/` proviene de un blueprint de diseño ya aprobado. Ningún archivo de esta carpeta debe contradecirlo; si un agente detecta una inconsistencia entre archivos, debe señalarla explícitamente en vez de resolverla por su cuenta.

## Mapa de lectura: los 27 archivos

| # | Archivo | Contenido | Cuándo consultarlo |
|---|---|---|---|
| — | [AGENTS.md](./AGENTS.md) | Este documento: reglas no negociables, protocolo de `PENDING DECISION`, checklist de Definición de Hecho | Siempre, antes de cualquier tarea |
| 00 | [00-SYSTEM-SPECIFICATION.md](./00-SYSTEM-SPECIFICATION.md) | Visión general del sistema, las 8 capas del principio fundamental, glosario canónico de términos | Antes de tocar cualquier módulo; para resolver el significado exacto de un término |
| 01 | [01-VISION.md](./01-VISION.md) | Objetivo de producto, problema que resuelve, perfiles de usuario, mercado inicial | Al diseñar UX o priorizar features |
| 02 | [02-REQUIREMENTS.md](./02-REQUIREMENTS.md) | Requisitos funcionales por módulo, no funcionales, de datos, restricciones tecnológicas | Al implementar cualquier módulo, para validar alcance |
| 03 | [03-ARCHITECTURE.md](./03-ARCHITECTURE.md) | Estilo arquitectónico (monolito modular), capas, comunicación entre módulos, estructura de carpetas | Antes de crear cualquier estructura de proyecto o módulo nuevo |
| 04 | [04-DOMAIN-MODEL.md](./04-DOMAIN-MODEL.md) | Catálogo completo de entidades (~52 tablas), patrones canónicos, ciclos de vida | Antes de modelar o modificar cualquier entidad de dominio |
| 05 | [05-DATABASE.md](./05-DATABASE.md) | Esquema PostgreSQL tabla por tabla, aislamiento, soft-delete, mutabilidad, índices | Antes de escribir cualquier migración o query |
| 06 | [06-AUTHORIZATION.md](./06-AUTHORIZATION.md) | Authentication + Authorization: identidad global, membership, RBAC, sesiones | Al implementar login, permisos o control de acceso |
| 07 | [07-ATTENDANCE.md](./07-ATTENDANCE.md) | Eventos de asistencia, ajustes, inmutabilidad, deduplicación | Al tocar captura o corrección de marcaciones |
| 08 | [08-SHIFTS.md](./08-SHIFTS.md) | Jornadas planificadas, turnos, asignaciones, casos de turno nocturno/partido | Al generar o modificar turnos |
| 09 | [09-TIME-CALCULATION.md](./09-TIME-CALCULATION.md) | Motor de cálculo de tiempo, marco general de reglas laborales | Antes de escribir o modificar cualquier cálculo de horas |
| 10 | [10-PAYROLL.md](./10-PAYROLL.md) | Motor de nómina, periodos, cierre, ajustes post-cierre, traducción de reglas a dinero | Antes de escribir o modificar cualquier cálculo de nómina |
| 11 | [11-SOCIAL-SECURITY.md](./11-SOCIAL-SECURITY.md) | Afiliaciones y aportes de seguridad social | Al calcular o reportar aportes |
| 12 | [12-BIOMETRICS.md](./12-BIOMETRICS.md) | `BiometricProvider`, enrolamiento, seguridad y privacidad biométrica | Al integrar cualquier dispositivo o dato biométrico |
| 13 | [13-REPORTS.md](./13-REPORTS.md) | Reglas de reportes de solo lectura, filtros, RBAC de datos personales | Al construir cualquier endpoint de reporte |
| 14 | [14-PDF.md](./14-PDF.md) | Generación de comprobantes/reportes en PDF, versionado de documentos | Al generar cualquier documento oficial |
| 15 | [15-MULTI-TENANCY.md](./15-MULTI-TENANCY.md) | Aislamiento por `company_id`, resolución de empresa activa, excepción de `SUPER_ADMIN` | En toda tarea que toque datos tenant-scoped |
| 16 | [16-AUDIT.md](./16-AUDIT.md) | Acciones auditables, inmutabilidad de `audit_logs`, comportamiento ante fallo de auditoría | Antes de escribir cualquier acción sensible |
| 17 | [17-NOTIFICATIONS.md](./17-NOTIFICATIONS.md) | Canales, plantillas, eventos disparadores de notificación | Al integrar envío de alertas |
| 18 | [18-API.md](./18-API.md) | Convenciones REST, versionado `/api/v1`, paginación, errores estándar | Antes de diseñar cualquier endpoint |
| 19 | [19-FRONTEND.md](./19-FRONTEND.md) | Módulos de UI, separación planificado/real/calculado, prohibición de cálculos en cliente | Al construir cualquier vista o componente |
| 20 | [20-SECURITY.md](./20-SECURITY.md) | Seguridad transversal: cifrado, secretos, rate limiting, respuesta a incidentes | En toda tarea con datos sensibles o superficie de ataque |
| 21 | [21-TESTING.md](./21-TESTING.md) | Estrategia de testing, casos obligatorios, cobertura mínima por módulo | Antes de dar por cerrada cualquier tarea de implementación |
| 22 | [22-DEPLOYMENT.md](./22-DEPLOYMENT.md) | Entornos, pipeline CI/CD, migraciones en producción, rollback | Al tocar infraestructura o pipeline de despliegue |
| 23 | [23-DECISIONS.md](./23-DECISIONS.md) | Registro de 42 ADRs con contexto, decisión, motivo, alternativas, consecuencias | Antes de cuestionar o revertir una decisión arquitectónica ya tomada |
| 24 | [24-ROADMAP.md](./24-ROADMAP.md) | 16 fases (0–15) con objetivos, tareas, dependencias, criterios de aceptación | Para saber en qué fase está el proyecto y qué depende de qué |
| 25 | [25-MVP-SCOPE.md](./25-MVP-SCOPE.md) | Qué tareas del roadmap son `[MVP]` (construir primero) vs `[POST-MVP]` (agregar después de salir a producción) | **Antes de implementar cualquier tarea** — para confirmar que no se está adelantando algo `[POST-MVP]` sin que el corte MVP esté completo |

## Reglas no negociables

Cada regla existe para prevenir un error concreto que este dominio no puede permitirse (dinero mal pagado, datos cruzados entre empresas, históricos corrompidos).

1. **Leer la documentación relevante antes de implementar.** Evita reinventar una decisión ya tomada o contradecir una regla ya documentada. Antes de tocar un módulo, leer su archivo `.ai/` correspondiente y `00-SYSTEM-SPECIFICATION.md`.
2. **No implementar funcionalidades no especificadas sin documentar la decisión.** Toda funcionalidad nueva debe rastrearse hasta un requisito en `02-REQUIREMENTS.md` o un ADR en `23-DECISIONS.md`. Si no existe, se documenta primero.
3. **No modificar la arquitectura sin actualizar `03-ARCHITECTURE.md`.** La arquitectura es un contrato entre módulos; cambiarla sin documentarla rompe la coherencia que otros agentes asumen como verdadera.
4. **No modificar el modelo de datos sin actualizar `05-DATABASE.md` y `04-DOMAIN-MODEL.md`.** Estos dos archivos deben reflejar exactamente el esquema real en todo momento; una divergencia entre documentación y esquema real es peor que no tener documentación.
5. **No poner lógica de negocio crítica (cálculo de tiempo, cálculo de nómina) en el frontend.** El backend es la única fuente de verdad para cálculos (ver `09-TIME-CALCULATION.md`, `10-PAYROLL.md`, `19-FRONTEND.md`). El frontend solo formatea y muestra resultados ya calculados.
6. **No duplicar lógica de negocio entre módulos.** Cada regla de cálculo o de negocio vive en un solo lugar (ver los patrones canónicos de `04-DOMAIN-MODEL.md`). Duplicarla crea el riesgo de que dos copias diverjan silenciosamente.
7. **Nunca eliminar históricos de asistencia.** `attendance_events` es inmutable: solo se inserta, nunca se actualiza ni se borra (ver `07-ATTENDANCE.md`, ADR-003). Es el registro legal de lo que realmente ocurrió.
8. **Nunca modificar una nómina cerrada silenciosamente.** Una vez que `payroll_periods.status = CLOSED`, `payroll_entries` y `payroll_entry_lines` son de solo lectura a nivel de aplicación; toda corrección pasa por `payroll_adjustments` (ver `10-PAYROLL.md`, ADR-012).
9. **Toda modificación sensible debe quedar en `audit_logs`.** Si la escritura del log de auditoría falla, la transacción de negocio debe abortar (ver `16-AUDIT.md`, ADR-018). Sin esto, no hay forma de responder "quién cambió qué y por qué".
10. **Todo cálculo importante debe tener tests antes de mergear.** Los motores de Time Calculation y Payroll concentran el mayor riesgo del sistema (dinero y cumplimiento legal); no se mergea código de estos motores sin cobertura de los casos obligatorios listados en `21-TESTING.md`.
11. **Antes de crear una abstracción nueva, revisar si ya existe una equivalente.** Los patrones "effective-dated lookup" y "evento + ajuste" ya están documentados en `04-DOMAIN-MODEL.md` y se reutilizan; no se inventan variantes paralelas.
12. **Mantener la separación de módulos definida en `03-ARCHITECTURE.md`.** Cada módulo tiene límites explícitos de "qué hace" y "qué NO debe hacer"; cruzar esos límites reintroduce acoplamiento que el diseño modular busca evitar.
13. **Mantener multi-tenancy (aislamiento por `company_id`) en todo momento.** Ninguna query, endpoint o vista puede exponer datos de una empresa a otra, incluyendo por error de desarrollo (ver `15-MULTI-TENANCY.md`).
14. **No introducir dependencias innecesarias.** Cada librería o servicio externo agrega superficie de ataque y costo de mantenimiento; se justifica antes de agregarla.
15. **No asumir reglas legales o porcentajes que no estén documentados.** Ningún porcentaje de recargo, tasa de aporte o regla laboral se hardcodea; todo vive en `labor_rules`/`labor_rule_versions` configurables (ver ADR-007, ADR-020). Si la legislación aplicable no está definida, es un `PENDING DECISION`, no un valor de ejemplo.
16. **Ante una ambigüedad funcional real, documentarla como `PENDING DECISION` antes de implementar, nunca inventar.** Esta es la regla más importante del proyecto: si el comportamiento correcto no está escrito en ningún archivo `.ai/`, no se adivina ni se implementa "lo más razonable" sin dejar rastro escrito de la ambigüedad.
17. **Construir el corte `[MVP]` de [25-MVP-SCOPE.md](./25-MVP-SCOPE.md) antes que cualquier tarea `[POST-MVP]`** (ADR-041 en [23-DECISIONS.md](./23-DECISIONS.md)), sin importar el orden en que [24-ROADMAP.md](./24-ROADMAP.md) presente las fases. No implementar Biometrics, Notifications activas ni reportes avanzados antes de que el flujo esencial (turnos → asistencia → nómina → pago) funcione en producción, salvo pedido explícito del propietario del producto.

## Protocolo para declarar un nuevo `PENDING DECISION`

Cuando un agente encuentre, durante la implementación, una ambigüedad funcional que no esté ya cubierta por un `PENDING DECISION` existente:

1. **Ubicar el archivo correcto.** El `PENDING DECISION` se agrega en el archivo `.ai/` cuyo dominio cubre la ambigüedad (por ejemplo, una duda sobre el algoritmo de redondeo va en `09-TIME-CALCULATION.md`, no en `10-PAYROLL.md`). Si la ambigüedad es transversal, se agrega en `00-SYSTEM-SPECIFICATION.md`.
2. **Formato exacto.** Se escribe como línea o bloque independiente, literal:
   ```
   **PENDING DECISION**: <la pregunta abierta exacta, formulada de forma que se pueda responder con una decisión concreta>
   ```
3. **No resolverlo por cuenta propia.** El agente implementa alrededor de la ambigüedad (por ejemplo, con un valor de configuración explícito y documentado como placeholder, nunca oculto) o detiene esa porción de trabajo, pero no inventa la respuesta ni la deja implícita en el código.
4. **Registrar el impacto.** Si la ambigüedad bloquea una tarea del roadmap (`24-ROADMAP.md`), anotar en esa fase qué tarea queda bloqueada y por cuál `PENDING DECISION`.
5. **No eliminar un `PENDING DECISION` existente.** Solo se remueve cuando el usuario (humano, dueño del producto) provee la decisión explícitamente; en ese momento se reemplaza por la decisión tomada y, si corresponde, se agrega un ADR nuevo en `23-DECISIONS.md`.

## Checklist de "Definición de Hecho"

Antes de dar por cerrada una tarea de implementación, verificar:

- [ ] La tarea corresponde a un requisito documentado (`02-REQUIREMENTS.md`) o a una fase del roadmap (`24-ROADMAP.md`).
- [ ] Si la tarea toca el motor de cálculo de tiempo o el motor de nómina, existen tests que cubren los casos obligatorios relevantes de `21-TESTING.md` (nocturno, cruce de medianoche, descansos, tolerancias, contrato partido a mitad de periodo, cierre + corrección posterior, etc.) y todos pasan.
- [ ] Si la tarea ejecuta una acción sensible (definida en `16-AUDIT.md`), se genera exactamente un registro correcto en `audit_logs` con usuario, valor anterior, valor nuevo y motivo.
- [ ] Si la tarea toca el modelo de datos, `04-DOMAIN-MODEL.md` y `05-DATABASE.md` están actualizados y siguen siendo coherentes entre sí.
- [ ] Si la tarea toca límites de módulo o comunicación entre módulos, `03-ARCHITECTURE.md` refleja el cambio.
- [ ] No hay ninguna consulta, endpoint o vista que permita fuga de datos entre `company_id` distintos (verificar contra `15-MULTI-TENANCY.md`).
- [ ] Ningún cálculo de horas, recargos, deducciones o neto a pagar ocurre en el frontend.
- [ ] No se introdujo ningún valor legal, porcentaje o regla laboral hardcodeado sin respaldo en `labor_rules`/`labor_rule_versions` o sin quedar marcado como `PENDING DECISION`.
- [ ] Ninguna ambigüedad funcional encontrada durante la tarea quedó resuelta "en silencio"; toda ambigüedad real fue declarada como `PENDING DECISION` siguiendo el protocolo anterior.
- [ ] Si la tarea introduce un `UPDATE`/`DELETE` sobre una tabla marcada `INMUTABLE` o `AJUSTE` en `05-DATABASE.md`, fue rechazada y reemplazada por el patrón evento+ajuste correspondiente.

## Antipatrones explícitos prohibidos

- **Calcular horas extra (o cualquier hora ordinaria/faltante) en el frontend.** El frontend recibe `attendance_records` ya calculados por el backend; no debe sumar, restar ni aplicar tolerancias por su cuenta (ver `09-TIME-CALCULATION.md`, `19-FRONTEND.md`).
- **Hacer `UPDATE` o `DELETE` directo sobre `attendance_events`.** Cualquier corrección pasa exclusivamente por `attendance_adjustments`; el evento original permanece intacto (ver `07-ATTENDANCE.md`, ADR-003).
- **Hardcodear un porcentaje de recargo nocturno, dominical o de horas extra en código de aplicación.** Todo porcentaje vive en `labor_rule_versions.parameters` y se resuelve por vigencia (ver `09-TIME-CALCULATION.md`, `10-PAYROLL.md`, ADR-007, ADR-020).
- **Sobrescribir un `payroll_entry` con `status = CLOSED`.** Toda corrección posterior al cierre se registra en `payroll_adjustments`, nunca editando la entrada original (ver `10-PAYROLL.md`, ADR-012).
- **Usar el `company_id` recibido como parámetro libre del cliente para decidir el alcance de una query.** El `company_id` efectivo se resuelve siempre desde la membership activa autenticada del usuario, nunca se confía en un valor enviado por el cliente (ver `15-MULTI-TENANCY.md`, `18-API.md`).
- **Omitir el registro en `audit_logs` porque "la acción no es tan sensible".** La lista de acciones auditables está definida en `16-AUDIT.md`; si una acción calza en esa lista, se audita sin excepción, y si el registro falla, la transacción se aborta (ADR-018).
- **Borrar físicamente (`hard-delete`) un `employee`, `employment_contract` o cualquier entidad con histórico de nómina o asistencia asociado.** Se usa soft-delete (`status`/`deleted_at`); el histórico debe seguir siendo consultable (ver ADR-010).
- **Inventar un valor de configuración legal (tasa de aporte, tope de horas, moneda por defecto) porque el brief no lo especifica.** Si no está documentado, se marca `PENDING DECISION`; no se asume un valor "razonable" de forma silenciosa (ver sección "Open Questions" de cada archivo aplicable).
- **Crear una segunda tabla o servicio que duplique `novelty_records`, `attendance_adjustments` o `payroll_adjustments` para un caso "especial".** Estos patrones ya son genéricos por diseño; un caso nuevo se modela extendiendo el tipo/catálogo existente, no creando una tabla paralela.

## Módulo → archivo(s) `.ai/` responsable(s)

| Módulo | Archivo(s) `.ai/` |
|---|---|
| Authentication | [06-AUTHORIZATION.md](./06-AUTHORIZATION.md) |
| Authorization | [06-AUTHORIZATION.md](./06-AUTHORIZATION.md) |
| Companies | [15-MULTI-TENANCY.md](./15-MULTI-TENANCY.md), [04-DOMAIN-MODEL.md](./04-DOMAIN-MODEL.md) |
| Branches | [15-MULTI-TENANCY.md](./15-MULTI-TENANCY.md), [04-DOMAIN-MODEL.md](./04-DOMAIN-MODEL.md) |
| Employees | [04-DOMAIN-MODEL.md](./04-DOMAIN-MODEL.md), [05-DATABASE.md](./05-DATABASE.md) |
| Positions | [04-DOMAIN-MODEL.md](./04-DOMAIN-MODEL.md) |
| Employment Contracts | [04-DOMAIN-MODEL.md](./04-DOMAIN-MODEL.md), [10-PAYROLL.md](./10-PAYROLL.md) |
| Work Schedules | [08-SHIFTS.md](./08-SHIFTS.md) |
| Shifts | [08-SHIFTS.md](./08-SHIFTS.md) |
| Attendance | [07-ATTENDANCE.md](./07-ATTENDANCE.md) |
| Time Calculation | [09-TIME-CALCULATION.md](./09-TIME-CALCULATION.md) |
| Overtime | [09-TIME-CALCULATION.md](./09-TIME-CALCULATION.md), [10-PAYROLL.md](./10-PAYROLL.md) |
| Absences / Leave | [09-TIME-CALCULATION.md](./09-TIME-CALCULATION.md), [10-PAYROLL.md](./10-PAYROLL.md) |
| Payroll | [10-PAYROLL.md](./10-PAYROLL.md) |
| Social Security | [11-SOCIAL-SECURITY.md](./11-SOCIAL-SECURITY.md) |
| Reports | [13-REPORTS.md](./13-REPORTS.md) |
| PDF | [14-PDF.md](./14-PDF.md) |
| Biometrics | [12-BIOMETRICS.md](./12-BIOMETRICS.md) |
| Notifications | [17-NOTIFICATIONS.md](./17-NOTIFICATIONS.md) |
| Audit | [16-AUDIT.md](./16-AUDIT.md) |
| Settings | [03-ARCHITECTURE.md](./03-ARCHITECTURE.md), [05-DATABASE.md](./05-DATABASE.md) |

Archivos transversales que aplican a todos los módulos: [00-SYSTEM-SPECIFICATION.md](./00-SYSTEM-SPECIFICATION.md), [03-ARCHITECTURE.md](./03-ARCHITECTURE.md), [15-MULTI-TENANCY.md](./15-MULTI-TENANCY.md), [16-AUDIT.md](./16-AUDIT.md), [18-API.md](./18-API.md), [20-SECURITY.md](./20-SECURITY.md), [21-TESTING.md](./21-TESTING.md), [23-DECISIONS.md](./23-DECISIONS.md), [24-ROADMAP.md](./24-ROADMAP.md).
