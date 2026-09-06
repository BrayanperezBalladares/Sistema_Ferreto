# MÓDULO DE INVENTARIOS Y CATÁLOGO
*Documento de Requerimientos y Diseño Técnico (Especificación de Software)*
**Código del Módulo: MOD_INVENTARIOS**  
**Versión: 1.0**  
**Proyecto: Sistema de Gestión Integral "Ferreterías El Constructor"**

---

## 1. Introducción y Contexto del Módulo
El presente documento detalla la especificación del **Módulo de Inventarios y Catálogo**, considerado el pilar operativo y transaccional para la cadena **Ferreterías El Constructor**.

La gestión descentralizada del inventario que utiliza actualmente la ferretería produce desabastecimientos recurrentes en productos estructurales de alta demanda, exceso de existencias obsoletas o deterioradas y nula visibilidad multisucursal en tiempo real. Este módulo unifica el catálogo de productos de construcción, hogar y bricolaje, centralizando el control físico de stock a nivel de almacén, estante y lote, y automatizando las alertas de obsolescencia.

La arquitectura de software implementa la pila ligera **"No-Build"**:
*   **Backend:** PHP Procedimental con acceso estructurado de datos vía **PDO** para seguridad y rendimiento directo.
*   **Frontend:** Interfaz interactiva mediante **HTMX** (comunicación asíncrona basada en fragmentos HTML parciales enviados por el servidor, eliminando bundles pesados de JS) y maquetación móvil/escritorio con **Bulma CSS**.
*   **Bases de Datos:** Diseño unificado portable a MariaDB y SQL Server.

---

## 2. Requerimientos Funcionales
Los requerimientos funcionales modelan de forma técnica las transacciones y operaciones del inventario:

| Código | Requerimiento Funcional | Descripción y Reglas de Negocio en el Sistema |
| :--- | :--- | :--- |
| **RF-01** | Gestión del Catálogo de Productos | Registro, edición, desactivación y categorización de productos a nivel de catálogo nacional único. Cada producto debe incluir campos para SKU único, código de barras EAN-13, nombre, descripción detallada, unidad de medida (unidad, kg, metro, litro) y precio de venta base de catálogo. |
| **RF-02** | Control de Stock Multisucursal | Control preciso del inventario físico distribuido por almacén en cada tienda. Para un entorno multisucursal, la relación entre producto y stock no puede ser directa; se modela mediante una entidad intermedia que gestiona el inventario por Almacén y por Ubicación específica. |
| **RF-03** | Alertas de Stock Mínimo y Punto de Reorden | Evaluación continua del stock de seguridad. Cuando el stock disponible en un almacén caiga por debajo de su punto de reorden definido, el sistema generará una alerta de abastecimiento para el módulo de compras de forma automática. |
| **RF-04** | Integración con Lectores de Códigos de Barras | Soporte nativo para captura física de códigos de barras (pistolas USB/Bluetooth) en los procesos de picking, ventas en caja, y auditorías físicas de inventario. |
| **RF-05** | Gestión de Lotes y Obsolescencia | Rastreo obligatorio de productos sensibles (ej. cemento, yeso, siliconas o pinturas perecederas) mediante lotes con fecha de ingreso y fecha de vencimiento física, gatillando alertas automáticas 30 días antes del vencimiento. |

---

## 3. Requerimientos No Funcionales
Los requerimientos no funcionales definen las restricciones de rendimiento, calidad técnica y hardware del inventario:

| Código | Requisitos No Funcionales | Métricas, Estándares e Integración Técnica |
| :--- | :--- | :--- |
| **RNF-01** | Tiempo de Búsqueda de Productos | Las búsquedas por SKU, nombre o código de barras en el catálogo del sistema deben ejecutarse e inyectarse en el DOM mediante HTMX en un tiempo inferior a **300 milisegundos** bajo carga operativa de concurrencia completa. |
| **RNF-02** | Transacciones de Stock ACID | Todo ajuste de stock manual, venta transaccional o recepción en bodega de compras debe ejecutarse bajo transacciones atómicas seguras de base de datos para impedir inconsistencias de datos o pérdidas de inventario físico. |
| **RNF-03** | Compatibilidad de Entrada de Lectores | La interfaz web del sistema debe interpretar la señal del lector de códigos de barras de manera transparente, simulando el evento de pulsación de teclado (*keyboard event*) y gatillando la búsqueda automática asíncrona mediante HTMX sin requerir clics adicionales. |

---

## 4. Bloques Funcionales (Complejidad de Desarrollo)
El cronograma de desarrollo del inventario se divide en los siguientes bloques específicos:

*   **Catálogo Maestro Único (Complejidad: Baja):** Pantallas CRUD para administración de productos, categorías y unidades de medida. (*RF-01*)
*   **Gestión de Ubicaciones Físicas (Complejidad: Media):** Pantalla de mapeo de almacenes, pasillos, estantes y asignación de productos. (*RF-02*)
*   **Módulo de Lotes y FIFO Transaccional (Complejidad: Alta):** Motor de asignación automática de mermas, alertas de caducidad y despacho basado estrictamente en el método First-In, First-Out (FIFO) para productos perecederos. (*RF-05, RNF-02*)
*   **Mapeo de Dispositivos de Captura Rápida (Complejidad: Media):** Integración por software en la UI del POS y Bodega para interceptar el flujo continuo de las pistolas lectoras de barras. (*RF-04, RNF-03*)

---

## 5. Diseño de Base de Datos (Modelo Relacional 3FN)
Esquema de base de datos normalizado diseñado para controlar el inventario de la cadena:

### Tabla: CATEGORIA
*   `id_categoria` (INT, PK, AUTO_INCREMENT): Identificador único de la categoría.
*   `nombre` (VARCHAR(100)): Nombre descriptivo de la categoría (ej. "Obra Gruesa", "Herramientas Manuales", "Pinturas").
*   `descripcion` (TEXT): Notas complementarias de la familia de productos.

### Tabla: PRODUCTO
*   `id_producto` (INT, PK, AUTO_INCREMENT): Identificador del producto.
*   `id_categoria` (INT, FK -> CATEGORIA.id_categoria): Categoría de clasificación.
*   `sku` (VARCHAR(50), UNIQUE): Código único de inventario del producto.
*   `codigo_barras` (VARCHAR(50), UNIQUE): Código de barras físico del fabricante.
*   `nombre` (VARCHAR(150)): Nombre comercial de la mercancía.
*   `descripcion` (TEXT): Ficha descriptiva del producto.
*   `unidad_medida` (VARCHAR(20)): Unidad operativa (ej. `'unidad'`, `'kg'`, `'metro'`, `'litro'`).
*   `precio_base` (DECIMAL(12,2)): Precio de venta de catálogo nacional.
*   `es_perecedero` (BOOLEAN): Define si requiere trazabilidad de caducidad por lotes (1: Sí, 0: No).

### Tabla: ALMACEN
*   `id_almacen` (INT, PK, AUTO_INCREMENT): Identificador del almacén de la sucursal.
*   `id_sucursal` (INT, FK -> SUCURSAL.id_sucursal): Sucursal física donde se ubica el almacén.
*   `nombre` (VARCHAR(100)): Nombre del almacén (ej. "Almacén Principal", "Patio de Fierros", "Bodega Merma").

### Tabla: UBICACION
*   `id_ubicacion` (INT, PK, AUTO_INCREMENT): Identificador de posición física interna.
*   `id_almacen` (INT, FK -> ALMACEN.id_almacen): Almacén contenedor.
*   `pasillo` (VARCHAR(30)): Pasillo de la bodega.
*   `estante` (VARCHAR(30)): Estante o rack físico.
*   `nivel` (VARCHAR(30)): Altura o nivel específico de la gaveta.

### Tabla: INVENTARIO_STOCK
Control consolidado de existencias físicas por ubicación.
*   `id_stock` (INT, PK, AUTO_INCREMENT): Identificador físico del stock.
*   `id_producto` (INT, FK -> PRODUCTO.id_producto): Producto.
*   `id_almacen` (INT, FK -> ALMACEN.id_almacen): Almacén físico.
*   `id_ubicacion` (INT, FK -> UBICACION.id_ubicacion): Ubicación exacta.
*   `stock_actual` (INT): Cantidad física de existencias disponibles en la ubicación.
*   `stock_minimo` (INT): Stock mínimo de alerta.
*   `stock_maximo` (INT): Capacidad física máxima permisible en ubicación.
*   `punto_reorden` (INT): Nivel de stock umbral para disparar órdenes de compra automáticas.

### Tabla: LOTE_PRODUCTO
Control de obsolescencia de materiales.
*   `id_lote` (INT, PK, AUTO_INCREMENT): Identificador único del lote ingresado.
*   `id_producto` (INT, FK -> PRODUCTO.id_producto): Producto.
*   `numero_lote` (VARCHAR(50)): Código de lote asignado por el fabricante o proveedor.
*   `fecha_ingreso` (DATE): Fecha de recepción física en el almacén de destino.
*   `fecha_vencimiento` (DATE, NULL): Fecha límite de vida útil (aplica solo si `es_perecedero` = 1).
*   `cantidad` (INT): Existencias físicas asociadas a este lote específico.

### 5.1 Consideraciones de Diseño Clave
*   **Consistencia Relacional:** Al agregar, modificar o dar de baja stock físico, se debe validar en una transacción de base de datos aislada (`BEGIN TRANSACTION`) que no se permitan stocks negativos bajo ninguna circunstancia.
*   **Mapeo de SKU:** El SKU se genera automáticamente combinando las iniciales de la categoría de pertenencia del producto con un secuencial correlativo de inventario.

---

## 6. Ciclo de Vida de los Lotes de Producto
Los productos con fecha de vencimiento (como cemento o aditivos químicos) se rastrean rigurosamente:

```
    [ Ingresado ] ──(Recepción física de compra)──> [ Disponible ]
                                                          │
                                         (Fecha vencimiento < 30 días)
                                                          ▼
                                                 [ Prox. a Caducar ]
                                                          │
                                              (Expiración física o merma)
                                                          ▼
                                                       [ Merma ]
```

1.  **Ingresado:** Lote registrado en el muelle de descarga tras la recepción física de compra de proveedores. Todavía no habilitado para la venta a la espera de aprobación de calidad por bodega.
2.  **Disponible:** Habilitado formalmente para ventas o transferencias. Se despacha usando estrictamente el orden de llegada FIFO (*First-In, First-Out*).
3.  **Próximo a Caducar:** Estado intermedio que detona alertas automáticas en el dashboard de inventario al aproximarse a los 30 días de la fecha de expiración física registrada. Se prioriza para liquidación.
4.  **Merma:** Lote caducado, dañado o inservible. Pasa a una ubicación virtual en la tabla `ALMACEN` designada para desecho, restándolo del stock de venta inmediata.

---

## 7. Arquitectura del Módulo (Bulma + HTMX)
Estructura de arquitectura de archivos para el módulo de inventarios:

1.  `inventarios_v.php` (Vista Partial): Interfaz de inventarios con Bulma CSS. Cuenta con una barra de búsqueda que implementa los atributos `hx-get="inventarios_c.php?action=search"`, `hx-trigger="keyup changed delay:300ms, search"` para consultar el catálogo de manera asíncrona sin recargas, renderizando los resultados en un listado responsivo.
2.  `inventarios_c.php` (Controlador): Enruta peticiones asíncronas de HTMX. Al recibir búsquedas, formatea y retorna un fragmento de tabla HTML con las coincidencias. También procesa la recepción de lotes de forma síncrona.
3.  `inventarios_l.php` (Lógica de Negocio): Implementa validaciones lógicas para los ajustes de stock mínimos, cálculos de alerta de punto de reorden en segundo plano y verificación física de las fechas de obsolescencia de lotes.
4.  `inventarios_d.php` (Datos / Acceso SQL): Sentencias preparadas SQL PDO contra la base de datos: `SELECT * FROM INVENTARIO_STOCK WHERE stock_actual <= punto_reorden`.
5.  `inventarios_m.php` (Mantenimiento): Script programado (*cronjob*) que se ejecuta de forma periódica en el servidor, actualizando el estado de los lotes y gatillando alertas de obsolescencia.

---

## 8. Puntos Pendientes de Definición
Antes de iniciar formalmente la construcción el próximo lunes, se requiere definir:
1.  **Manejo de Productos Fraccionados:** Determinar si el sistema debe soportar stock fraccionado mediante números decimales (ej. 15.5 kg de clavos o 2.4 metros de cable) y cómo se integrará esto con los lectores de códigos de barras.
2.  **Modo de Trabajo Offline en Bodega:** Definir el nivel de tolerancia a fallas de conexión WAN en muelles de descarga y si los registros de bodega se guardarán en un buffer local antes de sincronizar con el host central.
3.  **Dimensiones y Capacidad Límite:** Confirmar si el sistema debe alertar al bodeguero cuando se intente ingresar mercancía que exceda la capacidad física (`stock_maximo`) asignada a una ubicación específica (`id_ubicacion`).
