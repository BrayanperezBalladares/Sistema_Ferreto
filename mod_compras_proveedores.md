# MÓDULO DE COMPRAS Y PROVEEDORES
*Documento de Requerimientos y Diseño Técnico (Especificación de Software)*
**Código del Módulo: MOD_COMPRAS**  
**Versión: 1.0**  
**Proyecto: Sistema de Gestión Integral "Ferreterías El Constructor"**

---

## 1. Introducción y Contexto del Módulo
El presente documento define detalladamente la especificación técnica para el **Módulo de Compras y Proveedores** del sistema integral de **Ferreterías El Constructor**.

Las relaciones ineficientes con proveedores, el cálculo manual de órdenes de compra (propenso a desabastecimientos de stock) y la falta de control histórico de costos pactados por volumen elevan los costos de adquisición de la ferretería. Este módulo soluciona estos problemas implementando un algoritmo del lado del servidor que genera de forma automática borradores de órdenes de compra basados en los puntos de reorden de inventario, evaluando continuamente el cumplimiento logístico de los proveedores y el lead time de entrega.

Siguiendo el stack ligero **"No-Build"**:
*   **Backend:** PHP Procedimental con acceso estructurado mediante **PDO** para transacciones seguras contra inyecciones de código SQL.
*   **Frontend (Interactividad):** **HTMX** para peticiones AJAX asíncronas que inyectan fragmentos de HTML directamente en el DOM, complementado por la maquetación responsiva de **Bulma CSS**.
*   **Motor de Base de Datos:** Compatibilidad portable entre MariaDB y SQL Server.

---

## 2. Requerimientos Funcionales
Los requerimientos funcionales modelan de forma técnica los procesos de abastecimiento del sistema:

| Código | Requerimiento Funcional | Descripción y Reglas de Negocio en el Sistema |
| :--- | :--- | :--- |
| **RF-01** | Gestión del Catálogo de Proveedores | Registro, edición e historial de empresas proveedoras. Cada ficha debe incluir identificador tributario válido, razón social, detalles de contacto, días de crédito otorgados y un campo para la calificación automática de cumplimiento. |
| **RF-02** | Asociación de Costos y Lead Times | Registro detallado de la relación de compras. Un mismo producto puede ser surtido por varios proveedores con costos de compra pactados, tiempos de entrega (*lead times* en días) y volúmenes de compra mínimos diferentes. |
| **RF-03** | Algoritmo de Reorden Automático | Tarea automatizada del sistema que evalúa continuamente el stock de seguridad en los almacenes. Si el stock disponible cae por debajo de su punto de reorden, el sistema busca en la base de datos el proveedor idóneo con mejor calificación y menor costo, y genera un borrador de Orden de Compra automáticamente. |
| **RF-04** | Control de Recepción de Pedidos | Interfaz de bodega para registrar la entrada física de mercancía en sucursal, permitiendo contrastar la cantidad de unidades solicitadas en la orden de compra original contra las unidades realmente recibidas en el muelle de descarga. |

---

## 3. Requerimientos No Funcionales
Los requerimientos no funcionales estipulan las restricciones de rendimiento, calidad técnica y analítica de compras:

| Código | Requisitos No Funcionales | Métricas, Estándares e Integración Técnica |
| :--- | :--- | :--- |
| **RNF-01** | Cálculo de Calificación de Proveedor (Fill Rate) | El sistema debe recalcular la calificación del proveedor de manera automática en el muelle tras cada entrega, aplicando la métrica del **Fill Rate** (relación de unidades completadas / unidades pedidas originales en la Orden de Compra). |
| **RNF-02** | Procesamiento de Reorden en Background | El script que procesa y evalúa de forma nocturna el stock para generar las órdenes de compra automáticas debe ejecutarse en segundo plano (*background job* de PHP) sin interferir en los accesos de las cajas activas. |
| **RNF-03** | Consistencia de Recepción ACID | Al recibir existencias, el sistema debe registrar en una única transacción segura de base de datos la adición de productos a bodega, el registro en la bitácora de mermas, y la actualización de costos para evitar duplicaciones o mermas administrativas. |

---

## 4. Bloques Funcionales (Complejidad de Desarrollo)
El cronograma de desarrollo del módulo de compras se divide de la siguiente manera:

*   **Catálogo de Proveedores y Precios (Complejidad: Baja):** Pantallas para gestionar la base de datos de proveedores y la matriz de asociación de productos. (*RF-01, RF-02*)
*   **Interfaz de Control de Muelle de Descarga (Complejidad: Media):** Pantallas móviles de bodega en Bulma para verificar órdenes contra unidades recibidas de forma asíncrona vía HTMX. (*RF-04, RNF-01, RNF-03*)
*   **Algoritmo de Reorden Automático (Complejidad: Alta):** Motor de cálculo probabilístico en PHP de stock de seguridad y generación nocturna automatizada de borradores de reabastecimiento en base de datos. (*RF-03, RNF-02*)

---

## 5. Diseño de Base de Datos (Modelo Relacional 3FN)
Esquema relacional de base de datos diseñado para la administración de adquisiciones:

### Tabla: PROVEEDOR
*   `id_proveedor` (INT, PK, AUTO_INCREMENT): Identificador único del proveedor.
*   `identificacion_fiscal` (VARCHAR(30), UNIQUE): RUT, RFC o NIT comercial de la empresa proveedora.
*   `razon_social` (VARCHAR(150)): Razón social registrada.
*   `contacto` (VARCHAR(100)): Persona de contacto en ventas del proveedor.
*   `telefono` (VARCHAR(20)): Teléfono de contacto.
*   `email` (VARCHAR(100)): Correo de correspondencia para el envío automático de órdenes de compra.
*   `dias_credito` (INT): Días de plazo de pago acordados con el proveedor.
*   `calificacion_cumplimiento` (DECIMAL(5,2)): Calificación acumulada ponderada de entregas en muelle (0 a 100).

### Tabla: PRODUCTO_PROVEEDOR
Catálogo intermedio de costos de adquisición pactados de ferretería.
*   `id_prod_prov` (INT, PK, AUTO_INCREMENT): Identificador de costo acordado.
*   `id_producto` (INT, FK -> PRODUCTO.id_producto): Producto.
*   `id_proveedor` (INT, FK -> PROVEEDOR.id_proveedor): Proveedor.
*   `precio_compra_pactado` (DECIMAL(12,2)): Costo neto unitario pactado para adquisiciones.
*   `tiempo_entrega_dias` (INT): Plazo de entrega estimado en días (*lead time* del proveedor).
*   `volumen_minimo` (INT): Cantidad mínima obligatoria por pedido para mantener el costo de compra pactado.

### Tabla: ORDEN_COMPRA
Cabecera consolidada de abastecimiento comercial.
*   `id_orden_compra` (INT, PK, AUTO_INCREMENT): Identificador único del pedido.
*   `id_proveedor` (INT, FK -> PROVEEDOR.id_proveedor): Proveedor asignado.
*   `id_almacen_destino` (INT, FK -> ALMACEN.id_almacen): Bodega donde ingresará la mercancía.
*   `id_usuario` (INT, FK -> USUARIO.id_usuario): Comprador de la ferretería responsable de procesar la orden.
*   `fecha_emision` (DATETIME): Fecha de creación del pedido.
*   `fecha_esperada` (DATE): Fecha comprometida de entrega del proveedor en muelle.
*   `estado` (VARCHAR(30)): Estado actual del flujo de compras (`'borrador'`, `'aprobada'`, `'recibida_parcial'`, `'completada'`, `'cancelada'`).
*   `total` (DECIMAL(12,2)): Monto de inversión neto total de la orden de compra.

### Tabla: DETALLE_ORDEN_COMPRA
*   `id_detalle_orden` (INT, PK, AUTO_INCREMENT): Identificador de la línea de detalle.
*   `id_orden_compra` (INT, FK -> ORDEN_COMPRA.id_orden_compra): Orden de compra principal.
*   `id_producto` (INT, FK -> PRODUCTO.id_producto): Producto solicitado.
*   `cantidad_pedida` (INT): Unidades físicas solicitadas.
*   `cantidad_recibida` (INT): Unidades físicas realmente verificadas en muelle.
*   `precio_unitario` (DECIMAL(12,2)): Costo neto unitario de compra acordado.

### 5.1 Consideraciones de Diseño Clave
*   **Tolerancia en Muelle:** Al registrar la entrada de mercancía en la tabla `DETALLE_ORDEN_COMPRA`, el controlador PHP validará si `cantidad_recibida < cantidad_pedida`, cambiando el estado de la cabecera a `'recibida_parcial'`. Al mismo tiempo, incrementará las existencias en `INVENTARIO_STOCK` utilizando transacciones seguras PDO de forma inmediata.
*   **Baja de Proveedores:** No se permite la eliminación física (`DELETE`) de proveedores para mantener la integridad histórica de costos y compras. Al dar de baja, se implementará un bloqueo comercial mediante lógica a nivel de software.

---

## 6. Ciclo de Vida de la Orden de Compra
La Orden de Compra progresa de manera controlada por las siguientes etapas inmutables:

```
    [ Borrador ] ──(Aprobación por Compras)──> [ Aprobada ]
                                                   │
                                          (Verificación en Muelle)
                                                   ▼
    [ Completada ] <──(Entrega total de stock)── [ Recibida Parcial ]
                                                   │
                                         (Deterioro o Incumplimiento)
                                                   ▼
                                              [ Cancelada ]
```

1.  **Borrador:** Generado de forma nocturna automática por el algoritmo de reorden o manualmente por el encargado de compras. Permite modificaciones de cantidades y adición de productos antes del envío.
2.  **Aprobada:** Validada por la gerencia y enviada formalmente por correo automatizado al proveedor correspondiente. Bloqueada para ediciones del personal.
3.  **Recibida Parcial:** El transportador del proveedor entrega mercancías en el muelle de descarga de bodega pero se detectan faltantes o discrepancias que se registran de forma asíncrona.
4.  **Completada:** Entrada física total de productos verificada y cargada al stock activo de bodega. Finaliza el proceso y gatilla el recálculo automático de la calificación de cumplimiento del proveedor.
5.  **Cancelada:** Orden desestimada o rechazada por discrepancias críticas o retrasos insostenibles de entrega en el almacén.

---

## 7. Arquitectura del Módulo (Bulma + HTMX)
Mapeo de archivos físicos de software del módulo de compras:

1.  `compras_v.php` (Vista Partial): Formulario del muelle de recepción en Bulma CSS que permite contrastar los campos de cantidades de forma responsiva. Dispara la llamada `hx-post="compras_c.php?action=verify_item"` para validar existencias de forma interactiva y veloz mediante HTMX.
2.  `compras_c.php` (Controlador): Enruta peticiones de compras. Procesa la aprobación manual de órdenes e inyecta la confirmación HTML de guardado en el DOM operativo de muelle de descarga.
3.  `compras_l.php` (Lógica de Negocio): Contiene las reglas del negocio de abastecimiento. Ejecuta el algoritmo del punto de reorden, calcula la métrica de **Fill Rate** de cada entrega física y valida las desviaciones de lead times en muelle.
4.  `compras_d.php` (Datos / Acceso SQL): Sentencias preparadas de acceso SQL PDO: `SELECT * FROM PRODUCTO_PROVEEDOR WHERE id_proveedor = :id_prov`.
5.  `compras_m.php` (Mantenimiento): Script automatizado (*cronjob*) nocturno que recorre existencias mínimas en bodega y genera borradores en la base de datos transaccional.

---

## 8. Puntos Pendientes de Definición
Antes de iniciar formalmente la construcción de software el próximo lunes, se debe resolver:
1.  **Porcentaje Máximo de Tolerancia en Discrepancias:** Definir el nivel máximo aceptable de discrepancias físicas en muelle (ej. recibir mercancías con empaque mojado o faltantes mínimos) antes de forzar la cancelación de toda la orden de compra en sucursal.
2.  **Mecanismo de Notificación a Proveedores:** Confirmar si las órdenes de compra aprobadas se enviarán directamente en formato PDF por email mediante librerías de servidor de PHP o si se prefiere una integración de API B2B externa.
3.  **Autorización de Presupuestos:** Establecer si la generación de órdenes automáticas que superen cierto umbral monetario requiere la aprobación y firma digital obligatoria de la junta directiva ferretera.
