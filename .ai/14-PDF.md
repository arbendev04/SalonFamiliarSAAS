# 14-PDF.md — PDF

## Objetivo

Definir cómo el sistema genera y versiona documentos PDF (comprobantes, reportes materializados) a partir de datos ya calculados por otros módulos, garantizando que un documento generado nunca se altera ni se sobrescribe.

## Alcance

Incluye: los tipos de documento a generar (comprobante de nómina, reporte de asistencia/horas, resumen de periodo, reporte de novedades, reporte de seguridad social), la entidad `generated_documents` como registro versionado de cada PDF, el contenido obligatorio de todo documento, y el flujo de generación/regeneración.

No incluye: el cálculo de ninguna cifra mostrada en el documento — todo dato viene ya calculado de su módulo de origen ([10-PAYROLL.md](./10-PAYROLL.md), [11-SOCIAL-SECURITY.md](./11-SOCIAL-SECURITY.md), [13-REPORTS.md](./13-REPORTS.md)); la consulta agregada/filtrada de datos previa a la generación, que vive en [13-REPORTS.md](./13-REPORTS.md); el permiso RBAC exacto de quién puede generar o descargar cada tipo de documento, que vive en [06-AUTHORIZATION.md](./06-AUTHORIZATION.md).

## Conceptos

- **Documento generado**: artefacto PDF producido a partir de datos ya calculados, en un momento concreto, con una versión asociada.
- **Inmutabilidad del documento**: un documento PDF ya generado nunca se edita ni se sobrescribe; cualquier corrección posterior produce una **nueva versión**, conservando la anterior íntegra en `generated_documents` (ADR-011 en [23-DECISIONS.md](./23-DECISIONS.md)).
- **`PdfGenerator`**: interfaz abstracta que desacopla el módulo PDF de la librería concreta de renderizado (ADR-011). **Recomendación de arquitectura** (no bloqueante, ajustable en Fase 11 si hace falta): `barryvdh/laravel-dompdf` — es PHP puro (sin dependencia de un binario de Chromium como Browsershot/Puppeteer), lo cual encaja mejor con el compute Flex de Laravel Cloud (ADR-021), y es suficiente para documentos tabulares como comprobantes y reportes. Si más adelante se necesita fidelidad visual de CSS moderno (flexbox/grid complejo), evaluar `spatie/laravel-pdf` como alternativa, asumiendo el costo de correr Chromium en el entorno de hosting.

## Entidades

| Entidad | Propósito | Notas de `05-DATABASE.md` |
|---|---|---|
| `generated_documents` | Registro versionado de cada PDF generado: `id`, `company_id`, `type`, `reference_entity_type`/`reference_entity_id`, `storage_ref`, `generated_by`, `generated_at`, `version` | Aislamiento DIRECTO; sin soft-delete; **INMUTABLE** — una corrección genera una fila nueva con `version` incrementada, nunca sobrescribe `storage_ref` de una fila existente |

Consultar [04-DOMAIN-MODEL.md](./04-DOMAIN-MODEL.md) y [05-DATABASE.md](./05-DATABASE.md) para el detalle completo de columnas, índices y constraints.

## Reglas

### Tipos de documento a generar

- **Comprobante de nómina**: liquidación individual de un empleado para un `payroll_entry` de un periodo, típicamente `CLOSED`.
- **Reporte de asistencia/horas**: materialización en PDF de un reporte de [13-REPORTS.md](./13-REPORTS.md) (asistencia diaria, por trabajador, horas trabajadas/extra/faltantes).
- **Resumen de periodo**: consolidado de un `payroll_period` completo (todos los empleados, totales agregados).
- **Reporte de novedades**: consolidado de `novelty_records`/`leave_records`/`overtime_records` aprobados en un rango.
- **Reporte de seguridad social**: consolidado de `social_security_contributions` por periodo/entidad.

### Contenido obligatorio

Todo documento generado por este módulo debe incluir, como mínimo:

- Información de la empresa (`company`, y `branch` cuando aplique).
- Información del trabajador (cuando el documento sea individual).
- Periodo al que corresponde el documento.
- Detalle de líneas/conceptos que sustentan el documento.
- Totales (devengado, deducido, neto, o el total agregado que corresponda al tipo de documento).
- Observaciones (cuando existan, ej. ajustes aplicados, novedades relevantes del periodo).
- Fecha de generación del documento.

### Inmutabilidad

- **El PDF generado es inmutable**: un documento ya generado nunca se sobrescribe. Una corrección posterior de los datos fuente (ej. un `payroll_adjustment` sobre un periodo reabierto) que requiera reflejarse en el documento produce una **nueva versión** en `generated_documents`, con su propio `storage_ref` y `version` incrementada; la versión anterior permanece accesible.
- La generación del documento **nunca altera los datos fuente que lo originaron** (`payroll_entries`, `payroll_entry_lines`, `social_security_contributions`, etc.); PDF es puramente un consumidor de solo lectura de esos módulos.

## Flujos

### Generar comprobante al cerrar un periodo de nómina

1. Al completarse `CLOSE` sobre un `payroll_period` (ver flujo de cierre en [10-PAYROLL.md](./10-PAYROLL.md)), se dispara la generación del comprobante de nómina de cada `payroll_entry` del periodo.
2. El módulo PDF lee los datos ya calculados y `CLOSED` (nunca recalcula nada) y produce el documento vía `PdfGenerator`.
3. Se inserta una fila en `generated_documents` con `version=1`, referenciando el `payroll_entry` correspondiente (`reference_entity_type='payroll_entry'`).
4. **RESUELTO** (ADR-035 en [23-DECISIONS.md](./23-DECISIONS.md)): no se genera PDF "borrador" antes del cierre. La generación de comprobantes solo ocurre desde entradas `CLOSED` (ver [10-PAYROLL.md](./10-PAYROLL.md)).

### Regenerar un documento sin alterar los datos fuente

1. Un cambio en los datos fuente (ej. `payroll_adjustments` tras una reapertura auditada) requiere un documento actualizado.
2. El módulo PDF genera un nuevo artefacto a partir del estado actual de los datos fuente (ya corregidos por su módulo dueño).
3. Se inserta una **nueva fila** en `generated_documents` para la misma `reference_entity_id`, con `version` incrementada respecto a la anterior. La fila anterior no se modifica ni se elimina.
4. Los datos fuente que originaron la regeneración no se tocan como efecto de esta operación: PDF solo lee.

### Firma electrónica en comprobantes de pago

**RESUELTO** (ADR-028 en [23-DECISIONS.md](./23-DECISIONS.md)): los comprobantes de la v1 **no** requieren firma electrónica; son documentos informativos completos sin validez de firma legal. Queda como mejora futura si un requisito legal o comercial concreto lo exige.

## Casos normales

- Se cierra una quincena y se generan automáticamente los comprobantes de nómina de todos los empleados del periodo, cada uno como `version=1` en `generated_documents`.
- Un `EMPLOYEE` descarga su propio comprobante de nómina ya generado.

## Casos especiales

- **Periodo de nómina reabierto y corregido**: cuando un `payroll_period` se reabre (`REOPENED`) y se corrige mediante `payroll_adjustments`, el documento anterior asociado **no se borra ni se sobrescribe**; se genera una **nueva versión** en `generated_documents` que refleja los datos corregidos, y ambas versiones quedan accesibles con su `version` diferenciada.

## Errores

- **Datos incompletos para generar el documento**: si falta información obligatoria (ver Contenido obligatorio) en los datos fuente, la generación debe rechazarse explícitamente en vez de producir un documento con campos vacíos silenciosos.
- **Plantilla faltante**: si no existe una plantilla de renderizado configurada para el tipo de documento solicitado, la generación falla de forma explícita.

## Seguridad

- El acceso a documentos que contienen datos salariales (comprobante de nómina, resumen de periodo, costos laborales) sigue el mismo criterio de RBAC de datos personales que [10-PAYROLL.md](./10-PAYROLL.md): un `EMPLOYEE` solo puede descargar sus propios comprobantes; roles con `payroll.read` amplio (`HR_MANAGER` no tiene `payroll.read` según la matriz RBAC de [06-AUTHORIZATION.md](./06-AUTHORIZATION.md); `SUPER_ADMIN`, `COMPANY_OWNER`, `ADMIN`, `PAYROLL_MANAGER`, `ACCOUNTANT` sí) pueden acceder a comprobantes de otros empleados de su propia empresa.
- Los documentos generados se almacenan mediante `storage_ref`; el mecanismo de almacenamiento concreto (proveedor, cifrado en reposo) es un detalle de infraestructura tratado en [20-SECURITY.md](./20-SECURITY.md).

## Dependencias

- [10-PAYROLL.md](./10-PAYROLL.md): dispara la generación del comprobante de nómina al cerrar un periodo; es la fuente de las cifras de nómina.
- [11-SOCIAL-SECURITY.md](./11-SOCIAL-SECURITY.md): fuente de las cifras del reporte de seguridad social.
- [13-REPORTS.md](./13-REPORTS.md): fuente de los datos de reportes de asistencia/horas/novedades que este módulo materializa en PDF.
- [06-AUTHORIZATION.md](./06-AUTHORIZATION.md): RBAC de acceso a documentos con datos salariales.
- [23-DECISIONS.md](./23-DECISIONS.md): ADR-011 (estrategia de generación de PDF e inmutabilidad del artefacto).

## Criterios de aceptación

- Ningún documento generado por este módulo, una vez creado, se sobrescribe: toda corrección produce una fila nueva en `generated_documents` con `version` incrementada.
- Todo documento generado contiene, como mínimo, los campos listados en Contenido obligatorio.
- La generación de un documento nunca modifica los datos fuente que lo originaron.
- Un `EMPLOYEE` solo puede acceder a sus propios documentos con datos salariales.
- Reabrir y corregir un periodo de nómina produce una nueva versión del comprobante sin eliminar la versión previa.
