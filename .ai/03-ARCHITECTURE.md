# 03 — Arquitectura

## Objetivo

Definir el estilo arquitectónico del sistema, las capas que lo componen, los límites de responsabilidad de cada uno de los 22 módulos funcionales, y las convenciones estructurales que cualquier implementación (humana o agente de IA) debe respetar para mantener el sistema coherente a medida que crece.

Este documento es la referencia estructural de más alto nivel del sistema. No define entidades ni columnas (eso vive en [04-DOMAIN-MODEL.md](./04-DOMAIN-MODEL.md) y [05-DATABASE.md](./05-DATABASE.md)), y no define reglas de negocio de un módulo específico (eso vive en el archivo `NN-*.md` de cada módulo). El stack tecnológico es **PHP/Laravel (backend) + Inertia.js/Vue (frontend)**, ver ADR-022 en [23-DECISIONS.md](./23-DECISIONS.md).

## Alcance

Cubre: estilo arquitectónico general, capas del sistema, la matriz completa de los 22 módulos con sus límites, el mecanismo de comunicación interna entre módulos, los candidatos a extracción futura a microservicios, y las convenciones de organización de carpetas a nivel conceptual.

No cubre: modelo de datos detallado, reglas de negocio de un módulo individual, diseño de UI, ni decisiones de infraestructura/despliegue (ver [22-DEPLOYMENT.md](./22-DEPLOYMENT.md)).

## Conceptos

- **Monolito modular**: una única unidad de despliegue (un solo proceso/servidor backend) internamente organizada en módulos de dominio con límites de responsabilidad estrictos, en oposición a un monolito "big ball of mud" sin fronteras internas.
- **Módulo**: unidad de organización del código que agrupa entidades, reglas de negocio y endpoints de un dominio funcional específico (ver matriz de 22 módulos más abajo). Un módulo puede depender de otros módulos, pero no debe duplicar su lógica ni acceder directamente a sus tablas sin pasar por su capa de servicio.
- **Capa**: nivel de responsabilidad técnica transversal a todos los módulos (Frontend, API, Business Logic, Database).

## Entidades

Este archivo no define entidades de dominio. Ver [04-DOMAIN-MODEL.md](./04-DOMAIN-MODEL.md) para el catálogo completo de las ~52 entidades y [05-DATABASE.md](./05-DATABASE.md) para su esquema de base de datos.

## Reglas

### Estilo arquitectónico: monolito modular

El sistema se construye como **monolito modular**, no como microservicios, desde la fase inicial. Esta decisión está registrada como **ADR-001** en [23-DECISIONS.md](./23-DECISIONS.md).

Motivo (según ADR-001 del blueprint aprobado):

- El dominio tiene alta cohesión transversal: Time Calculation, Overtime, Absences/Leave y Social Security son todos consumidores directos de Attendance y todos productores directos de Payroll. Separar estos módulos en servicios independientes desde el día 1 introduciría llamadas de red, serialización y consistencia eventual donde hoy hay simples llamadas de función con transacciones ACID locales.
- El producto arranca en un solo vertical (panaderías) con una base de tenants pequeña/mediana; el costo operativo de microservicios (orquestación, descubrimiento de servicios, observabilidad distribuida, versionado de contratos entre servicios) no está justificado todavía.
- Un monolito modular bien delimitado (con los 22 módulos de este documento como fronteras internas) preserva la opción de extraer servicios más adelante sin reescribir el dominio, siempre que los módulos no se acoplen entre sí por atajos (acceso directo a tablas de otro módulo, lógica duplicada).
- Multi-tenancy (ver [15-MULTI-TENANCY.md](./15-MULTI-TENANCY.md)) es más simple de garantizar con una única base de datos y una única capa de aplicación que validan `company_id` de forma centralizada, que coordinando el aislamiento de tenant entre múltiples servicios.

### Capas

El sistema se organiza en cuatro capas, cada módulo atraviesa las cuatro:

```
Frontend  →  API  →  Business Logic  →  Database
```

| Capa | Responsabilidad | Restricciones |
|---|---|---|
| **Frontend** | Presentación, captura de input del usuario, formateo de datos ya calculados | Nunca calcula nómina, horas, recargos ni montos (ver ADR-004); solo consume la API y renderiza su respuesta. Ver [19-FRONTEND.md](./19-FRONTEND.md) |
| **API** | Autenticación de la request, resolución de la empresa activa (membership), enrutamiento a la capa de negocio, serialización de la respuesta, validación de forma del input | No contiene reglas de negocio de dominio; delega toda decisión a Business Logic. Ver [18-API.md](./18-API.md) |
| **Business Logic** | Reglas de negocio de cada módulo, orquestación entre módulos, validación semántica, escritura a través de servicios (nunca acceso directo no controlado a otra tabla de otro módulo) | Es la única capa que decide (aprobar, calcular, rechazar, cerrar); es la única fuente de verdad de "qué es correcto" |
| **Database** | Persistencia, integridad referencial, constraints, aislamiento de tenant como defensa en profundidad (`company_id` denormalizado) | No contiene lógica de negocio compleja; los constraints de base de datos son una segunda línea de defensa, no la única. Ver [05-DATABASE.md](./05-DATABASE.md) |

Toda request de escritura sensible atraviesa las cuatro capas en orden; ninguna capa puede saltarse (por ejemplo, el frontend nunca escribe directo a la base de datos, y la capa API nunca decide reglas de negocio que le corresponden a Business Logic).

### Matriz de los 22 módulos

Cada módulo tiene una responsabilidad y una lista explícita de lo que NO debe hacer, para evitar que la lógica se duplique o se filtre a un módulo equivocado.

| Módulo | Responsabilidad | NO debe hacer |
|---|---|---|
| **Authentication** | Login, sesiones, tokens, password reset, MFA | No decide permisos (eso es Authorization) |
| **Authorization** | RBAC, roles, permisos, membership por empresa | No valida credenciales; no contiene lógica de negocio de dominio |
| **Companies** | CRUD de empresa, configuración raíz de tenant | No gestiona sucursales operativas del día a día (eso es Branches) ni facturación SaaS (fuera de alcance, `PENDING DECISION`) |
| **Branches** | CRUD de sucursales, asociación de dispositivos/turnos a sucursal | No decide horarios (eso es Work Schedules) |
| **Employees** | Datos personales/laborales básicos del trabajador | No calcula nómina, no guarda datos bancarios sensibles fuera de `payroll_information`, no decide turnos |
| **Positions** | Catálogo de cargos | No define salario (vive en contrato/`salary_history`) |
| **Employment Contracts** | Historial contractual, determinar contrato vigente a una fecha | No calcula tiempo trabajado ni nómina, solo expone "qué contrato aplica" |
| **Work Schedules** | Reglas generales de jornada por plantilla/día | No genera instancias de turno con fecha (eso es Shifts) |
| **Shifts** | Turnos concretos con fecha y asignación a empleado | No calcula asistencia real ni horas trabajadas |
| **Attendance** | Captura y almacenamiento de eventos + mecanismo de ajuste | No decide si un exceso de tiempo es hora extra pagable (eso es Time Calculation + Payroll); no borra eventos |
| **Time Calculation** | Cruza planificado vs eventos reales → tiempo ordinario/extra candidato/faltante | No decide si la extra se paga ni su tarifa monetaria (eso es Overtime + Payroll) |
| **Overtime** | Ciclo de vida de horas extra (detectada→...→pagada) | No calcula el tiempo bruto (lo recibe de Time Calculation), no paga (eso es Payroll) |
| **Absences / Leave** | Solicitud y aprobación de ausencias/permisos/vacaciones/incapacidades | No calcula el efecto monetario, solo lo comunica como novedad a Payroll |
| **Payroll** | Motor de liquidación, periodos, cierre, ajustes post-cierre | Nunca corre en frontend; no gestiona afiliaciones (eso es Social Security), no genera el PDF final (eso es PDF, aunque lo dispara) |
| **Social Security** | Afiliaciones y aportes | No decide el salario base ni el neto a pagar (los consume desde Payroll) |
| **Reports** | Consultas agregadas/filtradas de solo lectura | No modifica datos, no calcula nómina, solo lee resultados ya calculados |
| **PDF** | Generación de comprobantes/reportes en PDF | No calcula montos, solo renderiza datos ya calculados por otros módulos |
| **Biometrics** | Identificación de empleado a partir de lectura del dispositivo | El lector NO calcula nómina ni decide asistencia final, solo produce un evento candidato |
| **Notifications** | Envío de alertas por eventos del sistema | No contiene lógica de negocio, solo reacciona a eventos ya decididos por otros módulos |
| **Audit** | Registro inmutable de acciones sensibles | No decide si una acción es válida (eso lo valida el módulo de origen), solo la registra |
| **Settings** | Configuración por empresa y de plataforma | No contiene reglas laborales (eso vive en Labor Rules dentro de Time Calculation) |

Nota estructural: **Authentication** y **Authorization** no tienen archivo `.ai/` propio separado; ambos se documentan juntos en [06-AUTHORIZATION.md](./06-AUTHORIZATION.md) porque comparten entidades (`users`, `auth_tokens`, `roles`, `permissions`, `role_permissions`, `user_company_memberships`) y flujos estrechamente relacionados (login, sesión, permiso). El **Motor de Reglas Laborales** tampoco tiene archivo propio: su framework general de tiempo vive en [09-TIME-CALCULATION.md](./09-TIME-CALCULATION.md) y su traducción a dinero vive en [10-PAYROLL.md](./10-PAYROLL.md), ambos referenciando las mismas tablas `labor_rules`/`labor_rule_versions`.

### Comunicación interna entre módulos

El blueprint no fija un mecanismo único de comunicación interna. Dado que el sistema es un monolito modular (no microservicios), hay dos patrones razonables y no mutuamente excluyentes:

- **Llamadas directas dentro del monolito** (invocación de función/método entre la capa de Business Logic de un módulo y la interfaz pública de otro): patrón por defecto recomendado para flujos síncronos donde el resultado se necesita inmediatamente y debe participar de la misma transacción de base de datos (ejemplo: Payroll consultando a Time Calculation y Social Security durante el cálculo de un periodo — ver flujo (c) en [10-PAYROLL.md](./10-PAYROLL.md)).
- **Eventos internos** (publicación/suscripción dentro del mismo proceso, sin cola externa obligatoria): patrón razonable para flujos asíncronos o de "reacción" donde el módulo productor no necesita conocer a sus consumidores (ejemplo: Notifications reaccionando a que un `overtime_record` cambió de estado, o el disparo de recálculo de `attendance_records` tras aprobar un `attendance_adjustment`).

**PENDING DECISION**: el blueprint no fija si el mecanismo de eventos internos usa un bus en memoria, una librería de eventos de dominio, o si se implementa como llamadas directas encadenadas al inicio y se introduce un bus solo cuando la necesidad de desacoplamiento sea real (YAGNI). Esta decisión queda abierta para la fase de implementación (Fase 1 del roadmap, ver [24-ROADMAP.md](./24-ROADMAP.md)); ningún archivo de este proyecto debe asumir una tecnología de mensajería concreta hasta que se resuelva.

Regla no negociable independiente del mecanismo elegido: ningún módulo accede directamente a las tablas de otro módulo sin pasar por la interfaz pública (capa de servicio) de ese módulo. Esto preserva la posibilidad de extraer un módulo a microservicio más adelante sin refactorizar a los módulos que lo consumen.

### Candidatos a extracción futura a microservicios

Aunque el sistema arranca como monolito modular, los límites de la matriz de 22 módulos están diseñados para que ciertos módulos puedan extraerse a servicios independientes cuando la escala o el equipo lo justifiquen, sin rediseñar el dominio. Los candidatos más probables, en orden de probabilidad, son:

1. **Payroll**: motor de cálculo intensivo en CPU, con ciclos de ejecución (cierre de periodo) que no necesitan ser síncronos con el resto de la aplicación; además concentra el mayor requisito de auditabilidad y podría beneficiarse de aislamiento de recursos y de despliegue independiente para evitar que una corrida de nómina pesada afecte la disponibilidad del resto del sistema.
2. **Biometrics**: tiene una superficie de integración externa distinta al resto (protocolos de fabricante, dispositivos físicos, tolerancia a offline/sync) y requisitos de seguridad/privacidad propios (ver [12-BIOMETRICS.md](./12-BIOMETRICS.md)); aislarlo limita el radio de impacto de una vulnerabilidad o caída relacionada con proveedores de hardware de terceros.
3. **Reports**: es de solo lectura por diseño (nunca escribe), lo que lo hace un candidato natural para escalar independientemente (réplicas de lectura, cachés, un almacén analítico separado) sin arriesgar la integridad transaccional del resto del sistema.

Esta lista es orientativa, no un compromiso de roadmap: la extracción real solo se justifica cuando aparezca presión de escala o de equipo concreta (YAGNI); no debe anticiparse en el diseño de las fases 1–15 salvo mantener los límites de módulo ya definidos en la matriz anterior.

### Stack tecnológico

**RESUELTO** (ADR-022, ver [23-DECISIONS.md](./23-DECISIONS.md)): backend en **PHP con Laravel**; frontend con **Inertia.js + Vue**, dentro del mismo monolito modular, sin API pública separada en la v1. PostgreSQL sigue siendo el almacén principal (ADR-002). Hosting: **Laravel Cloud** (ADR-021). Esta misma decisión aplica a [19-FRONTEND.md](./19-FRONTEND.md) para la parte de frontend.

### Convenciones de estructura de carpetas (nivel conceptual)

Independientemente del stack que se elija, la estructura de carpetas del backend debe organizarse **por módulo/dominio, no por tipo técnico** (es decir, evitar carpetas globales como `controllers/`, `models/`, `services/` que mezclen los 22 módulos entre sí). Cada uno de los 22 módulos de la matriz anterior debe poder ubicarse como una unidad cohesiva reconocible en el árbol de carpetas, con sus propias reglas de negocio, endpoints y acceso a datos encapsulados.

Reglas conceptuales:

- Un módulo no debe importar detalles internos de otro módulo; solo su interfaz pública.
- Las entidades compartidas por naturaleza (ejemplo: `novelty_records` como tabla "paraguas" consumida por Time Calculation y Payroll — ver Contradicción #2 en [04-DOMAIN-MODEL.md](./04-DOMAIN-MODEL.md)) pertenecen a un único módulo dueño; los demás módulos la consumen a través de la interfaz de ese módulo dueño, nunca escribiéndola directamente.
- Los módulos transversales (Audit, Notifications, Settings) se organizan como servicios de infraestructura de dominio invocables desde cualquier otro módulo, no como dependencias circulares.

La convención de nombres de carpetas sigue las convenciones idiomáticas de Laravel: estructura por dominio dentro de `app/` (ej. `app/Payroll`, `app/Attendance`, `app/Biometrics`) en vez de la organización por tipo técnico por defecto de un proyecto Laravel nuevo (`Models`/`Controllers`/`Services` planos), para respetar los límites de módulo de la sección anterior. El detalle exacto de esta organización de carpetas se define al iniciar la Fase 1 (Foundation) del roadmap.

## Flujos

Este archivo no define flujos end-to-end de negocio (ver el archivo `NN-*.md` de cada módulo). El único "flujo" relevante a nivel arquitectónico es el recorrido de una request a través de las cuatro capas descrito en la sección Reglas → Capas.

## Casos normales

- Una request de lectura simple (ejemplo: `GET /employees`) atraviesa Frontend → API (autentica y resuelve `company_id`) → Business Logic (aplica filtros de RBAC) → Database (consulta filtrada por `company_id`) y regresa por el mismo camino.
- Una request de escritura que involucra a más de un módulo (ejemplo: cerrar un periodo de nómina) se resuelve con Payroll invocando directamente, dentro de la misma transacción de base de datos, a las interfaces públicas de Time Calculation, Overtime, Absences/Leave y Social Security.

## Casos especiales

- Un módulo que necesita reaccionar a un cambio de estado de otro módulo sin bloquear la respuesta de la request original (ejemplo: Notifications reaccionando a la aprobación de una hora extra) debe usar el mecanismo de eventos internos descrito arriba, no una llamada directa síncrona que retrase la respuesta al usuario.
- Si en el futuro se extrae un módulo a microservicio (ver candidatos arriba), su interfaz pública debe mantenerse estable de cara a los módulos que lo consumen, para que la extracción sea transparente a nivel de contrato.

## Errores

- Un módulo que accede directamente a las tablas de otro módulo (bypass de su capa de servicio) es un error arquitectónico que debe bloquearse en revisión de código; rompe la posibilidad de extracción futura y duplica el riesgo de inconsistencia de reglas de negocio.
- Colocar lógica de negocio (cálculo de tiempo, dinero, o reglas de aprobación) en la capa API o en el Frontend es un error arquitectónico — ver ADR-004 y la Contradicción #6 documentada en [04-DOMAIN-MODEL.md](./04-DOMAIN-MODEL.md).

## Seguridad

La arquitectura en capas es en sí misma un control de seguridad: la capa API es el único punto de entrada autenticado, y ninguna capa inferior confía en datos de la capa superior sin revalidar (ver "validación de tenant en 3 capas" en [15-MULTI-TENANCY.md](./15-MULTI-TENANCY.md)). El detalle de autenticación/autorización vive en [06-AUTHORIZATION.md](./06-AUTHORIZATION.md); el detalle transversal de seguridad (cifrado, secretos, rate limiting, cumplimiento) vive en [20-SECURITY.md](./20-SECURITY.md).

## Dependencias

- [04-DOMAIN-MODEL.md](./04-DOMAIN-MODEL.md): las entidades que cada módulo de la matriz de esta sección manipula.
- [05-DATABASE.md](./05-DATABASE.md): el esquema físico sobre el que corre la capa Database.
- [00-SYSTEM-SPECIFICATION.md](./00-SYSTEM-SPECIFICATION.md): mapa de los 22 módulos y sus dependencias a nivel de producto.
- [15-MULTI-TENANCY.md](./15-MULTI-TENANCY.md): estrategia de aislamiento que la capa Database y la capa API deben implementar.
- [19-FRONTEND.md](./19-FRONTEND.md): comparte el mismo stack tecnológico de frontend (Inertia.js + Vue, ver ADR-022).
- [23-DECISIONS.md](./23-DECISIONS.md): ADR-001 (monolito modular) y ADR-002 (PostgreSQL) que sustentan este documento.
- Cada archivo `NN-*.md` de módulo: implementa los límites definidos en la matriz de esta sección.

## Criterios de aceptación

- La matriz de 22 módulos está completa, sin módulos faltantes ni duplicados, y usa exactamente los mismos nombres de módulo que [00-SYSTEM-SPECIFICATION.md](./00-SYSTEM-SPECIFICATION.md) y el resto de los archivos `NN-*.md`.
- Cada módulo tiene definida explícitamente su responsabilidad y al menos una cosa que NO debe hacer.
- El documento fija el lenguaje, framework y librería principal de backend y frontend (PHP/Laravel + Inertia.js/Vue, ADR-022); no fija todavía librerías secundarias específicas (ej. la librería concreta de PDF), que quedan resueltas en el archivo de dominio correspondiente.
- El mecanismo de comunicación interna entre módulos está descrito como decisión abierta de diseño (llamadas directas vs eventos internos), no como una elección cerrada no solicitada por el blueprint.
- Los candidatos a extracción a microservicios están justificados con al menos una razón concreta cada uno y explícitamente marcados como no vinculantes para el roadmap de las fases 1–15.
