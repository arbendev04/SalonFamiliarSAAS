# 20-SECURITY.md — Security

## Objetivo

Servir como documento paraguas de seguridad del sistema: reunir en un solo lugar las áreas de seguridad que atraviesan todos los módulos, remitiendo al archivo específico cuando el detalle ya vive documentado ahí, y fijando las reglas y procesos que no tienen un hogar más específico.

## Alcance

Cubre **todas** las áreas de seguridad del sistema: autenticación, autorización/RBAC, multi-tenancy, protección de datos en reposo y en tránsito, cifrado, gestión de secretos, rate limiting, validación de inputs, protección contra SQL injection/XSS/CSRF, logs de seguridad, auditoría, backups, recuperación ante desastres, gestión de sesiones, y seguridad biométrica.

Los detalles específicos de seguridad biométrica (cifrado de templates, retención, eliminación en offboarding) viven en [12-BIOMETRICS.md](./12-BIOMETRICS.md) y se referencian desde aquí en vez de duplicarse.

No incluye: la lógica de negocio de cada módulo (qué es válido o no en términos de dominio); el detalle de implementación de RBAC (vive en [06-AUTHORIZATION.md](./06-AUTHORIZATION.md)) ni el detalle de auditoría (vive en [16-AUDIT.md](./16-AUDIT.md)) — este documento los referencia sin repetirlos.

## Conceptos

- **Defensa en profundidad**: ninguna capa (API, servicio, base de datos) confía únicamente en que la capa anterior ya validó algo; cada capa revalida lo que le corresponde (por ejemplo, tenant isolation se valida en API + Service + DB, ver ADR-006).
- **Secreto**: cualquier credencial, clave de API, cadena de conexión o material criptográfico que nunca debe residir en el código fuente versionado.
- **Incidente de seguridad**: cualquier evento que comprometa o amenace confidencialidad, integridad o disponibilidad de los datos del sistema o de sus tenants.

## Entidades

Este documento no introduce entidades propias; se apoya en `audit_logs` (ver [16-AUDIT.md](./16-AUDIT.md)) para dejar rastro de eventos de seguridad relevantes, y en `payroll_information` (ver [04-DOMAIN-MODEL.md](./04-DOMAIN-MODEL.md) y [05-DATABASE.md](./05-DATABASE.md)) como ejemplo central de dato sensible que requiere cifrado en reposo.

## Reglas

### Áreas cubiertas

- **Autenticación**: hashing de contraseñas, rate limiting en login, expiración/rotación de sesión, MFA. Detalle completo en [06-AUTHORIZATION.md](./06-AUTHORIZATION.md).
- **Autorización / RBAC**: control de acceso granular por permiso atómico, matriz de roles. Detalle completo en [06-AUTHORIZATION.md](./06-AUTHORIZATION.md).
- **Multi-tenancy**: aislamiento estricto de `company_id`, resolución de empresa activa, excepción controlada de `SUPER_ADMIN`. Detalle completo en [15-MULTI-TENANCY.md](./15-MULTI-TENANCY.md).
- **Protección de datos en reposo y en tránsito**: toda comunicación externa debe viajar cifrada (TLS); los datos sensibles en base de datos (ver cifrado más abajo) deben estar protegidos incluso ante acceso directo al almacenamiento físico.
- **Cifrado**: especialmente `payroll_information`, que contiene datos bancarios/fiscales del empleado — estos campos deben cifrarse en reposo (ver `bank_account_enc` en [05-DATABASE.md](./05-DATABASE.md)), nunca almacenarse en texto plano.
- **Gestión de secretos**: **nunca almacenar secretos en el repositorio** — regla explícita y no negociable del brief. Todo secreto (credenciales de base de datos, claves de API de terceros) reside en las **variables de entorno cifradas de Laravel Cloud**, por entorno (dev/staging/prod) — ver ADR-021 en [23-DECISIONS.md](./23-DECISIONS.md). No se introduce un vault externo adicional para la v1.
- **Rate limiting en endpoints sensibles**: aplica especialmente a `/auth/login`, y en general a cualquier endpoint expuesto a abuso por volumen (ver también [18-API.md](./18-API.md)).
- **Validación de inputs**: todo límite del sistema (API pública, formularios, payloads de dispositivos biométricos) valida su entrada antes de procesarla; nunca se confía en datos externos sin validar (consistente con la regla general de "validar en los límites del sistema").
- **Protección contra SQL injection**: uso obligatorio de queries parametrizadas en toda interacción con PostgreSQL; nunca concatenación de strings de usuario dentro de una consulta.
- **Protección XSS**: el frontend nunca debe insertar contenido dinámico sin escapar/sanear (ver [19-FRONTEND.md](./19-FRONTEND.md)).
- **Protección CSRF**: obligatoria y nativa — el sistema usa sesión de servidor de Laravel con cookies (ADR-017 en [23-DECISIONS.md](./23-DECISIONS.md)), por lo que todo formulario/request de escritura desde Inertia.js debe llevar el token CSRF que Laravel emite automáticamente.
- **Logs de seguridad**: eventos relevantes de seguridad (intentos de login fallidos repetidos, accesos denegados por permiso, intentos de acceso cross-tenant) deben quedar registrados; el registro de acciones de negocio sensibles vive en `audit_logs` (ver [16-AUDIT.md](./16-AUDIT.md)).
- **Respaldo y auditoría**: ver [16-AUDIT.md](./16-AUDIT.md) para el detalle de qué se audita y cómo.
- **Backups**: gestionados por Laravel Cloud sobre su PostgreSQL administrado (ADR-021 en [23-DECISIONS.md](./23-DECISIONS.md)); la frecuencia exacta la fija el proveedor.
- **Recuperación ante desastres (DR)**: los valores concretos de RPO/RTO del SLA de Laravel Cloud son **PENDING DECISION** — quedan por confirmar contra su documentación oficial (ver también [22-DEPLOYMENT.md](./22-DEPLOYMENT.md)).
- **Gestión de sesiones**: expiración y rotación de tokens de acceso/refresco. Detalle completo en [06-AUTHORIZATION.md](./06-AUTHORIZATION.md).
- **Seguridad biométrica**: cifrado de templates/identificadores biométricos, política de retención y eliminación en offboarding, control de acceso a datos biométricos. Detalle completo en [12-BIOMETRICS.md](./12-BIOMETRICS.md).

### Regla explícita de secretos

Nunca almacenar secretos en el repositorio de código, bajo ninguna circunstancia, incluyendo archivos de configuración versionados, scripts, o comentarios. Se usan las **variables de entorno cifradas de Laravel Cloud**, por entorno (ADR-021 en [23-DECISIONS.md](./23-DECISIONS.md)); no se introduce un vault externo adicional para la v1.

## Flujos

### Gestión de incidentes de seguridad

1. **Detectar**: identificación del incidente (por monitoreo, alerta automática, o reporte manual).
2. **Contener**: aislar el alcance del incidente (por ejemplo, revocar tokens comprometidos, deshabilitar una cuenta o membership afectada) para evitar que se propague o continúe.
3. **Notificar**: comunicar el incidente a las partes relevantes (según severidad y alcance — el proceso exacto de notificación a clientes/autoridades es **PENDING DECISION**, depende del marco normativo aplicable).
4. **Remediar**: aplicar la corrección definitiva (parche, rotación de credenciales, corrección de configuración).
5. **Documentar**: registrar el incidente y su resolución en `audit_logs` (ver [16-AUDIT.md](./16-AUDIT.md)) y en la documentación operativa correspondiente.

### Rotación de credenciales/secretos

- Cualquier secreto potencialmente expuesto (por ejemplo, tras una fuga de datos o un commit accidental) debe rotarse de inmediato, en línea con la regla general de "rotar cualquier secreto que pueda haber sido expuesto".

## Casos normales

- Un despliegue nuevo obtiene sus secretos exclusivamente desde variables de entorno o el gestor de secretos configurado, sin ningún valor sensible hardcodeado en el repositorio.

## Casos especiales

- **Sospecha de fuga de datos**: se activa el flujo de gestión de incidentes completo (detectar→contener→notificar→remediar→documentar); mientras no se confirme el alcance, se debe priorizar la contención (por ejemplo, revocar sesiones activas de las cuentas potencialmente afectadas) sobre la investigación exhaustiva previa.
- **Cuenta de usuario comprometida**: revocación inmediata de `auth_tokens` activos de esa cuenta (ver [06-AUTHORIZATION.md](./06-AUTHORIZATION.md)), rotación forzada de contraseña, y registro del incidente.

## Plan de respuesta a incidentes

A alto nivel, el plan de respuesta sigue las mismas cinco etapas del flujo de gestión de incidentes: **detectar, contener, notificar, remediar, documentar en `audit_logs`**. El detalle operativo específico (herramientas de monitoreo, SLA de respuesta, responsables) es materia de implementación y queda fuera del alcance de este documento de diseño.

## Cumplimiento normativo

Con la legislación objetivo ya resuelta a Colombia (ADR-023 en [23-DECISIONS.md](./23-DECISIONS.md)), el marco aplicable es el régimen de **Habeas Data colombiano** (Ley 1581 de 2012 y Decreto 1377 de 2013 — protección de datos personales, con los datos biométricos clasificados como dato sensible). **PENDING DECISION**: el detalle exacto de implementación (políticas de tratamiento de datos, autorización explícita del titular, registro nacional de bases de datos si aplica) sigue sin validarse contra fuentes oficiales o asesoría legal profesional — este documento no debe usarse como referencia legal, ver la regla no negociable en [AGENTS.md](./AGENTS.md) sobre no asumir reglas legales no validadas. El detalle específicamente biométrico se trata en [12-BIOMETRICS.md](./12-BIOMETRICS.md).

## Dependencias

Prácticamente todos los módulos dependen de este documento en tanto reglas transversales de seguridad. Referencias específicas:

- [06-AUTHORIZATION.md](./06-AUTHORIZATION.md): autenticación, autorización, gestión de sesiones.
- [15-MULTI-TENANCY.md](./15-MULTI-TENANCY.md): aislamiento de tenant.
- [16-AUDIT.md](./16-AUDIT.md): auditoría y logs de acciones sensibles.
- [12-BIOMETRICS.md](./12-BIOMETRICS.md): seguridad y privacidad biométrica.
- [18-API.md](./18-API.md): rate limiting y validación de input a nivel de API.
- [19-FRONTEND.md](./19-FRONTEND.md): protección XSS en cliente.
- [22-DEPLOYMENT.md](./22-DEPLOYMENT.md): backups, DR, secretos en el pipeline.

## Criterios de aceptación

- No existe ningún secreto (credencial, clave de API, cadena de conexión) presente en el repositorio de código en ningún momento del historial versionado.
- Todo dato bancario/fiscal en `payroll_information` está cifrado en reposo.
- Toda consulta a PostgreSQL usa queries parametrizadas; no existe concatenación de input de usuario en SQL.
- Los endpoints sensibles (especialmente `/auth/login`) tienen rate limiting activo.
- Existe un flujo documentado y accionable de detectar→contener→notificar→remediar→documentar ante cualquier incidente de seguridad.
- Toda sospecha de fuga de datos o cuenta comprometida resulta en revocación de sesiones activas y registro en `audit_logs`.
