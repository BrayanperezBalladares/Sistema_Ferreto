# ESPECIFICACIÓN DE REQUERIMIENTOS DE SOFTWARE (SRS)
## SISTEMA DE GESTIÓN FERRETERO "FERRETERÍAS EL CONSTRUCTOR"
**Versión:** 1.0  
**Estándar de Diseño:** Bulma + HTMX + PHP  
**Estatus:** CONFIDENCIAL — PARA USO EXCLUSIVO DE DESARROLLO

---

## 1. INTRODUCCIÓN Y CONTEXTO DEL PROYECTO

El presente documento detalla la Especificación de Requerimientos de Software (SRS) para el desarrollo del sistema de gestión comercial e inventarios de la cadena nacional **Ferreterías El Constructor**. Actualmente, la empresa opera de manera descentralizada, lo que genera desabastecimiento en productos clave, serias dificultades para coordinar las ventas intersucursal y pérdidas por productos obsoletos en almacenes. 

Para mitigar estas deficiencias, el nuevo sistema plantea una arquitectura integral que combina la operativa transaccional diaria (OLTP) de múltiples sucursales con capacidades analíticas avanzadas de Inteligencia de Negocios (OLAP/BI), facilitando la toma de decisiones y optimizando de extremo a extremo la cadena de suministro.

### Beneficios Esperados del Sistema
*   **Mejora en la Eficiencia Operativa:** Automatización de procesos repetitivos y reducción directa de los costos operativos globales.
*   **Control Total de Inventarios:** Visibilidad centralizada y en tiempo real del stock y las ventas en todas las tiendas del país.
*   **Optimización de Abastecimiento:** Mejora sustancial en la relación y coordinación de pedidos automáticos con proveedores.
*   **Fidelización de Clientes:** Servicio más ágil, preciso y reducción absoluta de quiebres de stock en sala de venta.

### Estructura de Arquitectura y Pila Tecnológica (Pila "Sin Compilación")
Con base en el patrón de agilidad definido para el desarrollo del proyecto, se establece el uso de una pila de desarrollo moderna, ligera y robusta que prescinde de un paso de compilación de JavaScript pesado, reduciendo tiempos de despliegue y costo de mantenimiento:
*   **Backend PHP (PDO y Excepciones):** Lógica estructurada en el servidor, utilizando la API PDO de conexión para garantizar transacciones inmutables y un control de errores mediante bloques try-catch.
*   **HTMX en Capa de Interactividad:** Manejo de peticiones AJAX asíncronas de forma declarativa directamente en HTML. El servidor devuelve fragmentos renderizados (HTML parciales) que HTMX inserta directamente en el DOM, emulando una SPA.
*   **Estilos con Bulma CSS:** Framework puramente basado en CSS (sin dependencias JS) que asegura una maquetación responsiva, rápida, móvil-primero y estéticamente moderna.
*   **Bases de Datos Robustas:** Flexibilidad en motores SQL relacionales compatibles, soportando despliegues sobre MariaDB o Microsoft SQL Server.

---

## 2. REQUERIMIENTOS FUNCIONALES (RF)

Los requerimientos funcionales modelan de forma técnica las transacciones, operaciones y servicios que el sistema del negocio debe ejecutar de manera mandatoria para asegurar el control transaccional y analítico.

| Código | Requerimiento Funcional | Descripción y Reglas de Negocio en el Sistema |
| :--- | :--- | :--- |
| **RF-01** | Gestión del Catálogo de Productos | Registro, edición y categorización de productos a nivel central, incluyendo SKU, código de barras, precios base, cantidades mínimas de inventario y ubicación física detallada (pasillo, estante, nivel) por sucursal. |
| **RF-02** | Sincronización en POS en Tiempo Real | Descuento automático de existencias físicas en la tabla de inventario de la sucursal tan pronto como se consolide una venta en la caja del POS, garantizando consistencia inalterable del stock. |
| **RF-03** | Motor de Recomendación de Productos | Durante el cobro en caja, el POS desplegará de forma proactiva recomendaciones de accesorios, repuestos compatibles o materiales complementarios según reglas técnicas de relación de productos e historial de ventas. |
| **RF-04** | Reabastecimiento Automático por Reorden | Generación automática en estado 'Borrador' de pedidos de compra dirigidos al proveedor histórico de mejor calificación cuando las existencias actuales de un almacén desciendan por debajo de su punto de reorden. |
| **RF-05** | Módulo de Términos de Proveedores | Bitácora de negociación que almacena los precios de compra pactados, tiempos promedio de entrega (lead time) y tasa de cumplimiento (fill rate) de cada proveedor para optimizar el direccionamiento de pedidos. |
| **RF-06** | Integración con Escáner de Barras | Integración de hardware para lectura de códigos de barras en los procesos operativos clave de bodega: recepción de compras, transferencias, ajustes, inventarios rotativos y operaciones de caja del POS. |
| **RF-07** | Seguimiento de Lotes y Obsolescencia | Control estricto de ingresos por números de lote de productos, registrando fechas de ingreso y caducidad para emitir alertas automatizadas previas a la inactividad, merma o envejecimiento del inventario. |
| **RF-08** | Transferencias de Stock entre Sucursales | Regulación de envíos logísticos entre tiendas del país mediante un ciclo inmutable de estados de dos fases (Solicitada, En Tránsito, Recibida, Cancelada) con auditoría obligatoria de firmas de salida y entrada. |
| **RF-09** | Bitácora de Logs y Auditoría Inmutable | Registro automatizado e inalterable de cada evento del sistema, guardando marcas de tiempo, IP, ID del usuario responsable, operación SQL (INSERT/UPDATE), valor anterior y valor nuevo en formato JSON estructurado. |
| **RF-10** | Procesos ETL para Capa Analítica | Rutinas automatizadas de Extracción, Transformación y Carga (ETL) periódicas para transferir la base relacional operativa transaccional hacia el esquema dimensional (estrella/copo de nieve) del Data Warehouse. |
| **RF-11** | Visualización Analítica y Dashboards de BI | Tableros interactivos de Inteligencia de Negocios integrados que muestren proyecciones de ventas, tendencias de reabastecimiento, márgenes operativos y métricas críticas de rotación de stock por almacén. |
| **RF-12** | Trazabilidad de Documentación Normativa | Gestión, archivo digital y validación fiscal de guías de despacho/remisión asociadas a traslados entre sucursales y emisión formal de facturas electrónicas de venta conforme a la normativa vigente. |

---

## 3. REQUERIMIENTOS NO FUNCIONALES (RNF)

Los requerimientos no funcionales estipulan las restricciones técnicas, niveles de servicio (SLA), mecanismos de seguridad informática y estándares de calidad indispensables para la operatividad del sistema.

| Código | Requisitos No Funcionales | Métricas, Estándares e Integración Técnica |
| :--- | :--- | :--- |
| **RNF-01** | Tiempo de Respuesta (POS) | Las operaciones críticas del punto de venta, incluyendo la validación y descuento transaccional de stock, deben completarse en un tiempo de respuesta de percentil 95 (p95) inferior a 500 ms. |
| **RNF-02** | Alta Disponibilidad (SLA) | El motor transaccional operativo (OLTP) debe asegurar una disponibilidad anual del 99.9%, traduciéndose en un límite máximo de tolerancia a interrupciones no planificadas de 8.76 horas anuales. |
| **RNF-03** | Integridad Referencial (ACID) | Toda manipulación de datos sobre el stock, finanzas o logística intersucursal debe encapsularse bajo transacciones de base de datos con estricta conformidad ACID para bloquear inconsistencias físicas. |
| **RNF-04** | Tolerancia a Fallos de Conexión | Frente a una caída de la red WAN, el POS mantendrá un búfer transaccional offline-first en local que sincronizará de forma automática, asíncrona e idempotente una vez que la conectividad retorne. |
| **RNF-05** | Autenticación Segura (RBAC) | Acceso de usuarios condicionado a control de acceso por roles (RBAC). Las contraseñas deben procesarse con algoritmo de hash seguro bcrypt, y las sesiones se validarán por tokens criptográficos JWT. |
| **RNF-06** | Cifrado de Información | Todas las conexiones de red utilizarán obligatoriamente cifrado en tránsito HTTPS/TLS 1.3. Los datos corporativos sensibles (claves, datos fiscales) se encriptarán en reposo mediante el estándar AES-256. |
| **RNF-07** | Aislamiento de Cargas (BI/POS) | El Data Warehouse y los procesos analíticos de BI (OLAP) operarán sobre un hardware de datos aislado de la base transaccional de ventas (OLTP), impidiendo retrasos por consultas masivas de reportes en cajas. |
| **RNF-08** | Políticas de Backups (RPO/RTO) | Copias de respaldo incrementales automáticas cada hora y completas diariamente de la base de datos central. El sistema debe garantizar un RPO <= 1 hora y un tiempo máximo de restauración RTO <= 2 horas. |
| **RNF-09** | Bitácora de Auditoría Inalterable | La tabla de histórico técnico (logs) operará bajo el enfoque exclusivo append-only, denegando por permisos de base de datos cualquier transacción de tipo UPDATE o DELETE sobre los registros de log. |
| **RNF-10** | Accesibilidad Sin Ratón (Teclado) | Las interfaces rápidas para cajeros y personal de almacén en recepción serán 100% operables mediante combinaciones de teclas (hotkeys) y escáneres de código de barras para acelerar el servicio. |

---

## 4. BLOQUES FUNCIONALES DE DESARROLLO (MÓDULOS)

Para efectos de la planificación técnica del proyecto, los requerimientos se organizan de forma lógica en los siguientes módulos de software, indicando su nivel de complejidad estimada:

*   **Módulo de Cuentas y Accesos (Complejidad Baja):** Inicio de sesión seguro, asignación de roles y sucursal. *(Relacionado con: RF-01, RF-09, RNF-05)*
*   **Módulo de Sucursales y Logística (Complejidad Media):** Traslados de stock, guías de despacho en tránsito y recepciones. *(Relacionado con: RF-01, RF-08, RF-12)*
*   **Módulo de Inventarios y Catálogo (Complejidad Alta):** Ficha técnica de productos, lotes, ubicaciones físicas, escáner y alertas. *(Relacionado con: RF-01, RF-06, RF-07, RNF-10)*
*   **Módulo POS y Facturación Electrónica (Complejidad Alta):** Interfaz de caja, cálculo de impuestos, motor de sugerencia offline. *(Relacionado con: RF-02, RF-03, RF-12, RNF-01, RNF-03, RNF-04)*
*   **Módulo de Compras y Proveedores (Complejidad Media-Alta):** Puntos de reorden automáticos y evaluación de lead times. *(Relacionado con: RF-04, RF-05)*
*   **Módulo de Auditoría y Bitácora Transversal (Complejidad Alta):** Inyección inalterable de logs en JSON ante operaciones. *(Relacionado con: RF-09, RNF-09)*
*   **Módulo Analítico e Inteligencia de Negocios (Complejidad Media-Alta):** Procesos ETL, Data Warehouse centralizado y reportes BI. *(Relacionado con: RF-10, RF-11, RNF-07)*

---

## 5. DISEÑO DE BASE DE DATOS (MODELO RELACIONAL 3FN)

Para cumplir con el diseño técnico estructurado, se establece el siguiente esquema relacional de base de datos normalizado en Tercera Forma Normal (3FN), garantizando consistencia inalterable:

| Tabla | Propósito Principal y Atributos Clave | RF Relacionado |
| :--- | :--- | :--- |
| **SUCURSAL** | Define las tiendas físicas de la cadena. Campos: `id_sucursal` (PK), `nombre`, `direccion`, `ciudad`, `estado_activo`. | RF-01, RF-08, RF-12 |
| **ALMACEN** | Ubicación lógica interna (Mostrador, Bodega, Patio, Mermas). Campos: `id_almacen` (PK), `id_sucursal` (FK), `nombre`. | RF-01, RF-08 |
| **UBICACION** | Posición física tridimensional de pasillo, estante y nivel. Campos: `id_ubicacion` (PK), `id_almacen` (FK), `pasillo`, `estante`, `nivel`. | RF-01 |
| **CATEGORIA** | Líneas de producto para jerarquía de ventas. Campos: `id_categoria` (PK), `nombre`, `descripcion`. | RF-01 |
| **PRODUCTO** | Catálogo central de materiales. Campos: `id_producto` (PK), `sku`, `codigo_barras`, `nombre`, `precio_base`, `es_perecedero`. | RF-01, RF-06 |
| **COMPATIBILIDAD_PRODUCTO** | Autorrelación de productos para repuestos o complementarios. Campos: `id_compatibilidad` (PK), `id_producto_origen` (FK), `id_producto_relacionado` (FK), `tipo_relacion`. | RF-03 |
| **INVENTARIO_STOCK** | Existencias en tiempo real por almacén. Campos: `id_stock` (PK), `id_producto` (FK), `id_almacen` (FK), `stock_actual`, `stock_minimo`, `punto_reorden`. | RF-01, RF-02, RF-04 |
| **LOTE_PRODUCTO** | Control de ingresos físicos para productos obsoletos o perecederos. Campos: `id_lote` (PK), `id_producto` (FK), `numero_lote`, `fecha_ingreso`, `fecha_vencimiento`, `cantidad`. | RF-07 |
| **TRANSFERENCIA_SUCURSAL** | Cabecera de traslados físicos de stock intersucursal. Campos: `id_transferencia` (PK), `id_almacen_origen` (FK), `id_almacen_destino` (FK), `estado`, `fecha_envio`, `fecha_recepcion`. | RF-08 |
| **DETALLE_TRANSFERENCIA** | Detalle de cantidades transferidas. Campos: `id_detalle_transferencia` (PK), `id_transferencia` (FK), `id_producto` (FK), `cantidad`. | RF-08 |
| **CLIENTE** | Maestro de clientes y NIT fiscal para facturación. Campos: `id_cliente` (PK), `identificacion_fiscal`, `nombre`, `telefono`, `email`. | RF-02, RF-12 |
| **VENTA** | Cabecera transaccional de venta en cajas. Campos: `id_venta` (PK), `id_sucursal` (FK), `id_cliente` (FK), `id_usuario` (FK), `numero_factura`, `fecha_venta`, `subtotal`, `impuestos`, `total`. | RF-02, RF-12 |
| **DETALLE_VENTA** | Renglones individuales facturados. Campos: `id_detalle_venta` (PK), `id_venta` (FK), `id_producto` (FK), `cantidad`, `subtotal`. | RF-02 |
| **PROVEEDOR** | Datos del proveedor de insumos. Campos: `id_proveedor` (PK), `identificacion_fiscal`, `razon_social`, `contacto`, `telefono`, `email`. | RF-04, RF-05 |
| **PRODUCTO_PROVEEDOR** | Relación de costos acordados y tiempos de entrega. Campos: `id_prod_prov` (PK), `id_producto` (FK), `id_proveedor` (FK), `precio_compra`, `lead_time`. | RF-04, RF-05 |
| **ORDEN_COMPRA** | Cabecera de reabastecimiento generada. Campos: `id_orden_compra` (PK), `id_proveedor` (FK), `id_almacen_destino` (FK), `estado`, `total`. | RF-04 |
| **DETALLE_ORDEN_COMPRA** | Artículos de la compra. Campos: `id_detalle_orden` (PK), `id_orden_compra` (FK), `id_producto` (FK), `cantidad_pedida`, `cantidad_recibida`. | RF-04 |
| **USUARIO** | Credenciales del personal asignado a una sucursal. Campos: `id_usuario` (PK), `id_sucursal` (FK), `username`, `password_hash`, `rol`. | RF-09 |
| **REGISTRO_ACCION_LOG** | Bitácora técnica de auditoría inmutable. Campos: `id_log` (PK), `id_usuario` (FK), `tabla_afectada`, `tipo_accion`, `valor_anterior`, `valor_nuevo` (JSON). | RF-09 |
| **DOCUMENTO_NORMATIVO** | Bitácora de documentos legales asociados. Campos: `id_documento` (PK), `id_sucursal` (FK), `tipo_documento`, `numero_documento`, `ruta_archivo`. | RF-12 |

### 5.1 CONSIDERACIONES CLAVE DE DISEÑO TRANSACCIONAL
*   **Manejo de Concurrencia (RNF-03):** Múltiples cajas de cobro facturando de manera simultánea en una sucursal compitiendo por el mismo inventario físico exigen aislamiento transaccional robusto. Al insertar un registro en `DETALLE_VENTA`, el backend ejecutará un bloqueo pesimista en base de datos (mediante `SELECT ... FOR UPDATE` en la fila correspondiente de `INVENTARIO_STOCK`) para evitar inconsistencias de inventario, descuadres y sobreventas.
*   **Punto de Reorden Automatizado (RF-04):** Un gatillo (Trigger) o proceso cron programado monitoreará continuamente cuando `stock_actual <= punto_reorden` en la tabla `INVENTARIO_STOCK`. El sistema generará de forma automática un borrador inmutable de pedido en `ORDEN_COMPRA` dirigido al proveedor correspondiente registrado con el menor plazo de entrega o menor precio pactado en `PRODUCTO_PROVEEDOR`.
*   **Portabilidad Multi-Motor (MariaDB / SQL Server):** Para garantizar la portabilidad técnica sin depender exclusivamente de un motor, se evitarán los tipos de datos exclusivos como `ENUM` (utilizando restricciones de verificación `CHECK CONSTRAINTS` en campos como 'estado' o 'rol') y se evitarán las comillas invertidas (backticks) en las consultas SQL nativas del código PHP.

---

## 6. CICLOS DE VIDA DE ENTIDADES CLAVE

Se especifican las máquinas de estado y reglas inmutables de transición para los flujos operativos más críticos del sistema logístico:

### A) Flujo de la Orden de Compra a Proveedores (RF-04)
1.  **Borrador:** Orden autogenerada por punto de reorden, editable por el encargado de compras.
2.  **Aprobada:** Pedido validado por gerencia y enviado formalmente al proveedor (bloqueado para modificaciones).
3.  **Recibida Parcial:** Llegada física incompleta de stock. Se actualiza `cantidad_recibida` en el detalle.
4.  **Completada:** Recepción del 100% de la mercancía. Se incrementa automáticamente el inventario físico y se cierra contablemente.
5.  **Cancelada:** Anulación administrativa del pedido antes de la entrega física del stock.

### B) Flujo de Transferencia de Stock entre Sucursales (RF-08)
1.  **Solicitada:** Sucursal destino requiere stock a un almacén de origen. El stock solicitado se reserva en origen (bloqueado).
2.  **En Tránsito:** Origen despacha la mercancía. Se genera la guía de remisión correspondiente obligatoria.
3.  **Recibida:** Destino recibe, inspecciona y acepta físicamente el traslado. Se incrementa stock en destino y se libera la reserva.
4.  **Cancelada:** Solicitud denegada por origen por falta de stock físico o cancelada por destino antes de salir en ruta.

---

## 7. ARQUITECTURA DE MÓDULOS (BULMA + HTMX)

Siguiendo el estándar estructurado del módulo de citas, cada submódulo del sistema ferretero se dividirá en una convención estricta de 5 archivos que delimitan responsabilidades de forma limpia:
*   **Controlador (`_c`):** Captura eventos disparados desde el front-end y responde directamente con fragmentos HTML (parciales) en lugar de pesados JSON.
*   **Vista (`_v`):** Estructura de diseño HTML con clases utilitarias de Bulma CSS (`is-primary`, `columns`, `card`), inyectados dinámicamente por las directivas HTMX.
*   **Lógica de Negocio (`_l`):** Clases PHP con la lógica de negocio (evaluación de compatibilidades, descuentos, alertas).
*   **Datos (`_d`):** Capa de persistencia SQL utilizando PDO, parametrizando variables de forma inmutable contra inyecciones SQL.
*   **Mantenimiento (`_m`):** Tareas programadas asociadas (triggers de reorden, rutinas de depuración de índices).

---

## 8. PUNTOS PENDIENTES DE DEFINICIÓN DE CARA AL DESARROLLO

Previo al inicio de la fase de construcción de la próxima semana, el equipo de desarrollo debe definir formalmente los siguientes aspectos técnicos y de negocio con el cliente:
1.  **Proveedor de Facturación Electrónica:** Definir la API o proveedor de facturas electrónicas local para integrar el timbrado fiscal digital en tiempo real en el módulo del POS *(RF-12)*.
2.  **Compatibilidad del Modo Offline en POS:** Determinar si el búfer transaccional offline se implementará mediante bases de datos embebidas (*SQLite compiled via WebAssembly*) o a través del *LocalStorage* del navegador web.
3.  **Dispositivos Lectores de Código de Barras:** Validar las interfaces de comunicación física de las pistolas lectoras para asegurar que simulen pulsaciones de teclado estándar sin requerir controladores (drivers) dedicados en bodega.
4.  **Periodicidad de Carga ETL:** Confirmar si la carga analítica al Data Warehouse *(RF-10)* se programará en formato nocturno (diario a las 00:00 horas) o si se requiere sincronización en micro-cargas frecuentes por hora.
