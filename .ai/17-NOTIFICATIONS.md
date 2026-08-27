# 17-NOTIFICATIONS.md — Notifications

## Objetivo

Definir cómo el sistema comunica a usuarios y trabajadores los eventos relevantes ya decididos por otros módulos, sin contener lógica de negocio propia.

## Alcance

Incluye: el catálogo de eventos que disparan notificación, las entidades `notification_templates` y `notification_logs`, el flujo de envío/reintento/registro de resultado, y las reglas de contenido seguro para canales no cifrados.

No incluye: la decisión de negocio que origina el evento (ej. si una ausencia debe aprobarse) — esa decisión vive en el módulo de origen (Attendance, Overtime, Leave, Payroll, Biometrics); el registro de auditoría de la acción que disparó la notificación, que vive en [16-AUDIT.md](./16-AUDIT.md).

**RESUELTO** (ADR-039 en [23-DECISIONS.md](./23-DECISIONS.md)): la v1 usa **solo email** como canal de notificación (sin SMS ni push). Proveedor recomendado: **Resend** (buena integración con el driver de mail de Laravel, plan gratuito amplio, precio bajo si se supera). SMS/push quedan como extensión futura si el negocio lo requiere.

## Conceptos

- **Canal**: medio por el que se entrega una notificación. En la v1, el único canal soportado es **email** (ADR-039).
- **Plantilla de notificación (`notification_templates`)**: contenido parametrizable asociado a un canal y a un evento disparador, reutilizable entre empresas (plantilla de sistema, `company_id` nulo) o específica de una empresa (`company_id` no nulo).
- **Evento disparador**: ocurrencia de negocio, decidida por otro módulo, que activa el envío de una o más notificaciones según el catálogo de Reglas.

## Entidades

| Entidad | Propósito | Notas de `05-DATABASE.md` |
|---|---|---|
| `notification_templates` | Plantilla de notificación por canal/evento | Aislamiento DIRECTO/GLOBAL (`company_id` nullable); soft-delete; MUTABLE |
| `notification_logs` | Historial de envíos: `id`, `company_id`, `channel`, `event_code`, `status`, `sent_at` | Aislamiento DIRECTO; sin soft-delete; INMUTABLE (log) |

Consultar [04-DOMAIN-MODEL.md](./04-DOMAIN-MODEL.md) y [05-DATABASE.md](./05-DATABASE.md) para el detalle completo de columnas, índices y constraints.

## Reglas

### Catálogo de eventos que disparan notificación

Basado en los eventos de negocio ya decididos por sus módulos de origen, incluye como mínimo:

| Evento | Módulo de origen | Destinatario típico |
|---|---|---|
| Ausencia aprobada/rechazada | Absences / Leave ([04-DOMAIN-MODEL.md](./04-DOMAIN-MODEL.md)) | Trabajador solicitante, supervisor |
| Hora extra autorizada/rechazada | Overtime | Trabajador solicitante |
| Ajuste de asistencia aprobado | Attendance ([07-ATTENDANCE.md](./07-ATTENDANCE.md)) | Trabajador afectado, quien solicitó el ajuste |
| Periodo de nómina cerrado | Payroll ([10-PAYROLL.md](./10-PAYROLL.md)) | Trabajadores del periodo, `PAYROLL_MANAGER` |
| Comprobante de nómina disponible | Payroll / PDF ([14-PDF.md](./14-PDF.md)) | Trabajador |
| Dispositivo biométrico offline | Biometrics ([12-BIOMETRICS.md](./12-BIOMETRICS.md)) | `ADMIN`/rol técnico de la sucursal |
| Empleado no identificado en marcación (`UNMATCHED`) | Biometrics | `ADMIN`/`HR_MANAGER` |

Este catálogo se documenta una única vez aquí; los módulos de origen referencian este archivo en vez de redefinir el contenido o el canal de la notificación.

### Reglas de disparo

- Notifications **no contiene lógica de negocio**: solo reacciona a un evento ya decidido y validado por su módulo de origen. Nunca decide si una acción es válida, solo la comunica.
- Cada evento se resuelve contra la(s) `notification_templates` correspondientes a su `event_code` y al canal configurado para ese tipo de evento/empresa.

## Flujos

### Envío de notificación

1. El módulo de origen dispara el evento (ej. Overtime marca `overtime_records.status=AUTHORIZED`).
2. Notifications resuelve la plantilla aplicable (`event_code` + canal + `company_id`) y arma el contenido con los datos del evento.
3. Se envía a través del proveedor del canal correspondiente.
4. Se registra el resultado (éxito o fallo) en `notification_logs`, con `channel`, `event_code`, `status` y `sent_at`.

### Reintento ante fallo

- Un envío fallido se reintenta según una política de reintento (número de intentos, backoff); los parámetros exactos y si existe un límite de reintentos antes de marcar el envío como definitivamente fallido son **PENDING DECISION**, dependientes del proveedor elegido.
- Cada intento (exitoso o fallido) queda reflejado en `notification_logs`.

### Registro del resultado

- Todo envío, exitoso o fallido, genera una fila en `notification_logs`; este módulo nunca descarta silenciosamente un intento de envío sin dejar rastro.

## Casos normales

- Se aprueba una ausencia y el trabajador recibe una notificación por el canal configurado para ese tipo de evento.

## Casos especiales

- **Usuario sin canal de contacto configurado**: si el destinatario no tiene un canal válido configurado (ej. sin email registrado), el envío no puede ejecutarse; se registra en `notification_logs` con `status` de fallo explícito (no se descarta en silencio), y el sistema no debe bloquear la acción de negocio que originó el evento por esta causa.
- **Envío masivo**: un evento que aplica a múltiples destinatarios a la vez (ej. notificar a todos los empleados de un cierre de nómina) genera un envío por destinatario; si cada envío masivo debe agregarse en un único `notification_log` o generar una fila por destinatario es **PENDING DECISION** (mismo tipo de ambigüedad que el caso de acciones batch documentado en [16-AUDIT.md](./16-AUDIT.md)).

## Errores

- **Proveedor de envío caído**: con Resend como proveedor de email (ADR-039), un fallo de envío se maneja mediante la política de reintento de la cola de Laravel (ver Reglas); el umbral exacto de reintentos y el punto en que se genera una alerta a soporte queda como detalle de implementación de la Fase 11, no como bloqueante de diseño.

## Seguridad

- Ningún canal no cifrado debe exponer datos sensibles: montos de nómina, datos biométricos, datos bancarios (`payroll_information`) o cualquier dato personal sensible no deben incluirse en el cuerpo de una notificación enviada por un canal sin garantía de cifrado en tránsito (ej. SMS plano). El contenido de la plantilla debe limitarse a un aviso genérico ("tu comprobante de nómina está disponible") que remita al sistema autenticado para ver el detalle.
- El acceso a `notification_logs` sigue el mismo criterio de tenant isolation que el resto del sistema (ver [15-MULTI-TENANCY.md](./15-MULTI-TENANCY.md)).

## Dependencias

- [16-AUDIT.md](./16-AUDIT.md): la acción de negocio que origina un evento de notificación ya queda auditada por su módulo de origen; Notifications no duplica ese registro, solo lo complementa con el resultado del envío.
- Attendance ([07-ATTENDANCE.md](./07-ATTENDANCE.md)), Overtime, Leave, Payroll ([10-PAYROLL.md](./10-PAYROLL.md)), Biometrics ([12-BIOMETRICS.md](./12-BIOMETRICS.md)): módulos que disparan los eventos listados en el catálogo de Reglas.
- Proveedor de email: **Resend** (ADR-039 en [23-DECISIONS.md](./23-DECISIONS.md)).

## Criterios de aceptación

- Todo evento del catálogo de Reglas genera un intento de notificación registrado en `notification_logs`, exitoso o fallido.
- Ninguna notificación enviada por un canal no cifrado expone montos, datos biométricos o datos bancarios.
- Un destinatario sin canal configurado no bloquea la acción de negocio que originó el evento; el fallo de envío queda registrado explícitamente.
- Notifications no contiene ninguna validación de negocio propia: toda decisión (aprobar, rechazar, autorizar) proviene ya resuelta del módulo de origen.
