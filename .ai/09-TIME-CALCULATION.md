# 09-TIME-CALCULATION.md — Motor de Cálculo de Tiempo

## Objetivo

Definir el motor de **Time Calculation**, que cruza el tiempo planificado ([08-SHIFTS.md](./08-SHIFTS.md)) con los eventos reales de asistencia ([07-ATTENDANCE.md](./07-ATTENDANCE.md)) y las reglas laborales vigentes para producir tiempo ordinario, extra candidato y faltante. Este archivo incluye también el **marco general del Motor de Reglas Laborales**: el framework de *tiempo* (jornada ordinaria, tolerancias, redondeo, nocturno, dominical/festivo como magnitud de tiempo) vive aquí; la traducción de esas mismas reglas a *dinero* (recargos, conceptos salariales) vive en [10-PAYROLL.md](./10-PAYROLL.md). Ambos archivos referencian las mismas tablas `labor_rules`/`labor_rule_versions` de [05-DATABASE.md](./05-DATABASE.md) — los mismos parámetros nunca se definen dos veces de forma distinta.

## Alcance

**Este motor decide:**
- Tiempo planificado (a partir de `shift_assignments`+`shift_breaks`).
- Tiempo trabajado real (a partir de `attendance_events`, netos de ajustes).
- Descansos (planificados vs. realmente tomados).
- Horas ordinarias.
- Horas extra **candidatas** (excedente detectado, no pagable automáticamente).
- Horas faltantes.
- Diferencias entre planificado y real, e incidencias (anomalías de baja severidad heredadas de [07-ATTENDANCE.md](./07-ATTENDANCE.md)).

**Este motor NO decide:**
- Si una hora extra candidata se paga, se autoriza, y a qué tarifa. Eso es responsabilidad del módulo Overtime y de [10-PAYROLL.md](./10-PAYROLL.md).
- Ningún porcentaje de recargo (nocturno, dominical, festivo, hora extra) ni ninguna tasa monetaria. Este motor produce **minutos clasificados por categoría de tiempo**, nunca montos.
- **PENDING DECISION**: la legislación/país exacto a aplicar en reglas laborales (¿Colombia exclusivo?, ¿multi-país desde el inicio?) no está definida en el brief. Esto determina, entre otras cosas, qué ventana horaria se considera "nocturna" o qué días se consideran "festivo" por defecto. Mientras no se resuelva, todo parámetro de este tipo se configura explícitamente por empresa vía `labor_rule_versions.parameters`, sin valor por defecto asumido.

## Conceptos

- **Tolerancia**: margen de minutos dentro del cual una diferencia entre lo planificado y lo real (llegada tardía, salida tardía, exceso de tiempo trabajado) no genera ninguna incidencia ni candidato a hora extra. Es un parámetro configurable en `labor_rule_versions.parameters`, nunca un valor fijo en código.
- **Redondeo**: regla de aproximación aplicada a los tiempos calculados (por ejemplo, al múltiplo de 5 minutos más cercano). También configurable por versión de regla; el blueprint no fija ningún valor de redondeo por defecto.
- **Ventana de gracia**: periodo, generalmente breve, alrededor de la hora planificada de entrada/salida dentro del cual una marcación se considera "a tiempo" a efectos de tolerancia.
- **Regla laboral versionada por fecha de vigencia**: instancia del patrón "effective-dated lookup" documentado en [04-DOMAIN-MODEL.md](./04-DOMAIN-MODEL.md) — una `labor_rule_version` es válida únicamente dentro de su rango `effective_from`/`effective_to`; el motor siempre resuelve la versión vigente para la fecha calculada, nunca "la última" ni "la más reciente" sin verificar el rango.
- **Categorías de tiempo del framework** (según la convención transversal del blueprint): jornada ordinaria, tolerancias, redondeo, nocturno, dominical/festivo — todas expresadas como *magnitudes de tiempo* aquí; su traducción a dinero (recargos y conceptos salariales) es exclusiva de [10-PAYROLL.md](./10-PAYROLL.md).

## Entidades

Esquema autoritativo completo en [04-DOMAIN-MODEL.md](./04-DOMAIN-MODEL.md) y [05-DATABASE.md](./05-DATABASE.md).

| Entidad | Propósito | Mutabilidad |
|---|---|---|
| `labor_rules` | Definición de una regla laboral configurable: tipo (`rule_type`) + empresa, o `company_id` nulo para el default de plataforma. | MUTABLE (metadata) |
| `labor_rule_versions` | Versión de una regla vigente en un rango de fechas (`effective_from`/`effective_to`), con sus parámetros en `parameters` (jsonb). | HISTORIAL — nunca se edita una versión ya vigente o ya usada en cálculos pasados; una corrección crea una versión nueva |
| `time_calculation_runs` | Traza de auditoría de cada corrida del motor: empleado, fecha, versión de regla usada, hash de los insumos, referencia a la salida. Para debug y soporte. | INMUTABLE (traza) |
| `attendance_records` | La salida del motor: definida/catalogada en [07-ATTENDANCE.md](./07-ATTENDANCE.md) como parte del dominio de Asistencia, pero **calculada exclusivamente por este motor**. Nunca se edita manualmente; se regenera completa desde los eventos. | RECALCULABLE (caché derivado, nunca fuente de verdad — ADR-014) |

## Reglas

1. **Insumos del motor**: turno planificado (`shift_assignments`+`shift_breaks` de [08-SHIFTS.md](./08-SHIFTS.md)) + eventos reales (`attendance_events` netos de `attendance_adjustments` aprobados, de [07-ATTENDANCE.md](./07-ATTENDANCE.md)) + reglas laborales vigentes (`labor_rule_versions` para la fecha) + novedades aplicables (`novelty_records`, por ejemplo una ausencia aprobada sin marcación física).
2. **Salida del motor**: `attendance_records` (por empleado y fecha), más una fila de traza en `time_calculation_runs`.
3. **El exceso de tiempo trabajado sobre lo planificado nunca se asume automáticamente como hora extra pagable.** Depende de las tolerancias, el redondeo y los límites configurables en `labor_rule_versions` vigente para esa fecha. Si el exceso supera el umbral configurado, se crea/actualiza un `overtime_records` en estado `DETECTED` — el resto del ciclo de vida de esa hora extra (solicitud, autorización, pago) es responsabilidad del módulo Overtime y de [10-PAYROLL.md](./10-PAYROLL.md).
4. **El motor nunca modifica `attendance_events`.** Es estrictamente de solo lectura sobre ellos (ver Seguridad).
5. **Ninguna regla laboral se hardcodea.** Todo parámetro (tolerancia, redondeo, ventana nocturna, definición de festivo/dominical a efectos de tiempo) vive en `labor_rule_versions.parameters`, resuelto por vigencia (ADR-007, ADR-020).

## Flujos

1. **Cálculo diario, tras el cierre de eventos del día**: para un empleado y una fecha, el motor reúne el turno planificado, los eventos reales netos de ajustes, resuelve la `labor_rule_version` vigente para esa fecha (por cada `rule_type` aplicable), resuelve `novelty_records` aplicables, calcula tiempo ordinario/extra candidato/faltante, y escribe/regenera el `attendance_record` correspondiente junto con una fila en `time_calculation_runs`.
2. **Recálculo tras un ajuste de asistencia aprobado**: disparado por el Flujo 2 de [07-ATTENDANCE.md](./07-ATTENDANCE.md), paso 6. El motor identifica la(s) fecha(s) afectada(s) por el ajuste, vuelve a ejecutar el mismo algoritmo con el conjunto de eventos corregido, y **regenera por completo** el `attendance_record` (nunca lo parcha de forma incremental, consistente con su naturaleza de caché derivado — ADR-014). Si el periodo de nómina correspondiente ya está `CLOSED`, el recálculo no se propaga automáticamente a `payroll_entries`; genera la señal de "ajuste de nómina pendiente" descrita en [07-ATTENDANCE.md](./07-ATTENDANCE.md) y resuelta en [10-PAYROLL.md](./10-PAYROLL.md).
3. **Recálculo tras cambio de una regla laboral vigente**: cuando se publica una nueva `labor_rule_version` (nunca editando una ya usada — ver Reglas), las fechas afectadas por el cambio pueden requerir recalcular su `attendance_record`.
   - **PENDING DECISION**: el blueprint establece que este flujo de recálculo existe, pero no define si el disparo es automático (recalcula de inmediato todas las fechas futuras/pasadas afectadas por la nueva versión) o bajo demanda, ni si aplica retroactivamente a fechas cuyo periodo de nómina ya está `CLOSED`. Esta ambigüedad se agrega aquí porque no estaba resuelta en el blueprint original.

## Casos normales

**Ejemplo numérico simple**: turno planificado 06:00→14:00 (8h00m planificadas), con un descanso planificado (`shift_breaks`) de 12:00 a 13:00. El empleado marca entrada real a las 06:07 y salida real a las 14:23, respetando el mismo horario de descanso planificado.

- Tiempo trabajado bruto (fichaje a fichaje): 14:23 − 06:07 = **8h16m**.
- Tiempo planificado bruto (mismo rango de descanso en ambos casos, por lo que se cancela en la comparación): 14:00 − 06:00 = **8h00m**.
- Diferencia: **+16 minutos** sobre lo planificado.
- Esos 16 minutos de exceso **no se asumen automáticamente como hora extra pagable**: dependen de la tolerancia configurada en la `labor_rule_version` vigente para esa fecha. Por ejemplo, si la tolerancia configurada fuera de 20 minutos, este caso no generaría ningún `overtime_records`; si fuera de 15 minutos, superaría el umbral por 1 minuto y podría generar un `overtime_records` en estado `DETECTED`. Estos valores son puramente ilustrativos del mecanismo — el valor real siempre proviene de `labor_rule_versions.parameters`, nunca de un default asumido por el motor.

## Casos especiales

- **Turno nocturno**: el algoritmo de cálculo de tiempo (ordinario/extra/faltante) es el mismo que para un turno diurno. La clasificación de qué minutos caen dentro de la "ventana nocturna" (para efectos de tiempo, no de dinero) se resuelve con el parámetro correspondiente de `labor_rule_versions` vigente — nunca con un horario nocturno fijo en el código.
- **Turno que cruza medianoche**: todo el tiempo trabajado del turno (incluida la porción posterior a la medianoche) se atribuye a una única fecha laboral: la de `shifts.date` (ver [08-SHIFTS.md](./08-SHIFTS.md)), evitando dividir un mismo turno en dos `attendance_records` de fechas de calendario distintas.
- **Permiso/ausencia aprobada sin marcación física**: cuando existe un `novelty_records` vigente para esa fecha (originado en un `leave_records` aprobado, según el patrón "paraguas" documentado en [04-DOMAIN-MODEL.md](./04-DOMAIN-MODEL.md)), el motor trata el tiempo planificado cubierto por esa novedad como justificado, no como tiempo faltante, aun sin `attendance_events` reales ese día.
- **Exceso de tiempo por debajo del umbral de tolerancia**: se registra en el detalle del `attendance_record` (para trazabilidad), pero no genera ningún `overtime_records` ni incidencia relevante para nómina.

## Errores

- **Datos insuficientes para calcular** (por ejemplo, falta un evento crítico como el `CLOCK_OUT` y no existe todavía un ajuste que lo supla): el cálculo de esa fecha queda bloqueado explícitamente; el motor no asume ni interpola un valor faltante.
- **Regla laboral vigente no encontrada para la fecha**: es un **error bloqueante explícito**. El motor nunca asume un valor por defecto silencioso cuando no existe una `labor_rule_version` vigente para el `rule_type` y la fecha requeridos; se reporta el error y el cálculo de esa fecha/empleado no se completa hasta que exista una versión vigente.

## Seguridad

- El motor es **estrictamente de solo lectura sobre `attendance_events`**: nunca los modifica, nunca los marca, nunca escribe sobre ellos. Su única escritura es sobre sus propias salidas.
- Sus salidas (`attendance_records`) son **recalculables/derivadas, nunca fuente de verdad** (ADR-014); pueden regenerarse por completo desde los eventos en cualquier momento sin pérdida de información, porque la fuente de verdad siempre son los eventos de [07-ATTENDANCE.md](./07-ATTENDANCE.md).
- `time_calculation_runs` es inmutable y sirve como traza auditable de qué versión de regla y qué insumos produjeron cada resultado, para debug y soporte.

## Dependencias

- [07-ATTENDANCE.md](./07-ATTENDANCE.md): fuente de los eventos reales (netos de ajustes) que este motor consume.
- [08-SHIFTS.md](./08-SHIFTS.md): fuente del tiempo planificado (`shift_assignments`+`shift_breaks`) que este motor consume.
- [10-PAYROLL.md](./10-PAYROLL.md): consumidor de las salidas de este motor (tiempo ordinario/extra candidato/faltante) para traducirlas a dinero; comparte las mismas tablas `labor_rules`/`labor_rule_versions`. *(Documento en redacción paralela por otro agente.)*
- [04-DOMAIN-MODEL.md](./04-DOMAIN-MODEL.md) / [05-DATABASE.md](./05-DATABASE.md): esquema autoritativo de las entidades de este módulo.

## Criterios de aceptación

- [ ] El motor nunca ejecuta `UPDATE`/`DELETE` sobre `attendance_events`, verificable a nivel de esquema/servicio.
- [ ] Ante la ausencia de una `labor_rule_version` vigente para la fecha y el `rule_type` requeridos, el cálculo falla con un error explícito, nunca con un valor asumido en silencio.
- [ ] El ejemplo numérico documentado (turno 06:00→14:00, entrada 06:07, salida 14:23) es reproducible exactamente con el resultado descrito (+16 minutos de diferencia, no asumidos como hora extra).
- [ ] Un recálculo disparado por un ajuste de asistencia aprobado regenera por completo el `attendance_record` afectado y, si el periodo de nómina ya está `CLOSED`, no modifica `payroll_entries` directamente.
- [ ] Un turno que cruza medianoche produce un único `attendance_record` atribuido a la fecha de inicio del turno, no dos registros divididos por fecha de calendario.
- [ ] Ningún porcentaje de recargo ni tasa monetaria aparece en la lógica ni en la salida de este motor; sus salidas son exclusivamente magnitudes de tiempo.
