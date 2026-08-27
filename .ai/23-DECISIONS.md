# 23 — Registro de Decisiones Arquitectónicas (ADRs)

## Objetivo

Mantener un registro único y completo de las decisiones arquitectónicas del proyecto, con su contexto, motivo, alternativas consideradas y consecuencias, para que ningún agente de IA futuro las cuestione o revierta sin conocer por qué se tomaron.

## Alcance

Cubre 31 decisiones: las 20 decisiones arquitectónicas originales (ADR-001 a ADR-020 — las 5 explícitas del brief y las 15 adicionales derivadas directamente de los requisitos del dominio, ver [00-SYSTEM-SPECIFICATION.md](./00-SYSTEM-SPECIFICATION.md) y [02-REQUIREMENTS.md](./02-REQUIREMENTS.md)) más las decisiones adicionales (ADR-021 en adelante) que resuelven `PENDING DECISION` de negocio/producto/infraestructura mediante conversación directa con el propietario del producto una vez completada la Fase 0, y que se siguen agregando incrementalmente a medida que se resuelven más pendientes. No incluye decisiones de producto de bajo impacto arquitectónico (ver [01-VISION.md](./01-VISION.md)) salvo donde se cruzan directamente con arquitectura, como es el caso de varias de ADR-021 en adelante.

## Plantilla

Cada ADR sigue este formato exacto:

```
### ADR-XXX: Título

**Contexto**: <situación que motiva la decisión>

**Decisión**: <qué se decidió>

**Motivo**: <por qué se decidió así>

**Alternativas consideradas**: <opciones descartadas y por qué>

**Consecuencias**: <qué implica esta decisión hacia adelante>
```

---

### ADR-001: Monolito modular (no microservicios al inicio)

**Contexto**: el sistema es greenfield, con 22 módulos y ~52 tablas por construir, y no existe todavía evidencia de carga real ni de necesidad de escalar módulos de forma independiente.

**Decisión**: construir el sistema como monolito modular: un único desplegable, con límites de módulo estrictos definidos en [03-ARCHITECTURE.md](./03-ARCHITECTURE.md).

**Motivo**: reduce la complejidad operativa de coordinar servicios distribuidos antes de validar el producto, a la vez que preserva la posibilidad de extraer módulos a servicios independientes más adelante si los límites internos se respetan.

**Alternativas consideradas**: microservicios desde el inicio (descartada: overhead de infraestructura, versionado y comunicación distribuida injustificado para un producto sin tráfico validado); monolito sin modularización interna (descartada: dificulta mantenibilidad y hace imposible una futura extracción ordenada).

**Consecuencias**: se requiere disciplina fuerte para no violar los límites de módulo de [03-ARCHITECTURE.md](./03-ARCHITECTURE.md); el propio archivo de arquitectura debe listar candidatos a extracción futura a microservicios.

---

### ADR-002: PostgreSQL como almacén principal

**Contexto**: el brief fija PostgreSQL explícitamente como base de datos del sistema.

**Decisión**: usar PostgreSQL como único almacén relacional principal para las ~52 tablas del dominio.

**Motivo**: soporta modelado relacional complejo con integridad referencial fuerte, tipos `jsonb` para parámetros de reglas laborales configurables, tipos de rango (`daterange`/`tstzrange`) y restricciones `EXCLUDE USING gist` para prevenir solapamientos de vigencia (contratos, afiliaciones), y es un motor maduro de código abierto.

**Alternativas consideradas**: ninguna evaluada seriamente — es una restricción fija del brief, no una decisión abierta.

**Consecuencias**: [05-DATABASE.md](./05-DATABASE.md) debe aprovechar tipos de rango y `jsonb` donde el modelo lo requiera; cualquier decisión de escalado horizontal futuro debe respetar las capacidades y límites de PostgreSQL.

---

### ADR-003: Eventos de asistencia inmutables + mecanismo de ajuste

**Contexto**: la asistencia real de un trabajador es un hecho legal y financiero que no puede alterarse retroactivamente sin dejar rastro.

**Decisión**: `attendance_events` es una tabla de solo inserción (INSERT-only); ninguna corrección se realiza mediante `UPDATE`/`DELETE` sobre esta tabla. Toda corrección se registra en `attendance_adjustments`, preservando el valor original y el corregido.

**Motivo**: preserva la fuente de verdad legal de lo que realmente ocurrió, permite reconstruir el historial completo de una disputa o auditoría, y previene que un error humano o malintencionado borre evidencia.

**Alternativas consideradas**: permitir `UPDATE` con columnas de auditoría en la misma fila (descartada: mezcla el hecho real con su corrección y complica distinguir "qué pasó" de "qué se corrigió"); borrar y recrear el evento (descartada: destruye el historial).

**Consecuencias**: todo flujo de corrección de asistencia requiere un registro explícito en `attendance_adjustments` con motivo y, según la matriz de aprobación, un flujo de autorización (ver [07-ATTENDANCE.md](./07-ATTENDANCE.md)).

---

### ADR-004: Motor de nómina en backend, nunca en frontend

**Contexto**: los cálculos de tiempo trabajado y de nómina determinan cuánto se le paga a una persona; son sensibles legal y financieramente.

**Decisión**: todo cálculo de horas ordinarias/extra, recargos, deducciones y neto a pagar ocurre exclusivamente en el backend. El frontend únicamente presenta resultados ya calculados.

**Motivo**: garantiza una única fuente de verdad para el cálculo, evita manipulación desde el cliente, y permite testear y auditar el cálculo de forma centralizada.

**Alternativas consideradas**: cálculo híbrido con "preview" instantáneo en frontend (descartada: duplica lógica de negocio entre cliente y servidor, con riesgo real de que ambas copias diverjan — ver regla no negociable en [AGENTS.md](./AGENTS.md)).

**Consecuencias**: la interfaz debe manejar estados de espera mientras el backend calcula (ver [19-FRONTEND.md](./19-FRONTEND.md)); ningún componente de UI puede sumar, restar o aplicar reglas de negocio sobre tiempo o dinero.

---

### ADR-005: Biometric Gateway con interfaz `BiometricProvider` desacoplada del fabricante

**Contexto**: existen múltiples fabricantes de hardware biométrico posibles, y el brief no fija uno en particular.

**Decisión**: definir una interfaz abstracta `BiometricProvider` en el Biometric Gateway; cada fabricante concreto se integra mediante un adaptador que implementa esa interfaz.

**Motivo**: evita acoplar el núcleo del sistema a un proveedor de hardware específico y permite soportar varios dispositivos/fabricantes en paralelo sin tocar el core.

**Alternativas consideradas**: integración directa por proveedor sin capa de abstracción (descartada: cada proveedor nuevo requeriría modificar el núcleo del sistema de asistencia).

**Consecuencias**: el/los proveedor(es) concreto(s) a integrar quedan como `PENDING DECISION` (ver [12-BIOMETRICS.md](./12-BIOMETRICS.md)); el contrato exacto de la interfaz se ajusta cuando se conozca el/los proveedor(es) reales.

---

### ADR-006: Estrategia de aislamiento de tenant — `company_id` denormalizado

**Contexto**: el sistema exige que nunca sea posible cruzar datos entre empresas; un único error de `JOIN` olvidado en una query podría filtrar datos entre tenants.

**Decisión**: denormalizar la columna `company_id` en cada tabla tenant-scoped, incluso donde técnicamente podría derivarse por FK, como defensa en profundidad. Se evalúa a futuro el uso de PostgreSQL Row-Level Security como capa adicional.

**Motivo**: permite validar el aislamiento de tenant directamente en cada query sin depender de un `JOIN` correcto en cada punto del código; reduce la superficie de error de un solo punto de falla.

**Alternativas consideradas**: schema-per-tenant (descartada: complejidad operativa de migraciones multiplicadas por tenant); database-per-tenant (descartada: no escala operativamente con muchas empresas pequeñas); normalización estricta solo vía FK sin denormalizar (descartada: mayor riesgo de fuga de datos por errores de query).

**Consecuencias**: se acepta conscientemente cierta redundancia de datos a cambio de seguridad multi-tenant (trade-off documentado también como contradicción resuelta en [04-DOMAIN-MODEL.md](./04-DOMAIN-MODEL.md)); toda tabla nueva tenant-scoped debe incluir `company_id` propio salvo catálogos globales.

---

### ADR-007: Reglas laborales versionadas por fecha de vigencia

**Contexto**: las reglas laborales (tolerancias, recargos, redondeo) pueden cambiar en el tiempo por decisión de la empresa o por cambios legales; recalcular un periodo pasado con reglas nuevas produciría resultados incorrectos.

**Decisión**: modelar `labor_rule_versions` con `effective_from`/`effective_to`; ninguna regla laboral se hardcodea ni se trata como "siempre vigente".

**Motivo**: permite recalcular correctamente cualquier periodo pasado usando exactamente las reglas que estaban vigentes en ese momento, y soporta cambios legales futuros sin desplegar código nuevo.

**Alternativas consideradas**: reglas hardcodeadas en código de aplicación (descartada: requiere despliegue para cada cambio legal y es imposible de auditar); una única fila de "regla actual" sin historial de versiones (descartada: rompe el recálculo correcto de periodos pasados).

**Consecuencias**: todo cálculo de tiempo o dinero debe resolver la versión de regla vigente mediante el patrón "effective-dated lookup" (ver [04-DOMAIN-MODEL.md](./04-DOMAIN-MODEL.md)).

---

### ADR-008: Quincena como periodo de nómina soportado desde el día 1

**Contexto**: la quincena es el tipo de periodo prioritario del negocio objetivo (panaderías), pero el sistema también debe admitir semanal y mensual.

**Decisión**: modelar `payroll_periods` de forma genérica con un campo `period_type` (semanal/quincenal/mensual), sin tratar la quincena como un caso especial de código.

**Motivo**: evita reescribir el modelo de datos o el motor de cálculo cuando se necesite otro tipo de periodo; la quincena es simplemente un valor de configuración.

**Alternativas consideradas**: modelar inicialmente solo la quincena y generalizar después (descartada: el costo de refactorizar un modelo ya en producción sería mayor que diseñarlo genérico desde el inicio).

**Consecuencias**: el motor de Payroll (ver [10-PAYROLL.md](./10-PAYROLL.md)) debe ser agnóstico al `period_type` específico en toda su lógica de cálculo.

---

### ADR-009: RBAC granular por permiso atómico

**Contexto**: distintos roles del negocio (dueño, RRHH, supervisor, contador, etc.) requieren combinaciones de acceso distintas, y algunas empresas pueden necesitar roles custom.

**Decisión**: `permissions` es un catálogo atómico global; los roles (`roles`) son colecciones configurables de permisos (`role_permissions`), ya sean de sistema (`company_id NULL`) o custom por empresa.

**Motivo**: da flexibilidad para que cada empresa ajuste permisos sin tocar código, y evita lógica de autorización dispersa basada en comparar nombres de rol.

**Alternativas consideradas**: RBAC basado únicamente en el nombre fijo del rol (descartada: rígido, no permite roles custom por empresa); control de acceso basado en atributos (ABAC) completo (descartada: complejidad innecesaria para el alcance actual del sistema).

**Consecuencias**: toda verificación de autorización en el código se hace contra un código de permiso atómico (ej. `payroll.close`), nunca contra el nombre del rol (ver [06-AUTHORIZATION.md](./06-AUTHORIZATION.md)).

---

### ADR-010: Política de soft-delete vs hard-delete

**Contexto**: el sistema necesita "eliminar" entidades operativas obsoletas (empleados inactivos, sucursales cerradas) sin romper el histórico legal de nómina y asistencia asociado a ellas.

**Decisión**: soft-delete para entidades operativas (`employees`, `branches`, catálogos); prohibición absoluta de hard-delete sobre entidades históricas (`attendance_events`, `audit_logs`, `payroll_entries` en estado `CLOSED`).

**Motivo**: los históricos de nómina y asistencia tienen valor legal y de auditoría que no puede perderse, mientras que las entidades operativas sí necesitan poder "desactivarse" limpiamente de las vistas activas.

**Alternativas consideradas**: hard-delete generalizado con archivado externo (descartada: mayor complejidad operativa y riesgo de pérdida accidental de datos); no permitir ningún tipo de borrado, ni siquiera lógico (descartada: no permite desactivar catálogos obsoletos de forma ordenada).

**Consecuencias**: toda query de listado "activo" debe filtrar por `status`/`deleted_at`; los reportes históricos deben seguir resolviendo registros inactivos por su `id` sin importar su estado actual.

---

### ADR-011: Estrategia de generación de PDF

**Contexto**: el sistema necesita generar comprobantes de pago y reportes en PDF, sin que el brief fije una librería o servicio específico.

**Decisión**: definir una interfaz abstracta `PdfGenerator` y almacenar cada artefacto generado de forma versionada en `generated_documents`; la librería o servicio concreto de renderizado queda pendiente de elegir.

**Motivo**: desacopla el motor de negocio (qué datos van en el comprobante) de la tecnología concreta de renderizado, permitiendo cambiarla sin afectar al resto del sistema.

**Alternativas consideradas**: acoplar directamente a una librería específica desde el inicio (descartada: dificulta cambiar de proveedor o librería más adelante sin refactor mayor).

**Consecuencias**: la librería/servicio concreto queda como `PENDING DECISION` (ver [14-PDF.md](./14-PDF.md)); toda regeneración de un documento crea una nueva versión en `generated_documents`, nunca sobrescribe la anterior.

---

### ADR-012: Patrón "evento + ajuste" generalizado

**Contexto**: el mismo problema de fondo —corregir algo ya cerrado sin sobrescribirlo— aparece tanto en asistencia (`attendance_events`/`attendance_adjustments`) como en nómina cerrada.

**Decisión**: generalizar el patrón evento + ajuste también a `payroll_adjustments` sobre `payroll_entries` en estado `CLOSED`, en vez de inventar un mecanismo de corrección distinto para nómina.

**Motivo**: mantiene un solo patrón mental —"cómo se corrige algo que ya es histórico"— reutilizable en todo el sistema, reduciendo la carga cognitiva y el riesgo de inconsistencias entre módulos.

**Alternativas consideradas**: mecanismos de corrección distintos por módulo, diseñados ad hoc (descartada: multiplica la complejidad y el riesgo de que cada módulo lo resuelva de forma distinta e incompatible).

**Consecuencias**: cualquier módulo futuro que introduzca datos "cerrados" o inmutables debe seguir este mismo patrón (ver antipatrones prohibidos en [AGENTS.md](./AGENTS.md)), nunca inventar uno paralelo.

---

### ADR-013: Usuarios como identidad global + membership multiempresa

**Contexto**: se busca soportar "1 cuenta → N empresas" (por ejemplo, un contador que trabaja para varias panaderías clientes) sin acoplar la identidad del usuario a un único tenant.

**Decisión**: `users` es una tabla global, no tenant-scoped; `user_company_memberships` vincula usuario + empresa + rol, habilitando que un mismo usuario tenga acceso a múltiples empresas con roles potencialmente distintos en cada una.

**Motivo**: evita duplicar identidad para la misma persona en cada empresa donde trabaja, y simplifica la autenticación a un único punto de entrada por usuario.

**Alternativas consideradas**: usuario tenant-scoped, con una fila de usuario distinta por cada empresa incluso para la misma persona física (descartada: duplica identidad, complica el login único y no soporta de forma nativa el caso de una persona con múltiples empresas).

**Consecuencias**: toda sesión debe resolver una "empresa activa" en cada request, y todo el aislamiento de datos posterior depende de esa resolución (ver [15-MULTI-TENANCY.md](./15-MULTI-TENANCY.md)).

---

### ADR-014: `attendance_records` como estado derivado/recalculable

**Contexto**: existe el riesgo de tratar un resumen calculado de asistencia como si fuera la fuente de verdad primaria, lo cual rompería la trazabilidad hacia los eventos reales.

**Decisión**: `attendance_records` es un caché derivado, siempre regenerable por completo a partir de `attendance_events` más las reglas laborales vigentes; nunca es la fuente de verdad primaria.

**Motivo**: si se detecta un error en el algoritmo de cálculo o cambia una regla laboral retroactivamente aplicable, el sistema puede recalcular sin pérdida de información, porque los eventos originales permanecen intactos.

**Alternativas consideradas**: tratar `attendance_records` como una tabla editable directamente (descartada: rompería la trazabilidad hacia los eventos reales establecida en ADR-003).

**Consecuencias**: ninguna corrección de asistencia edita `attendance_records` directamente; toda corrección dispara un recálculo desde los eventos (ver [09-TIME-CALCULATION.md](./09-TIME-CALCULATION.md)).

---

### ADR-015: Tabla de staging `biometric_raw_events`

**Contexto**: los dispositivos biométricos pueden enviar eventos duplicados, fuera de orden, o de forma diferida tras un periodo offline.

**Decisión**: toda lectura biométrica cruda se inserta primero en `biometric_raw_events` (append-only) antes de convertirse, tras identificación y deduplicación, en un `attendance_event` validado.

**Motivo**: absorbe el ruido propio del hardware sin contaminar el histórico inmutable de asistencia, y permite reprocesar un evento crudo sin perder el dato original tal como llegó del dispositivo.

**Alternativas consideradas**: insertar directamente en `attendance_events` desde el dispositivo (descartada: expondría el histórico inmutable de asistencia a duplicados y errores de hardware sin posibilidad de limpieza previa).

**Consecuencias**: existe un paso explícito de identificación de empleado y deduplicación entre el staging y el evento final (ver flujo de fichada biométrica en [12-BIOMETRICS.md](./12-BIOMETRICS.md)).

---

### ADR-016: Mecanismo de corrección post-cierre de nómina

**Contexto**: una nómina cerrada es inmutable a nivel de aplicación (ver ADR-010), pero operativamente puede necesitarse corregir un error detectado después del cierre.

**Decisión**: se documentan dos estrategias válidas — **reapertura auditada** (un rol privilegiado reabre el periodo, corrige, y vuelve a cerrarlo, quedando el cierre anterior en `audit_logs`) y **ajuste en periodo siguiente** (se inyecta una línea compensatoria en el próximo periodo abierto del mismo empleado, sin tocar el periodo cerrado). El mecanismo por defecto entre ambos queda pendiente de definición.

**Motivo**: ambas estrategias son operativamente legítimas según el contexto de cada empresa; imponer una sola sin que el dueño de producto la elija equivaldría a inventar una regla de negocio no solicitada.

**Alternativas consideradas**: prohibir toda corrección posterior al cierre (descartada: poco realista operativamente, los errores de nómina ocurren); permitir edición directa de una entrada cerrada (descartada: viola ADR-003/ADR-010 y rompe la inmutabilidad del cierre).

**Consecuencias**: `PENDING DECISION`: cuál de las dos estrategias es el comportamiento por defecto del sistema (ver [10-PAYROLL.md](./10-PAYROLL.md)); mientras tanto, el esquema de datos debe soportar ambas estructuralmente.

---

### ADR-017: Estrategia de autenticación de sesión

**Contexto**: el sistema necesita sesiones seguras y revocables. La propuesta original de este ADR (JWT de acceso corto + refresh rotativo) era una propuesta de trabajo explícitamente revisable cuando se definiera el stack — ver ADR-022, que ya fijó Laravel + Inertia.js/Vue como un monolito sin API pública separada en la v1 (sin app móvil nativa, ver ADR-029).

**Decisión**: usar el mecanismo de **sesión de servidor de Laravel** (cookie de sesión firmada + protección CSRF nativa), no JWT. La revocación de sesión se hace invalidando la sesión del lado del servidor (y, si corresponde, forzando el cierre de sesión de un `user_company_membership` revocado).

**Motivo**: JWT con refresh rotativo está pensado para clientes desacoplados (SPA pura consumiendo una API, o una app móvil nativa) que necesitan portar un token entre distintos front-ends. Con Inertia.js, el frontend se sirve desde el mismo monolito Laravel y comparte el ciclo de vida de la sesión del servidor — usar JWT aquí agregaría complejidad (gestión de expiración, rotación, almacenamiento seguro en cliente) sin un beneficio real, ya que no hay un segundo cliente desacoplado consumiendo la misma sesión en la v1.

**Alternativas consideradas**: JWT de acceso corto + refresh rotativo (la propuesta original — descartada tras fijar el stack: resuelve un problema, el de clientes desacoplados, que no existe en la v1); JWT de larga duración sin refresh (descartada: amplía innecesariamente la ventana de riesgo si un token se compromete, y sigue sin ser necesaria).

**Consecuencias**: [06-AUTHORIZATION.md](./06-AUTHORIZATION.md) y [19-FRONTEND.md](./19-FRONTEND.md) documentan sesión de servidor + CSRF en vez de JWT; `auth_tokens` se usa para sesiones activas revocables, no para JWT/refresh tokens. Si en el futuro se introduce una app móvil nativa o una API pública separada (ver ADR-029), ese momento es el indicado para reevaluar un esquema de tokens portátiles (JWT u otro) para ese cliente específico, sin necesariamente cambiar la autenticación del frontend web.

---

### ADR-018: Auditoría como módulo transversal write-only

**Contexto**: el sistema requiere garantía fuerte de que toda acción sensible quede registrada, sin excepciones silenciosas.

**Decisión**: toda escritura de negocio sensible pasa por una capa que garantiza el registro correspondiente en `audit_logs`; si esa escritura de auditoría falla, la transacción de negocio completa debe abortar.

**Motivo**: decisión deliberadamente conservadora — es preferible que una operación sensible falle por completo a que se ejecute sin dejar rastro auditable, dado el dominio (dinero, datos personales, biometría).

**Alternativas consideradas**: registrar la auditoría de forma "best effort" o asíncrona desacoplada (fire-and-forget) (descartada: podría perder registros de auditoría silenciosamente, contradiciendo el principio de trazabilidad total del sistema).

**Consecuencias**: la escritura en `audit_logs` debe ocurrir dentro de la misma transacción de base de datos que la acción de negocio que audita (ver [16-AUDIT.md](./16-AUDIT.md)).

---

### ADR-019: Convención de versionado de API

**Contexto**: la API necesita poder evolucionar con el tiempo sin romper integraciones o clientes ya existentes (incluyendo el propio frontend del producto).

**Decisión**: prefijo `/api/v1` para todos los endpoints, con estilo REST uniforme aplicado de forma consistente en todos los módulos.

**Motivo**: convención estándar y predecible que permite introducir `/api/v2` en el futuro sin romper integraciones existentes bajo `/api/v1`.

**Alternativas consideradas**: no versionar explícitamente la URL (descartada: dificulta introducir cambios incompatibles de forma controlada); versionar solo mediante un header HTTP (descartada: menos descubrible y menos convencional para consumidores externos del API).

**Consecuencias**: todo endpoint documentado en [18-API.md](./18-API.md) vive bajo `/api/v1` hasta que se decida explícitamente introducir una versión nueva.

---

### ADR-020: Motor de Reglas Laborales desacoplado del núcleo

**Contexto**: la legislación/país exacto a aplicar en reglas laborales es un `PENDING DECISION` (ver [02-REQUIREMENTS.md](./02-REQUIREMENTS.md)); el sistema no puede asumir una legislación específica sin arriesgarse a tener que reescribir su núcleo cuando se defina.

**Decisión**: los parámetros de reglas laborales (tolerancias, recargos, redondeo, etc.) se configuran vía `labor_rules`/`labor_rule_versions` (con parámetros en `jsonb`), consumidos por Time Calculation y Payroll, en vez de existir como constantes en el código de aplicación.

**Motivo**: permite operar el sistema bajo distintos contextos legales sin recompilar ni redesplegar, y se alinea directamente con el versionado por vigencia de ADR-007.

**Alternativas consideradas**: constantes de configuración vía variables de entorno (descartada: no soporta versionado por vigencia ni configuración distinta por empresa); hardcodear una legislación de referencia inicial con intención de refactorizar después (descartada: contradice directamente la regla no negociable de no asumir reglas legales no documentadas, ver [AGENTS.md](./AGENTS.md)).

**Consecuencias**: [09-TIME-CALCULATION.md](./09-TIME-CALCULATION.md) y [10-PAYROLL.md](./10-PAYROLL.md) deben leer siempre desde `labor_rule_versions` vigente, nunca desde valores fijos en código.

---

### ADR-021: Laravel Cloud como plataforma de hosting

**Contexto**: se necesitaba un proveedor de hosting compatible con procesos de larga duración de Laravel (colas, scheduler, conexión persistente a PostgreSQL) y con un costo de entrada bajo para el tamaño inicial del negocio. Vercel, propuesto inicialmente por el propietario del producto, fue descartado tras verificación técnica: es una plataforma serverless pensada sobre todo para Next.js, sin soporte nativo de colas ni cron de Laravel.

**Decisión**: usar **Laravel Cloud**, plan **Starter** ($5/mes con $5 de crédito de uso incluido, primer mes gratis), como proveedor de hosting para los entornos de desarrollo, staging y producción.

**Motivo**: es la plataforma oficial de Laravel — compute Flex con *scale-to-zero*, PostgreSQL serverless administrado, colas y scheduler administrados, CDN y protección DDoS incluidos — con un costo de entrada verificado como el más bajo de las alternativas serias evaluadas, y límites de gasto configurables que evitan sorpresas de facturación.

**Alternativas consideradas**: Vercel (descartada: no soporta procesos de larga duración de PHP/Laravel); Railway y Render (descartadas para v1: precio de entrada más alto o menos alineadas específicamente a Laravel); VPS + Laravel Forge (descartada para v1: más control pero más carga operativa de la necesaria para una sola empresa cliente; queda como opción de escalamiento futuro si el negocio crece significativamente).

**Consecuencias**: [22-DEPLOYMENT.md](./22-DEPLOYMENT.md) documenta los tres entornos y el pipeline asumiendo Laravel Cloud; la gestión de secretos usa las variables de entorno cifradas de la plataforma en vez de un vault externo hasta que el crecimiento lo justifique; el RPO/RTO exacto de backups queda como `PENDING DECISION` menor, sujeto a confirmar contra la documentación operativa del proveedor.

---

### ADR-022: Stack tecnológico — PHP/Laravel + Inertia.js/Vue

**Contexto**: [03-ARCHITECTURE.md](./03-ARCHITECTURE.md) dejaba el stack técnico como `PENDING DECISION` explícito.

**Decisión**: backend en PHP con el framework **Laravel**; frontend con **Inertia.js + Vue**, dentro del mismo monolito modular, sin una API pública separada en la v1.

**Motivo**: Laravel ofrece un ecosistema maduro para multi-tenancy, colas, scheduler y generación de PDF; Inertia.js permite una experiencia de SPA real sin mantener una API JSON separada a mano, manteniendo un solo repositorio y un solo ciclo de release — decisión del propietario del producto.

**Alternativas consideradas**: Node.js/TypeScript y Python (Django/FastAPI) (descartadas: el propietario del producto prefirió Laravel); Laravel como API + SPA totalmente separada (descartada para v1: más trabajo inicial sin necesidad concreta todavía, ver ADR-029 sobre alcance móvil).

**Consecuencias**: todo `PENDING DECISION` de stack técnico en [00-SYSTEM-SPECIFICATION.md](./00-SYSTEM-SPECIFICATION.md), [02-REQUIREMENTS.md](./02-REQUIREMENTS.md), [03-ARCHITECTURE.md](./03-ARCHITECTURE.md) y [19-FRONTEND.md](./19-FRONTEND.md) queda resuelto; las convenciones de estructura de carpetas deben alinearse a las convenciones idiomáticas de Laravel.

---

### ADR-023: Legislación objetivo v1 — Colombia exclusivamente

**Contexto**: [02-REQUIREMENTS.md](./02-REQUIREMENTS.md) dejaba el país/legislación objetivo como `PENDING DECISION`.

**Decisión**: la v1 del sistema apunta **exclusivamente a la legislación laboral y de seguridad social de Colombia**.

**Motivo**: decisión de negocio del propietario del producto; permite modelar concretamente EPS/ARL/pensión/CCF y documentos de identidad colombianos sin renunciar a que el motor de reglas laborales (ADR-007, ADR-020) siga siendo configurable y versionado por vigencia.

**Alternativas consideradas**: modelo genérico multi-país desde el día 1 (descartada para v1: costo de diseño adicional sin necesidad de negocio inmediata).

**Consecuencias**: [11-SOCIAL-SECURITY.md](./11-SOCIAL-SECURITY.md) puede modelar catálogos específicos de Colombia cuando se implemente, siempre sujeto a validación profesional explícita (ver regla no negociable en [AGENTS.md](./AGENTS.md) — este ADR **no** autoriza a inventar porcentajes ni fórmulas legales); el algoritmo exacto de prorrateo de contrato a mitad de periodo sigue como `PENDING DECISION` hasta esa validación.

---

### ADR-024: Modelo comercial multi-tenant v1 — 1 cuenta = 1 empresa

**Contexto**: [15-MULTI-TENANCY.md](./15-MULTI-TENANCY.md) dejaba el modelo comercial multi-tenant como `PENDING DECISION`, aunque el modelo de datos (`user_company_memberships`) ya soporta varias empresas por cuenta.

**Decisión**: para la v1, **cada cuenta del SaaS administra una sola empresa**.

**Motivo**: modelo más simple de vender, facturar y soportar para el mercado inicial (panaderías individuales) — decisión del propietario del producto.

**Alternativas consideradas**: 1 cuenta = N empresas desde v1 (descartada para v1: complejidad comercial y de precios no justificada todavía, aunque el modelo de datos ya lo permite para no bloquear una expansión futura sin migración).

**Consecuencias**: el onboarding y la facturación se diseñan asumiendo una empresa por cuenta; `user_company_memberships` se mantiene en el modelo de datos sin cambios, dejando la puerta abierta a habilitar 1:N más adelante sin rediseño.

---

### ADR-025: Moneda única — peso colombiano (COP) en v1

**Contexto**: la moneda soportada era un `PENDING DECISION` explícito.

**Decisión**: el sistema opera en una **sola moneda (COP)** para la v1; no hay selección de moneda por empresa.

**Motivo**: simplifica el modelo de datos y el formateo de reportes/comprobantes; coherente con ADR-023 (Colombia exclusivamente) — decisión del propietario del producto.

**Alternativas consideradas**: multi-moneda desde el inicio (descartada para v1: complejidad no justificada sin mercado que lo requiera todavía).

**Consecuencias**: si el sistema se expande a otro país con otra moneda (ver expansión futura en [01-VISION.md](./01-VISION.md)), el soporte multi-moneda se introduce como un cambio de alcance explícito, con su propio ADR en ese momento.

---

### ADR-026: Corrección post-cierre de nómina por defecto — ajuste en el periodo siguiente

**Contexto**: ADR-016 documentaba dos estrategias válidas de corrección post-cierre sin fijar un default.

**Decisión**: el mecanismo **por defecto** es "ajuste en el periodo siguiente": un periodo cerrado no se reabre por defecto; la corrección se inyecta como línea compensatoria (`payroll_adjustments`) en la próxima liquidación del mismo empleado.

**Motivo**: decisión del propietario del producto; evita que la operación más sensible (reapertura de un periodo cerrado) sea el camino por defecto, reservándola para casos excepcionales.

**Alternativas consideradas**: reapertura auditada como default (descartada como default; se mantiene disponible en el modelo de datos — `payroll_periods.status = REOPENED` — para los casos que realmente la ameriten).

**Consecuencias**: [10-PAYROLL.md](./10-PAYROLL.md) y [04-DOMAIN-MODEL.md](./04-DOMAIN-MODEL.md) documentan "ajuste en periodo siguiente" como comportamiento estándar; el estado `REOPENED` no se elimina de la máquina de estados, solo deja de ser el camino por defecto.

---

### ADR-027: `payroll.close` — un solo rol basta (sin maker-checker)

**Contexto**: [06-AUTHORIZATION.md](./06-AUTHORIZATION.md) dejaba pendiente si cerrar un periodo de nómina requería doble aprobación.

**Decisión**: un único usuario con el permiso `payroll.close` puede cerrar un periodo, **sin** requerir una segunda aprobación de otro rol.

**Motivo**: decisión del propietario del producto; apropiado para el tamaño de negocio inicial, donde exigir maker-checker agregaría fricción operativa sin un beneficio de control proporcional.

**Alternativas consideradas**: maker-checker obligatorio (descartada para v1; queda como posible mejora configurable por empresa si negocios más grandes lo requieren en el futuro).

**Consecuencias**: [06-AUTHORIZATION.md](./06-AUTHORIZATION.md) y [10-PAYROLL.md](./10-PAYROLL.md) documentan `payroll.close` como acción de un solo rol; sigue siendo obligatoriamente auditada (ver [16-AUDIT.md](./16-AUDIT.md)).

---

### ADR-028: Sin firma electrónica en comprobantes de pago (v1)

**Contexto**: [14-PDF.md](./14-PDF.md) dejaba pendiente si los comprobantes de nómina requerían firma electrónica.

**Decisión**: los comprobantes PDF de la v1 son documentos informativos completos (empresa, trabajador, periodo, detalle, totales, observaciones) **sin** firma electrónica con validez legal.

**Motivo**: decisión del propietario del producto; evita sumar la elección e integración de un proveedor de firma digital al alcance inicial.

**Alternativas consideradas**: firma electrónica obligatoria desde v1 (descartada para v1; queda como mejora futura explícita si un requisito legal o comercial concreto lo exige).

**Consecuencias**: [14-PDF.md](./14-PDF.md) documenta la firma electrónica como extensión futura, no como hueco de diseño bloqueante.

---

### ADR-029: Sin app móvil nativa en v1 — solo web responsive

**Contexto**: [19-FRONTEND.md](./19-FRONTEND.md) y [01-VISION.md](./01-VISION.md) dejaban pendiente si habría una app móvil nativa.

**Decisión**: la v1 es exclusivamente una aplicación **web responsive** (Laravel + Inertia.js + Vue), sin app móvil nativa.

**Motivo**: decisión del propietario del producto; reduce el alcance inicial. Es coherente con ADR-022 (sin API pública separada en v1): si se decide construir una app nativa más adelante, se evaluará introducir una API dedicada en ese momento.

**Alternativas consideradas**: app móvil nativa planeada desde v1 con API separada (descartada para v1).

**Consecuencias**: [19-FRONTEND.md](./19-FRONTEND.md) no necesita diseñar para consumo por una app nativa en esta fase; el modo offline del frontend web sigue como `PENDING DECISION` independiente, no resuelto por este ADR.

---

### ADR-030: Integración DIAN (nómina electrónica) y PILA pospuesta

**Contexto**: Colombia exige nómina electrónica DIAN y aportes vía PILA para muchos empleadores; era una pregunta abierta si esa integración entraba en el alcance de v1.

**Decisión**: la v1 calcula la nómina y los aportes de seguridad social correctamente puertas adentro (con conceptos y porcentajes vigentes sujetos a validación profesional, ver [AGENTS.md](./AGENTS.md)), pero la **integración formal de envío/generación de documentos hacia DIAN y hacia los operadores de PILA queda fuera del alcance de v1**.

**Motivo**: decisión del propietario del producto; evita sumar la certificación e integración con proveedores tecnológicos autorizados por la DIAN al arranque del proyecto.

**Alternativas consideradas**: integrar DIAN/PILA desde v1 (descartada para v1; queda como fase futura explícita del roadmap, posterior a las 16 fases actuales).

**Consecuencias**: [24-ROADMAP.md](./24-ROADMAP.md) debe reflejar esta integración como trabajo futuro fuera de las Fases 0-15; [10-PAYROLL.md](./10-PAYROLL.md) y [11-SOCIAL-SECURITY.md](./11-SOCIAL-SECURITY.md) documentan el cálculo correcto de valores sin asumir todavía el formato de envío exacto de DIAN/PILA.

---

### ADR-031: Sin MFA en v1

**Contexto**: [06-AUTHORIZATION.md](./06-AUTHORIZATION.md) dejaba pendiente si el sistema requería autenticación multifactor, y para qué roles.

**Decisión**: la v1 **no** implementa MFA. El login usa usuario/contraseña con hashing adaptativo (ver Fase 2 de [24-ROADMAP.md](./24-ROADMAP.md)) y rate limiting por IP/usuario.

**Motivo**: decisión del propietario del producto; reduce fricción de login y alcance inicial, apropiado para el tamaño de negocio actual.

**Alternativas consideradas**: MFA obligatorio para roles administrativos (SUPER_ADMIN/COMPANY_OWNER/ADMIN/PAYROLL_MANAGER) (descartada para v1); MFA obligatorio para todos los usuarios (descartada para v1: fricción excesiva para el uso diario de fichada/consulta de un `EMPLOYEE`).

**Consecuencias**: [06-AUTHORIZATION.md](./06-AUTHORIZATION.md) no diseña un flujo de MFA en la v1; si se introduce más adelante, se evaluará como una mejora incremental sobre el mecanismo de sesión ya definido en ADR-017, sin requerir rediseño de autenticación.

---

### ADR-032: Matriz de aprobación de ajustes de asistencia — auto-aprobado según el rol

**Contexto**: [07-ATTENDANCE.md](./07-ATTENDANCE.md) y [06-AUTHORIZATION.md](./06-AUTHORIZATION.md) dejaban pendiente si una corrección manual de asistencia (`attendance_adjustments`) requería siempre doble validación, o si algunos roles podían auto-aprobar su propia corrección.

**Decisión**: `SUPER_ADMIN`, `COMPANY_OWNER`, `ADMIN` y `HR_MANAGER` **auto-aprueban** su propio ajuste (queda `APPROVED` de inmediato, siempre auditado en `audit_logs`). `SUPERVISOR` solo puede **solicitar** un ajuste (`status = PENDING`); alguien con `attendance.approve_adjustment` (de los roles con auto-aprobación) debe revisar y aprobar o rechazar esa solicitud.

**Motivo**: decisión del propietario del producto; balancea agilidad operativa (las correcciones de asistencia son frecuentes y a menudo urgentes, ej. un olvido de marcación) con un control adicional específicamente sobre el rol de menor jerarquía administrativa (`SUPERVISOR`), que es quien está más cerca del día a día operativo y por ende más expuesto a corregir a su propio favor o al de su equipo sin supervisión.

**Alternativas consideradas**: doble validación obligatoria para todos los roles, incluyendo `ADMIN`/`HR_MANAGER` (descartada: fricción excesiva para una operación frecuente, sin un beneficio de control proporcional dado que igual queda auditada).

**Consecuencias**: [06-AUTHORIZATION.md](./06-AUTHORIZATION.md) y [07-ATTENDANCE.md](./07-ATTENDANCE.md) documentan esta matriz como definitiva, no como propuesta; la estructura de permisos (`attendance.adjust` vs `attendance.approve_adjustment`) ya soportaba este comportamiento sin necesitar cambios de esquema.

---

### ADR-033: Sin rol `DEVICE_TECHNICIAN` dedicado en v1

**Contexto**: [06-AUTHORIZATION.md](./06-AUTHORIZATION.md) dejaba pendiente si se necesitaba un rol técnico/IT dedicado a la gestión de dispositivos biométricos en campo, separado de `ADMIN`.

**Decisión**: no se crea un rol `DEVICE_TECHNICIAN` en la v1; el permiso `devices.manage` permanece asignado a `ADMIN` (y a los roles superiores que ya lo tienen).

**Motivo**: decisión del propietario del producto; para el tamaño de negocio inicial (una panadería) no se justifica separar esta responsabilidad en un rol aparte.

**Alternativas consideradas**: crear `DEVICE_TECHNICIAN` como rol de sistema (descartada para v1; se puede introducir después sin fricción, ya que `roles` soporta agregar roles nuevos con `role_permissions` sin cambios de esquema).

**Consecuencias**: [06-AUTHORIZATION.md](./06-AUTHORIZATION.md) mantiene la matriz RBAC de 8 roles sin ampliar; si en el futuro se subcontrata la gestión de dispositivos a un tercero externo a la empresa, ese es el momento de reevaluar este ADR.

---

### ADR-034: Gate `APPROVED` de nómina — opcional, no obligatorio

**Contexto**: [10-PAYROLL.md](./10-PAYROLL.md) y [04-DOMAIN-MODEL.md](./04-DOMAIN-MODEL.md) dejaban pendiente si el estado `APPROVED` de `payroll_periods` era un paso obligatorio entre `CALCULATED` y `CLOSED`, o un paso opcional que podía omitirse.

**Decisión**: `APPROVED` es un paso **opcional**. `payroll.close` puede ejecutarse directamente desde `CALCULATED`, sin pasar por `APPROVED`.

**Motivo**: decisión del propietario del producto; menos fricción para el tamaño de negocio actual (un solo `PAYROLL_MANAGER`/`ADMIN` suele calcular y cerrar en el mismo flujo de trabajo).

**Alternativas consideradas**: `APPROVED` como gate obligatorio para todo cierre (descartada para v1: agrega un paso a cada quincena sin un beneficio de control proporcional al tamaño del negocio actual).

**Consecuencias**: la máquina de estados de `payroll_periods` en [04-DOMAIN-MODEL.md](./04-DOMAIN-MODEL.md) permite la transición directa `CALCULATED → CLOSED`, además de la ruta `CALCULATED → APPROVED → CLOSED` para quien elija usarla; el permiso `payroll.approve` sigue existiendo y siendo útil para esa ruta opcional.

---

### ADR-035: Sin PDF "borrador" de nómina antes del cierre

**Contexto**: [14-PDF.md](./14-PDF.md) y [10-PAYROLL.md](./10-PAYROLL.md) dejaban pendiente si se permitía generar un comprobante PDF marcado como "no oficial" a partir de una `payroll_entry` en estado `CALCULATED`, antes del cierre.

**Decisión**: **no** se genera PDF borrador. La generación de comprobantes ocurre exclusivamente desde entradas en estado `CLOSED`.

**Motivo**: decisión del propietario del producto; evita la confusión de que un empleado o un administrador reciba/vea un número que todavía puede cambiar antes del cierre real.

**Alternativas consideradas**: permitir un borrador con marca de agua "BORRADOR" (descartada para v1: agrega un estado de documento adicional a gestionar en `generated_documents` sin necesidad de negocio confirmada).

**Consecuencias**: [14-PDF.md](./14-PDF.md) y [10-PAYROLL.md](./10-PAYROLL.md) documentan la generación de PDF como exclusiva de `CLOSED`, sin un estado "borrador" en `generated_documents`.

---

### ADR-036: Fase 12 (Biometrics) arranca con un `BiometricProvider` mock

**Contexto**: [12-BIOMETRICS.md](./12-BIOMETRICS.md) señala que el proveedor de hardware biométrico sigue sin elegirse (`PENDING DECISION`); esto no debería bloquear la construcción y prueba del resto del pipeline de asistencia.

**Decisión**: la Fase 12 del roadmap implementa primero un `BiometricProvider` **mock** (simulado en software, sin hardware real) que cumple la interfaz agnóstica ya diseñada, y se prueba de punta a punta contra el flujo completo lector→evento. El proveedor real se conecta más adelante, cuando se elija.

**Motivo**: decisión del propietario del producto (confirmó que todavía no eligió proveedor); mantiene el roadmap avanzando sin bloquearse en una decisión de compra de hardware que puede tomar tiempo, validando igualmente todo el diseño (deduplicación, staging, identificación, manejo de desorden) contra un doble simulado.

**Alternativas consideradas**: bloquear la Fase 12 hasta elegir proveedor (descartada: retrasaría innecesariamente el roadmap por una decisión de compra ajena al diseño de software).

**Consecuencias**: [21-TESTING.md](./21-TESTING.md) debe incluir el mock de `BiometricProvider` como parte de la suite de integración de la Fase 12; el contrato real de la interfaz (definido en [12-BIOMETRICS.md](./12-BIOMETRICS.md)) no se cierra del todo hasta confirmar el proveedor real, así que debe mantenerse deliberadamente agnóstico y no sobre-ajustarse a las capacidades del mock.

---

### ADR-037: Modo offline acotado a la fichada manual

**Contexto**: [19-FRONTEND.md](./19-FRONTEND.md) dejaba pendiente si el frontend necesitaba funcionar sin conexión. Inertia.js (ADR-022) funciona mediante ida-y-vuelta al servidor por cada navegación — no es una SPA con estado local diseñada para operar offline, por lo que hacer que **todo** el dashboard funcione sin conexión sería un esfuerzo grande y en tensión con la simplicidad buscada del monolito.

**Decisión**: el modo offline se acota exclusivamente a la **pantalla de fichada manual de entrada/salida**. Esa pantalla se implementa como una capa de PWA/service worker independiente del resto de la aplicación Inertia: guarda el evento localmente (con un identificador cliente único para deduplicar, análogo al mecanismo de `biometric_raw_events` de [12-BIOMETRICS.md](./12-BIOMETRICS.md)) y lo sincroniza como `attendance_event` (`source = WEB`/`MANUAL`) cuando vuelve la conexión. El resto del sistema (reportes, nómina, configuración, auditoría) sigue requiriendo conexión.

**Motivo**: decisión del propietario del producto tras conocer el trade-off técnico; cubre el caso de negocio real (una panadería con wifi inestable no debe perder marcaciones de entrada/salida) sin pagar el costo de rediseñar todo el frontend para offline-first.

**Alternativas consideradas**: todo el dashboard offline (descartada: costo desproporcionado, tensiona la decisión de Inertia.js de ADR-022); nada offline (descartada: el propietario del producto confirmó que sí lo necesita para la fichada, dado el contexto de conectividad real del negocio).

**Consecuencias**: [19-FRONTEND.md](./19-FRONTEND.md) y [07-ATTENDANCE.md](./07-ATTENDANCE.md) deben documentar que un evento de fichada manual puede llegar con retraso (creado offline, sincronizado después) y necesita el mismo tipo de manejo de deduplicación/desorden que ya existe para eventos biométricos; la Fase 6 del roadmap debe incluir esta capa de PWA como tarea explícita, no como parte genérica del frontend Inertia.

---

### ADR-038: Alcance de la impersonación de `SUPER_ADMIN` — visibilidad completa, sin aprobación previa

**Contexto**: [15-MULTI-TENANCY.md](./15-MULTI-TENANCY.md) documentaba la impersonación de `SUPER_ADMIN` como excepción controlada y auditada al aislamiento de tenant (Contradicción #3 del blueprint aprobado), pero dejaba sin resolver su alcance exacto: si veía todo (incluyendo salarios y datos biométricos) o solo metadatos operativos, y si requería aprobación previa de la empresa afectada.

**Decisión**: durante la impersonación, `SUPER_ADMIN` ve **todos** los datos de la empresa impersonada, con el mismo alcance que un `ADMIN` de esa empresa (incluyendo `payroll_information`, salarios y datos biométricos). **No** requiere aprobación previa de la empresa afectada. El control es: motivo obligatorio declarado antes de iniciar la impersonación + registro completo y automático en `audit_logs`.

**Motivo**: decisión del propietario del producto; un soporte técnico efectivo a menudo necesita diagnosticar justo el dato problemático (ej. un salario mal calculado, una identidad biométrica corrupta), y restringir la visibilidad a metadatos limitaría la capacidad real de resolver incidentes. La auditoría completa post-hoc, combinada con el motivo obligatorio declarado por adelantado, es el control elegido en vez de un control preventivo (aprobación previa).

**Alternativas consideradas**: visibilidad restringida a metadatos operativos (descartada: limita el soporte técnico real); impersonación con aprobación previa de la empresa afectada (descartada para v1: agrega fricción y latencia a la resolución de incidentes urgentes).

**Consecuencias**: [15-MULTI-TENANCY.md](./15-MULTI-TENANCY.md) y [16-AUDIT.md](./16-AUDIT.md) documentan la impersonación de `SUPER_ADMIN` como una excepción de alcance completo, con motivo obligatorio y auditoría automática como único control — esto exige que el registro de auditoría de esta acción específica nunca pueda fallar en silencio (coherente con ADR-018).

---

### ADR-039: Notificaciones — solo email en v1, proveedor Resend

**Contexto**: [17-NOTIFICATIONS.md](./17-NOTIFICATIONS.md) dejaba pendiente qué canal(es) de notificación soportar (email/SMS/push) y qué proveedor concreto usar.

**Decisión**: la v1 soporta **solo email** como canal de notificación. Proveedor recomendado: **Resend**.

**Motivo**: decisión del propietario del producto; email cubre los eventos de notificación identificados en el brief (ausencia aprobada, hora extra autorizada, cierre de nómina, dispositivo offline) sin sumar el costo y la complejidad de integrar un proveedor de SMS. Resend se elige por su buena integración con el driver de mail de Laravel y un plan gratuito amplio adecuado al volumen de una sola empresa.

**Alternativas consideradas**: email + SMS desde v1 (descartada para v1: costo por mensaje y complejidad adicional sin necesidad de negocio confirmada); otros proveedores de email (Postmark, SES) quedan como alternativas válidas si Resend no cumple en la práctica, dado que el driver de mail de Laravel los hace intercambiables sin rediseño.

**Consecuencias**: [17-NOTIFICATIONS.md](./17-NOTIFICATIONS.md) documenta email como único canal; `notification_templates`/`notification_logs` no necesitan modelar un campo de canal variable en la v1 más allá de `email`, aunque el esquema no impide agregar otro canal después.

---

### ADR-040: Rollback automático ante un deploy fallido

**Contexto**: [22-DEPLOYMENT.md](./22-DEPLOYMENT.md) dejaba pendiente si el rollback ante un deploy fallido debía dispararse automáticamente o requerir confirmación manual de un operador.

**Decisión**: el rollback es **automático** — al detectar el fallo (smoke test post-deploy fallido o error crítico reportado), el sistema revierte a la versión estable anterior sin esperar confirmación humana.

**Motivo**: decisión del propietario del producto; minimiza el tiempo de indisponibilidad para los usuarios finales, que es más importante que dar a un operador la chance de intervenir antes de revertir — sobre todo porque revertir a la versión anterior conocida como estable es, por definición, una operación segura.

**Alternativas consideradas**: rollback manual con confirmación de un operador (descartada: deja el sistema roto más tiempo mientras alguien se entera y reacciona, sin un beneficio de control proporcional dado que la reversión es a un estado ya validado).

**Consecuencias**: [22-DEPLOYMENT.md](./22-DEPLOYMENT.md) documenta el procedimiento de rollback como automático; el incidente igual se registra (ver plan de respuesta a incidentes en [20-SECURITY.md](./20-SECURITY.md)) para que el equipo se entere y corrija la causa del fallo, aunque el sistema ya haya vuelto a un estado funcional.

---

### ADR-041: Estrategia de construcción — MVP de producción primero, incremental después

**Contexto**: el roadmap completo de [24-ROADMAP.md](./24-ROADMAP.md) describe 16 fases y el sistema completo con los 22 módulos. El propietario del producto pidió explícitamente que, al momento de construir, no se implemente todo el roadmap de una sola vez, sino el mínimo necesario para salir a producción y ser usado por una empresa real, agregando el resto de forma incremental después.

**Decisión**: se crea [25-MVP-SCOPE.md](./25-MVP-SCOPE.md), que prioriza las tareas del roadmap en `[MVP]` (se construyen primero) y `[POST-MVP]` (se agregan después del lanzamiento). El corte MVP cubre: Foundation, Auth (sin MFA), Companies/Employees, Contracts, Schedules/Shifts, Attendance (solo `WEB`/`MANUAL`, sin biometría ni modo offline), Time Calculation, Overtime/Novedades esenciales, Payroll completo, Social Security (cálculo de aportes, sin integración DIAN/PILA), solo el comprobante de nómina en PDF, auditoría transversal básica, testing de los motores críticos, y Deployment. Quedan `[POST-MVP]`: Biometrics (incluso el mock), Notifications activas, reportes avanzados, y todo lo ya pospuesto en ADR-028/029/030/031.

**Motivo**: decisión del propietario del producto; permite validar el producto con uso real (una panadería operando de punta a punta: turnos → asistencia → nómina → pago) antes de invertir esfuerzo en módulos que no bloquean esa operación básica, reduciendo tiempo hasta el primer valor real entregado.

**Alternativas consideradas**: construir el roadmap completo antes de salir a producción (descartada: retrasa la validación del producto con uso real sin necesidad, dado que los motores críticos y el flujo esencial ya cubren el valor central del negocio).

**Consecuencias**: ningún ADR ni el modelo de dominio se modifica por esta decisión — el esquema de datos de [04-DOMAIN-MODEL.md](./04-DOMAIN-MODEL.md)/[05-DATABASE.md](./05-DATABASE.md) se construye completo para lo que el MVP toca, evitando migraciones destructivas cuando se retomen los módulos `[POST-MVP]`. `AGENTS.md` referencia [25-MVP-SCOPE.md](./25-MVP-SCOPE.md) como consulta obligatoria antes de decidir qué implementar a continuación.

---

### ADR-042: Opción recomendada de hardware biométrico — ZKTeco K40

**Contexto**: [12-BIOMETRICS.md](./12-BIOMETRICS.md) marca el proveedor de hardware biométrico como `PENDING DECISION` (ver ADR-036, que ya resolvió arrancar con un `BiometricProvider` mock mientras tanto). El propietario del producto pidió investigar una opción barata y suficiente para conectar, reconocer la huella del trabajador, y empezar a contabilizar tiempo.

**Decisión**: se registra **ZKTeco K40** (o K40 Pro) como **opción recomendada, no compra confirmada**, para cuando se aborde la Fase 12 con hardware real. Características verificadas: lector de huella económico (~USD 45-100 según canal de compra), capacidad de 1.000-3.000 huellas y hasta 100.000 marcaciones, batería integrada, conectividad TCP/IP con soporte del protocolo **ADMS** — el dispositivo se configura con la URL/puerto de un servidor propio directamente en su menú y empuja cada marcación por HTTP en tiempo real, sin depender de la nube del fabricante. Existe al menos un paquete Laravel de referencia (`syofyanzuhad/filament-zkteco-adms`) que ya implementa este protocolo, útil como base para el `BiometricProvider` real.

**Motivo**: cumple los tres requisitos pedidos (barato, reconoce huella, permite contabilizar tiempo automáticamente) y encaja sin fricción con la arquitectura ya diseñada — el protocolo ADMS es exactamente el tipo de integración por push HTTP que el Device Gateway de [12-BIOMETRICS.md](./12-BIOMETRICS.md) espera recibir, y no ata el sistema a un servicio en la nube de terceros.

**Alternativas consideradas**: no se compararon en profundidad otras marcas (Hikvision, Suprema, Anviz) porque ZKTeco ya resolvía el requisito de "barato + reconoce huella + se puede conectar" con evidencia verificable (precio, specs, protocolo documentado, integración Laravel existente); si al momento de comprar el precio o la disponibilidad en Colombia no son adecuados, este ADR debe revisarse contra esas alternativas antes de comprar.

**Consecuencias**: esto **no** resuelve el `PENDING DECISION` de proveedor de forma definitiva — sigue marcado como tal en [12-BIOMETRICS.md](./12-BIOMETRICS.md) hasta que se confirme la compra real. Cuando se compre el equipo real (o se decida otro), el contrato de `BiometricProvider` debe validarse contra el protocolo ADMS documentado aquí, y este ADR se actualiza o se reemplaza con la decisión final.
