# 19-FRONTEND.md — Frontend

## Objetivo

Definir cómo la interfaz de usuario presenta información planificada, real y calculada de forma visualmente diferenciada, y garantizar que ningún cálculo de negocio (tiempo, dinero, reglas laborales) ocurre en el cliente.

## Alcance

Incluye: los 13 módulos de UI, el contenido del dashboard principal, la separación visual estricta entre información planificada/real/calculada, la prohibición absoluta de cálculos de nómina/horas en el frontend, y los flujos principales de navegación, aprobación de nómina y ajuste de asistencia en la UI.

No incluye: la lógica de negocio o el cálculo en sí de cualquier cifra mostrada — vive siempre en el módulo backend correspondiente ([09-TIME-CALCULATION.md](./09-TIME-CALCULATION.md), [10-PAYROLL.md](./10-PAYROLL.md)); la definición de permisos y roles, que vive en [06-AUTHORIZATION.md](./06-AUTHORIZATION.md); el contrato de endpoints que este módulo consume, que vive en [18-API.md](./18-API.md).

**RESUELTO**: el stack de frontend es **Inertia.js + Vue**, servido desde el mismo monolito Laravel (ver ADR-022 en [23-DECISIONS.md](./23-DECISIONS.md)). No hay app móvil nativa en la v1 (ADR-029), por lo que este documento no necesita diseñar para un cliente móvil desacoplado.

## Conceptos

- **Información planificada**: lo que se esperaba que ocurriera (ej. un `shift` asignado, un `employee_schedule` vigente). Proviene de [08-SHIFTS.md](./08-SHIFTS.md).
- **Información real**: lo que efectivamente ocurrió, registrado como hecho inmutable (ej. `attendance_events`). Proviene de [07-ATTENDANCE.md](./07-ATTENDANCE.md).
- **Información calculada**: el resultado de cruzar planificado y real a través de reglas laborales, producido exclusivamente por el backend (ej. `attendance_records`, `payroll_entries`). Proviene de [09-TIME-CALCULATION.md](./09-TIME-CALCULATION.md) y [10-PAYROLL.md](./10-PAYROLL.md).
- **Separación visual estricta**: estas tres categorías nunca se presentan mezcladas sin distinción — un componente de UI que muestre, por ejemplo, el turno planificado y las horas realmente trabajadas de un día debe diferenciarlas explícitamente (ej. columnas separadas, etiquetas, color), nunca como un único valor ambiguo.

## Módulos de UI

Los 13 módulos de interfaz, cada uno consumiendo su dominio backend correspondiente vía [18-API.md](./18-API.md):

| Módulo de UI | Descripción |
|---|---|
| Dashboard | Vista consolidada del estado operativo del día: personal activo, ausencias, horas extra, estado de nómina y alertas (ver detalle abajo). |
| Trabajadores | Gestión de `employees`, `positions`, `employment_contracts` y `salary_history`. |
| Turnos | Definición de `work_schedule_templates` y generación/asignación de `shifts`. |
| Calendario | Vista temporal combinada de turnos planificados, ausencias aprobadas y festivos (`holidays`). |
| Asistencia | Consulta de `attendance_events`/`attendance_records` y creación de `attendance_adjustments`. |
| Novedades | Gestión de `leave_records`/`absence_records` y su ciclo de aprobación. |
| Horas extra | Ciclo de vida de `overtime_records`: solicitud, autorización, rechazo. |
| Nómina | Flujo de `payroll_periods`: calcular, aprobar, cerrar, reabrir, ajustar. |
| Seguridad social | Consulta de `social_security_affiliations` y `social_security_contributions`. |
| Reportes | Consumo de los reportes filtrables de [13-REPORTS.md](./13-REPORTS.md) y descarga de documentos de [14-PDF.md](./14-PDF.md). |
| Dispositivos | Gestión de `attendance_devices`, estado de conexión y `device_heartbeats`. |
| Configuración | `company_settings`, catálogos de la empresa (posiciones, tipos de novedad, conceptos salariales). |
| Auditoría | Consulta de solo lectura de `audit_logs` vía [16-AUDIT.md](./16-AUDIT.md). |

### Dashboard principal

El dashboard consolida, como mínimo:

- Trabajadores activos (conteo).
- Personas trabajando actualmente (derivado de `attendance_events` sin `CLOCK_OUT` correspondiente en el día).
- Entradas recientes.
- Ausencias del día/periodo en curso.
- Horas extra (detectadas/pendientes de autorización).
- Próximos turnos.
- Estado de nómina del periodo en curso (`OPEN`/`CALCULATED`/`APPROVED`/`CLOSED`).
- Alertas (ej. dispositivo biométrico offline, empleado no identificado en marcación — ver catálogo de eventos en [17-NOTIFICATIONS.md](./17-NOTIFICATIONS.md)).

Todo valor del dashboard es una lectura directa de datos ya calculados por su módulo dueño; el dashboard no deriva ni calcula ninguna de estas cifras por sí mismo.

## Reglas

- **Prohibición explícita y absoluta de cálculos de nómina/horas en el frontend**: el cliente únicamente formatea y compara campos ya calculados por el backend (`attendance_records`, `payroll_entries`); nunca suma, resta, aplica un porcentaje de recargo, ni ejecuta ninguna regla de negocio sobre tiempo o dinero (ver Contradicción #6 del blueprint aprobado, resuelta así: *"No lógica de negocio en frontend" vs "separar visualmente planificado/real/calculado": podría interpretarse que el frontend necesita calcular diferencias para mostrarlas. Resolución: el frontend solo formatea y compara campos ya calculados por el backend (`attendance_records`, `payroll_entries`); cualquier suma, resta o regla de negocio sobre tiempo/dinero se prohíbe explícitamente en el cliente*).
- Esta prohibición aplica también a "cálculos aparentemente triviales" (ej. restar hora de entrada menos hora de salida para mostrar "horas trabajadas"): si el valor no viene ya calculado y persistido por el backend, la UI no debe derivarlo localmente; debe solicitarlo al backend o mostrarlo como no disponible.
- **Idioma de la interfaz: español, sin capa de i18n** (ver ADR-043 en [23-DECISIONS.md](./23-DECISIONS.md)). Todo texto visible al usuario (botones, etiquetas, mensajes, títulos de página) se escribe directamente en español neutro/profesional — no en inglés, y no mediante un sistema de traducción por claves. Las pantallas construidas antes de este ADR (Fase 1-3: Login, Register, ForgotPassword, ResetPassword, ConfirmPassword, VerifyEmail, Dashboard, Profile, Security, Appearance, Employees) quedaron en inglés y deben traducirse como tarea explícita. Identificadores de código, nombres de archivo, rutas y comentarios siguen en inglés — esto solo aplica al texto visible.
- La separación visual planificado/real/calculado (ver Conceptos) es obligatoria en todo componente que combine más de una de estas categorías.

## Flujos

### Navegación principal

- El usuario autenticado accede a los módulos de UI para los que su rol tiene al menos permiso de lectura (ver matriz RBAC en [06-AUTHORIZATION.md](./06-AUTHORIZATION.md)); los módulos sin ningún permiso de lectura no se muestran en la navegación.

### Aprobación de nómina en la UI

1. `PAYROLL_MANAGER` (u otro rol con `payroll.calculate`) dispara "Calcular" sobre un `payroll_period` desde el módulo Nómina; la UI muestra el resultado ya calculado por el backend.
2. El paso de aprobación es **opcional** (ADR-034, ver [10-PAYROLL.md](./10-PAYROLL.md)): si se usa, un rol con `payroll.approve` revisa y aprueba desde la misma vista antes de cerrar; si no, el flujo pasa directo de "Calcular" a "Cerrar".
3. Un rol con `payroll.close` ejecuta el cierre; la UI refleja el nuevo estado `CLOSED` y habilita la descarga de comprobantes ([14-PDF.md](./14-PDF.md)).

### Ajuste de asistencia en la UI

1. Un rol con `attendance.adjust` selecciona un evento o día con problema desde el módulo Asistencia y crea un `attendance_adjustment`, indicando el motivo (obligatorio).
2. Si el ajuste requiere aprobación (ver matriz RBAC), la UI lo muestra en estado `PENDING` hasta que un rol con `attendance.approve_adjustment` lo resuelva.
3. La UI nunca permite editar directamente un `attendance_event` original: solo expone la creación de un ajuste, consistente con la inmutabilidad definida en [07-ATTENDANCE.md](./07-ATTENDANCE.md).

## Casos normales

- Un `HR_MANAGER` navega entre Trabajadores, Turnos y Novedades con acceso de escritura completo dentro de su empresa.

## Casos especiales

- **Vista de un empleado normal**: un `EMPLOYEE` ve una versión reducida de los módulos de UI, limitada a su propia información (su asistencia, sus horas, su nómina, sus solicitudes de ausencia/hora extra), consistente con la matriz RBAC de [06-AUTHORIZATION.md](./06-AUTHORIZATION.md) y con el criterio "un empleado ve solo lo propio" de [13-REPORTS.md](./13-REPORTS.md).
- **Modo offline del frontend**: **RESUELTO, alcance acotado** (ADR-037 en [23-DECISIONS.md](./23-DECISIONS.md)) — solo la pantalla de **fichada manual de entrada/salida** debe seguir funcionando sin conexión (guardado local + sincronización posterior mediante una capa de PWA/service worker dedicada a esa pantalla). El resto del sistema (reportes, nómina, configuración, auditoría) sigue requiriendo conexión, como es esperable para tareas administrativas — no se rediseña Inertia.js para el resto de la aplicación.

## Errores

- El manejo de errores de API es uniforme: cada código del catálogo de [18-API.md](./18-API.md) se mapea a un mensaje de usuario consistente (ej. `403` → "no tienes permiso para esta acción", `409` → "esta operación entra en conflicto con el estado actual del recurso"), sin exponer detalle técnico interno al usuario final.

## Seguridad

- **Manejo de sesión**: con Inertia.js no hay tokens que gestionar manualmente en el cliente — la autenticación usa la cookie de sesión de Laravel (httpOnly) más el token CSRF que Inertia adjunta automáticamente a cada request (ver ADR-017, actualizado en [23-DECISIONS.md](./23-DECISIONS.md)). No hay JWT ni almacenamiento de tokens en `localStorage`/`sessionStorage` que gestionar ni asegurar.
- **Ocultar elementos de UI según permisos**: la UI oculta acciones y módulos para los que el usuario no tiene permiso, como mejora de experiencia — pero esto **nunca reemplaza la validación real de autorización**, que siempre ocurre en el backend (ver [06-AUTHORIZATION.md](./06-AUTHORIZATION.md)). Ocultar un botón en el cliente no es, por sí mismo, un control de seguridad.

## Dependencias

- [18-API.md](./18-API.md): contrato de endpoints, paginación, filtrado y catálogo de errores que este módulo consume.
- [06-AUTHORIZATION.md](./06-AUTHORIZATION.md): matriz RBAC que determina qué módulos y acciones se muestran/habilitan por rol.

## Criterios de aceptación

- Ningún componente de la UI ejecuta una suma, resta, porcentaje o regla de negocio sobre tiempo o dinero; todo valor mostrado proviene ya calculado del backend.
- Todo componente que combine información planificada, real y calculada las distingue visualmente sin ambigüedad.
- Los 13 módulos de UI listados están disponibles, cada uno restringido según la matriz RBAC de [06-AUTHORIZATION.md](./06-AUTHORIZATION.md).
- Ocultar un elemento de UI por falta de permiso nunca es la única barrera: la operación subyacente también está protegida en el backend.
- El dashboard principal muestra, como mínimo, los ocho indicadores listados en esta sección, todos derivados de datos ya calculados.

## Stack de frontend

**RESUELTO**: framework de frontend — Inertia.js + Vue (ADR-022). **RESUELTO**: sin app móvil nativa en la v1, solo web responsive (ADR-029).
