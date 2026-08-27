# 12-BIOMETRICS.md — Biometría

## Objetivo

Preparar el sistema para conectar lectores biométricos de marcación (fichada) sin acoplar la lógica de negocio del SaaS a un fabricante o modelo de dispositivo concreto. La integración biométrica debe poder cambiar de proveedor de hardware sin tocar el resto de los módulos (`07-ATTENDANCE.md`, `09-TIME-CALCULATION.md`, `10-PAYROLL.md`), y debe tratar la información biométrica como dato personal de categoría especial desde el primer día de diseño, incluso antes de que exista una integración real con un dispositivo.

## Alcance

Este archivo cubre:

- La interfaz abstracta `BiometricProvider` que desacopla el fabricante del lector de la lógica de negocio.
- Las entidades de staging y de vínculo empleado↔dispositivo↔proveedor (`attendance_devices`, `device_heartbeats`, `biometric_identities`, `biometric_raw_events`).
- Los flujos de enrolamiento, fichada, sincronización offline y monitoreo de estado del dispositivo.
- La política de seguridad y privacidad específica de datos biométricos (sección obligatoria y extensa de este documento).

Explícitamente **fuera de alcance** de este módulo, y por tanto NO se decide aquí:

- El cálculo de tiempo trabajado, tolerancias, redondeo o nocturnidad — eso es `09-TIME-CALCULATION.md`.
- La decisión de si una marcación genera hora extra pagable — eso es `10-PAYROLL.md` vía `overtime_records`.
- El mecanismo de ajuste manual de una asistencia — eso es `07-ATTENDANCE.md`.

**Principio rector**: el lector biométrico y el módulo de Biometrics **NUNCA** calculan nómina ni deciden la asistencia final por sí mismos. Su única responsabilidad es identificar a un trabajador y producir un **evento candidato**, que luego procesa el resto del pipeline descrito en `07-ATTENDANCE.md`.

## Conceptos

- **`BiometricProvider`**: interfaz abstracta que todo fabricante/dispositivo biométrico debe implementar (o para la cual se debe escribir un adaptador) para conectarse al sistema. Encapsula, como mínimo, las operaciones de enrolamiento, normalización de payload de eventos, sincronización de eventos pendientes y consulta de estado del dispositivo. Ningún módulo de negocio debe conocer detalles propios de un fabricante (protocolo, formato de payload, SDK); todos hablan contra esta interfaz. Ver ADR-005.
- **Identificación vs. autenticación biométrica**: son dos modos distintos y no intercambiables.
  - *Identificación* (1:N): el dispositivo/proveedor recibe una lectura biométrica y la compara contra el conjunto de identidades enroladas para determinar **quién es** la persona, sin que esta declare previamente su identidad. Es el modo típico de una fichada libre en un dispositivo compartido.
  - *Autenticación* (1:1): la persona declara previamente quién es (tarjeta, PIN, código de empleado) y la lectura biométrica solo **verifica** que coincide con la identidad declarada. Es más liviano computacionalmente y reduce falsos positivos, pero exige un paso adicional de identificación previa.
  - Qué modo(s) soporta el sistema en la práctica depende del proveedor/dispositivo elegido — ver `PENDING DECISION` en la sección de Seguridad.
- **Template / identificador biométrico vs. imagen cruda**: un *template* es una representación matemática derivada de una característica biométrica (p. ej. minucias de una huella), generalmente unidireccional y no reconstruible a la imagen original; una *imagen cruda* es la captura original del sensor (p. ej. una foto de huella). El sistema **nunca almacena imágenes crudas**, salvo que en el futuro exista una justificación técnica y legal explícita para hacerlo — hoy esa justificación no existe, por lo que se trata como decisión de diseño por defecto: **NO almacenar imágenes**. Preferiblemente, el sistema tampoco almacena el template en sí: la tabla `biometric_identities` guarda un `external_ref`, es decir, un identificador opaco emitido y resuelto por el proveedor, delegando en él la custodia real del dato biométrico. Si el proveedor elegido exige almacenar un template cifrado del lado del sistema, esa es una excepción a documentar en su momento (ver Seguridad y privacidad biométrica).
- **Dispositivo online / offline**: un `attendance_device` puede operar sin conexión permanente a la plataforma central. El diseño asume que la conexión puede perderse en cualquier momento y que el dispositivo debe seguir capturando eventos localmente y sincronizarlos después, sin pérdida de datos y sin bloquear al trabajador que está fichando.

## Entidades

| Entidad | Propósito | Notas de este módulo |
|---|---|---|
| `attendance_devices` | Dispositivo de marcación (biométrico u otro origen) | Columnas relevantes aquí: `company_id`, `branch_id`, `provider`, `external_device_id`, `status`, `last_heartbeat_at`. El estado y el último latido determinan si el dispositivo se considera online/offline. |
| `device_heartbeats` | Historial inmutable de latidos/estado de un dispositivo | `device_id`, `status`, `received_at`. Mutabilidad: INMUTABLE (log de solo inserción). |
| `biometric_identities` | Vínculo entre un empleado y su identificador en un proveedor biométrico concreto | `employee_id`, `provider`, `external_ref`, `enrolled_at`, `status`. Mutabilidad: MUTABLE con soft-delete (revocar la identidad, nunca borrarla físicamente). Un mismo empleado puede tener más de una fila si se re-enrola con otro proveedor o dispositivo. |
| `biometric_raw_events` | Staging inmutable, append-only, de los payloads crudos recibidos del dispositivo **antes** de identificar al empleado | `device_id`, `external_event_id`, `payload`, `received_at`, `processing_status`, `matched_attendance_event_id`. Esta tabla es la que absorbe duplicados, desorden y sincronización offline sin tocar el histórico inmutable de `attendance_events` (ADR-015). |
| `attendance_events` | Evento inmutable de marcación (entidad propia de `07-ATTENDANCE.md`, referenciada aquí como destino del flujo) | Este módulo produce filas con `source=BIOMETRIC` y `device_id` apuntando a `attendance_devices`. Su definición completa vive en `07-ATTENDANCE.md`. |

`processing_status` de `biometric_raw_events` cubre, como mínimo, los estados: `PENDING` (recién insertado, aún no procesado), `PROCESSED` (generó o se enlazó a un `attendance_event`), `DUPLICATE` (coincide con un evento ya existente), `UNMATCHED` (no se pudo resolver a un empleado) y `ERROR` (payload no procesable — ver Errores). Esta enumeración es una elaboración de diseño de este archivo sobre lo ya descrito en el blueprint de dominio; no introduce reglas de negocio nuevas, solo nombra los estados que el flujo (a) ya exige.

## Reglas

1. La arquitectura debe abstraer siempre el fabricante del lector vía `BiometricProvider`. Ningún módulo de negocio (Attendance, Time Calculation, Payroll) puede depender de un SDK, protocolo o formato de payload propietario de una marca específica.
2. Se prefieren templates/identificadores seguros provistos por el proveedor (`external_ref`) sobre el almacenamiento de imágenes biométricas crudas. Por defecto, **no se almacenan imágenes**.
3. El sistema debe considerar explícitamente, para cualquier proveedor que se integre:
   - Dispositivos **online/offline** y su transición de estado.
   - **Sincronización posterior** de eventos capturados mientras el dispositivo estuvo desconectado.
   - **Eventos duplicados** (mismo empleado, mismo tipo de evento, misma ventana de tiempo).
   - **Eventos fuera de orden** (p. ej. llega un `BREAK_END` antes que su `BREAK_START` correspondiente).
   - **Pérdida de conexión** entre el dispositivo y el Device Gateway durante el envío.
   - **Identificación y autenticación del propio dispositivo** ante la plataforma (distinto de identificar al empleado): el dispositivo debe presentar credenciales/certificado propios; un dispositivo no autorizado o dado de baja nunca debe poder insertar eventos.
   - **Estado del dispositivo** (`attendance_devices.status`, `last_heartbeat_at`) como dato de primera clase, no como subproducto.
4. Ningún evento crudo se descarta en silencio. Si no se puede identificar al empleado, se marca `UNMATCHED` y dispara revisión manual; nunca se borra ni se ignora.
5. La deduplicación y el manejo de desorden ocurren **antes** de tocar `attendance_events`, sobre `biometric_raw_events`, precisamente porque `attendance_events` es inmutable y no debe absorber el ruido propio del hardware.
6. El ACK al dispositivo se emite en cuanto el payload crudo queda insertado en `biometric_raw_events`, independientemente de si el resto del pipeline (identificación, deduplicación, cálculo) tiene éxito después. Esto evita que un fallo interno de la plataforma provoque reintentos indefinidos o pérdida de la marcación en el dispositivo.

## Flujos

### Flujo (a) — Fichada biométrica → cálculo de tiempo (transcrito de la arquitectura aprobada)

1. El lector identifica/lee al trabajador → el Device Gateway normaliza el payload del fabricante vía la interfaz `BiometricProvider`.
2. Insert en `biometric_raw_events` (staging, inmutable, `processing_status=PENDING`); el dispositivo recibe **ACK inmediato** incluso si el resto del pipeline falla después (tolerancia a offline).
3. Identificación de empleado: se resuelve `external_ref` → `biometric_identities` → `employees`, con scoping por `company_id`/`branch_id` del dispositivo. Si no hay match → `UNMATCHED`, se dispara notificación de revisión manual; **nunca se descarta en silencio**.
4. Deduplicación: si ya existe un `attendance_event` equivalente (mismo empleado/tipo/ventana de tiempo) → se marca `DUPLICATE` y se enlaza (`matched_attendance_event_id`), no se crea un evento nuevo.
5. Manejo de desorden: los eventos fuera de secuencia (p. ej. `BREAK_END` antes que `BREAK_START`) no se rechazan; se aceptan y se marca una anomalía de baja severidad que consume el motor de cálculo. La inferencia exacta del tipo de evento por modelo de dispositivo es **PENDING DECISION**, ya que depende del proveedor elegido.
6. Con match y deduplicación exitosos, se crea el `attendance_event` inmutable (`source=BIOMETRIC`); se actualiza el raw event a `PROCESSED`.
7. El motor de Time Calculation (disparado async o bajo demanda) cruza el `ShiftAssignment` planificado + eventos reales del día + `labor_rule_versions` vigente + `novelty_records` que apliquen → escribe/regenera `attendance_records` (recalculable, nunca fuente de verdad primaria — la fuente de verdad son los eventos). Ver `09-TIME-CALCULATION.md`.
8. Si el exceso supera la tolerancia configurada, se crea/actualiza `overtime_records` en estado `DETECTED` (no pagable automáticamente). Ver `10-PAYROLL.md`.

### Flujo de enrolamiento de un empleado en el dispositivo

1. Un usuario con permiso `biometrics.enroll` (ver RBAC en Seguridad y privacidad biométrica) inicia el enrolamiento del empleado, normalmente desde la ficha del empleado o desde la administración del dispositivo.
2. El Device Gateway invoca la operación de enrolamiento de `BiometricProvider` para el dispositivo/proveedor correspondiente; el proveedor captura la muestra biométrica y genera su representación interna (template), proceso que ocurre del lado del dispositivo o del proveedor, no dentro del backend del SaaS.
3. El proveedor devuelve un `external_ref` (identificador opaco) que representa al empleado dentro de su propio sistema/dispositivo.
4. El sistema crea (o reactiva) una fila en `biometric_identities` con `employee_id`, `provider`, `external_ref`, `enrolled_at`, `status=ACTIVE`.
5. La operación queda registrada en `audit_logs` (acción sensible — ver Seguridad y privacidad biométrica).
6. Si el empleado ya tenía una identidad `ACTIVE` con el mismo proveedor, la política de reemplazo (sobrescribir vs. coexistir con la anterior revocada) es una decisión de implementación menor que debe resolverse contra el contrato real del `BiometricProvider` elegido; no bloquea el diseño porque `biometric_identities` admite múltiples filas por empleado.

### Flujo de sincronización tras pérdida de conexión (sync offline)

1. Mientras el dispositivo está desconectado, sigue capturando eventos localmente (esto depende de que el hardware elegido soporte almacenamiento local — capacidad que forma parte del contrato de `BiometricProvider` a definir).
2. Al recuperar conexión, el Device Gateway ejecuta la operación de sincronización de `BiometricProvider`, que trae en lote los eventos pendientes del dispositivo, cada uno con su `external_event_id` y su marca de tiempo **original** de captura (no la de recepción).
3. Cada evento sincronizado entra por el mismo pipeline del flujo (a) desde el paso 2 (insert en `biometric_raw_events`, identificación, deduplicación, manejo de desorden, creación de `attendance_event`).
4. Como los eventos offline llegan con retraso y potencialmente desordenados entre sí y respecto a eventos ya procesados de otros dispositivos, el manejo de desorden (regla 3 de este archivo) es el mecanismo que absorbe esta situación; no se trata como un caso especial adicional a nivel de esquema.
5. El estado del dispositivo transiciona de `OFFLINE` a `ONLINE` una vez confirmada la sincronización (ver flujo de heartbeat).

### Flujo de heartbeat / monitoreo de estado del dispositivo

1. El dispositivo (o el Device Gateway consultándolo activamente, según lo que soporte el proveedor) emite periódicamente una señal de latido.
2. Cada latido se inserta como fila inmutable en `device_heartbeats` (`device_id`, `status`, `received_at`).
3. `attendance_devices.last_heartbeat_at` se actualiza con cada latido recibido; `attendance_devices.status` refleja el estado agregado más reciente (p. ej. `ONLINE`, `OFFLINE`, `DEGRADED`).
4. La ausencia de latidos por un período determina la transición a `OFFLINE` a nivel de plataforma (aunque el dispositivo, físicamente, pueda seguir capturando eventos localmente sin conexión). El umbral exacto de tiempo sin latido es un parámetro de configuración operativa, no una regla de negocio fija, y debe resolverse junto con la elección del proveedor.
5. Un cambio de estado a `OFFLINE` o `DEGRADED` puede disparar una notificación operativa (ver `17-NOTIFICATIONS.md`), pero esto no bloquea ni descarta eventos ya capturados por el dispositivo — solo informa al equipo operativo.

## Casos normales

- Fichada con identificación exitosa, sin duplicados, sin desorden: el `attendance_event` se crea en el mismo ciclo de procesamiento del `biometric_raw_event`.
- Enrolamiento exitoso de un nuevo empleado con el dispositivo en línea.
- Latidos periódicos regulares; el dispositivo permanece `ONLINE` de forma continua durante la jornada.

## Casos especiales

- **Dispositivo offline con sincronización posterior**: los eventos capturados sin conexión se procesan íntegramente al reconectar, siguiendo el flujo de sincronización descrito arriba; ningún evento se pierde por la desconexión, y el orden de llegada tardío se trata como manejo de desorden, no como error.
- **Evento duplicado**: el proveedor reenvía (por reintento propio, por reconexión, o por doble captura del sensor) un evento ya procesado; se marca `DUPLICATE` en `biometric_raw_events` y se enlaza al `attendance_event` existente vía `matched_attendance_event_id`, sin generar un segundo evento.
- **Empleado no identificado (`UNMATCHED`)**: la lectura biométrica no resuelve a ninguna `biometric_identities` activa dentro del scoping de `company_id`/`branch_id` del dispositivo (empleado no enrolado, identidad revocada, o lectura de baja calidad). Se marca `UNMATCHED`, se dispara notificación de revisión manual, y el trabajador debe usar el mecanismo de registro alternativo definido en `07-ATTENDANCE.md` mientras se resuelve. El evento crudo permanece en `biometric_raw_events` para trazabilidad y eventual re-procesamiento manual.
- **Dispositivo no autorizado o dado de baja intentando enviar eventos**: el Device Gateway debe rechazar la conexión/el payload en la capa de autenticación del propio dispositivo, antes de llegar a `biometric_raw_events`. El intento se registra como evento de seguridad (ver Seguridad y privacidad biométrica) y nunca se inserta un `attendance_event` a partir de un dispositivo no autorizado.

## Errores

- **Fallo de autenticación del dispositivo**: el dispositivo no presenta credenciales/certificado válidos ante el Device Gateway. La conexión se rechaza; no se genera ninguna fila en `biometric_raw_events`; se registra el intento en auditoría/seguridad como posible anomalía operativa o intento de suplantación de dispositivo.
- **Payload malformado del fabricante**: el Device Gateway no puede normalizar el payload vía `BiometricProvider` (formato inesperado, campos faltantes, corrupción de datos). Siguiendo el principio de "nunca descartar en silencio", el payload crudo recibido se persiste igualmente en `biometric_raw_events` con `processing_status=ERROR` para revisión forense/soporte, en vez de descartarse antes de almacenarse.
- **Empleado sin identidad biométrica enrolada**: la lectura es técnicamente válida pero no existe ninguna fila `biometric_identities` que la resuelva (empleado nunca enrolado, o su identidad fue revocada por offboarding). Se trata como `UNMATCHED` (ver Casos especiales); no es un error de sistema, es un estado de datos esperado que requiere resolución operativa (enrolar al empleado o usar registro alternativo).

## Seguridad y privacidad biométrica

Esta sección trata **toda** la información biométrica (identificadores, templates, referencias externas, metadatos de enrolamiento) como información personal de categoría especial / altamente sensible, con un tratamiento estrictamente más estricto que el de un dato personal ordinario.

**Cifrado**

- Cualquier identificador o template biométrico que la plataforma llegue a almacenar (como mínimo, `biometric_identities.external_ref`, y cualquier metadato de enrolamiento asociado) debe viajar cifrado en tránsito (TLS) entre el dispositivo, el Device Gateway y el backend, y debe almacenarse cifrado en reposo, siguiendo el mismo criterio ya aplicado a otros datos sensibles del dominio (p. ej. `payroll_information.bank_account_enc`).
- Si el `BiometricProvider` elegido requiriera que la plataforma almacene un template propiamente dicho (y no solo una referencia opaca), ese template debe tratarse con el mismo nivel de cifrado, y su necesidad debe quedar documentada explícitamente como excepción al principio de "solo referencia externa" de este archivo.

**Retención**

- **PENDING DECISION**: no existe hoy una política de retención definida para los datos biométricos — ni el plazo durante el cual se conserva una identidad biométrica enrolada, ni el disparador exacto de borrado (por ejemplo: al finalizar el contrato, tras N días de inactividad, a solicitud explícita del titular). Esta política depende del marco legal aplicable (ver más abajo) y debe definirse antes de construir la funcionalidad de retención automática.

**Eliminación al offboarding de un empleado**

- Eliminar o anonimizar la identidad biométrica de un empleado (`biometric_identities`) **NUNCA** debe invalidar el histórico de `attendance_events` ya generado. Esta es la resolución de la Contradicción #4 detectada en el diseño del sistema: `attendance_events` referencia `employee_id`, **no** la identidad biométrica que originó cada evento; por lo tanto, borrar o anonimizar `biometric_identities` (vía soft-delete/revocación, nunca borrado físico inmediato del registro histórico de enrolamiento) no afecta la validez ni la integridad de los eventos de asistencia ya registrados, que siguen siendo consultables para reportes, nómina y auditoría.
- En la práctica, el offboarding de un empleado revoca su(s) fila(s) en `biometric_identities` (cambio de `status`, soft-delete), pero no toca `attendance_events`, `attendance_records`, `payroll_entries` ni ningún otro dato derivado de su historial laboral.

**Control de acceso**

- `biometrics.enroll` (enrolar a un empleado en un dispositivo/proveedor): permitido a `SUPER_ADMIN`, `COMPANY_OWNER`, `ADMIN` y `HR_MANAGER`. No permitido a `PAYROLL_MANAGER`, `SUPERVISOR`, `ACCOUNTANT` ni `EMPLOYEE`.
- `biometrics.delete_data` (eliminar/revocar datos biométricos de un empleado): permitido únicamente a `SUPER_ADMIN`, `COMPANY_OWNER` y `ADMIN`. Ni siquiera `HR_MANAGER` tiene este permiso por defecto — enrolar y eliminar son operaciones con distinto nivel de sensibilidad y no comparten el mismo permiso.
- Ambos permisos deben verificarse en la capa de servicio del backend, nunca solo ocultando la opción en el frontend (principio general de `19-FRONTEND.md`/`20-SECURITY.md`).

**Auditoría obligatoria**

- Toda operación sobre datos biométricos (enrolar, revocar/eliminar, re-enrolar, y cualquier consulta administrativa que exponga el vínculo empleado↔proveedor↔`external_ref`) es una acción sensible de auditoría obligatoria (`audit_logs`), con usuario, valor anterior/nuevo y motivo, siguiendo la misma regla conservadora del resto del sistema: si la escritura del log de auditoría falla, la transacción de negocio debe abortar (ADR-018).

**Riesgos**

- **Suplantación (spoofing)**: un tercero podría intentar hacerse pasar por un empleado ante el lector biométrico. La mitigación real (detección de vida/liveness, calidad mínima de captura, etc.) depende enteramente de las capacidades del hardware/proveedor elegido y no puede especificarse de forma agnóstica en este documento.
- **Fuga de template/identificador**: a diferencia de una contraseña, un dato biométrico comprometido no se puede "rotar" de la misma manera para la persona afectada. Esto refuerza la exigencia de cifrado en tránsito/reposo y de minimizar lo que la plataforma almacena (preferir `external_ref` opaco sobre template propio, y nunca imágenes).
- **Dependencia de un único proveedor**: acoplarse a un solo fabricante crea riesgo operativo (continuidad del servicio, condiciones comerciales) y de portabilidad de los datos biométricos. La interfaz `BiometricProvider` (ADR-005) es la mitigación arquitectónica explícita de este riesgo: permite sustituir el proveedor sin rediseñar el resto del sistema, aunque no elimina el riesgo de que los datos biométricos ya enrolados no sean portables a otro proveedor (limitación a evaluar cuando se elija el proveedor real).

**Dependencias legales**

- **PENDING DECISION**: el marco legal exacto aplicable al tratamiento de datos biométricos (más allá de lo genérico que trate `20-SECURITY.md` sobre cumplimiento normativo de datos personales en general) no está definido en el brief original. Esto incluye, sin limitarse a: si aplica un régimen de protección de datos biométricos con requisitos reforzados (consentimiento explícito, evaluación de impacto, notificación a autoridad de control), y en qué jurisdicción(es) debe operar el sistema. Esta decisión es un bloqueante identificado en el diseño general del sistema y debe resolverse antes de construir cualquier integración biométrica real (no solo antes de esta documentación).

**Proveedor(es) de dispositivo biométrico**

- **PENDING DECISION**: no se ha seleccionado un proveedor o fabricante de hardware biométrico concreto. Esta decisión define el contrato real de `BiometricProvider` (qué operaciones expone el SDK/API del fabricante, qué modo de identificación/autenticación soporta, si permite match-on-device o requiere match-on-server, qué formato de payload y de sincronización offline ofrece, y si exige almacenar un template propio en vez de solo un `external_ref`). Hasta que se tome esta decisión, `BiometricProvider` se diseña como interfaz agnóstica basada en las capacidades descritas en este documento, no contra un SDK específico.
- **RESUELTO** (ADR-036 en [23-DECISIONS.md](./23-DECISIONS.md)): el propietario del producto confirmó que todavía no eligió proveedor. La Fase 12 del roadmap arranca implementando un `BiometricProvider` **mock** que cumple esta interfaz agnóstica y se prueba de punta a punta (flujo (a) completo) sin depender de hardware real; el proveedor real se conecta después, sin necesitar rediseñar el resto del pipeline de asistencia.
- **OPCIÓN RECOMENDADA, no cerrada** (ADR-042 en [23-DECISIONS.md](./23-DECISIONS.md)): **ZKTeco K40** (o K40 Pro) como candidato principal cuando se decida comprar hardware real — lector de huella económico (~USD 45-100), con TCP/IP y protocolo **ADMS** (el dispositivo empuja cada marcación por HTTP directo a un servidor propio configurado en su propio menú, sin depender de la nube del fabricante), compatible con el criterio de `BiometricProvider` agnóstico ya diseñado. No es una compra confirmada, es la opción de partida para cuando se aborde la Fase 12.

## Dependencias

- [07-ATTENDANCE.md](./07-ATTENDANCE.md): destino final del flujo — este módulo entrega `attendance_event` inmutables con `source=BIOMETRIC`; el mecanismo de ajuste manual, la deduplicación a nivel de asistencia y los casos de asistencia (nocturno, cruce de medianoche, olvido de salida) se definen allí, no aquí.
- [16-AUDIT.md](./16-AUDIT.md): registro obligatorio de toda operación sensible sobre datos biométricos (enrolar, revocar, eliminar).
- [20-SECURITY.md](./20-SECURITY.md): marco general de cifrado, gestión de secretos y cumplimiento normativo dentro del cual se enmarca la política específica de datos biométricos descrita en este archivo; el marco legal biométrico exacto queda `PENDING DECISION` en ambos documentos hasta que se resuelva de forma centralizada.

Referencia adicional: el catálogo formal de las entidades `attendance_devices`, `device_heartbeats`, `biometric_identities` y `biometric_raw_events` (columnas, tipos, constraints) vive en `04-DOMAIN-MODEL.md` y `05-DATABASE.md`; este archivo no redefine esas columnas, solo las referencia en el contexto del flujo biométrico.

## Criterios de aceptación

- El diseño no referencia en ningún punto un SDK, protocolo o formato de payload propio de un fabricante concreto; toda interacción con hardware pasa por `BiometricProvider`.
- El flujo (a) completo (lector → `biometric_raw_events` → identificación → deduplicación → `attendance_event` → Time Calculation) está probado con un proveedor mock, incluyendo los casos: evento duplicado, evento fuera de orden, empleado `UNMATCHED`, y dispositivo offline con sincronización posterior.
- Un dispositivo no autorizado o dado de baja no puede insertar eventos, y el intento queda registrado como evento de seguridad.
- Eliminar/revocar la identidad biométrica de un empleado no altera ni oculta su histórico de `attendance_events`, `attendance_records` ni `payroll_entries` ya generados.
- Ninguna imagen biométrica cruda se almacena en ningún punto del pipeline.
- Toda operación de `biometrics.enroll` y `biometrics.delete_data` genera una entrada correspondiente en `audit_logs`, y el permiso se valida en el backend independientemente de la UI.
- Los `PENDING DECISION` de esta sección (proveedor de hardware, marco legal aplicable, política de retención) están señalizados explícitamente y no se ha tomado ninguna decisión de negocio implícita en su lugar.
