# MÓDULO DE VENTAS Y PUNTO DE VENTA (POS)
*Documento de Requerimientos y Diseño Técnico (Especificación de Software)*
**Código del Módulo: MOD_VENTAS**  
**Versión: 1.0**  
**Proyecto: Sistema de Gestión Integral "Ferreterías El Constructor"**

---

## 1. Introducción y Contexto del Módulo
El presente documento detalla la especificación del **Módulo de Ventas y Punto de Venta (POS)** para la cadena **Ferreterías El Constructor**.

La coordinación deficiente en cajas, la imposibilidad de vender si la conexión WAN falla de forma intermitente, y la falta de ventas complementarias en mostrador representan cuellos de botella severos para la empresa. Este módulo ofrece una interfaz de caja optimizada para agilizar los cobros del punto de venta físicas, integrada con un motor inteligente de sugerencia de productos por compatibilidad física y un flujo seguro de timbrado fiscal para la emisión de facturación electrónica.

Bajo la filosofía **"No-Build"**:
*   **Backend:** PHP Procedimental estructurado con **PDO** de alta seguridad contra SQLi.
*   **Frontend (Interactividad):** **HTMX** (peticiones asíncronas optimizadas y recargas parciales de DOM, eliminando capas complejas de frameworks JS de cliente) y estilos visuales responsivos con **Bulma CSS**.
*   **Base de Datos:** Estructuras relacionales normalizadas para MariaDB y SQL Server.

---

## 2. Requerimientos Funcionales
Los requerimientos funcionales modelan de forma técnica las transacciones de venta de la cadena:

| Código | Requerimiento Funcional | Descripción y Reglas de Negocio en el Sistema |
| :--- | :--- | :--- |
| **RF-01** | Registro de Transacciones de Venta | Interfaz de facturación rápida que registra la compra de materiales en mostrador, vinculando cliente, sucursal, cajero, ítems vendidos, cantidad y costos reales aplicados. |
| **RF-02** | Sincronización Transaccional de Stock | Toda inserción de registros en la tabla de detalle de venta debe disminuir en tiempo real e inmediatamente las existencias correspondientes en la tabla de existencias físicas de la sucursal emisora. |
| **RF-03** | Motor de Recomendación por Compatibilidad | El sistema debe sugerir de forma interactiva en la pantalla del POS productos complementarios, accesorios o repuestos compatibles con el ítem consultado (ej. sugerir brocas de concreto compatibles con el modelo de taladro seleccionado), basado en una tabla estructurada de compatibilidades. |
| **RF-04** | Gestión de Clientes y Crédito | Registro y vinculación de clientes minoristas y constructores con identificador tributario válido (RUT/RFC/NIT) y dirección para trazabilidad y facturación. |
| **RF-05** | Emisión de Facturación Electrónica Fiscal | Integración con la entidad gubernamental tributaria correspondiente para la firma digital de facturas y la generación automática de la firma fiscal única nacional. |

---

## 3. Requerimientos No Funcionales
Los requerimientos no funcionales definen las restricciones de rendimiento, resiliencia y diseño del POS:

| Código | Requisitos No Funcionales | Métricas, Estándares e Integración Técnica |
| :--- | :--- | :--- |
| **RNF-01** | Tiempo de Respuesta en Punto de Venta | Las operaciones críticas del punto de venta (como el escaneo de un producto en la cola de compras, el descuento de stock, y el procesamiento del pago) deben ejecutarse en un lapso inferior a **500 milisegundos** bajo cargas de alta concurrencia. |
| **RNF-02** | Resiliencia de Red en Cajas (Offline-First) | En caso de desconexión del servidor central de base de datos en la nube, las cajas de venta deben continuar facturando a través de un búfer de transacciones local, ejecutando la sincronización de manera idéntica e idempotente al restablecerse el enlace. |
| **RNF-03** | Operabilidad sin Ratón (Keyboard-First) | La interfaz operativa del POS debe ser operable al 100% utilizando combinaciones de teclas físicas rápidas (*hotkeys*) y flujos del lector de barras, evitando la necesidad de ratón o teclado virtual. |

---

## 4. Bloques Funcionales (Complejidad de Desarrollo)
El cronograma de desarrollo del punto de venta se agrupa de la siguiente manera:

*   **Carrito e Interfaz Transaccional (Complejidad: Media):** Pantalla del POS optimizada en Bulma con recarga de totales interactivos asíncronos vía HTMX. (*RF-01, RNF-03*)
*   **Motor de Reglas de Compatibilidad (Complejidad: Alta):** Algoritmo del lado del servidor que consulta relaciones cruzadas en base de datos y provee sugerencias contextuales al agregar ítems al carrito. (*RF-03*)
*   **Módulo de Sincronización e Integración Fiscal (Complejidad: Alta):** Integración con el proveedor externo de timbrado fiscal electrónico y resiliencia offline del POS local. (*RF-02, RF-05, RNF-01, RNF-02*)

---

## 5. Diseño de Base de Datos (Modelo Relacional 3FN)
Esquema transaccional normalizado para la operativa comercial en cajas:

### Tabla: CLIENTE
*   `id_cliente` (INT, PK, AUTO_INCREMENT): Identificador único del cliente.
*   `identificacion_fiscal` (VARCHAR(30), UNIQUE): Registro fiscal tributario (ej. RFC, RUT, NIT).
*   `nombre` (VARCHAR(150)): Razón social o nombre completo.
*   `telefono` (VARCHAR(20)): Teléfono de contacto.
*   `email` (VARCHAR(100)): Correo de envío automático de facturas electrónicas.
*   `direccion` (TEXT): Domicilio fiscal del cliente.

### Tabla: VENTA
Cabecera consolidada de la transacción de venta.
*   `id_venta` (INT, PK, AUTO_INCREMENT): Identificador de la venta.
*   `id_sucursal` (INT, FK -> SUCURSAL.id_sucursal): Sucursal física donde ocurrió la venta.
*   `id_cliente` (INT, FK -> CLIENTE.id_cliente): Cliente que adquiere la mercancía.
*   `id_usuario` (INT, FK -> USUARIO.id_usuario): Cajero que factura la venta.
*   `numero_factura` (VARCHAR(50), UNIQUE): Correlativo fiscal de la factura electrónica.
*   `fecha_venta` (DATETIME): Fecha y hora del cobro.
*   `subtotal` (DECIMAL(12,2)): Subtotal de la transacción antes de impuestos.
*   `impuestos` (DECIMAL(12,2)): Impuesto de valor agregado aplicado.
*   `descuento` (DECIMAL(12,2)): Monto del descuento aplicado.
*   `total` (DECIMAL(12,2)): Total de pago consolidado.
*   `estado_pago` (VARCHAR(30)): Estado de la transacción (`'pendiente'`, `'pagada'`, `'facturada'`, `'anulada'`).

### Tabla: DETALLE_VENTA
Detalle físico de las mercancías facturadas en la venta.
*   `id_detalle_venta` (INT, PK, AUTO_INCREMENT): Identificador único de la línea de detalle.
*   `id_venta` (INT, FK -> VENTA.id_venta): Cabecera de venta asociada.
*   `id_producto` (INT, FK -> PRODUCTO.id_producto): Producto vendido.
*   `cantidad` (INT): Unidades físicas vendidas.
*   `precio_unitario` (DECIMAL(12,2)): Precio neto de venta aplicado.
*   `subtotal` (DECIMAL(12,2)): Monto acumulado de la línea de detalle.

### Tabla: COMPATIBILIDAD_PRODUCTO
Catálogo de relaciones de compatibilidad de ferretería.
*   `id_compatibilidad` (INT, PK, AUTO_INCREMENT): Identificador de relación.
*   `id_producto_origen` (INT, FK -> PRODUCTO.id_producto): Producto consultado.
*   `id_producto_relacionado` (INT, FK -> PRODUCTO.id_producto): Producto sugerido.
*   `tipo_relacion` (VARCHAR(50)): Tipo de compatibilidad (ej. `'accesorio'`, `'reemplazo'`, `'complementario'`).

### 5.1 Consideraciones de Diseño Clave
*   **Concurrencia Transaccional:** Al realizar el cobro, el controlador PHP ejecutará una transacción PDO exclusiva usando bloqueos pesimistas en base de datos (`SELECT ... FOR UPDATE` sobre la tabla `INVENTARIO_STOCK`) antes de insertar en `DETALLE_VENTA`. Esto asegura la inmutabilidad y consistencia del inventario, anulando carreras de sobreventas.
*   **Baja Logística por Anulaciones:** Las facturas o ventas anuladas no se eliminan físicamente de la base de datos (`DELETE`). Se modifica su estado en la tabla `VENTA` a `'anulada'` y se ejecuta una transacción reversa que restituye el stock en el inventario de la sucursal de manera atómica.

---

## 6. Ciclo de Vida de la Transacción de Venta
La venta física en el POS progresa a través de la siguiente máquina de estados inmutables:

```
    [ En Proceso ] ──(Confirmar Pago de Caja)──> [ Pagada ]
                                                    │
                                         (Timbrado Fiscal Exitoso)
                                                    ▼
    [ Anulada ] <──(Anulación por Admin)── [ Facturada ]
```

1.  **En Proceso:** El cajero está escaneando ítems en mostrador. El carrito de compras se almacena temporalmente y se muestran sugerencias basadas en relaciones de compatibilidad.
2.  **Pagada:** El cliente completa el pago (efectivo, tarjeta o transferencia). Se descuenta el stock de manera transaccional y atómica en el inventario.
3.  **Facturada:** El sistema completa el timbrado fiscal electrónico con el proveedor externo de la agencia tributaria, enviando el comprobante automáticamente al correo electrónico del cliente.
4.  **Anulada:** Operación excepcional autorizada solo por el Administrador. Cancela el comprobante fiscal y devuelve de forma atómica el stock a la bodega física asignada.

---

## 7. Arquitectura del Módulo (Bulma + HTMX)
Estructura física de archivos para la implementación técnica del POS:

1.  `ventas_v.php` (Vista Partial): Pantalla transaccional de caja estructurada en Bulma. Cuenta con atajos de teclado asignados e integra el atributo `hx-post="ventas_c.php?action=add_cart"` para inyectar líneas de carrito asíncronas y refrescar totales mediante HTMX sin recargar el DOM.
2.  `ventas_c.php` (Controlador): Gestiona solicitudes AJAX del POS. Procesa la adición de productos, la selección de clientes de la base de datos y enruta la orden final hacia el módulo de procesamiento de cobros.
3.  `ventas_l.php` (Lógica de Negocio): Contiene las reglas comerciales del negocio. Ejecuta el motor de compatibilidad de materiales, el cálculo de descuentos promocionales y gestiona las conexiones asíncronas al proveedor de facturas.
4.  `ventas_d.php` (Datos / Acceso SQL): Sentencias SQL seguras controladas por PDO: `SELECT * FROM COMPATIBILIDAD_PRODUCTO WHERE id_producto_origen = :id_prod`.
5.  `ventas_m.php` (Mantenimiento): Orquestador encargado de almacenar temporalmente las colas de venta locales offline y sincronizarlas tan pronto la conexión retorne a la sucursal.

---

## 8. Puntos Pendientes de Definición
Antes de iniciar formalmente la codificación de cajas la próxima semana, se requiere aclarar:
1.  **Proveedor de Facturación Electrónica:** Definir los endpoints de la API del agente tributario nacional para iniciar el proceso de integración del timbrado fiscal en el controlador.
2.  **Soporte de Impresión Local:** Confirmar si el sistema debe conectarse a impresoras térmicas de tickets (*impresoras POS de 80mm*) directamente por comandos de sistema en terminales de caja.
3.  **Límite Máximo de Descuento Manual:** Establecer el porcentaje máximo de descuento manual que un cajero operativo puede aplicar a una transacción sin requerir la aprobación y firma digital de la administración.
