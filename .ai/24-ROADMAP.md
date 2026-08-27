# 24 — Roadmap de Implementación (16 Fases)

## Objetivo

Definir el orden de construcción del sistema en 16 fases (Fase 0 a Fase 15), cada una con objetivo, tareas clave, dependencias de fases previas, criterios de aceptación y tests requeridos, de modo que cualquier agente de IA sepa en qué fase se encuentra el proyecto y qué debe estar terminado antes de avanzar a la siguiente.

## Alcance

Cubre la secuencia completa desde la documentación inicial (esta misma carpeta `.ai/`) hasta el despliegue en producción. No define el contenido detallado de cada módulo (eso vive en los archivos `04`–`22`), solo el orden y las condiciones de avance.

## Cómo leer las fases

- **Depende de**: fase(s) previa(s) que deben estar completas (según sus propios criterios de aceptación) antes de iniciar esta fase.
- **Criterios de aceptación**: condiciones verificables que determinan que la fase está terminada.
- **Tests requeridos**: qué debe estar cubierto por pruebas automatizadas antes de considerar la fase cerrada, sin excepción para las fases que tocan los motores de cálculo de tiempo y nómina (ver regla no negociable en [AGENTS.md](./AGENTS.md)).
- Ninguna fase puede saltarse aunque parezca "simple"; el orden refleja las dependencias reales del modelo de dominio (ver [04-DOMAIN-MODEL.md](./04-DOMAIN-MODEL.md)).

---

### Fase 0 — Documentación/arquitectura

**Objetivo**: producir la documentación completa de `.ai/` que rige toda la construcción posterior del sistema.

**Tareas clave**: escribir los 26 archivos originales de `.ai/` (este roadmap incluido), más [25-MVP-SCOPE.md](./25-MVP-SCOPE.md) agregado después para priorizar la construcción; validar consistencia cruzada de nombres de entidades, módulos y términos del glosario canónico ([00-SYSTEM-SPECIFICATION.md](./00-SYSTEM-SPECIFICATION.md)).

**Depende de**: — (fase inicial).

**Criterios de aceptación**: los 27 archivos existen, sin contradicciones entre sí, con todo `PENDING DECISION` marcado explícitamente donde corresponda.

**Tests requeridos**: N/A — es una fase de revisión documental, no de código.

---

### Fase 1 — Foundation

**Objetivo**: establecer la base técnica del proyecto sobre la cual se construirán todos los módulos.

**Tareas clave**: elegir stack de backend y frontend (resolviendo el `PENDING DECISION` de [03-ARCHITECTURE.md](./03-ARCHITECTURE.md)); configurar monorepo; establecer CI básico; conectar a PostgreSQL; definir convención de migraciones (ver [05-DATABASE.md](./05-DATABASE.md)).

**Depende de**: Fase 0.

**Criterios de aceptación**: el proyecto arranca, migra una base de datos vacía correctamente, y lint/test corren en CI.

**Tests requeridos**: smoke test de arranque de la aplicación.

---

### Fase 2 — Auth/usuarios

**Objetivo**: implementar identidad, sesiones y control de acceso base.

**Tareas clave**: modelar y construir `users`, `auth_tokens`, `roles`, `permissions`, `role_permissions`, `user_company_memberships`; implementar login/logout/refresh (ver [06-AUTHORIZATION.md](./06-AUTHORIZATION.md)).

**Depende de**: Fase 1.

**Criterios de aceptación**: login funcional; RBAC básico aplicado como middleware en cada request.

**Tests requeridos**: unitarios de hashing de contraseña y de tokens; integración de login exitoso y de acceso denegado por falta de permiso.

---

### Fase 3 — Companies/employees

**Objetivo**: establecer el tenant raíz y la entidad de trabajador dentro de él.

**Tareas clave**: modelar y construir `companies`, `branches`, `employees`, `positions`; implementar aislamiento de tenant en middleware (ver [15-MULTI-TENANCY.md](./15-MULTI-TENANCY.md)).

**Depende de**: Fase 2.

**Criterios de aceptación**: el aislamiento de tenant queda probado — no hay cruce de datos entre dos empresas distintas.

**Tests requeridos**: integración de multi-tenancy con dos empresas simultáneas, verificando ausencia total de fuga de datos entre ellas.

---

### Fase 4 — Contracts

**Objetivo**: modelar la relación contractual histórica del trabajador con la empresa.

**Tareas clave**: modelar y construir `employment_contracts`, `salary_history`, `payroll_information`; implementar la resolución de "contrato vigente a una fecha" (patrón effective-dated lookup, ver [04-DOMAIN-MODEL.md](./04-DOMAIN-MODEL.md)).

**Depende de**: Fase 3.

**Criterios de aceptación**: el sistema determina correctamente el contrato vigente para cualquier fecha dada.

**Tests requeridos**: unitarios de effective-dated lookup, incluyendo el caso de contratos solapados sin cierre correcto, que debe producir un error explícito (ver flujo (d) en [10-PAYROLL.md](./10-PAYROLL.md)).

---

### Fase 5 — Schedules/shifts

**Objetivo**: modelar jornada planificada y turnos concretos.

**Tareas clave**: modelar y construir `work_schedule_templates`, `work_schedule_days`, `employee_schedules`, `shifts`, `shift_assignments`, `shift_breaks`; implementar generación de turnos desde plantilla (ver [08-SHIFTS.md](./08-SHIFTS.md)).

**Depende de**: Fase 4.

**Criterios de aceptación**: el sistema genera turnos correctamente desde una plantilla, incluyendo turnos nocturnos y turnos que cruzan medianoche.

**Tests requeridos**: unitarios de turno que cruza medianoche y de turno partido.

---

### Fase 6 — Attendance

**Objetivo**: capturar eventos de asistencia real y su mecanismo de corrección.

**Tareas clave**: modelar y construir `attendance_devices`, `attendance_events`, `attendance_adjustments`; implementar endpoints de marcación manual/web (ver [07-ATTENDANCE.md](./07-ATTENDANCE.md)).

**Depende de**: Fase 5.

**Criterios de aceptación**: todo evento creado es inmutable; todo ajuste preserva el evento original sin modificarlo.

**Tests requeridos**: integración que cree un ajuste y verifique explícitamente la inmutabilidad del evento original correspondiente.

---

### Fase 7 — Time Calculation Engine

**Objetivo**: construir el motor que traduce turno planificado + eventos reales en tiempo clasificado.

**Tareas clave**: modelar y construir `labor_rules`, `labor_rule_versions`, `attendance_records`; implementar el algoritmo de cálculo de tiempo (ver [09-TIME-CALCULATION.md](./09-TIME-CALCULATION.md)).

**Depende de**: Fase 6.

**Criterios de aceptación**: el motor calcula correctamente horas ordinarias, extra candidatas y faltantes en casos estándar y en casos especiales documentados.

**Tests requeridos**: cobertura unitaria exhaustiva de: turno nocturno, turno que cruza medianoche, descansos, tolerancia y redondeo.

---

### Fase 8 — Overtime/novedades

**Objetivo**: implementar el ciclo de vida de horas extra y de novedades que afectan tiempo y nómina.

**Tareas clave**: modelar y construir `overtime_records`, `leave_types`, `leave_records`, `absence_records`, `novelty_types`, `novelty_records`, `holidays` (ver [09-TIME-CALCULATION.md](./09-TIME-CALCULATION.md)).

**Depende de**: Fase 7.

**Criterios de aceptación**: el ciclo de vida completo de una hora extra (detectada→solicitada→autorizada/rechazada→pagada) funciona; una ausencia aprobada afecta correctamente el cálculo de asistencia.

**Tests requeridos**: integración donde una ausencia aprobada excluye correctamente una falta, y donde una hora extra no autorizada no se traduce en pago.

---

### Fase 9 — Payroll

**Objetivo**: construir el motor de liquidación de nómina completo.

**Tareas clave**: modelar y construir `payroll_periods`, `payroll_entries`, `payroll_entry_lines`, `payroll_concept_definitions`, `payroll_deduction_plans`, `payroll_adjustments`; implementar el flujo calcular/aprobar/cerrar (ver [10-PAYROLL.md](./10-PAYROLL.md)).

**Depende de**: Fase 8.

**Criterios de aceptación**: el cierre de un periodo es efectivamente inmutable; un ajuste posterior al cierre no sobrescribe la entrada original.

**Tests requeridos**: unitarios e integración cubriendo una quincena completa, un contrato que cambia a mitad de periodo, y un cierre seguido de una corrección posterior vía `payroll_adjustments`.

---

### Fase 10 — Social Security

**Objetivo**: calcular y trazar los aportes de seguridad social asociados a cada liquidación.

**Tareas clave**: modelar y construir `social_security_entities`, `social_security_affiliations`, `social_security_contributions`, `social_security_concept_definitions` (ver [11-SOCIAL-SECURITY.md](./11-SOCIAL-SECURITY.md)).

**Depende de**: Fase 9.

**Criterios de aceptación**: los aportes calculados son trazables hasta la liquidación (`payroll_entry`) que los generó.

**Tests requeridos**: unitarios de bases de cálculo de aportes y de cambio de afiliación a mitad de periodo.

---

### Fase 11 — Reports/PDF

**Objetivo**: exponer reportes de solo lectura y generar comprobantes oficiales en PDF.

**Tareas clave**: construir endpoints de reportes filtrables; modelar y construir `generated_documents`; implementar generación de comprobantes (ver [13-REPORTS.md](./13-REPORTS.md), [14-PDF.md](./14-PDF.md)).

**Depende de**: Fase 9, Fase 10.

**Criterios de aceptación**: el comprobante PDF contiene todos los campos obligatorios; un reporte es reproducible con los mismos filtros.

**Tests requeridos**: integración verificando que el PDF de un periodo cerrado y luego corregido genera una nueva versión del documento, sin sobrescribir la anterior.

---

### Fase 12 — Biometrics

**Objetivo**: integrar la captura biométrica de asistencia de extremo a extremo.

**Tareas clave**: extender `attendance_devices`; modelar y construir `biometric_identities`, `biometric_raw_events`; construir el Device Gateway y la interfaz `BiometricProvider` (ver [12-BIOMETRICS.md](./12-BIOMETRICS.md)).

**Depende de**: Fase 6.

**Criterios de aceptación**: el flujo completo lector→evento de asistencia queda probado con un proveedor mock; los casos de duplicados y desorden de eventos se manejan sin corromper el histórico.

**Tests requeridos**: integración de evento duplicado, evento fuera de orden, y dispositivo offline con sincronización posterior.

---

### Fase 13 — Audit/hardening

**Objetivo**: garantizar que toda acción sensible del sistema quede auditada, y reforzar la seguridad general.

**Tareas clave**: implementar `audit_logs` de forma transversal en todas las acciones sensibles listadas en [16-AUDIT.md](./16-AUDIT.md); realizar una revisión de seguridad general (ver [20-SECURITY.md](./20-SECURITY.md)).

**Depende de**: Fases 2 a 12.

**Criterios de aceptación**: toda acción sensible identificada deja rastro con usuario, valor anterior, valor nuevo y motivo.

**Tests requeridos**: integración verificando que cada acción crítica genera exactamente un registro de auditoría correcto, ni más ni menos.

---

### Fase 14 — Testing

**Objetivo**: consolidar cobertura de pruebas en los motores críticos y en los flujos completos del sistema.

**Tareas clave**: elevar cobertura en Time Calculation y Payroll; construir pruebas end-to-end de los flujos críticos documentados en [21-TESTING.md](./21-TESTING.md).

**Depende de**: Fases 1 a 13.

**Criterios de aceptación**: se alcanza la cobertura mínima definida en los motores críticos (valor exacto: ver `PENDING DECISION` en [21-TESTING.md](./21-TESTING.md)).

**Tests requeridos**: todos los casos obligatorios listados en el brief: turno nocturno, cruce de medianoche, descansos, horas extra, ausencias, nómina completa, deducciones, seguridad social, multi-tenancy, permisos y auditoría.

---

### Fase 15 — Deployment

**Objetivo**: llevar el sistema a un entorno de producción de forma reproducible y segura.

**Tareas clave**: construir pipeline de CI/CD; definir entornos; implementar backups y plan de recuperación ante desastres (ver [22-DEPLOYMENT.md](./22-DEPLOYMENT.md)).

**Depende de**: Fase 14.

**Criterios de aceptación**: el despliegue es reproducible; el rollback ha sido probado; un backup ha sido restaurado exitosamente en un ensayo.

**Tests requeridos**: test de restauración de backup; smoke test posterior al despliegue.

---

## Riesgos transversales al roadmap completo

- **Stack tecnológico**: **RESUELTO** — PHP/Laravel + Inertia.js/Vue (ver ADR-022 en [23-DECISIONS.md](./23-DECISIONS.md)); ya no bloquea el inicio de la Fase 1.
- **Legislación/país**: **RESUELTO** — Colombia exclusivamente (ADR-023). Persiste un riesgo menor en las Fases 7, 9 y 10: los porcentajes y fórmulas exactas de recargos/seguridad social siguen sujetos a validación profesional antes de codificarse (ver [AGENTS.md](./AGENTS.md)); estas fases deben implementarse siempre contra `labor_rule_versions` configurables (ADR-020), nunca contra valores fijos, aun sabiendo ya el país.
- **Proveedor biométrico no definido** (ver `PENDING DECISION` en [12-BIOMETRICS.md](./12-BIOMETRICS.md)): confirmado con el propietario del producto, se mitiga arrancando la Fase 12 con un `BiometricProvider` mock (**RESUELTO**, ADR-036 en [23-DECISIONS.md](./23-DECISIONS.md)) y conectando el proveedor real más adelante sin bloquear el roadmap.
- **Mecanismo de corrección post-cierre**: **RESUELTO** — "ajuste en periodo siguiente" por defecto (ADR-026); la Fase 9 y la Fase 11 deben implementar ese camino como estándar, manteniendo `REOPENED` disponible en el esquema solo para el caso excepcional.
- **Cobertura mínima de tests**: **RESUELTO** — 80% global, 90% en los motores críticos de las Fases 7 y 9 (ver [21-TESTING.md](./21-TESTING.md)).
- **Integración DIAN (nómina electrónica) y PILA pospuesta** (ADR-030): la v1 calcula nómina y aportes correctamente puertas adentro, pero la integración formal de envío hacia DIAN y hacia los operadores de PILA queda **fuera de las 16 fases actuales** (Fase 0-15) como trabajo futuro explícito, a evaluar una vez el sistema esté en producción con datos reales.
- **Dependencias secuenciales estrictas**: dado que las fases 4 a 12 dependen linealmente unas de otras siguiendo las 8 capas del principio fundamental (ver [00-SYSTEM-SPECIFICATION.md](./00-SYSTEM-SPECIFICATION.md)), un retraso o cambio de diseño en una fase temprana (por ejemplo, Fase 5, Shifts) se propaga a todas las fases posteriores que dependen de ella.

## Criterios de aceptación del roadmap mismo

- Las 16 fases están numeradas de 0 a 15, sin huecos ni fases duplicadas.
- Cada fase indica explícitamente de qué fase(s) previa(s) depende, y esa dependencia es consistente con el orden de las 8 capas del principio fundamental.
- Cada fase que toca un motor de cálculo (Time Calculation en Fase 7, Payroll en Fase 9) especifica tests obligatorios, no opcionales.
- Todo riesgo transversal identificado enlaza al archivo `.ai/` donde vive el `PENDING DECISION` correspondiente.
