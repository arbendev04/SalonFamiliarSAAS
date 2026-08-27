# 15 — Multi-Tenancy

## Objetivo

Definir cómo el sistema garantiza el aislamiento de datos entre empresas (tenants), cómo un usuario puede pertenecer a varias empresas sin quedar atado a una sola, y cómo se resuelve en cada request cuál es la "empresa activa" sin confiar en un `company_id` enviado libremente por el cliente.

Este documento formaliza el principio no negociable del sistema: **nunca debe ser posible cruzar datos entre empresas**, incluyendo la única excepción controlada y auditada (visibilidad de soporte de `SUPER_ADMIN`).

## Alcance

Cubre: el concepto de tenant y membership multiempresa, las entidades relevantes, las reglas de resolución de empresa activa, los flujos de creación de empresa/invitación/cambio de empresa/offboarding, los casos especiales (usuario con múltiples empresas, impersonación auditada de `SUPER_ADMIN`), el manejo de errores ante intentos de cruce, y la estrategia de validación de tenant en las tres capas del sistema.

No cubre: el modelo de datos detallado de `companies`/`branches`/`user_company_memberships` (ver [04-DOMAIN-MODEL.md](./04-DOMAIN-MODEL.md) y [05-DATABASE.md](./05-DATABASE.md)), ni el detalle de RBAC (roles y permisos), que se documenta en [06-AUTHORIZATION.md](./06-AUTHORIZATION.md), ni el detalle de qué se audita y cómo, que se documenta en [16-AUDIT.md](./16-AUDIT.md).

**Nota de coordinación**: [06-AUTHORIZATION.md](./06-AUTHORIZATION.md) y [16-AUDIT.md](./16-AUDIT.md) están siendo escritos en paralelo por otro agente; los enlaces a ambos archivos en este documento usan sus nombres exactos aunque puedan no existir todavía en disco al momento de escribir este archivo.

## Conceptos

- **Tenant**: unidad de aislamiento de datos del sistema. En este dominio, el tenant es la entidad `companies` — cada empresa cliente del SaaS es un tenant independiente.
- **Membership multiempresa**: un usuario (`users`, identidad global) puede pertenecer a varias empresas simultáneamente a través de `user_company_memberships`, cada membership con su propio rol. Esto habilita el modelo "1 cuenta → N empresas" a nivel de **modelo de datos** (**ADR-013**, ver [23-DECISIONS.md](./23-DECISIONS.md)): la identidad del usuario nunca está acoplada a un tenant fijo. **Nota**: el modelo **comercial** de la v1 es "1 cuenta = 1 empresa" (**ADR-024**) — el soporte de N empresas por cuenta ya existe en el esquema, pero no se ofrece comercialmente todavía.
- **Empresa activa**: la empresa bajo cuyo contexto se ejecuta una request en un momento dado. Se resuelve del lado del servidor a partir de la sesión/membership del usuario autenticado, nunca de un valor libre enviado por el cliente.
- **Impersonación auditada**: mecanismo de excepción controlada mediante el cual `SUPER_ADMIN` puede operar temporalmente en el contexto de una empresa ajena con fines de soporte, dejando un rastro obligatorio en `audit_logs` con el motivo de la impersonación.

## Entidades

| Entidad | Rol en multi-tenancy |
|---|---|
| `companies` | Raíz de tenant; todo dato tenant-scoped resuelve, directa o indirectamente, a una fila de esta tabla |
| `branches` | Subunidad operativa dentro de una empresa; siempre hereda el aislamiento de su `company_id` |
| `user_company_memberships` | Vínculo N:N entre `users` y `companies`, con un `role_id` por membership; es la tabla que hace posible resolver la "empresa activa" de un usuario en cada request |

Ver definición completa de columnas y mutabilidad de estas tres tablas en [05-DATABASE.md](./05-DATABASE.md), bloque Tenancy/Acceso.

## Reglas

1. **`company_id` obligatorio en toda tabla tenant-scoped**, salvo los catálogos globales explícitamente marcados como `GLOBAL` en [05-DATABASE.md](./05-DATABASE.md) (ejemplo: `permissions`, `system_settings`, `users`). Toda tabla nueva que no declare `company_id` debe justificar explícitamente por qué es global.
2. **La "empresa activa" de una request se resuelve del lado del servidor** a partir de la sesión autenticada y la membership activa del usuario — nunca se acepta un `company_id` libre enviado por el cliente (en el body, en un parámetro de query o en un header) como fuente de verdad de qué empresa se está consultando o modificando. Si el cliente indica una empresa (por ejemplo, al cambiar de empresa activa), el servidor valida que exista una `user_company_memberships` vigente y con `status` activo antes de aceptar el cambio.
3. **Prohibición absoluta de cruce de datos entre empresas**: ninguna consulta, reporte, cálculo o respuesta de API puede exponer datos de una empresa distinta a la empresa activa resuelta en la request, salvo la única excepción controlada de impersonación auditada de `SUPER_ADMIN` (ver "Casos especiales").
4. Toda consulta de negocio debe filtrar explícitamente por `company_id` de la empresa activa, incluso en tablas de aislamiento `HEREDADO`, aprovechando la denormalización de `company_id` descrita en **ADR-006** ([23-DECISIONS.md](./23-DECISIONS.md) y [05-DATABASE.md](./05-DATABASE.md)).

## Flujos

### Crear empresa

1. Un usuario (existente o nuevo) inicia el alta de una empresa.
2. Se crea la fila en `companies` y, automáticamente, un `user_company_memberships` que vincula al usuario creador con esa empresa bajo un rol inicial de máximo privilegio dentro del tenant (`COMPANY_OWNER`, según la matriz RBAC de [06-AUTHORIZATION.md](./06-AUTHORIZATION.md)).
3. Se inicializa `company_settings` para la nueva empresa con sus valores por defecto (timezone, moneda, tipo de periodo por defecto).

### Invitar usuario a una empresa

1. Un usuario con permiso `users.manage` en la empresa activa invita a otro usuario (existente por email, o nuevo) a unirse con un rol específico.
2. Se crea (o activa) un `user_company_memberships` para ese usuario y esa empresa. Si el usuario invitado ya tiene cuenta en la plataforma (identidad global en `users`), no se duplica su identidad — solo se agrega una nueva membership.
3. Se notifica al usuario invitado (ver [17-NOTIFICATIONS.md](./17-NOTIFICATIONS.md)).

### Cambiar de empresa activa

1. Un usuario autenticado con más de una `user_company_memberships` vigente solicita cambiar su empresa activa (ejemplo: `POST /companies/{id}/switch-active`, ver [18-API.md](./18-API.md)).
2. El servidor valida que exista una membership activa del usuario hacia esa empresa; si no existe, se rechaza con 403.
3. La sesión (o el token) se actualiza para reflejar la nueva empresa activa; todas las requests subsiguientes del usuario en esa sesión resuelven su `company_id` contra esta empresa hasta el próximo cambio.

### Offboarding de empresa/usuario

1. **Offboarding de un usuario de una empresa**: se revoca (no se borra) el `user_company_memberships` correspondiente, cambiando su `status`; el usuario pierde acceso a esa empresa pero conserva su identidad global y cualquier otra membership activa.
2. **Offboarding completo de una empresa** (cierre de cuenta del cliente SaaS): la empresa pasa a `status` inactivo/cerrado (soft-delete, ver [05-DATABASE.md](./05-DATABASE.md)); nunca se borra físicamente porque hay históricos de nómina, asistencia y auditoría que deben permanecer accesibles para obligaciones legales y de reporte, incluso después de que la empresa deja de operar en la plataforma.

## Casos normales

- Un usuario con una única membership opera siempre bajo esa empresa; no necesita seleccionar empresa activa explícitamente.
- Un usuario con varias membership ve, al iniciar sesión, la lista de empresas a las que pertenece y selecciona (o retoma la última usada) como empresa activa.

## Casos especiales

- **Usuario con membership en 5 empresas simultáneamente**: el sistema debe soportarlo sin fricción — cada membership tiene su propio rol independiente (un usuario puede ser `HR_MANAGER` en una empresa y `EMPLOYEE` en otra), y cambiar de empresa activa nunca mezcla datos ni permisos entre ellas. Este es el caso de diseño central de ADR-013, no una situación excepcional a evitar.
- **`SUPER_ADMIN` con necesidad de visibilidad cross-tenant para soporte**: el brief exige que "nunca debe ser posible cruzar datos entre empresas", pero un rol de plataforma necesita, en la práctica, poder ver y operar entre empresas para dar soporte técnico o resolver incidentes. Esto se documenta explícitamente como **una excepción controlada y auditada, nunca como una violación del principio**:
  - Toda visibilidad cross-tenant de `SUPER_ADMIN` debe canalizarse mediante un mecanismo de **impersonación con motivo registrado obligatorio**: antes de operar en el contexto de una empresa ajena, el `SUPER_ADMIN` debe declarar explícitamente el motivo, y esa acción se registra en `audit_logs` con el usuario, la empresa impersonada, el motivo y el timestamp.
  - Esta excepción resuelve la Contradicción #3 documentada en el blueprint aprobado (sección 11): "Multi-tenancy estricto vs necesidad de soporte cross-tenant de `SUPER_ADMIN`".
  - **RESUELTO** (ADR-038 en [23-DECISIONS.md](./23-DECISIONS.md)): durante la impersonación, `SUPER_ADMIN` ve **todos** los datos de la empresa impersonada — incluyendo salarios y datos biométricos — con el mismo alcance que tendría un `ADMIN` de esa empresa. No requiere aprobación previa de la empresa afectada; el control es la combinación de motivo obligatorio declarado antes de entrar + auditoría completa post-hoc en `audit_logs`.

## Errores

- Un intento de acceso cross-tenant (ejemplo: un usuario autenticado en la empresa A intenta leer o modificar un recurso cuyo `company_id` resuelto es la empresa B, sin una membership activa hacia B) debe responder **403 Forbidden** y **registrar obligatoriamente el intento en `audit_logs`** — nunca debe fallar en silencio ni devolver un error genérico que oculte que se trató de un intento de cruce de tenant. Este registro aplica incluso si el intento fue accidental (ejemplo: un bug de cliente enviando un `company_id` incorrecto), porque el patrón de acceso en sí es una señal de seguridad relevante.
- Un cambio de empresa activa hacia una empresa donde el usuario no tiene membership vigente se rechaza con el mismo criterio (403 + audit log).

## Seguridad

La validación de tenant se aplica en **tres capas**, ninguna de las cuales confía exclusivamente en la anterior:

1. **API**: la capa de entrada resuelve la empresa activa a partir de la sesión/token autenticado (nunca de un parámetro libre del cliente) y rechaza cualquier request que no pueda resolver una membership vigente. Ver [18-API.md](./18-API.md).
2. **Capa de servicio**: cada operación de negocio revalida que el `company_id` de los recursos que va a leer o escribir coincide con la empresa activa resuelta, independientemente de lo que ya haya validado la capa API — esto evita que un error de enrutamiento o un bypass parcial de la capa API exponga datos de otro tenant.
3. **Base de datos**: `company_id` denormalizado en cada tabla tenant-scoped (**ADR-006**) actúa como la última línea de defensa, permitiendo que toda consulta filtre directamente por tenant. A futuro se evaluará adoptar **PostgreSQL Row-Level Security (RLS)** como mecanismo adicional para que el propio motor de base de datos rechace consultas sin el filtro de tenant correcto, incluso ante un error de programación en la capa de servicio — esta evaluación queda como trabajo futuro, no comprometida para la fase inicial (ver [05-DATABASE.md](./05-DATABASE.md)).

## Dependencias

- [04-DOMAIN-MODEL.md](./04-DOMAIN-MODEL.md): definición de `companies`, `branches`, `user_company_memberships` y sus relaciones clave.
- [05-DATABASE.md](./05-DATABASE.md): esquema físico de estas tablas y la estrategia de denormalización de `company_id` (ADR-006).
- [06-AUTHORIZATION.md](./06-AUTHORIZATION.md): matriz de roles y permisos que se asignan dentro de cada membership, y el detalle de login/sesión/cambio de empresa activa a nivel de autenticación.
- [16-AUDIT.md](./16-AUDIT.md): mecanismo concreto de registro de `audit_logs`, incluyendo los intentos de cruce de tenant y las impersonaciones de `SUPER_ADMIN`.
- [23-DECISIONS.md](./23-DECISIONS.md): ADR-006 (denormalización de `company_id`) y ADR-013 (usuarios como identidad global con membership multiempresa).

## Criterios de aceptación

- El documento distingue claramente tenant (`companies`) de membership (`user_company_memberships`), usando exactamente estos nombres de tabla.
- La resolución de empresa activa está descrita como una operación exclusivamente del lado del servidor, nunca confiando en un `company_id` enviado libremente por el cliente.
- La excepción de `SUPER_ADMIN` está documentada como excepción controlada y auditada (impersonación con motivo obligatorio), nunca como una violación del principio de aislamiento — y su alcance exacto ya está resuelto (ADR-038): visibilidad completa de la empresa impersonada, sin aprobación previa, con motivo obligatorio + auditoría completa como único control.
- Todo intento de cruce de tenant está descrito con su respuesta obligatoria (403 + registro en `audit_logs`).
- La validación de tenant en tres capas (API, servicio, base de datos) está descrita completa, incluyendo la mención de RLS como evaluación futura no comprometida.
- Los enlaces a `06-AUTHORIZATION.md` y `16-AUDIT.md` usan los nombres de archivo exactos aunque esos archivos puedan no existir todavía en disco al momento de escribir este documento.
