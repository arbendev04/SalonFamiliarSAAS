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
| 3 | Companies / Employees | ✅ Completa | `fe75590` | Aislamiento de tenant probado (`BelongsToCompany` + `CurrentCompany`); **deuda**: `Position`/`Branch` sin CRUD/UI propio (ver "Deuda técnica" abajo) |
| — | Traducción UI a español (ADR-043) | ✅ Completa | `9e247f0`, `4cffae4` | No es una fase del roadmap; se hizo entre Fase 3 y 4 a pedido explícito del usuario |
| 4 | Contracts | ✅ Completa | `61f8b1b` | `employment_contracts` + `salary_history` + `payroll_information`; effective-dated lookup con `AmbiguousContractException`; no-overlap reforzado en Postgres (EXCLUDE gist) y a nivel de request (fallback para sqlite en tests) |
| 5 | Schedules/Shifts | ✅ Completa | `37943c5` | 6 entidades (`work_schedule_templates/days`, `employee_schedules`, `shifts`, `shift_assignments`, `shift_breaks`); generación desde plantilla (`ShiftGenerator`) con soporte de turnos nocturnos, dobles y partidos; solapamiento rechazado a nivel de servicio; `audit_logs` + `AuditLogger` adelantados de la Fase 13 para auditar reasignaciones excepcionales. UI cubre el camino principal (plantilla → asignar → generar); turnos manuales/partidos/dobles y reasignación quedan probados por Pest sin UI todavía (ver "Deuda técnica") |
| 6 | Attendance | ⏳ Siguiente | — | Depende de Fase 5 (ya completa) |
| 7 | Time Calculation Engine | 🔲 Pendiente | — | |
| 8 | Overtime/Novedades | 🔲 Pendiente | — | |
| 9 | Payroll | 🔲 Pendiente | — | |
| 10 | Social Security | 🔲 Pendiente | — | |
| 11 | Reports/PDF | 🔲 Pendiente | — | |
| 12 | Biometrics | 🔲 Pendiente | — | |
| 13 | Audit/Hardening | 🔲 Pendiente | — | |
| 14 | Testing | 🔲 Pendiente | — | |
| 15 | Deployment | 🔲 Pendiente | — | |

## Deuda técnica conocida

- **`Position` y `Branch` no tienen controlador ni UI propios** (solo modelo + migración, desde la Fase 3). Se volvió visible en la Fase 4 porque `employment_contracts.position_id` tuvo que quedar `nullable` (igual que `employees.branch_id`) al no existir forma de crear un `Position` fuera de tinker. Hay una tarea en background suspendida para esto (`task_e6aae1f8`); conviene resolverla antes de que la Fase 5 (Shifts, que también depende de `branches`) la necesite de verdad.
- **Larastan no infiere tipos de atributos declarados vía el método moderno `protected function casts(): array`** (confirmado en Larastan v3.10.0, reproducido incluso en `Employee::hire_date` de la Fase 3). Cualquier código nuevo que llame un método de `Carbon` directamente sobre un atributo con cast de fecha debe envolverlo en `Carbon::parse(...)` primero — ver `app/Http/Controllers/EmployeeController.php::show()`.
- **`employment_contracts` en Postgres tiene una constraint `EXCLUDE USING gist` real que impide contratos solapados**, pero los tests corren en sqlite (`phpunit.xml`), que no la soporta. La validación de solapamiento equivalente vive también en `StoreEmploymentContractRequest::withValidator()` — cualquier fase futura que inserte `employment_contracts` fuera de ese Form Request debe replicar esa validación o pasar por él.
- **El test suite DEBE correr sobre sqlite en cualquier entorno, CI incluido** — es el supuesto detrás del punto anterior y de todo el patrón "constraint en Postgres + validación de respaldo a nivel de servicio". Esto se rompió una vez en CI: `.github/workflows/tests.yml` definía `DB_CONNECTION=pgsql` (y el resto de `DB_*`) como `env` a nivel de job, lo que filtraba esas variables al paso `composer ci:check` y hacía que Pest corriera contra el Postgres real del servicio en vez de sqlite — `EmploymentContractLookupTest::test_it_rejects_an_ambiguous_lookup...` fallaba con un `QueryException` crudo de Postgres (la constraint `EXCLUDE` bloqueaba el INSERT antes de que el código de la app pudiera lanzar `AmbiguousContractException`). Ojo: agregar `force="true"` a los `<env>` de `phpunit.xml` **no alcanza** para ganarle a una variable de entorno ya exportada por el shell/CI (limitación conocida de PHPUnit con `getenv()` vs `$_ENV`) — el fix real es que esas variables de Postgres solo estén scoped al paso que efectivamente las necesita (`Setup Application`, que corre `php artisan migrate --force` contra el Postgres real para validar el DDL), nunca a nivel de job completo.
- **Nunca usar el cast `'date'` (o `'datetime'`) sin formato para una columna solo-fecha si algún código va a hacer una comparación exacta de string contra ella.** El cast `'date'` puro guarda un valor con sufijo de hora (`"2026-02-10 00:00:00"`) en vez de `"2026-02-10"`; en Postgres la comparación funciona igual porque el motor normaliza por tipo de columna, pero en sqlite (motor de los tests) es una comparación de texto crudo que falla. Usar siempre `'date:Y-m-d'` para columnas de fecha pura — esto NO afecta el problema de Larastan de la nota anterior (ambos formatos fallan igual ahí; el fix real es `Carbon::parse(...)`), así que no hay motivo para usar el cast sin formato.
- **`ShiftAssignment::overlapsForEmployee()` no tiene una constraint equivalente a nivel de Postgres** (a diferencia de `employment_contracts`) — el rango de tiempo vive en `shifts`, no en `shift_assignments`, así que un `EXCLUDE` cruzando ambas tablas requeriría un trigger o desnormalizar el rango. Se dejó deliberadamente solo a nivel de servicio (`StoreShiftRequest`, `StoreShiftAssignmentRequest`) por ahora.
- **La UI de Shifts cubre solo el camino principal** (crear plantilla → asignar a empleado → generar turnos). Los flujos manuales — turno partido (agregar `shift_breaks`), turno doble, y reasignación excepcional de turno (`shifts.assignment.update`, que dispara auditoría) — están completamente implementados y probados en el backend (ver `tests/Feature/ShiftTest.php` y `tests/Feature/ShiftAssignmentTest.php`) pero todavía no tienen un formulario propio en la UI.

## Próximo paso

Fase 6 — Attendance (ver [24-ROADMAP.md](./24-ROADMAP.md)).
