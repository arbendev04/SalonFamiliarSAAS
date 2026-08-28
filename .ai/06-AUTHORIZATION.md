# 06-AUTHORIZATION.md — Authentication & Authorization

> Este archivo cubre **ambos** módulos, `Authentication` y `Authorization`. Son los únicos 2 de los 22 módulos del sistema que comparten un solo archivo de documentación (no tienen archivo propio separado). Ver el mapa de módulos en [00-SYSTEM-SPECIFICATION.md](./00-SYSTEM-SPECIFICATION.md).

## Objetivo

Definir cómo el sistema identifica a una persona que inicia sesión (`Authentication`) y cómo decide qué puede hacer esa persona en cada empresa a la que pertenece (`Authorization`), de forma consistente con el modelo "1 cuenta → N empresas" y con un control de acceso granular por permiso atómico, no solo por rol.

## Alcance

Incluye: identidad global de usuario, sesiones, membership de usuario a empresa, catálogo de roles (de plataforma y custom por empresa), catálogo de permisos atómicos, asignación de permisos a roles, y los flujos de login/logout/cambio de empresa activa/asignación de rol/revocación de acceso.

No incluye: la lógica de negocio de cada módulo de dominio (por ejemplo, qué significa `payroll.close` en términos de nómina vive en [10-PAYROLL.md](./10-PAYROLL.md)); la resolución de tenant activo a nivel de request y el detalle del acceso cross-tenant de `SUPER_ADMIN` (vive en [15-MULTI-TENANCY.md](./15-MULTI-TENANCY.md)); el registro de auditoría en sí (vive en [16-AUDIT.md](./16-AUDIT.md), aunque este módulo dispara esos registros); el detalle de infraestructura de seguridad transversal (vive en [20-SECURITY.md](./20-SECURITY.md)).

## Conceptos

- **Identidad global (`users`)**: una persona que puede iniciar sesión en la plataforma. Un `user` NO pertenece a una sola empresa — es una entidad global, independiente del tenant (ver ADR-013 en [23-DECISIONS.md](./23-DECISIONS.md)).
- **Membership por empresa (`user_company_memberships`)**: vincula un `user` a una `company` con un `role` concreto. Es lo que habilita el modelo "1 cuenta → N empresas": una misma persona puede tener una membership distinta (con rol distinto) en cada empresa donde participa.
- **Rol (`roles`)**: una colección configurable de permisos. Puede ser un **rol de sistema** (`company_id` nulo, definido por la plataforma, igual para todas las empresas) o un **rol custom de una empresa** (`company_id` no nulo, definido por esa empresa a partir del mismo catálogo de permisos).
- **Permiso atómico (`permissions`)**: unidad mínima de autorización, definida en un catálogo global (no tenant-scoped). Un permiso representa una acción concreta sobre un recurso (ej. `attendance.adjust`, `payroll.close`).
- **Sesión (`auth_tokens`)**: representación de una sesión de servidor activa de un `user` tras autenticarse, registrada para permitir revocación explícita (logout remoto, invalidación al revocar una membership).

## Entidades

| Entidad | Propósito | Notas de `05-DATABASE.md` |
|---|---|---|
| `users` | Identidad global de persona que inicia sesión (no pertenece a una sola empresa) | Aislamiento GLOBAL; soft-delete; MUTABLE |
| `auth_tokens` | Sesiones activas / tokens de refresco | Aislamiento GLOBAL (por `user`); MUTABLE; no soft-delete (expira) |
| `roles` | Rol de sistema (`company_id` nulo) o rol custom de una empresa | Aislamiento DIRECTO/GLOBAL según `company_id`; soft-delete; MUTABLE |
| `permissions` | Catálogo global de permisos atómicos | Aislamiento GLOBAL; sin soft-delete; INMUTABLE (catálogo versionado por release) |
| `role_permissions` | Asigna permisos a un rol | Aislamiento HEREDADO; PK compuesta; MUTABLE |
| `user_company_memberships` | Vincula un usuario a una empresa con un rol | Aislamiento DIRECTO; soft-delete (revocar ≠ borrar); unique `(user_id, company_id)`; MUTABLE |

Consultar [04-DOMAIN-MODEL.md](./04-DOMAIN-MODEL.md) para el detalle de relaciones y [05-DATABASE.md](./05-DATABASE.md) para columnas completas, constraints e índices.

## Reglas

- El control de acceso es **RBAC granular por permiso atómico**, no solo por rol (ADR-009 en [23-DECISIONS.md](./23-DECISIONS.md)). Cada endpoint u operación sensible valida la posesión de un permiso concreto (ej. `payroll.close`), no simplemente "el rol X puede hacer todo".
- Un **rol es una colección configurable de permisos**: se define asignando filas en `role_permissions`. Un mismo permiso puede pertenecer a varios roles.
- **Roles de sistema** (predefinidos, `company_id` nulo, iguales en toda la plataforma): `SUPER_ADMIN`, `COMPANY_OWNER`, `ADMIN`, `HR_MANAGER`, `PAYROLL_MANAGER`, `SUPERVISOR`, `ACCOUNTANT`, `EMPLOYEE`.
- **Roles custom por empresa**: una empresa puede definir roles adicionales (`company_id` no nulo) componiendo permisos del mismo catálogo global de `permissions`. No pueden otorgar permisos fuera de ese catálogo.
- Un `user` puede tener una `user_company_membership` distinta por cada `company`, cada una con su propio `role`. La empresa "activa" en un momento dado determina qué permisos aplican durante esa sesión de trabajo (ver flujo de cambio de empresa activa).
- La resolución de `company_id` en cada request se hace a partir de la membership activa, nunca como parámetro libre enviado por el cliente (ver [15-MULTI-TENANCY.md](./15-MULTI-TENANCY.md)).

### Matriz RBAC completa

Y = permitido, N = no permitido. Ver también la sección 6 del blueprint aprobado para el detalle exacto de cada celda.

| Categoría de permiso | SUPER_ADMIN | COMPANY_OWNER | ADMIN | HR_MANAGER | PAYROLL_MANAGER | SUPERVISOR | ACCOUNTANT | EMPLOYEE |
|---|---|---|---|---|---|---|---|---|
| companies.manage | Y (plataforma) | Y (propia) | N | N | N | N | N | N |
| users.manage / roles.manage | Y | Y | Y | N | N | N | N | N |
| employees.read | Y | Y | Y | Y | Y | Y (equipo) | Y (limitado a nómina) | Solo propio |
| employees.create/update | Y | Y | Y | Y | N | N | N | N |
| contracts.read/write | Y | Y | Y | Y (read/write) | Y (read) | N | Y (read) | Solo propio (read) |
| schedules/shifts.write | Y | Y | Y | Y | N | Y (equipo) | N | N |
| attendance.read | Y | Y | Y | Y | Y | Y (equipo) | N | Solo propio |
| attendance.record | Y | Y | Y | Y | N | Y | N | N |
| attendance.adjust / approve_adjustment | Y | Y | Y | Y | N | Solicitar solamente | N | N |
| overtime.request | Y | Y | Y | Y | N | Y | N | Y (propio) |
| overtime.authorize | Y | Y | Y | Y | N | Y (limitado) | N | N |
| leave.approve | Y | Y | Y | Y | N | Y (equipo) | N | N |
| payroll.read | Y | Y | Y | N | Y | N | Y | Solo propio comprobante |
| payroll.calculate | Y | N | N | N | Y | N | N | N |
| payroll.approve | Y | Y | Y | N | Y | N | N | N |
| payroll.close | Y | Y | Y | N | Y | N | N | N |
| payroll.reopen/adjust | Y | Y | Y | N | N | N | N | N |
| social_security.manage | Y | Y | Y | N | Y | N | Y | N |
| reports.read/export | Y | Y | Y | Y | Y | Y (equipo) | Y | Solo propio |
| devices.manage | Y | Y | Y | N | N | N | N | N |
| biometrics.enroll | Y | Y | Y | Y | N | N | N | N |
| biometrics.delete_data | Y | Y | Y | N | N | N | N | N |
| audit.read | Y (cross-tenant) | Y (propia empresa) | Y (propia empresa) | N | N | N | N | N |
| settings.manage | Y (sistema) | Y | Y | N | N | N | N | N |

Nota de diseño: **RESUELTO** (ADR-033 en [23-DECISIONS.md](./23-DECISIONS.md)) — no se crea un rol `DEVICE_TECHNICIAN` dedicado en la v1; `devices.manage` queda en `ADMIN`.

Nota de diseño sobre `payroll.approve`: **RESUELTO** (ADR-034 en [23-DECISIONS.md](./23-DECISIONS.md)) — `APPROVED` es un paso **opcional**, no un gate obligatorio; `payroll.close` puede ejecutarse directo desde `CALCULATED`. El permiso `payroll.approve` sigue existiendo para quien quiera usar ese paso intermedio.

## Flujos

### Login
1. El cliente envía credenciales (`POST /auth/login`).
2. El sistema valida credenciales contra `users.password_hash`.
3. Si son válidas, se inicia una **sesión de servidor** (cookie de sesión firmada por Laravel) y se registra la sesión activa en `auth_tokens`, habilitando revocación explícita desde el backend (ver ADR-017 en [23-DECISIONS.md](./23-DECISIONS.md)).
4. Se resuelve la lista de `user_company_memberships` activas del `user`. Si tiene más de una empresa, el cliente debe indicar (o el backend debe inferir por defecto) cuál es la empresa activa de la sesión.

### Logout
1. El cliente invoca `POST /auth/logout`.
2. El sistema invalida la sesión de servidor (cookie) y marca el `auth_tokens` correspondiente como revocado (`revoked_at`).

### Cambio de empresa activa (para usuarios con membership en varias empresas)
1. El `user` invoca `POST /companies/{id}/switch-active` (ver [18-API.md](./18-API.md)).
2. El sistema valida que exista una `user_company_membership` activa de ese `user` para esa `company`.
3. La sesión pasa a resolver permisos y `company_id` contra esa nueva membership; todo request subsiguiente queda scoped a la nueva empresa activa hasta el próximo cambio.

### Asignación de rol
1. Un usuario con `users.manage`/`roles.manage` asigna un `role_id` a una `user_company_membership` (nueva o existente).
2. El cambio queda registrado en `audit_logs` (acción "cambiar permisos", ver [16-AUDIT.md](./16-AUDIT.md)).

### Revocación de acceso
1. Un usuario con `users.manage` desactiva (`status`) o marca `deleted_at` en la `user_company_membership` correspondiente — revocar nunca borra físicamente el registro (soft-delete, ADR-010).
2. Los `auth_tokens` activos del `user` para esa sesión deben invalidarse de inmediato al revocar la membership (el usuario deja de poder actuar sobre esa empresa aunque conserve su identidad global y su sesión siga vigente para otras empresas).

## Casos normales

- Un `user` con una sola `user_company_membership` inicia sesión y opera directamente sobre esa empresa, sin necesidad de seleccionar empresa activa explícitamente.
- Un `user` con permisos de `roles.manage` crea un rol custom para su empresa combinando permisos existentes del catálogo global.

## Casos especiales

- **Usuario sin ninguna empresa asignada**: un `user` puede existir sin ninguna `user_company_membership` activa (por ejemplo, recién invitado y aún no aceptado, o con todas sus memberships revocadas). En ese estado no puede operar sobre ningún dato tenant-scoped; el login debe reflejar explícitamente esta condición en vez de fallar de forma ambigua.
- **Rol eliminado con usuarios todavía asignados**: dado que `roles` usa soft-delete (ADR-010), un rol "eliminado" no desaparece físicamente mientras existan `user_company_memberships` que lo referencien. El sistema debe seguir resolviendo los permisos de esos usuarios contra el rol soft-deleted hasta que se les reasigne un rol activo explícitamente; no se infiere ni se degrada automáticamente a otro rol.
- **Acceso cross-tenant de `SUPER_ADMIN`**: `SUPER_ADMIN` es la única vía de excepción explícita al principio de aislamiento estricto entre empresas. El detalle de cómo se implementa esta excepción controlada (impersonación auditada, alcance exacto de la visibilidad cross-tenant) vive en [15-MULTI-TENANCY.md](./15-MULTI-TENANCY.md); este módulo únicamente garantiza que el rol `SUPER_ADMIN` existe en el catálogo de roles de sistema y que su verificación de permisos no está limitada a una sola `company_id`.

## Errores

- **Credenciales inválidas**: rechazo genérico de login sin revelar si el error es de email o contraseña (para no facilitar enumeración de cuentas).
- **Sesión expirada/revocada**: cualquier operación con una sesión expirada o con `auth_tokens.revoked_at` no nulo debe rechazarse, forzando un nuevo login.
- **Permiso denegado (403)**: toda operación que requiera un permiso atómico no presente en el rol de la membership activa del `user` debe responder `403`, sin distinguir entre "no tienes ese permiso" y "el recurso no existe" cuando la distinción pudiera filtrar información sensible (ver también el catálogo general de errores en [18-API.md](./18-API.md)).

## Seguridad

- **Hashing de contraseñas**: `users.password_hash` nunca almacena contraseñas en texto plano; se usa un algoritmo de hashing adaptativo (bcrypt/argon2 u equivalente — la librería/parámetros concretos son un detalle de implementación de la fase de Foundation, no de este documento).
- **Rate limiting en login**: los intentos de login deben estar limitados por IP/usuario para mitigar fuerza bruta (ver también [20-SECURITY.md](./20-SECURITY.md)).
- **Expiración y revocación de sesión**: sesión de servidor de Laravel con protección CSRF nativa, sin JWT ni refresh token (ver ADR-017, actualizado en [23-DECISIONS.md](./23-DECISIONS.md) tras fijar el stack en ADR-022). La sesión se invalida en el backend al hacer logout o al revocar la `user_company_membership` correspondiente.
- **MFA (autenticación multifactor)**: **RESUELTO** (ADR-031 en [23-DECISIONS.md](./23-DECISIONS.md)) — no se implementa en la v1. El login usa usuario/contraseña con hashing adaptativo y rate limiting; MFA queda como mejora futura si el crecimiento del negocio o un requisito de un cliente concreto lo justifica.

## Dependencias

- [15-MULTI-TENANCY.md](./15-MULTI-TENANCY.md): resolución de "empresa activa" por request y detalle del acceso cross-tenant de `SUPER_ADMIN`.
- [16-AUDIT.md](./16-AUDIT.md): registro de auditoría de cambios de permisos, asignación de roles y accesos cross-tenant.
- [05-DATABASE.md](./05-DATABASE.md): definición completa de columnas, constraints e índices de `users`, `auth_tokens`, `roles`, `permissions`, `role_permissions`, `user_company_memberships`.
- [20-SECURITY.md](./20-SECURITY.md): rate limiting, gestión de sesiones a nivel de infraestructura, cumplimiento normativo.

## Criterios de aceptación

- Todo endpoint sensible valida un permiso atómico específico del catálogo `permissions`, no solo la pertenencia a un rol por nombre.
- Un `user` puede autenticarse una sola vez y operar sobre cualquiera de sus empresas mediante `user_company_memberships`, sin necesidad de credenciales distintas por empresa.
- Ningún flujo de autenticación ni autorización permite resolver o forzar un `company_id` distinto al de la membership activa, salvo la excepción documentada de `SUPER_ADMIN`.
- La matriz RBAC de este documento coincide exactamente con la implementada en `role_permissions` para los roles de sistema.
- Toda asignación, revocación o cambio de rol queda registrado en `audit_logs`.
