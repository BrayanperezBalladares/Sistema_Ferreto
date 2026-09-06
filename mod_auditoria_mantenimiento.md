# MÓDULO DE AUDITORÍA Y MANTENIMIENTO DEL SISTEMA
*Documento de Requerimientos y Diseño Técnico (Especificación de Software)*
**Código del Módulo: MOD_MANTENIMIENTO**  
**Versión: 1.0**  
**Proyecto: Sistema de Gestión Integral "Ferreterías El Constructor"**

---

## 1. Introducción y Contexto del Módulo
El presente documento describe detalladamente la especificación técnica para el **Módulo de Auditoría y Mantenimiento del Sistema** de **Ferreterías El Constructor**.

La manipulación fraudulenta de existencias en bodegas remotas, las pérdidas de base de datos debidas a fallas críticas del servidor físico de sucursal, y la degradación sistemática de la velocidad de consulta debido a la falta de mantenimiento de índices representan riesgos severos para la cadena. Este módulo establece un marco transversal e inalterable de gobernanza de datos para mitigar estos riesgos, implementando un registro de auditoría estricto (bitácora de logs con payloads JSON), automatización de tareas periódicas de respaldo continuo (backups) y optimización automatizada de índices de base de datos.

Implementado bajo la pila ligera **"No-Build"**:
*   **Backend:** PHP Procedimental con acceso e integridad de datos controlado a través de sentencias **PDO** nativas con modo de error por excepción para evitar inyecciones de código.
*   **Frontend (Interactividad):** **HTMX** (para dashboards interactivos del estado del servidor) y estilos visuales limpios con **Bulma CSS**.
*   **Bases de Datos:** MariaDB y Microsoft SQL Server.

---

## 2. Requerimientos Funcionales
Los requerimientos funcionales modelan de forma técnica las herramientas de gobierno y consistencia de datos:

| Código | Requerimiento Funcional | Descripción y Reglas de Negocio en el Sistema |
| :--- | :--- | :--- |
| **RF-01** | Bitácora Inmutable de Auditoría Transaccional | Registro obligatorio y continuo de toda acción sensible en el sistema (ajustes manuales de inventario, cambio de precios de productos, borrados lógicos de cuentas de usuarios, pedidos procesados). Cada log debe contener timestamp exacto, identidad, tabla afectada, ID del registro, acción ejecutada (INSERT, UPDATE) y payload JSON con el estado anterior y el nuevo de los datos. |
| **RF-02** | Plan Automatizado de Respaldos de Servidor | Sistema programado de tareas que ejecuta y comprueba copias de seguridad continuas de la base de datos (respaldos incrementales cada hora, respaldos completos de base de datos diarios) almacenados local y remotamente de forma redundante. |
| **RF-03** | Monitoreo de Rendimiento e Inconsistencias | Tareas automáticas en segundo plano (*background jobs*) encargadas de detectar latencias altas de consultas de cajas en POS, fragmentación de índices de base de datos, o discrepancias lógicas de stock consolidado. |

---

## 3. Requerimientos No Funcionales
Los requerimientos no funcionales definen los atributos de calidad, estándares técnicos de seguridad e integridad del servidor:

| Código | Requisitos No Funcionales | Métricas, Estándares e Integración Técnica |
| :--- | :--- | :--- |
| **RNF-01** | No-Repudiabilidad Inalterable de Bitácoras | El almacenamiento de logs de auditoría se rige bajo la política de **solo inserción** (*append-only*). Queda estrictamente deshabilitada cualquier sentencia de UPDATE o DELETE que intente modificar registros de logs ya confirmados. |
| **RNF-02** | Objetivos de Respaldo Exigentes | El sistema debe cumplir un Punto Objetivo de Recuperación (**RPO ≤ 1 hora**) y un Tiempo Objetivo de Recuperación (**RTO ≤ 2 horas**) de desastre completo ante incidentes. |
| **RNF-03** | Encriptación en Tránsito y Reposo (TLS 1.3) | Todos los datos de auditoría sensibles (como credenciales de base de datos, payloads de logs que involucren identificaciones de clientes) deben almacenarse encriptados con algoritmo AES-256 en reposo y transmitirse de forma cifrada mediante protocolo TLS 1.3. |

---

## 4. Bloques Funcionales (Complejidad de Desarrollo)
El cronograma de desarrollo del módulo de auditoría técnica se agrupa de la siguiente manera:

*   **Lógica de Inyección de Logs Transaccionales (Complejidad: Media):** Helper transversal de PHP que intercepta modificaciones de base de datos PDO e inyecta payloads JSON con el estado de datos de forma asíncrona. (*RF-01, RNF-01*)
*   **Dashboard del Estado del Servidor e Índices (Complejidad: Media):** Pantalla interactiva en Bulma que visualiza latencias del POS, fragmentación de tablas y ejecuta reestructuraciones de índices de forma directa mediante HTMX. (*RF-03, RNF-03*)
*   **Orquestación y Automatización de Respaldos (Complejidad: Alta):** Scripts de servidor (*cronjobs/shell tasks*) programados encargados de encriptar respaldos y subirlos de forma automatizada al host redundante en la nube. (*RF-02, RNF-02*)

---

## 5. Diseño de Base de Datos (Modelo Relacional 3FN)
Esquema de base de datos del log de auditoría técnica inmutable de ferretería:

### Tabla: REGISTRO_ACCION_LOG
Estructura inmutable encargada de guardar pistas del gobierno de datos.
*   `id_log` (BIGINT, PK, AUTO_INCREMENT): Identificador único secuencial de la bitácora de auditoría.
*   `id_usuario` (INT, FK -> USUARIO.id_usuario): Empleado de la ferretería que inicia la acción transaccional.
*   `tabla_afectada` (VARCHAR(100)): Nombre físico de la tabla afectada en la base de datos (ej. `'INVENTARIO_STOCK'`).
*   `id_registro_afectado` (VARCHAR(50)): Identificador único del registro modificado (ID del producto, ID de venta, etc.).
*   `tipo_accion` (VARCHAR(30)): Tipo de operación transaccional (`'INSERT'`, `'UPDATE'`, `'DELETE'`, `'AJUSTE_STOCK'`).
*   `valor_anterior` (TEXT): Payload JSON con el estado de las columnas de base de datos previo a la transacción.
*   `valor_nuevo` (TEXT): Payload JSON con el estado final de las columnas de base de datos tras confirmarse la transacción.
*   `fecha_hora` (DATETIME): Timestamp exacto de inyección de la fila de auditoría.
*   `ip_origen` (VARCHAR(45)): Dirección IP física de la terminal de caja o computadora de oficina emisora.

### 5.1 Consideraciones de Diseño Clave
*   **Inalterabilidad de Bitácora:** Se inhabilitarán formalmente los permisos de actualización (`UPDATE`) y borrado (`DELETE`) sobre la tabla de `REGISTRO_ACCION_LOG` para cualquier usuario operativo o de base de datos (incluyendo administradores del sistema ferretero). Esto se logra mediante políticas de seguridad restrictivas de SQL Server o MariaDB (permisos a nivel de tabla).
*   **Formato JSON Nativo:** El payload guardado en `valor_anterior` y `valor_nuevo` se almacena usando el tipo estructurado JSON nativo de MariaDB y SQL Server, permitiendo consultas e inyecciones rápidas mediante sintaxis de búsqueda directa de base de datos.

---

## 6. Ciclo de Vida del Respaldo de Base de Datos
La automatización de copias de seguridad del servidor progresa en background bajo el siguiente flujo:

```
    [ Planificado ] ──(Trigger por Cronjob)──> [ Ejecutándose ]
                                                    │
                                         (Cifrado y Subida Completa)
                                                    ▼
    [ Expirado ] <──(Política de Retención)── [ Validado / Remoto ]
```

1.  **Planificado:** Tarea automática programada de respaldo diario en segundo plano esperando el trigger de sistema correspondiente de PHP en el servidor de base de datos transaccional.
2.  **Ejecutándose:** Copia de seguridad atómica en curso encapsulando tablas críticas. Se encripta el archivo final generado mediante contraseña simétrica robusta.
3.  **Validado / Remoto:** El respaldo físico comprimido se transfiere y almacena de forma redundante en un hosting en la nube o disco de respaldo remoto seguro de la empresa. Se valida que el archivo de respaldo no tenga errores de integridad.
4.  **Expirado:** Copia de seguridad vieja que supera el límite de la política de retención y almacenamiento físico de la ferretería (ej. respaldos históricos de más de 6 meses) y es descartada automáticamente para recuperar almacenamiento.

---

## 7. Arquitectura del Módulo (Bulma + HTMX)
Estructura de archivos físicos de software del módulo de auditoría técnica:

1.  `mantenimiento_v.php` (Vista Partial): Interfaz estructurada en Bulma CSS que detalla latencias de base de datos, backups completados e incidencias de rendimiento. Integra los atributos `hx-get="mantenimiento_c.php?action=refresh_health"` y `hx-trigger="every 10s"` para refrescar el estado del servidor asíncronamente con HTMX.
2.  `mantenimiento_c.php` (Controlador): Enruta peticiones de mantenimiento e inyecta alertas HTML parciales en el DOM cuando se detectan índices de base de datos desestructurados o fragmentados de forma excesiva.
3.  `mantenimiento_l.php` (Lógica de Negocio): Helper de lógica pura encargado de invocar rutinas de optimización de índices SQL, verificar el tamaño de los respaldos comprimidos y validar la integridad criptográfica de payloads JSON.
4.  `mantenimiento_d.php` (Datos / Acceso SQL): Ejecución de consultasPDO directas sobre rendimiento: `INSERT INTO REGISTRO_ACCION_LOG (id_usuario, tabla_afectada, tipo_accion, valor_anterior, valor_nuevo, fecha_hora, ip_origen) VALUES (...)`.
5.  `mantenimiento_m.php` (Mantenimiento): Scripts de mantenimiento (*cronjob*) programados para ejecutar y comprimir respaldos y optimizar bases de datos de forma automática en background.

---

## 8. Puntos Pendientes de Definición
Antes del inicio formal de la construcción la próxima semana, se deben definir con la gerencia los siguientes aspectos:
1.  **Almacenamiento Geográfico de Respaldos:** Definir si los backups diarios se guardarán en un servicio de almacenamiento en la nube (ej. Amazon S3, Azure Blob o Google Cloud Storage) o si se requiere un servidor físico NAS local redundante de la ferretería.
2.  **Políticas de Retención de Históricos:** Confirmar el tiempo mínimo obligatorio que deben permanecer en base de datos los logs históricos en la tabla `REGISTRO_ACCION_LOG` antes de ser archivados o purgados para liberar almacenamiento.
3.  **Alertas de IPs Sospechosas:** Establecer si el sistema debe bloquear automáticamente la cuenta operativa de un usuario si inicia sesión desde una dirección IP física sospechosa o no autorizada previamente por administración.
