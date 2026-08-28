# 26 — Estado de Avance

## Objetivo

Registrar, de forma viva y siempre actualizada, en qué fase del [24-ROADMAP.md](./24-ROADMAP.md) está realmente el proyecto — a diferencia de ese archivo, que describe qué debe hacer cada fase pero no si ya se hizo. Existe porque este proyecto se construye en sesiones de agente distintas sin memoria compartida entre sí (ver [AGENTS.md](./AGENTS.md)): sin este archivo, la única forma de saber "¿por dónde vamos?" sería releer todo el historial de commits.

## Regla de mantenimiento

**Todo agente que complete o avance una fase debe actualizar este archivo en el mismo commit** (o inmediatamente después): mover la fase a su estado correcto, anotar el/los commit(s), y registrar cualquier decisión de implementación no obvia o deuda técnica generada. No se cierra una fase como "Completa" sin que haya pasado su checklist de Definición de Hecho (ver [AGENTS.md](./AGENTS.md)).

## Estado por fase

| Fase | Nombre | Estado | Commit(s) | Notas |
|---|---|---|---|---|
| 0 | Documentación `.ai/` | ✅ Completa | `9b30909` | 26 archivos + AGENTS.md; ampliado luego con `25-MVP-SCOPE.md` y este archivo |
| 1 | Foundation | ✅ Completa | `3cb5250`, `33eca47`, `2ce53c7`, `fd01bee` | Scaffold Laravel 13 + Inertia/Vue3/TS, Pest, Boost |
| 2 | Auth / Usuarios | ✅ Completa | `f76851d` | Fortify (sesión, sin 2FA/passkeys — ver ADR-031), RBAC vía `Gate::before` + 29 permisos / 8 roles seed |
| 3 | Companies / Employees | ✅ Completa | `fe75590` | Aislamiento de tenant probado (`BelongsToCompany` + `CurrentCompany`); **deuda de `Position`/`Branch` sin CRUD/UI resuelta** en `8bc28e2` (ver más abajo) |
| — | Traducción UI a español (ADR-043) | ✅ Completa | `9e247f0`, `4cffae4` | No es una fase del roadmap; se hizo entre Fase 3 y 4 a pedido explícito del usuario |
| 4 | Contracts | ✅ Completa | `61f8b1b` | `employment_contracts` + `salary_history` + `payroll_information`; effective-dated lookup con `AmbiguousContractException`; no-overlap reforzado en Postgres (EXCLUDE gist) y a nivel de request (fallback para sqlite en tests) |
| 5 | Schedules/Shifts | ✅ Completa | `37943c5`, `3330ec1`, `f750a66` | 6 entidades (`work_schedule_templates/days`, `employee_schedules`, `shifts`, `shift_assignments`, `shift_breaks`); generación desde plantilla (`ShiftGenerator`) con soporte de turnos nocturnos, dobles y partidos; solapamiento rechazado a nivel de servicio; `audit_logs` + `AuditLogger` adelantados de la Fase 13 para auditar reasignaciones excepcionales. UI de turnos manuales/partidos/dobles y reasignación agregada en `3330ec1`; descansos planificados a nivel de plantilla (con generación automática de `shift_breaks`) agregados en `f750a66`, cerrando el `PENDING DECISION` que tenía [08-SHIFTS.md](./08-SHIFTS.md) |
| 6 | Attendance | ✅ Completa (MVP) | `7e397fb`, `f9f6204` | Alcance MVP tal como define [25-MVP-SCOPE.md](./25-MVP-SCOPE.md): eventos de asistencia solo vía `WEB`/`MANUAL` (panel operado por RR.HH./supervisor, permiso nuevo `attendance.record` — se evaluó biometría de huella pero se confirmó con el usuario mantenerla en su lugar del roadmap, Fase 12/POST-MVP, ADR-036) + mecanismo de ajuste completo (`modify`/`add`/`invalidate`, auto-aprobación por RBAC según ADR-032). Sin capa offline/PWA (POST-MVP) y sin `attendance_records` (eso es Fase 7). Ver "Decisiones de implementación no obvias" abajo |
| 7 | Time Calculation Engine | ✅ Completa | `29660b3`, `69a2d2d`, `d94f605`, `e555977`, `4cdcfc4`, `2741585`, `6fa41b4`, `016570f`, `4e7762b`, `4f9f0de` | `labor_rules`/`labor_rule_versions` (effective-dated lookup + no-overlap Postgres/sqlite) + `attendance_records`/`time_calculation_runs` (esta última inmutable, mismo patrón de guarda que `attendance_events` pero duplicado, no reutilizado) + `AttendanceNetEventsResolver` (aplica ajustes aprobados sobre eventos crudos) + `TimeCalculationEngine` (motor 90%+ cobertura, ejemplo numérico del doc verificado exacto) + recálculo disparado por ajuste aprobado (`AttendanceAdjustmentService`) + permisos `labor_rules.*`/`time_calculation.*` + UI mínima (`labor-rules/Index.vue`, `employees/TimeCalculation.vue`). Sin integración de `novelty_records` (Fase 8 no existe todavía) ni disparo automático de recálculo al publicar una regla nueva (`PENDING DECISION` de `09-TIME-CALCULATION.md`, sigue abierto a propósito). Ver "Decisiones de implementación no obvias" abajo |
| 8 | Overtime/Novedades | ⏳ Siguiente | — | Depende de Fase 7 (ya completa) |
| 9 | Payroll | 🔲 Pendiente | — | |
| 10 | Social Security | 🔲 Pendiente | — | |
| 11 | Reports/PDF | 🔲 Pendiente | — | |
| 12 | Biometrics | 🔲 Pendiente | — | |
| 13 | Audit/Hardening | 🔲 Pendiente | — | |
| 14 | Testing | 🔲 Pendiente | — | |
| 15 | Deployment | 🔲 Pendiente | — | |

## Deuda técnica conocida

- **Larastan no infiere tipos de atributos declarados vía el método moderno `protected function casts(): array`** (confirmado en Larastan v3.10.0, reproducido incluso en `Employee::hire_date` de la Fase 3). Cualquier código nuevo que llame un método de `Carbon` directamente sobre un atributo con cast de fecha debe envolverlo en `Carbon::parse(...)` primero — ver `app/Http/Controllers/EmployeeController.php::show()`.
- **`employment_contracts` en Postgres tiene una constraint `EXCLUDE USING gist` real que impide contratos solapados**, pero los tests corren en sqlite (`phpunit.xml`), que no la soporta. La validación de solapamiento equivalente vive también en `StoreEmploymentContractRequest::withValidator()` — cualquier fase futura que inserte `employment_contracts` fuera de ese Form Request debe replicar esa validación o pasar por él.
- **El test suite DEBE correr sobre sqlite en cualquier entorno, CI incluido** — es el supuesto detrás del punto anterior y de todo el patrón "constraint en Postgres + validación de respaldo a nivel de servicio". Esto se rompió una vez en CI: `.github/workflows/tests.yml` definía `DB_CONNECTION=pgsql` (y el resto de `DB_*`) como `env` a nivel de job, lo que filtraba esas variables al paso `composer ci:check` y hacía que Pest corriera contra el Postgres real del servicio en vez de sqlite — `EmploymentContractLookupTest::test_it_rejects_an_ambiguous_lookup...` fallaba con un `QueryException` crudo de Postgres (la constraint `EXCLUDE` bloqueaba el INSERT antes de que el código de la app pudiera lanzar `AmbiguousContractException`). Ojo: agregar `force="true"` a los `<env>` de `phpunit.xml` **no alcanza** para ganarle a una variable de entorno ya exportada por el shell/CI (limitación conocida de PHPUnit con `getenv()` vs `$_ENV`) — el fix real es que esas variables de Postgres solo estén scoped al paso que efectivamente las necesita (`Setup Application`, que corre `php artisan migrate --force` contra el Postgres real para validar el DDL), nunca a nivel de job completo.
- **Nunca usar el cast `'date'` (o `'datetime'`) sin formato para una columna solo-fecha si algún código va a hacer una comparación exacta de string contra ella.** El cast `'date'` puro guarda un valor con sufijo de hora (`"2026-02-10 00:00:00"`) en vez de `"2026-02-10"`; en Postgres la comparación funciona igual porque el motor normaliza por tipo de columna, pero en sqlite (motor de los tests) es una comparación de texto crudo que falla. Usar siempre `'date:Y-m-d'` para columnas de fecha pura — esto NO afecta el problema de Larastan de la nota anterior (ambos formatos fallan igual ahí; el fix real es `Carbon::parse(...)`), así que no hay motivo para usar el cast sin formato.
- **`ShiftAssignment::overlapsForEmployee()` no tiene una constraint equivalente a nivel de Postgres** (a diferencia de `employment_contracts`) — el rango de tiempo vive en `shifts`, no en `shift_assignments`, así que un `EXCLUDE` cruzando ambas tablas requeriría un trigger o desnormalizar el rango. Se dejó deliberadamente solo a nivel de servicio (`StoreShiftRequest`, `StoreShiftAssignmentRequest`) por ahora. La misma limitación aplica, por la misma razón estructural, a la deduplicación de `AttendanceEventRecorder` (ver más abajo).
- **`attendance_devices` existe (modelo + migración + factory, Fase 6) pero sin controlador/UI propio, a propósito** — no hay un dispositivo real que gestionar hasta que la Fase 12 (Biometría) conecte hardware de verdad; construir esa UI ahora sería especulativo (YAGNI). Solo sirve hoy como FK opcional de `attendance_events.device_id`.
- **`SetCurrentCompany` corre después de `SubstituteBindings` en `bootstrap/app.php`** — en la primerísima request de una sesión (nada en `Session` todavía), el route-model-binding implícito de rutas anidadas por empleado (`employees/{employee}/...`) resuelve **antes** de que el scope global `BelongsToCompany` tenga por qué filtrar, así que una request "fría" a una ruta con un `{employee}` de otra empresa puede bindear igual (confirmado concretamente en Fase 7: una `POST` en frío a `employees/{empleado-ajeno}/attendance/events` llega a crear el evento). Detectado dos veces de forma independiente (Fase 6, CRUD de Branch/Position; Fase 7, `AttendanceRecordController`) sin corregirse ninguna de las dos veces por estar fuera de alcance del commit que lo encontró — los tests existentes no lo detectan porque siempre hacen un request de "calentamiento" antes. Necesita su propia sesión de trabajo: reordenar el middleware y correr la suite completa para confirmar que ningún test dependía (sin saberlo) del orden actual.

## Checklist de `PENDING DECISION` abiertas

Consolidado de todas las ambigüedades funcionales reales que siguen sin resolver en `.ai/`, agrupadas por urgencia. Cada una vive en detalle en su archivo de origen (columna "Fuente") — esta tabla es solo el índice de seguimiento; al resolver una, actualizar primero el archivo de origen (reemplazar `PENDING DECISION` por `RESUELTO` + ADR si corresponde) y recién después marcarla acá.

**Grupo 1 — Ya implementadas "alrededor" en Fase 6/7, sin decisión de negocio real todavía**

- [ ] Precedencia entre dos ajustes `APPROVED` que corrigen el mismo evento (`original_event_id` repetido) — hoy gana el más reciente por `created_at`, nunca se fusionan. Fuente: `07-ATTENDANCE.md` (Flujo 2, punto 4).
- [ ] Descanso real que se abre (`BREAK_START`) pero nunca se cierra — hoy no se resta del tiempo trabajado y no bloquea el cálculo. Fuente: `09-TIME-CALCULATION.md` (Reglas, punto 6).

**Grupo 2 — Bloquea/afecta directamente la Fase 9 (Payroll), la próxima motor crítico después de Overtime**

- [ ] Algoritmo exacto de prorrateo cuando un contrato cambia a mitad de periodo (días calendario / días hábiles / horas planificadas / otro). Fuente: `10-PAYROLL.md` (Reglas, ~línea 49).
- [ ] Comportamiento del motor ante un empleado sin ningún `attendance_record` ni `novelty_record` que justifique una ausencia dentro del periodo. Fuente: `10-PAYROLL.md` (Casos especiales, ~línea 91).
- [ ] Si el prorrateo de aportes de seguridad social sigue el mismo criterio que el de nómina (depende de resolver el punto anterior) o tiene su propia regla. Fuente: `11-SOCIAL-SECURITY.md` (~línea 54).

**Grupo 3 — Deferida a propósito en Fase 7, solo relevante cuando Payroll exista**

- [ ] Si publicar una `labor_rule_version` nueva dispara recálculo automático de fechas afectadas o solo bajo demanda, y si aplica retroactivo a periodos de nómina ya `CLOSED`. Fuente: `09-TIME-CALCULATION.md` (Flujos, punto 3).

**Grupo 4 — Biometría (Fase 12, POST-MVP explícito — no urgente)**

- [ ] Marco legal exacto para datos biométricos en Colombia, más allá del régimen genérico de Habeas Data — marcado como bloqueante antes de construir cualquier integración biométrica real. Fuente: `12-BIOMETRICS.md` (Dependencias legales).
- [ ] Proveedor de hardware biométrico sin elegir (hay una opción recomendada no cerrada: ZKTeco K40/K40 Pro, ADR-042). Fuente: `12-BIOMETRICS.md` (Proveedor(es) de dispositivo).
- [ ] Política de retención de datos biométricos (plazo de conservación, disparador exacto de borrado). Fuente: `12-BIOMETRICS.md` (~línea 130).
- [ ] Mecanismo de inferencia del tipo de evento cuando el dispositivo no lo envía explícito — depende de qué proveedor se elija (punto anterior). Fuente: `07-ATTENDANCE.md` (Reglas, punto 4).

**Grupo 5 — Cumplimiento legal general, no bloquea desarrollo pero sí producción real**

- [ ] Detalle de implementación de Habeas Data colombiano (Ley 1581 de 2012, Decreto 1377 de 2013) sin validar contra asesoría legal profesional. Fuente: `20-SECURITY.md` (Cumplimiento normativo).

**Grupo 6 — Infraestructura, para cuando se llegue a Deployment (Fase 15)**

- [ ] RPO/RTO exactos de backups no confirmados contra el SLA real de Laravel Cloud. Fuente: `22-DEPLOYMENT.md` (~línea 100).

**Grupo 7 — Nota arquitectónica, no es una ambigüedad de negocio (YAGNI explícito, no bloquea nada hoy)**

- [ ] Mecanismo de comunicación interna entre módulos: bus de eventos en memoria vs. llamadas directas encadenadas — el propio doc dice "se resuelve cuando la necesidad de desacoplamiento sea real". Fuente: `03-ARCHITECTURE.md` (~línea 92).

## Decisiones de implementación no obvias (Fase 7 — Time Calculation Engine)

- **Ventana de eventos reales para una fecha**: acotada al/los día(s) calendario que cubre `shifts.date`+`crosses_midnight`, no a un margen de tolerancia inventado — se deriva directamente de que el propio doc atribuye todo el turno a `shifts.date`.
- **Emparejamiento de descansos reales**: solo se resta un par `break_start`→`break_end` completo dentro de la ventana `clock_in`→`clock_out`. Un `break_start` sin `break_end` posterior **no se resta** (cuenta como trabajado) y **no bloquea** el cálculo — nuevo `PENDING DECISION` agregado a `09-TIME-CALCULATION.md` (regla #6), implementado alrededor tal como pide la regla #16 de `AGENTS.md`.
- **Precedencia entre ajustes aprobados sobre el mismo evento**: si dos ajustes `approved` (`modify`/`invalidate`) apuntan al mismo `original_event_id` (una corrección de una corrección), **gana el más reciente por `created_at`**, nunca una fusión campo a campo — nuevo `PENDING DECISION` agregado a `07-ATTENDANCE.md` (Flujo 2, punto 4).
- **`labor_rule_versions.parameters` solo tiene `tolerance_minutes`/`rounding_minutes` por ahora** — nada de ventana nocturna todavía (YAGNI: ningún criterio de aceptación de esta fase la ejercita, ningún consumidor la necesita hasta Payroll/Fase 9-10). Faltan ambas claves → `MissingLaborRuleParameterException`, bloqueante explícito, nunca un default asumido.
- **`TimeCalculationRun` duplica el patrón de inmutabilidad de `AttendanceEvent`, no lo reutiliza** — el `ImmutableBuilder` de `AttendanceEvent` tiene su propia excepción hardcodeada en el cuerpo de sus métodos, así que no es genérico. Se creó `TimeCalculationRunImmutableBuilder`/`TimeCalculationRunImmutableException` como clases hermanas, menor blast radius sobre código Fase 6 ya en producción. Cualquier tabla futura `INMUTABLE` debe replicar el patrón de nuevo (guardas `booted()` + builder propio), no intentar generalizar las clases existentes.
- **Recálculo disparado por un ajuste aprobado nunca aborta la transacción del ajuste**: `AttendanceAdjustmentService` llama a `TimeCalculationEngine::calculateForDate()` **después** de que la transacción propia del ajuste ya confirmó (no anidada), y atrapa cualquiera de las 4 excepciones bloqueantes del motor con un `Log::warning()` — aprobar una corrección de asistencia debe poder completarse aunque la empresa todavía no tenga configurada ninguna `labor_rule_version`. Es el equivalente más cercano a la "señal de ajuste de nómina pendiente" del doc, pero esa señal formal solo existe cuando Payroll (Fase 9) exista; por ahora es logueado-y-omitido, no ignorado en silencio.
- **Sin integración de `novelty_records`**: esa tabla es Fase 8 (que depende de esta Fase 7, no al revés). El caso "ausencia justificada sin marcación" del doc queda fuera de alcance hasta que exista — un día con turno y cero eventos hoy se calcula siempre como ausencia completa (`missing_minutes = planeado`), nunca como justificada.
- **Disparo automático de recálculo al publicar una `labor_rule_version` nueva sigue sin resolver** (`PENDING DECISION` ya existente en `09-TIME-CALCULATION.md`, línea ~55, no tocado). Se implementó alrededor: recálculo on-demand (`calculateForRange`) + disparado por ajuste aprobado (sí está en criterios de aceptación) — nunca un disparo masivo automático al publicar una regla.

## Decisiones de implementación no obvias (Fase 6 — Attendance)

- **Inmutabilidad de `attendance_events`** (regla no negociable, ADR-003: nunca se edita ni se borra) se hace cumplir en dos capas del modelo `AttendanceEvent`, no solo documentalmente: `booted()` registra listeners `updating`/`deleting` que lanzan `AttendanceEventImmutableException`, y un `newEloquentBuilder()` propio (`ImmutableBuilder`) sobreescribe `update()`/`delete()` a nivel de query builder para cubrir también updates/deletes masivos que no disparan eventos de modelo individuales. Es el primer modelo del proyecto con esta guarda; cualquier tabla futura marcada `INMUTABLE` en `05-DATABASE.md` (ej. `time_calculation_runs` en Fase 7) debería replicar el mismo patrón.
- **Deduplicación de eventos de asistencia**: ventana de 1 minuto, mismo empleado + mismo `event_type`. El esquema de `attendance_events` no tiene columna `status`, así que un duplicado detectado **no inserta fila nueva** — se devuelve el evento existente encontrado. El check-then-insert tiene una ventana TOCTOU conocida bajo concurrencia (documentada en el docblock de `AttendanceEventRecorder`); no se agregó constraint de Postgres porque no hay una forma natural de expresar una ventana deslizante de 60s como unique index.
- **Casing de valores tipo-enum**: `event_type`/`source`/`type`/`status` de Attendance usan `snake_case` minúscula (`clock_in`, `web`, `pending`) para quedar consistentes con el resto del codebase (`'assigned'`, `'cancelled'`, `'manual'`), aunque la prosa de `07-ATTENDANCE.md` esté en mayúscula (`CLOCK_IN`, `APPROVED` son conceptuales, no literales de esquema).
- **Primer uso real de `Rule::in()` en el proyecto**: hasta la Fase 6, ningún Form Request validaba un conjunto cerrado de valores porque los strings tipo-enum siempre los escribía código de confianza. `source` en `StoreAttendanceEventRequest` es el primer campo que recibe un valor cerrado desde afuera y de verdad necesita rechazar valores fuera de rango (`source` restringido a `web`/`manual` únicamente en esta fase; `mobile`/`qr`/`api`/`device`/`biometric` quedan reservados para fases posteriores).
- **`attendance.record` (crear un evento normal) es un permiso separado de `attendance.adjust` (corregir uno existente)** — mismo set de roles hoy (`SUPER_ADMIN`/`COMPANY_OWNER`/`ADMIN`/`HR_MANAGER`/`SUPERVISOR`), pero conceptualmente distintos; no reusar uno por el otro si en el futuro divergen.
- **Auto-aprobación de ajustes (ADR-032) se deriva en vivo de `$user->hasPermission('attendance.approve_adjustment')`**, nunca de una lista de roles hardcodeada en `AttendanceAdjustmentService` — así nunca se desincroniza de `RoleSeeder` si los grants cambian.
- **Modelo de fichada confirmado con el usuario**: se evaluó biometría de huella para la Fase 6, pero se decidió mantenerla en su lugar del roadmap (Fase 12, POST-MVP, ADR-036) y construir la Fase 6 tal como estaba especificada — panel operado por RR.HH./supervisor, sin autoservicio del empleado.

## Próximo paso

Fase 8 — Overtime/Novedades (ver [24-ROADMAP.md](./24-ROADMAP.md)).
