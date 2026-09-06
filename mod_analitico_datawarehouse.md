# MÓDULO ANALÍTICO E INTEGRACIÓN CON DATA WAREHOUSE
*Documento de Requerimientos y Diseño Técnico (Especificación de Software)*
**Código del Módulo: MOD_ANALITICO**  
**Versión: 1.0**  
**Proyecto: Sistema de Gestión Integral "Ferreterías El Constructor"**

---

## 1. Introducción y Contexto del Módulo
El presente documento describe detalladamente la especificación técnica para el **Módulo Analítico e Integración con Data Warehouse (BI)** de **Ferreterías El Constructor**.

La toma de decisiones gerenciales basada en estimaciones o consultas directas a la base de datos transaccional (OLTP) de mostrador introduce cuellos de botella críticos, ralentizando el cobro de las cajas físicas de venta. Para solucionar esto y optimizar la cadena de suministros con proveedores, este módulo implementa un pipeline automatizado de Procesos de Extracción, Transformación y Carga (ETL) periódicos de datos operativos hacia un esquema dimensional analítico de tipo estrella (Data Warehouse), aislando por completo la carga analítica del rendimiento de las sucursales.

Siguiendo el stack ligero y eficiente **"No-Build"**:
*   **Backend:** PHP Procedimental estructurado con **PDO** para la consulta, agregación, y carga segura de datos en la base analítica.
*   **Frontend (Interactividad):** **HTMX** (para dashboards gerenciales analíticos interactivos y de alta velocidad de inyección, anulando la necesidad de frameworks JS de cliente complejos) y maquetación visual limpia y responsiva de **Bulma CSS**.
*   **Bases de Datos:** MariaDB y Microsoft SQL Server (para el Data Warehouse dimensional).

---

## 2. Requerimientos Funcionales
Los requerimientos funcionales modelan de forma técnica los procesos ETL e integración analítica de la gerencia:

| Código | Requerimiento Funcional | Descripción y Reglas de Negocio en el Sistema |
| :--- | :--- | :--- |
| **RF-01** | Pipeline de Procesos ETL Automatizado | Script programado en el servidor que extrae de forma periódica las transacciones de ventas, compras a proveedores, y traslados internos, aplicando reglas de limpieza de datos para su transformación y posterior carga en la base analítica de Data Warehouse. |
| **RF-02** | Esquema de Base de Datos en Estrella | Diseño físico estructurado de base de datos analítica compuesto por una tabla de Hechos de Ventas, una tabla de Hechos de Inventario y tablas Dimensionales de clasificación (Producto, Sucursal, Cliente, Proveedor, Tiempo). |
| **RF-03** | Dashboards de Inteligencia de Negocios (BI) | Cuadros de mando interactivos gerenciales que muestran métricas operativas de ventas (ventas consolidadas diarias, márgenes de ganancia brutos por categoría), rotación física de productos, y evaluación automatizada del cumplimiento logístico de los proveedores de la cadena. |

---

## 3. Requerimientos No Funcionales
Los requerimientos no funcionales estipulan las restricciones técnicas de aislamiento, rendimiento y latencias del sistema analítico:

| Código | Requisitos No Funcionales | Métricas, Estándares e Integración Técnica |
| :--- | :--- | :--- |
| **RNF-01** | Aislamiento Físico Analítico Completo | La base de datos analítica (OLAP) de Data Warehouse debe residir físicamente en un servidor independiente del motor transaccional de ventas (OLTP). Las consultas de BI gerenciales masivas tienen estrictamente prohibido acceder a las tablas operativas activas de las cajas de mostrador. |
| **RNF-02** | Latencia Máxima de Datos (SLA) | El pipeline de procesos ETL de actualización de datos debe ejecutarse e inyectar datos de manera automatizada de forma nocturna (latencia analítica de datos inferior a **24 horas** calendario). |
| **RNF-03** | Velocidad de Carga de Reportes BI | Los dashboards analíticos y reportes complejos agregados mostrados a gerencia en el sistema deben cargar e inyectarse en el navegador en un tiempo inferior a **1.5 segundos** mediante HTMX asíncrono. |

---

## 4. Bloques Funcionales (Complejidad de Desarrollo)
El cronograma de desarrollo del pipeline y dashboard analítico se divide de la siguiente manera:

*   **Dashboards de Visualización Gerencial (Complejidad: Media):** Pantalla en Bulma CSS que grafica e integra reportes ejecutivos usando HTMX de actualización periódica. (*RF-03, RNF-03*)
*   **Diseño Físico y Estructuración Dimensional (Complejidad: Media):** Creación física del esquema estrella, migración y seeding de dimensiones en base de datos. (*RF-02, RNF-01*)
*   **Orquestador de Pipeline de Procesos ETL (Complejidad: Alta):** Script PHP en segundo plano (*background worker*) que ejecuta la extracción diferencial de transacciones de sucursales, remueve mermas operativas y consolida de forma atómica en el Data Warehouse. (*RF-01, RNF-02*)

---

## 5. Diseño de Base de Datos Analítica (Modelo en Estrella)
Esquema dimensional diseñado para la toma de decisiones estratégicas de la cadena ferretera:

### Tabla de Hechos: HECHO_VENTAS
Consolida métricas financieras agregadas de la cadena.
*   `id_hecho_venta` (BIGINT, PK, AUTO_INCREMENT): Identificador único secuencial de la línea de hecho.
*   `sk_tiempo` (INT, FK -> DIM_TIEMPO.sk_tiempo): Llave de tiempo correspondiente.
*   `sk_producto` (INT, FK -> DIM_PRODUCTO.sk_producto): Llave de producto.
*   `sk_sucursal` (INT, FK -> DIM_SUCURSAL.sk_sucursal): Llave de sucursal.
*   `sk_cliente` (INT, FK -> DIM_CLIENTE.sk_cliente): Llave de cliente.
*   `cantidad_unidades` (INT): Cantidad total física de unidades adquiridas.
*   `monto_venta` (DECIMAL(12,2)): Monto bruto ingresado por ventas.
*   `monto_costo` (DECIMAL(12,2)): Costo bruto de adquisición de mercancía de proveedores.
*   `monto_utilidad` (DECIMAL(12,2)): Margen bruto de ganancia obtenido.

### Tabla de Hechos: HECHO_INVENTARIO
Consolida métricas operativas de stocks en la cadena.
*   `id_hecho_inventario` (BIGINT, PK, AUTO_INCREMENT): Identificador único secuencial de la línea de hecho.
*   `sk_tiempo` (INT, FK -> DIM_TIEMPO.sk_tiempo): Llave de tiempo.
*   `sk_producto` (INT, FK -> DIM_PRODUCTO.sk_producto): Llave de producto.
*   `sk_sucursal` (INT, FK -> DIM_SUCURSAL.sk_sucursal): Llave de sucursal.
*   `stock_disponible` (INT): Cantidad disponible de stock consolidado.
*   `stock_minimo` (INT): Límite mínimo configurado.
*   `stock_maximo` (INT): Capacidad máxima de almacén física.

### Tablas Dimensionales (Ejemplo de Mapeo)
*   **DIM_PRODUCTO:** `sk_producto` (PK), `id_producto_transaccional`, `sku`, `nombre_producto`, `categoria_desc`, `unidad_medida`.
*   **DIM_SUCURSAL:** `sk_sucursal` (PK), `id_sucursal_transaccional`, `nombre_sucursal`, `ciudad`, `estado_activo`.
*   **DIM_CLIENTE:** `sk_cliente` (PK), `id_cliente_transaccional`, `nombre_cliente`, `identificacion_fiscal`, `email`.
*   **DIM_TIEMPO:** `sk_tiempo` (PK), `fecha`, `dia`, `mes`, `anio`, `trimestre`, `dia_semana_desc`.
*   **DIM_PROVEEDOR:** `sk_proveedor` (PK), `id_proveedor_transaccional`, `razon_social`, `contacto`, `calificacion_cumplimiento`.

### 5.1 Consideraciones de Diseño Clave
*   **Desacoplamiento Físico de Base de Datos:** Para evitar ralentizar el punto de venta de mostrador, el pipeline ETL ejecutará la extracción de datos OLTP exclusivamente en horarios nocturnos de menor volumen transaccional de sucursales, escribiendo los resultados consolidados en un servidor físico dedicado.
*   **Dimensiones Lentamente Cambiantes (SCD Tipo 2):** El sistema implementará el patrón analítico **SCD Tipo 2** sobre la tabla `DIM_PRODUCTO` y `DIM_SUCURSAL` (añadiendo columnas de fecha inicio, fecha fin, y flag activo) para mantener un histórico inmutable ante cambios de categorías o ubicaciones físicas sin perder consistencia de reportes previos.

---

## 6. Ciclo de Vida de los Lotes de Carga ETL
El pipeline nocturno automatizado de Procesos ETL progresa secuencialmente a través de las siguientes etapas:

```
    [ Iniciada ] ──(Extracción Diferencial)──> [ Extraída ]
                                                    │
                                        (Reglas de Limpieza y Cruces)
                                                    ▼
    [ Carga Exitosa ] <──(Carga de Datos)── [ Transformada ]
                                                    │
                                        (Errores de Consistencia)
                                                    ▼
                                                 [ Error ]
```

1.  **Iniciada:** Script automatizado nocturno gatillado por cronjob de PHP. Registra el inicio de auditoría analítica e inicia las conexiones asíncronas con las bases de datos de sucursales.
2.  **Extraída:** Extracción de transacciones operativas nuevas detectadas mediante marcas de fecha y hora, guardándolas en tablas temporales analíticas de almacenamiento del servidor dedicado de BI.
3.  **Transformada:** Limpieza de datos (conversión de formatos nulos de clientes, mapeo de unidades de medida, unión de costos promedio de proveedores y exclusión de mermas técnicas).
4.  **Carga Exitosa:** Inserción exitosa de registros en tablas analíticas de hechos e inyección de datos finales en el Data Warehouse. Se actualizan automáticamente los dashboards gerenciales de visualización.
5.  **Error:** Operación fallida o cancelada por pérdida de conectividad WAN con alguna sucursal remota o inconsistencias de datos críticas de muelle. Envía alerta inmediata al Administrador.

---

## 7. Arquitectura del Módulo (Bulma + HTMX)
Estructura física de archivos para la implementación técnica analítica de BI:

1.  `analitico_v.php` (Vista Partial): Dashboard gerencial en Bulma CSS que detalla gráficos interactivos analíticos de mermas y ventas de forma responsiva. Integra el atributo `hx-get="analitico_c.php?action=load_chart"` para solicitar e inyectar reportes asíncronamente mediante HTMX de velocidad de milisegundos.
2.  `analitico_c.php` (Controlador): Enruta peticiones analíticas AJAX gerenciales. Procesa filtrados por fechas y sucursales e inyecta la información agregada en el DOM sin recargas de página.
3.  `analitico_l.php` (Lógica de Negocio): Orquestador del pipeline analítico. Gestiona el mapeo ETL nocturno, las reglas de transformación y los cruces dimensionales analíticos.
4.  `analitico_d.php` (Datos / Acceso SQL): Ejecuta las sentencias preparadas de carga y extracción PDO: `SELECT SUM(monto_utilidad) FROM HECHO_VENTAS WHERE sk_tiempo = :sk_tiempo`.
5.  `analitico_m.php` (Mantenimiento): Scripts de mantenimiento programados para recalcular tablas analíticas agregadas previas y optimizar tablas de Data Warehouse.

---

## 8. Puntos Pendientes de Definición
Antes de iniciar formalmente la construcción la próxima semana, se deben definir con la gerencia los siguientes aspectos:
1.  **Herramientas de BI Compatibles:** Definir si los directores gerenciales de la ferretería usarán exclusivamente la interfaz de dashboards nativa Bulma + HTMX del sistema o si se requiere una integración técnica de exportación compatible con Power BI u Tableau de forma directa.
2.  **Periodicidad del Proceso ETL:** Confirmar si el pipeline ETL de extracción de datos transaccionales se ejecutará una única vez de forma nocturna diaria o si se requieren micro-batches de sincronización cada 4 horas.
3.  **Mantenimiento Histórico Analítico:** Establecer la cantidad máxima de años históricos (ej. 3 o 5 años) que deben permanecer legibles en caliente en el Data Warehouse de hechos antes de su almacenamiento en frío redundante.
