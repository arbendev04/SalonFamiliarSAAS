# 22-DEPLOYMENT.md — Deployment

## Objetivo

Definir los entornos del sistema, la convención de configuración y migraciones por entorno, el pipeline conceptual de CI/CD, el procedimiento de rollback ante un deploy fallido, y los criterios que garantizan que un deploy sea reproducible y una migración de base de datos en producción sea segura.

## Alcance

Incluye: la definición de los entornos de desarrollo, staging y producción; las reglas de configuración por entorno (variables de entorno, prohibición de secretos en el repositorio); la convención de migraciones de base de datos con backup previo obligatorio; el objetivo de deploy con el menor downtime posible; el pipeline conceptual de CI/CD (build → test → deploy); el procedimiento de rollback; los casos de migración en producción y hotfix urgente; y los criterios de aceptación de un deploy reproducible con rollback y backup probados.

No incluye: la estrategia numérica exacta de backups y recuperación ante desastres (RPO/RTO) (**PENDING DECISION** — Laravel Cloud gestiona backups de la base de datos administrada, pero los valores exactos de RPO/RTO del SLA quedan por confirmar); el detalle de cifrado y gestión general de secretos, cubierto en [20-SECURITY.md](./20-SECURITY.md).

**Decisión tomada** (ver ADR-021 en [23-DECISIONS.md](./23-DECISIONS.md)): el proveedor de hosting/cloud es **Laravel Cloud**, plan **Starter** ($5/mes + $5 de crédito de uso incluido), consistente con el stack backend elegido (PHP/Laravel, ver [03-ARCHITECTURE.md](./03-ARCHITECTURE.md)).

## Conceptos

- **Desarrollo (dev)**: entorno de trabajo del equipo/agentes, con datos de prueba, donde se valida que el código arranca y las migraciones aplican limpiamente sobre una base de datos vacía (ver Fase 1 en [24-ROADMAP.md](./24-ROADMAP.md)).
- **Staging**: entorno lo más parecido posible a producción (misma versión de PostgreSQL, misma configuración estructural), usado para validar un release completo —incluyendo migraciones— antes de exponerlo a datos reales de empresas clientes.
- **Producción (prod)**: entorno con datos reales de las empresas tenant; cualquier cambio aquí sigue el pipeline completo y las reglas de backup previo definidas en este documento; nunca se prueba directamente en producción.
- **Laravel Cloud**: plataforma oficial de hosting elegida para los tres entornos. Provee compute Flex con *scale-to-zero* (la app no consume recursos cuando nadie la usa y despierta en menos de 500ms), Postgres serverless administrado, colas administradas, scheduler administrado, CDN y protección DDoS incluidos, y límites de gasto configurables (el compute se pausa automáticamente si se alcanza el techo definido).

## Entidades

Este archivo no define entidades de dominio ni tablas nuevas (ver [04-DOMAIN-MODEL.md](./04-DOMAIN-MODEL.md) y [05-DATABASE.md](./05-DATABASE.md)). Define los siguientes artefactos conceptuales de despliegue:

| Artefacto | Propósito |
|---|---|
| Entorno | Instancia aislada del sistema (dev/staging/prod) con su propia configuración |
| Pipeline run | Ejecución concreta del pipeline de CI/CD para un commit o release dado |
| Backup / snapshot | Copia de respaldo de la base de datos tomada antes de una migración o de forma programada |
| Release | Versión desplegada del sistema, trazable a un commit específico |
| Punto de rollback | Estado (código + esquema de datos) al que se puede volver si un deploy falla |

## Reglas

### Configuración por entorno

- Toda configuración que varía entre entornos (credenciales, URLs de servicios externos, flags de features) se maneja mediante **variables de entorno**, nunca hardcodeada en el código fuente.
- **Nunca se commitean secretos al repositorio** (claves de API, credenciales de base de datos, tokens de proveedores externos): ver la política completa de gestión de secretos en [20-SECURITY.md](./20-SECURITY.md).
- La gestión de secretos usa el mecanismo de **variables de entorno cifradas de Laravel Cloud** (por entorno: dev/staging/prod), sin depender de un vault externo adicional para la v1. Ver [20-SECURITY.md](./20-SECURITY.md) para la política general.

### Migraciones de base de datos

- Toda migración de base de datos, en cualquier entorno con datos que importe preservar (staging y producción como mínimo), se ejecuta **siempre precedida de un backup** de la base de datos. Ninguna migración se considera válida para producción si no hay un backup verificable inmediatamente anterior.
- Las migraciones siguen la convención definida en [05-DATABASE.md](./05-DATABASE.md) (una migración por cambio de esquema, nunca editar una migración ya aplicada en un entorno compartido).

### Deploy con el menor downtime posible

- El deploy debe buscar minimizar el tiempo de indisponibilidad del sistema para los usuarios finales. Laravel Cloud publica que el deploy de un release es cuestión de segundos y que el compute despierta en menos de 500ms tras estar en scale-to-zero, pero el mecanismo exacto de corte de tráfico entre la versión vieja y la nueva (si es atómico, gradual, o requiere configuración explícita) no está confirmado en detalle — **PENDING DECISION** menor a verificar contra la documentación operativa de Laravel Cloud al llegar a la Fase 15.

## Flujos

### Pipeline de CI/CD conceptual

1. **Build**: se compila/empaqueta el sistema a partir del commit correspondiente.
2. **Test**: se ejecuta la suite completa descrita en [21-TESTING.md](./21-TESTING.md) (lint, unitarios, integración, verificación de cobertura); si algo falla o la cobertura cae por debajo del umbral, el pipeline se detiene antes de llegar a deploy.
3. **Deploy**: solo si el paso anterior es exitoso, se despliega al entorno correspondiente (staging primero, producción después de validación en staging).

### Procedimiento de rollback ante un deploy fallido

1. Se detecta que el deploy recién realizado presenta un fallo (error en smoke test post-deploy, error crítico reportado).
2. El rollback se dispara **automáticamente** (ADR-040): se revierte el código a la versión (release) previa conocida como estable, sin esperar confirmación manual de un operador.
3. Si el deploy fallido incluyó una migración de base de datos, la reversión del esquema se resuelve contra el backup tomado antes de esa migración (ver regla de backup obligatorio); revertir código sin revertir un esquema incompatible puede dejar el sistema en un estado inconsistente, por lo que ambos pasos deben coordinarse.
4. Se registra el incidente (ver plan de respuesta a incidentes en [20-SECURITY.md](./20-SECURITY.md)).

## Casos normales

- **Migración de base de datos en producción**: se toma un backup, se aplica la migración ya validada previamente en staging, se verifica el resultado, y se marca el deploy como exitoso. Este es el camino esperado para cualquier cambio de esquema.

## Casos especiales

- **Hotfix urgente fuera del ciclo normal**: una corrección crítica necesita desplegarse sin esperar el ciclo de release habitual. Aun en este caso, no se omiten los pasos no negociables: el backup previo a cualquier migración sigue siendo obligatorio, y el pipeline de test de [21-TESTING.md](./21-TESTING.md) sigue corriendo; el alcance exacto de ese pipeline para un hotfix urgente (suite completa vs. subconjunto crítico) es una decisión operativa que el brief no cierra.

## Errores

- **Fallo de deploy**: **RESUELTO** (ADR-040 en [23-DECISIONS.md](./23-DECISIONS.md)) — el rollback se dispara **automáticamente** al detectar el fallo (smoke test post-deploy fallido o error crítico reportado), sin esperar confirmación manual de un operador, minimizando el tiempo de indisponibilidad.

## Seguridad

- **Gestión de secretos en el pipeline de CI/CD**: ningún secreto (credenciales de base de datos, claves de proveedores externos, tokens) se expone en logs de build ni se almacena en el repositorio; se inyecta en tiempo de ejecución desde las variables de entorno cifradas de Laravel Cloud (ADR-021, ver [20-SECURITY.md](./20-SECURITY.md) para la política general).
- **Backup obligatorio antes de cualquier migración**: además de una regla de disponibilidad de datos, es un control de seguridad ante corrupción o pérdida de datos irreversible; se trata como no negociable para cualquier migración en staging o producción.

## Dependencias

- [20-SECURITY.md](./20-SECURITY.md): política de secretos, cifrado y plan de respuesta a incidentes.
- [05-DATABASE.md](./05-DATABASE.md): convención de migraciones de base de datos.
- [21-TESTING.md](./21-TESTING.md): la suite de tests que el pipeline de CI/CD ejecuta antes de cualquier deploy.
- [03-ARCHITECTURE.md](./03-ARCHITECTURE.md): stack tecnológico del que depende la implementación concreta del pipeline (PHP/Laravel + Inertia.js/Vue).
- [24-ROADMAP.md](./24-ROADMAP.md): Fase 15 (Deployment) depende de que las Fases 1 a 14 estén completas.

## Criterios de aceptación

- El deploy es reproducible: el mismo commit, desplegado dos veces, produce el mismo resultado.
- El rollback ha sido probado al menos una vez antes de considerarse un procedimiento válido para producción.
- Un backup ha sido restaurado exitosamente en un ensayo (drill), verificando que el proceso de recuperación funciona antes de necesitarlo en un incidente real.
- El pipeline bloquea el deploy a producción si la etapa de test de [21-TESTING.md](./21-TESTING.md) falla o la cobertura cae por debajo del umbral definido allí.

**RESUELTO**: Proveedor de infraestructura/hosting/cloud — **Laravel Cloud**, plan Starter. Ver ADR-021 en [23-DECISIONS.md](./23-DECISIONS.md).

**PENDING DECISION**: Estrategia concreta de backups y recuperación ante desastres (RPO/RTO exactos) — Laravel Cloud gestiona backups de su Postgres administrado, pero los valores concretos de RPO/RTO de su SLA no se han confirmado todavía contra la documentación oficial del proveedor.

**RESUELTO**: El rollback ante un deploy fallido es **automático** (ver ADR-040 en [23-DECISIONS.md](./23-DECISIONS.md)).
