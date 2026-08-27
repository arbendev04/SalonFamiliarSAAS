# 02 — Requisitos

## Objetivo

Fijar, módulo por módulo, los requisitos funcionales concretos del sistema, junto con los requisitos no funcionales, de datos, restricciones tecnológicas/de negocio y supuestos explícitos, para que cualquier agente de IA pueda validar si una implementación cumple el alcance esperado sin necesidad de interpretar el brief original.

## Alcance

Cubre los 22 módulos definidos en [00-SYSTEM-SPECIFICATION.md](./00-SYSTEM-SPECIFICATION.md) y [03-ARCHITECTURE.md](./03-ARCHITECTURE.md). No describe el "cómo" (eso vive en los archivos `06`–`22`), solo el "qué" mínimo exigido por módulo.

## Requisitos funcionales por módulo

- **Authentication**: login con credenciales, refresh de sesión, logout, recuperación de contraseña. Ver [06-AUTHORIZATION.md](./06-AUTHORIZATION.md).
- **Authorization**: asignación de roles por empresa vía `user_company_memberships`, permisos atómicos agrupables en roles de sistema o custom, cambio de empresa activa para un usuario con múltiples membresías. Ver [06-AUTHORIZATION.md](./06-AUTHORIZATION.md).
- **Companies**: alta/edición de empresa (tenant), configuración raíz (`company_settings`). Ver [15-MULTI-TENANCY.md](./15-MULTI-TENANCY.md).
- **Branches**: alta/edición de sucursales, asociación de dispositivos y turnos a una sucursal específica con su propia zona horaria. Ver [15-MULTI-TENANCY.md](./15-MULTI-TENANCY.md).
- **Employees**: alta/edición de trabajador, datos personales y laborales básicos, estado activo/inactivo (soft-delete). Ver [04-DOMAIN-MODEL.md](./04-DOMAIN-MODEL.md).
- **Positions**: catálogo de cargos por empresa. Ver [04-DOMAIN-MODEL.md](./04-DOMAIN-MODEL.md).
- **Employment Contracts**: creación de contrato con fecha de inicio/fin y salario base, historial de revisiones salariales (`salary_history`), resolución de "contrato vigente a una fecha" mediante effective-dated lookup. Ver [04-DOMAIN-MODEL.md](./04-DOMAIN-MODEL.md), [10-PAYROLL.md](./10-PAYROLL.md).
- **Work Schedules**: definición de plantillas de jornada con reglas por día de la semana, incluyendo turnos que cruzan medianoche. Ver [08-SHIFTS.md](./08-SHIFTS.md).
- **Shifts**: generación de turnos concretos desde una plantilla o de forma manual, asignación de trabajador a turno, definición de descansos planificados. Ver [08-SHIFTS.md](./08-SHIFTS.md).
- **Attendance**: captura de eventos de marcación (biométrica, manual o web), corrección mediante ajustes sin editar el evento original, deduplicación de eventos equivalentes. Ver [07-ATTENDANCE.md](./07-ATTENDANCE.md).
- **Time Calculation**: cálculo de horas ordinarias, extra candidatas y faltantes cruzando turno planificado, eventos reales y reglas laborales vigentes; recálculo ante ajuste de asistencia o cambio de regla. Ver [09-TIME-CALCULATION.md](./09-TIME-CALCULATION.md).
- **Overtime**: ciclo de vida completo de hora extra (detectada → solicitada → autorizada/rechazada → pagada). Ver [09-TIME-CALCULATION.md](./09-TIME-CALCULATION.md).
- **Absences / Leave**: solicitud y aprobación de ausencias, permisos, vacaciones e incapacidades, con efecto real sobre el día calendario (`absence_records`). Ver [09-TIME-CALCULATION.md](./09-TIME-CALCULATION.md).
- **Payroll**: apertura y cálculo de periodos de nómina (semanal/quincenal/mensual), aprobación y cierre, generación de líneas de detalle por concepto salarial, ajustes post-cierre sin sobrescribir. Ver [10-PAYROLL.md](./10-PAYROLL.md).
- **Social Security**: afiliación histórica de trabajadores a entidades de seguridad social, cálculo de aportes por periodo liquidado. Ver [11-SOCIAL-SECURITY.md](./11-SOCIAL-SECURITY.md).
- **Reports**: consultas agregadas y filtrables de solo lectura sobre asistencia, nómina, horas extra y ausencias. Ver [13-REPORTS.md](./13-REPORTS.md).
- **PDF**: generación de comprobantes de pago y reportes en PDF, versionados ante regeneración. Ver [14-PDF.md](./14-PDF.md).
- **Biometrics**: identificación de trabajador a partir de un dispositivo biométrico, staging de eventos crudos, tolerancia a duplicados/desorden/offline. Ver [12-BIOMETRICS.md](./12-BIOMETRICS.md).
- **Notifications**: envío de alertas por eventos del sistema (marcación no identificada, ausencia por aprobar, nómina lista, etc.) por canal configurable. Ver [17-NOTIFICATIONS.md](./17-NOTIFICATIONS.md).
- **Audit**: registro automático e inmutable de toda acción sensible definida en el sistema. Ver [16-AUDIT.md](./16-AUDIT.md).
- **Settings**: configuración por empresa (`company_settings`) y de plataforma (`system_settings`, solo `SUPER_ADMIN`). Ver [03-ARCHITECTURE.md](./03-ARCHITECTURE.md).

## Requisitos no funcionales

- **Rendimiento**: las consultas de asistencia y nómina deben responder en tiempo interactivo para el volumen típico de una empresa cliente (decenas a cientos de empleados por empresa); el motor de cálculo de tiempo y nómina puede ejecutarse de forma asíncrona para volúmenes mayores.
- **Disponibilidad**: la captura de eventos de asistencia (especialmente biométrica) debe tolerar caídas temporales de conectividad del dispositivo sin pérdida de datos, vía staging (`biometric_raw_events`) y sincronización posterior. Ver [12-BIOMETRICS.md](./12-BIOMETRICS.md).
- **Escalabilidad**: el sistema debe soportar múltiples empresas (tenants) de forma aislada dentro de la misma instancia (monolito modular), sin degradación cruzada entre tenants.
- **Seguridad**: aislamiento estricto entre empresas, RBAC granular, cifrado de datos sensibles en reposo (datos bancarios, biométricos), auditoría transversal. Ver [20-SECURITY.md](./20-SECURITY.md).
- **Usabilidad**: separación visual clara entre lo planificado, lo real y lo calculado en toda interfaz que las muestre juntas. Ver [19-FRONTEND.md](./19-FRONTEND.md).
- **Localización/idioma**: la documentación y el producto usan español neutro/profesional como idioma base; el soporte de múltiples idiomas de interfaz no está confirmado.

## Requisitos de datos

- **Retención**: los eventos de asistencia (`attendance_events`) y los registros de auditoría (`audit_logs`) son inmutables mientras el sistema los considera datos vivos — nunca se editan ni se borran silenciosamente (ver [05-DATABASE.md](./05-DATABASE.md)). Esto no equivale a una política de retención definida: el plazo exacto durante el cual se conservan (o el criterio de archivado, si llega a existir) es un `PENDING DECISION` — para `audit_logs` ver [16-AUDIT.md](./16-AUDIT.md), y para datos biométricos ver [12-BIOMETRICS.md](./12-BIOMETRICS.md).
- **Integridad referencial**: toda tabla tenant-scoped mantiene FK válidas hacia su empresa y hacia las entidades relacionadas; no se permite un registro huérfano de `company_id`. Ver [05-DATABASE.md](./05-DATABASE.md).
- **Auditabilidad**: toda escritura sobre una entidad marcada como sensible en [16-AUDIT.md](./16-AUDIT.md) debe quedar registrada con usuario, valor anterior, valor nuevo y motivo.

## Restricciones tecnológicas y de negocio conocidas

- **PostgreSQL obligatorio** como motor de base de datos (fijado por el brief, no negociable).
- **Monolito modular** como estilo arquitectónico inicial, no microservicios (ver ADR-001).
- **Quincena como periodo de nómina prioritario**, soportado desde el día 1, junto con semanal y mensual mediante un modelo genérico de `payroll_periods` (ver ADR-008).
- Lenguaje y framework de backend/frontend no están fijados por el brief.

**RESUELTO**: Stack tecnológico — backend PHP/Laravel, frontend Inertia.js/Vue (ver ADR-022 en [23-DECISIONS.md](./23-DECISIONS.md)).

## Supuestos explícitos

- Se asume que cada empresa cliente puede tener múltiples sucursales, y que un trabajador pertenece a una sola empresa a la vez pero un mismo usuario puede tener membresías en varias empresas (ver ADR-013).
- Se asume que el motor de reglas laborales debe ser configurable por empresa (o por defecto de plataforma) para no atarse a una legislación específica (ver ADR-020); la legislación exacta a aplicar sigue pendiente.
- Se asume que la captura biométrica es el mecanismo principal de asistencia, pero el sistema también admite marcación manual/web (ver [07-ATTENDANCE.md](./07-ATTENDANCE.md)).

**RESUELTO**: Legislación/país objetivo de la v1 — Colombia exclusivamente (ver ADR-023 en [23-DECISIONS.md](./23-DECISIONS.md)). Esto no resuelve por sí solo los porcentajes o fórmulas legales exactas, que siguen sujetos a validación profesional antes de implementarse (ver [AGENTS.md](./AGENTS.md)).

**RESUELTO**: Modelo comercial multi-tenant — 1 cuenta = 1 empresa en la v1 (ver ADR-024 en [23-DECISIONS.md](./23-DECISIONS.md)); el modelo de datos sigue soportando "1 cuenta → N empresas" (`user_company_memberships`) para una eventual expansión sin migración.

## Fuera de alcance

- Facturación del propio SaaS a sus empresas clientes.
- Integraciones externas específicas de nómina/seguridad social (por ejemplo, un ente recaudador o una pasarela de dispersión de pagos) mientras no se confirme el país/legislación objetivo.
- Definición de infraestructura de despliegue (proveedor de hosting, backups, DR) — ver [22-DEPLOYMENT.md](./22-DEPLOYMENT.md) para el detalle de lo pendiente en esa área.

## Criterios de aceptación

- Cada uno de los 22 módulos tiene al menos un requisito funcional concreto en este documento, verificable contra su archivo de dominio correspondiente.
- Todo requisito no funcional referencia el archivo `.ai/` donde se detalla su implementación esperada.
- Toda restricción tecnológica o de negocio desconocida está marcada explícitamente como `PENDING DECISION`, no asumida.
- Ningún requisito de este documento contradice los límites de módulo definidos en [03-ARCHITECTURE.md](./03-ARCHITECTURE.md).
