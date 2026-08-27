# 18-API.md — API

## Objetivo

Definir las convenciones transversales de la API REST del sistema (versionado, autenticación, resolución de tenant, paginación, filtrado, ordenamiento, formato de error) y el catálogo completo de endpoints por dominio, para que todos los módulos expongan una interfaz uniforme.

## Alcance

Incluye: la convención de versionado y estilo REST, la resolución de `company_id` por membership activa, las reglas transversales de paginación/filtrado/ordenamiento/errores (documentadas una única vez aquí), y el catálogo completo de endpoints principales por dominio.

No incluye: la lógica de negocio detrás de cada endpoint, que vive en el archivo del módulo correspondiente; la definición de los permisos atómicos en sí (catálogo, asignación a roles), que vive en [06-AUTHORIZATION.md](./06-AUTHORIZATION.md); el detalle de tenant isolation a nivel de arquitectura, que vive en [15-MULTI-TENANCY.md](./15-MULTI-TENANCY.md); infraestructura de seguridad transversal (rate limiting a nivel de plataforma, gestión de secretos), que vive en [20-SECURITY.md](./20-SECURITY.md).

## Conceptos

- **Versionado**: todos los endpoints se exponen bajo el prefijo `/api/v1` (ADR-019 en [23-DECISIONS.md](./23-DECISIONS.md)). Un cambio incompatible de contrato requiere una nueva versión de prefijo, nunca una modificación silenciosa del contrato existente en `v1`.
- **Convención REST uniforme**: recursos en plural, verbos HTTP estándar (`GET`/`POST`/`PATCH`/`DELETE`), acciones de transición de estado como sub-recursos con verbo (ej. `POST /payroll/periods/{id}/close`), coherente con el catálogo de endpoints de este documento.
- **Autenticación por sesión**: todo endpoint (salvo `/auth/login`, `/auth/password-reset`) requiere una sesión de servidor válida (cookie de sesión + token CSRF), según ADR-017 y el flujo de [06-AUTHORIZATION.md](./06-AUTHORIZATION.md). En la v1, estos endpoints son rutas del mismo monolito Laravel/Inertia (ADR-022), no una API pública separada consumida por terceros o por una app móvil — el catálogo de esta sección documenta el contrato de recursos/métodos/permisos, independientemente del mecanismo HTTP exacto (ruta Inertia vs. JSON puro) con el que se implemente cada uno.
- **Resolución de `company_id`**: el `company_id` de cada request se resuelve siempre a partir de la membership activa del usuario autenticado (ver [06-AUTHORIZATION.md](./06-AUTHORIZATION.md), [15-MULTI-TENANCY.md](./15-MULTI-TENANCY.md)). **Nunca se acepta un `company_id` libre enviado por el cliente** en el body, query string o header de un request de negocio; aceptarlo así sería una violación directa del principio de aislamiento de tenant.

## Reglas transversales

Estas reglas aplican a todos los endpoints de todos los dominios y se documentan una única vez aquí; los archivos de cada módulo no las repiten.

- **Paginación**: estrategia — offset (`page`/`limit`) vs cursor — es **PENDING DECISION**. Hasta resolverse, ningún endpoint de listado debe asumir un mecanismo concreto de forma inconsistente entre módulos.
- **Filtrado**: los filtros se expresan como query params nombrados por el campo que filtran (ej. `?branch_id=`, `?employee_id=`, `?date_from=`, `?date_to=`, `?status=`); los filtros comunes a reportes (empresa implícita, sucursal, trabajador, fecha, departamento, cargo, periodo) se detallan en [13-REPORTS.md](./13-REPORTS.md).
- **Ordenamiento**: query param `?sort=campo` con prefijo `-` para orden descendente (ej. `?sort=-created_at`); el campo de orden por defecto de cada listado es responsabilidad del endpoint concreto.
- **Formato de error estándar**: toda respuesta de error sigue el mismo envelope — código de estado HTTP, un código de error interno estable, un mensaje legible y (cuando aplique) el detalle de campo(s) inválido(s) — sin exponer información interna de implementación (stack traces, nombres de tabla) en el mensaje.

## Endpoints por dominio

Convención: prefijo `/api/v1` omitido por brevedad en la tabla. Autenticación por sesión y resolución de `company_id` por membership activa aplican a todos salvo donde se indique lo contrario.

| Dominio | Endpoints principales | Operaciones sensibles (permiso especial) |
|---|---|---|
| `/auth` | `POST /login`, `POST /logout`, `POST /password-reset` | — |
| `/companies` | `GET/POST /companies`, `GET/PATCH /companies/{id}`, `POST /companies/{id}/switch-active` | `companies.manage` |
| `/users` | `GET/POST /users`, `PATCH /users/{id}`, `POST /users/{id}/memberships` | `users.manage` |
| `/roles` | `GET/POST /roles`, `PATCH /roles/{id}`, `PUT /roles/{id}/permissions` | `roles.manage` |
| `/employees` | `GET/POST /employees`, `GET/PATCH /employees/{id}`, `DELETE` (soft) | `employees.create`/`employees.update` |
| `/contracts` | `GET/POST /employees/{id}/contracts`, `GET /contracts/{id}`, `POST /contracts/{id}/terminate` | `contracts.write` |
| `/schedules` | `GET/POST /schedule-templates`, `POST /employees/{id}/schedules` | `schedules.write` |
| `/shifts` | `GET/POST /shifts`, `POST /shifts/generate-from-template`, `PATCH /shifts/{id}`, `POST /shifts/{id}/assign` | `shifts.write` |
| `/attendance` | `GET /attendance/events`, `POST /attendance/events` (marcación manual/self clock), `POST /attendance/adjustments`, `POST /attendance/adjustments/{id}/approve` | `attendance.adjust`, `attendance.approve_adjustment` |
| `/overtime` | `GET /overtime`, `POST /overtime/{id}/request`, `POST /overtime/{id}/authorize`, `POST /overtime/{id}/reject` | `overtime.authorize` |
| `/leave` | `GET/POST /leave`, `POST /leave/{id}/approve`, `POST /leave/{id}/reject` | `leave.approve` |
| `/payroll` | `GET /payroll/periods`, `POST /payroll/periods/{id}/calculate`, `POST /payroll/periods/{id}/approve`, **`POST /payroll/periods/{id}/close`**, `POST /payroll/periods/{id}/reopen`, `POST /payroll/entries/{id}/adjustments` | `payroll.calculate`, `payroll.approve`, **`payroll.close`**, `payroll.reopen` |
| `/social-security` | `GET/POST /social-security/affiliations`, `GET /social-security/contributions` | `social_security.manage` |
| `/reports` | `GET /reports/attendance`, `/reports/payroll`, `/reports/overtime`, `/reports/absences` (todos con filtros empresa/sucursal/trabajador/fecha/departamento/cargo/periodo) | `reports.export` (para exportación) |
| `/devices` | `GET/POST /devices`, `POST /devices/{id}/sync`, `GET /devices/{id}/heartbeats` | `devices.manage` |
| `/audit` | `GET /audit/logs` (filtros por entidad/usuario/fecha) | `audit.read` |

## Casos normales

- Un listado sin filtros aplica los valores por defecto de paginación/orden del endpoint y devuelve solo datos de la `company_id` resuelta por membership activa.
- Una operación CRUD normal (ej. crear un `employee`) solo requiere el permiso base del dominio (`employees.create`), sin permiso adicional.

## Casos especiales

- **Operaciones sensibles que requieren un permiso adicional al CRUD normal**: toda fila de la tabla anterior marcada en la columna "Operaciones sensibles" exige, además de estar autenticado y pertenecer a la empresa, poseer el permiso atómico específico indicado (ej. `POST /payroll/periods/{id}/close` requiere `payroll.close`, no basta con poder leer o calcular nómina). Ver la matriz RBAC completa en [06-AUTHORIZATION.md](./06-AUTHORIZATION.md).

## Errores

Catálogo de códigos de error estándar y criterio de cuándo usar cada uno:

| Código | Cuándo se usa |
|---|---|
| `400` | Request malformado (JSON inválido, tipo de dato incorrecto en un campo). |
| `401` | Sesión ausente, expirada o revocada (ver [06-AUTHORIZATION.md](./06-AUTHORIZATION.md)). |
| `403` | Autenticado pero sin el permiso atómico requerido para la operación, o intento de acceso cross-tenant no autorizado (ver [15-MULTI-TENANCY.md](./15-MULTI-TENANCY.md)). |
| `404` | El recurso no existe, o existe pero pertenece a otra `company_id` (para no filtrar existencia cross-tenant, `404` se prefiere sobre `403` cuando la distinción pudiera revelar información sensible — ver criterio equivalente en [06-AUTHORIZATION.md](./06-AUTHORIZATION.md)). |
| `409` | Conflicto de estado (ej. cerrar un periodo de nómina ya `CLOSED`, crear un contrato solapado con uno vigente). |
| `422` | Validación de negocio fallida sobre un input sintácticamente válido (ej. rango de fechas inválido, filtro fuera de dominio — ver [13-REPORTS.md](./13-REPORTS.md)). |
| `500` | Error no controlado del servidor; nunca debe exponer detalle interno de implementación en el mensaje de respuesta. |

## Seguridad

- **Rate limiting**: aplicado a nivel de plataforma, con especial atención a `/auth/login` para mitigar fuerza bruta (ver [06-AUTHORIZATION.md](./06-AUTHORIZATION.md), [20-SECURITY.md](./20-SECURITY.md)).
- **Validación de input**: todo endpoint valida su input contra un esquema antes de procesarlo; ningún dato de negocio se persiste sin validación previa (ver principio general en [20-SECURITY.md](./20-SECURITY.md)).
- **CORS**: configuración restringida a los orígenes del frontend autorizado; el detalle de configuración concreta se trata en [20-SECURITY.md](./20-SECURITY.md).

## Dependencias

- [06-AUTHORIZATION.md](./06-AUTHORIZATION.md): catálogo de permisos atómicos y matriz RBAC que determina qué endpoints requieren qué permiso.
- [15-MULTI-TENANCY.md](./15-MULTI-TENANCY.md): resolución de `company_id` por membership activa y prohibición de `company_id` libre.
- [20-SECURITY.md](./20-SECURITY.md): rate limiting de plataforma, validación de input, CORS y demás infraestructura de seguridad transversal.

## Criterios de aceptación

- Todos los endpoints están bajo `/api/v1` y requieren sesión autenticada salvo los explícitamente listados como públicos en `/auth`.
- Ningún endpoint acepta un `company_id` provisto por el cliente para resolver el tenant de la operación.
- Cada operación sensible listada en el catálogo de endpoints exige exactamente el permiso atómico indicado, verificable contra la matriz RBAC de [06-AUTHORIZATION.md](./06-AUTHORIZATION.md).
- Toda respuesta de error de este catálogo usa el envelope de error estándar, sin excepción.
- El catálogo de endpoints de este documento coincide exactamente con los definidos en el blueprint aprobado (sección 5), sin dominios faltantes ni agregados.
