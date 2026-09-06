## 1. Professor Technical Baseline

La guía técnica autoritativa del profesor fija el enfoque de interfaz y comunicación: **PHP**, **HTMX**, **Bulma**, fragmentos HTML renderizados en servidor, HTML-over-the-wire, JavaScript personalizado mínimo y ausencia de paso de compilación frontend. El flujo esperado es PHP con acceso a datos y renderizado de HTML; HTMX solicita dinámicamente y actualiza partes del DOM; Bulma aporta estilos.

**Evidencia directa disponible.** La fuente autoritativa de esta exploración es el resumen suministrado por el usuario. En el espacio de trabajo no se localizó presentación, archivo comprimido, README, código PHP/HTML/JS/CSS, configuración de aplicación ni prueba de concepto CRUD/mini-framework: el inventario contiene documentación Markdown y OpenSpec. Por tanto, no es posible atribuir a un archivo local del profesor detalles adicionales ni verificar su layout. No se utilizó CodeGraph: no hay implementación sustancial ni índice disponible, por lo que no aportaría evidencia estructural adicional. Como evidencia contextual no autoritativa, el SRS describe el mismo patrón en `especificacion_requerimientos_sistema_ferretero.md` §1, líneas 21–26 y §7, líneas 137–144.

Este baseline técnico no modifica ni se clasifica como requisito funcional R1–R10. La referencia CRUD, si se incorpora al espacio de trabajo posteriormente, será evidencia de factibilidad y punto de partida, no una arquitectura de producción obligatoria.

## 2. What Is Fixed

| Item | Classification | Evidence | Consequence | Decision needed now? |
|---|---|---|---|---|
| PHP | FIXED BY PROFESSOR BASELINE | Resumen autoritativo del profesor. | El backend y el renderizado se implementan en PHP. | No |
| HTMX | FIXED BY PROFESSOR BASELINE | Resumen autoritativo del profesor. | Las interacciones dinámicas se expresan como solicitudes HTMX y actualizaciones parciales. | No |
| Bulma | FIXED BY PROFESSOR BASELINE | Resumen autoritativo del profesor. | La interfaz usa Bulma como base de estilos. | No |
| Server-rendered fragments | FIXED BY PROFESSOR BASELINE | Resumen autoritativo del profesor. | Los endpoints interactivos devuelven HTML, no se define una API JSON como patrón principal de UI. | No |
| HTML-over-the-wire | FIXED BY PROFESSOR BASELINE | Resumen autoritativo del profesor. | La UI conserva el servidor como fuente de renderizado y estado de presentación. | No |
| Minimal custom JavaScript | FIXED BY PROFESSOR BASELINE | Resumen autoritativo del profesor. | JavaScript propio solo cubre casos que HTMX y HTML no resuelvan razonablemente. | No |
| No frontend build step | FIXED BY PROFESSOR BASELINE | Resumen autoritativo del profesor. | No se incorpora bundler ni compilación frontend como requisito de la base. | No |
| Routing | STRONGLY IMPLIED | HTMX requiere destinos HTTP; el SRS contextual ejemplifica controladores que reciben acciones y devuelven parciales (§7, líneas 139–144). | Debe existir una convención de rutas/endpoints, pero no se prescribe router, formato de URL ni despacho. | Sí |
| Project structure | STILL OPEN | El profesor aclara que el layout del CRUD no es arquitectura de producción obligatoria; no hay POC local para inspeccionar. | Debe elegirse una estructura mantenible para puntos de entrada, plantillas, dominio, infraestructura y recursos públicos. | Sí |
| Dependency management | STILL OPEN | El baseline no prescribe CDN, archivos versionados, administrador de paquetes ni política de actualización. | Debe definirse cómo se obtienen, fijan y auditan HTMX, Bulma y futuras dependencias PHP. | Sí |
| Composer | NOT REQUIRED | El baseline solo fija PHP y no exige gestor de paquetes. | Puede adoptarse si aporta valor, pero no es condición para iniciar ni una inferencia válida. | No |
| PDO | STILL OPEN | El resumen del profesor exige acceso PHP a datos, no una API concreta. PDO aparece solo en documentación previa de equipo, no en la guía autoritativa. | Debe seleccionarse la abstracción de acceso a datos y su política de errores/transacciones. | Sí |
| Database engine | STILL OPEN | El baseline no selecciona motor. MariaDB/SQL Server figura en documentación previa, sin autoridad técnica nueva del profesor. | Debe elegirse un motor objetivo antes de fijar SQL, contenedores, migraciones y pruebas de integración. | Sí |
| Authentication/session strategy | STILL OPEN | El baseline no define credenciales, cookies, sesiones ni tokens. R6 fija control y trazabilidad funcional, no su mecanismo. | La estrategia se decide al fundar seguridad transversal o antes del primer flujo protegido. | No |
| Migrations | STRONGLY IMPLIED | PHP con acceso a datos y los dominios R1–R10 requieren una base reproducible; el baseline no determina herramienta ni formato. | La fundación necesita una estrategia versionada de esquema y datos iniciales, aunque puede ser SQL propio u otra alternativa. | Sí |
| Testing | STILL OPEN | `openspec/testing-capabilities.md`, líneas 6–38, confirma ausencia de proyecto y herramientas; el baseline no impone framework. | Debe establecerse un mínimo verificable para PHP, integración de datos y respuestas HTML/HTMX. | Sí |
| Logging | STRONGLY IMPLIED | Un backend PHP que atiende solicitudes necesita diagnóstico operativo; R8 exige monitoreo, pero no formato ni herramienta. | La fundación debe definir un punto de registro y niveles mínimos, sin fijar aún plataforma de observabilidad. | Sí |
| Configuration | STRONGLY IMPLIED | Un backend PHP y base de datos requieren parámetros por entorno; el baseline no prescribe archivos, variables ni secretos. | La fundación debe separar configuración y secretos del código. | Sí |
| Data Warehouse technology | STILL OPEN | R7 exige un Data Warehouse funcional; el profesor no selecciona tecnología. | Se difiere hasta diseñar la capacidad analítica y el modelo OLTP estabilizado. | No |
| ETL technology | STILL OPEN | R7 exige ETL funcional; el profesor no prescribe lenguaje, orquestador ni frecuencia. | Se difiere hasta definir contratos de datos y necesidades analíticas. | No |
| Deployment | STILL OPEN | La ausencia de build frontend reduce pasos, pero no fija servidor web, hosting, contenedores, CI/CD ni operación. | Se puede posponer la plataforma concreta, manteniendo compatibilidad con despliegue PHP y activos estáticos. | No |

Las únicas decisiones fijadas son las de patrón técnico de interfaz y comunicación. La coincidencia de los documentos previos con ese patrón respalda su factibilidad contextual, pero no convierte PDO, motores, JWT, cinco archivos por módulo, modelo dimensional ni portabilidad dual en decisiones del profesor.

## 3. What Remains Open

Siguen abiertas la estructura de producción, convención de rutas, distribución de dependencias, interfaz de datos/PDO, motor de base de datos, migraciones, configuración, pruebas y registro operativo. También quedan abiertas la autenticación/sesiones, Data Warehouse, ETL y despliegue; estas últimas no bloquean por sí mismas la base si se mantienen fuera de su alcance inicial.

No puede inferirse con seguridad Composer, un framework PHP, MVC, un router externo, MariaDB o SQL Server, JWT o sesiones, un formato de configuración, una herramienta de migraciones, un framework de pruebas, ni una plataforma de despliegue. Las menciones de estos elementos en documentos de equipo son evidencia contextual previa, no sustituyen la nueva autoridad técnica.

## 4. What the CRUD Example Proves

No hay un CRUD/mini-framework local disponible para inspección; por ello esta exploración no puede afirmar comportamientos concretos de ese ejemplo. Según la instrucción autoritativa del usuario, su existencia prueba únicamente la **factibilidad** del flujo PHP → fragmento HTML → actualización HTMX con estilos Bulma y sirve como referencia inicial.

Cuando se disponga de sus archivos, podrá verificarse qué rutas, plantillas, consultas, gestión de activos y controles de errores implementa realmente. Esa verificación no debe alterar el carácter fijo del baseline ni convertir detalles del ejemplo en obligaciones de producción.

## 5. What the CRUD Example Does NOT Prove

Aunque se aporte posteriormente, un CRUD no prueba arquitectura de módulos, seguridad de autenticación/autorización, concurrencia de inventario, transacciones, migraciones, pruebas, registro operativo, gestión de secretos, cumplimiento normativo, rendimiento, Data Warehouse/ETL ni despliegue. Tampoco prueba que sus nombres de carpetas, archivos, router, acceso a datos o dependencias sean adecuados u obligatorios para Sistema_Ferreto.

En particular, un ejemplo de CRUD no resuelve los dominios R1–R10 ni las decisiones de seguridad, datos y operación que una fundación de producción debe explicitar.

## 6. Constraints for project-foundation

- La fundación debe ser PHP server-rendered y servir HTML completo y fragmentos parciales para HTMX.
- La interactividad debe priorizar atributos HTMX y HTML; JavaScript propio debe justificarse por excepción.
- Bulma debe ser la base de estilos y no debe introducirse un paso de compilación frontend como requisito.
- No se debe imponer una API JSON de frontend ni una SPA como arquitectura primaria.
- No se debe copiar ni declarar obligatorio el layout de un CRUD de referencia.
- La fundación debe decidir de forma explícita rutas, activos/dependencias, configuración, motor/acceso a datos, evolución del esquema, pruebas y registro mínimo.
- R1–R10 siguen siendo la línea funcional separada; esta decisión técnica no añade requisitos de negocio.

## 7. Remaining architecture decisions

**Bloqueadores mínimos para una fundación segura:**

1. Convención de estructura y routing para páginas y fragmentos HTMX.
2. Motor de datos objetivo y interfaz PHP de acceso; PDO no puede asumirse solo por repetición documental.
3. Estrategia reproducible de esquema/migraciones y datos de arranque.
4. Configuración por entorno y manejo de secretos.
5. Entrega/versionado de HTMX y Bulma, sin convertir Composer en obligación.
6. Base mínima de pruebas y de registro operativo.

**Decisiones que pueden diferirse:** autenticación/sesiones hasta el primer flujo protegido; tecnología de Data Warehouse y ETL hasta estabilizar contratos OLTP y alcance analítico; plataforma de despliegue hasta que exista un entorno objetivo. Estas decisiones posteriores no deben bloquear la fundación si sus límites permanecen explícitos.

## 8. READY FOR project-foundation: NO

El baseline del profesor ya permite fijar la dirección de interfaz y evita rediscutir la pila frontend. Sin embargo, no es seguro iniciar una fundación de proyecto hasta resolver los seis bloqueadores mínimos anteriores, porque determinan cómo se ejecutará, persistirá, configurará y verificará la primera base PHP. No es necesario resolver todavía todas las decisiones posteriores de autenticación, ETL/DW o despliegue para alcanzar esa preparación.
