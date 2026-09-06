# MÓDULO DE LOGÍSTICA Y TRANSFERENCIAS
*Documento de Requerimientos y Diseño Técnico (Especificación de Software)*
**Código del Módulo: MOD_LOGISTICA**  
**Versión: 1.0**  
**Proyecto: Sistema de Gestión Integral "Ferreterías El Constructor"**

---

## 1. Introducción y Contexto del Módulo
El presente documento describe detalladamente la especificación técnica para el **Módulo de Logística y Transferencias** de la cadena de sucursales físicas de **Ferreterías El Constructor**.

La distribución física ineficiente, las transferencias de existencias internas mal auditadas que producen mermas en los trayectos de carretera, y la falta de documentación legal unificada que cumpla con los controles de tránsito nacional elevan las pérdidas de la cadena. Este módulo resuelve estas ineficiencias logísticas gestionando solicitudes de transferencia internas inter-sucursal mediante un flujo seguro de control de doble firma digital y rastreo de stock en tránsito de muelle a muelle.

El desarrollo sigue la pila moderna y simplificada **"No-Build"**:
*   **Backend:** PHP Procedimental con acceso e integridad de datos controlado a través de sentencias **PDO** robustas de base de datos.
*   **Frontend (Interactividad):** **HTMX** (peticiones asíncronas de servidor que devuelven HTML parcial, logrando un flujo de SPA ligero) y maquetación de Bulma CSS de alto rendimiento responsivo.
*   **Bases de Datos:** Diseño relacional portable para MariaDB y SQL Server.

---

## 2. Requerimientos Funcionales
Los requerimientos funcionales especifican las operaciones de transferencia de stock e integración física del sistema:

| Código | Requerimiento Funcional | Descripción y Reglas de Negocio en el Sistema |
| :--- | :--- | :--- |
| **RF-01** | Registro de Solicitud de Traslado | Interfaz que permite a una sucursal con déficit de mercancía solicitar stock a otra tienda de la cadena que posea existencias disponibles en catálogo. |
| **RF-02** | Control Transaccional de Stock en Tránsito | Toda transferencia aprobada debe descontarse inmediatamente del stock del almacén de origen y sumarse transaccionalmente a un Almacén Virtual de Tránsito asignado en la base de datos, asegurando que las existencias no desaparezcan administrativamente mientras dura el transporte de carretera. |
| **RF-03** | Recepción Física con Doble Control | El bodeguero de la sucursal de destino debe validar y registrar las unidades recibidas, cerrando la transferencia. El sistema exige la verificación digital de identidades (*doble sign-off* de bodegueros origen/destino) para evitar mermas operativas. |
| **RF-04** | Registro de Guía de Remisión Fiscal | Generación, validación fiscal y archivo de documentación legal obligatoria para el traslado físico de mercancías en carreteras del país (Guías de Despacho o Guías de Remisión según la normativa local). |

---

## 3. Requerimientos No Funcionales
Los requerimientos no funcionales definen los atributos de calidad, estándares técnicos y de seguridad informática del transporte:

| Código | Requisitos No Funcionales | Métricas, Estándares e Integración Técnica |
| :--- | :--- | :--- |
| **RNF-01** | Trazabilidad Total del Historial de Traslados | El sistema debe guardar de manera inmutable un registro histórico en base de datos de cada cambio de estado, hora, fecha, e identidad del bodeguero responsable de cada transferencia. |
| **RNF-02** | Transacciones de Transferencia ACID | El movimiento físico de stock (Origen -> Tránsito -> Destino) debe encapsularse en una única transacción de base de datos (`BEGIN TRANSACTION`) con aislamiento estricto de nivel Serializable para evitar inconsistencias de datos o duplicaciones. |
| **RNF-03** | Descarga Rápida de Comprobantes Legales | Las Guías de Remisión en formato PDF generadas en el servidor deben pesar menos de **100 KB** para permitir su descarga ágil y oportuna desde conexiones móviles celulares lentas en muelles de carga. |

---

## 4. Bloques Funcionales (Complejidad de Desarrollo)
El cronograma de desarrollo del módulo de logística se divide de la siguiente manera:

*   **Solicitud y Pantallas de Aprobación (Complejidad: Baja):** Pantallas para registrar solicitudes de traslado y notificaciones inter-sucursal asíncronas vía HTMX. (*RF-01*)
*   **Mapeo de Documentación Legal y Guías (Complejidad: Media):** Integración técnica para generar Guías de Remisión firmadas digitalmente y catalogadas en base de datos. (*RF-04, RNF-03*)
*   **Motor Transaccional de Stock en Tránsito (Complejidad: Alta):** Lógica robusta del lado del servidor que gestiona transacciones ACID concurrentes en base de datos y flujos lógicos con doble control digital. (*RF-02, RF-03, RNF-01, RNF-02*)

---

## 5. Diseño de Base de Datos (Modelo Relacional 3FN)
Esquema de base de datos relacional para el control logístico inter-sucursales:

### Tabla: TRANSFERENCIA_SUCURSAL
Cabecera que orquesta el traslado interestatal de mercancías.
*   `id_transferencia` (INT, PK, AUTO_INCREMENT): Identificador único de la transferencia.
*   `id_almacen_origen` (INT, FK -> ALMACEN.id_almacen): Bodega remitente de mercancía.
*   `id_almacen_destino` (INT, FK -> ALMACEN.id_almacen): Bodega destinataria de mercancía.
*   `fecha_envio` (DATETIME): Fecha y hora de salida del camión de transporte.
*   `fecha_recepcion` (DATETIME, NULL): Fecha y hora de entrada física verificada en destino.
*   `estado` (VARCHAR(30)): Estado actual del traslado (`'solicitada'`, `'en_transito'`, `'recibida'`, `'cancelada'`).
*   `id_usuario_envia` (INT, FK -> USUARIO.id_usuario): Bodeguero de origen responsable del despacho.
*   `id_usuario_recibe` (INT, FK -> USUARIO.id_usuario, NULL): Bodeguero de destino que firma de conformidad en muelle.

### Tabla: DETALLE_TRANSFERENCIA
Desglose físico de mercancías en trayecto.
*   `id_detalle_transferencia` (INT, PK, AUTO_INCREMENT): Identificador de línea de detalle.
*   `id_transferencia` (INT, FK -> TRANSFERENCIA_SUCURSAL.id_transferencia): Transferencia principal.
*   `id_producto` (INT, FK -> PRODUCTO.id_producto): Producto trasladado.
*   `cantidad` (INT): Cantidad física de unidades despachadas.

### Tabla: DOCUMENTO_NORMATIVO
Bitácora de documentos legales asociados al transporte de mercancía.
*   `id_documento` (INT, PK, AUTO_INCREMENT): Identificador único del documento legal.
*   `id_sucursal` (INT, FK -> SUCURSAL.id_sucursal): Sucursal que emite o archiva la documentación fiscal.
*   `tipo_documento` (VARCHAR(50)): Tipo de documento tributario (ej. `'guia_remision'`, `'certificado_seguridad'`, `'licencia_ambiental'`).
*   `numero_documento` (VARCHAR(100), UNIQUE): Número oficial del comprobante emitido por hacienda.
*   `fecha_emision` (DATE): Fecha de emisión fiscal.
*   `fecha_vencimiento` (DATE, NULL): Fecha de vencimiento legal (aplica solo para permisos temporales).
*   `ruta_archivo` (VARCHAR(255)): Ruta física segura en el disco del servidor para el archivo PDF escaneado.

### 5.1 Consideraciones de Diseño Clave
*   **Transaccionalidad en Tránsito:** Al confirmarse el despacho de la transferencia, el stock disminuye de `id_almacen_origen` y se suma de forma atómica a una ubicación de tránsito asignada al sistema en la tabla `INVENTARIO_STOCK`. Esto impide que se pierda la trazabilidad o que el stock quede "flotando" de forma huérfana en el sistema consolidado.
*   **Restricciones de Solo Inserción:** La tabla de documentos normativos e historial de transferencias se rige bajo la política de **solo inserción** (*append-only*). Queda estrictamente deshabilitada cualquier sentencia de UPDATE o DELETE que afecte a registros ya confirmados.

---

## 6. Ciclo de Vida del Traslado entre Sucursales
Las transferencias inter-sucursal siguen rigurosamente la siguiente secuencia de estados lógicos:

```
    [ Solicitada ] ──(Aprobación de Origen)──> [ En Tránsito ]
                                                    │
                                         (Verificación en Destino)
                                                    ▼
    [ Cancelada ] <──(Desviaciones críticas)── [ Recibida ]
```

1.  **Solicitada:** Sucursal de destino registra la petición de materiales en catálogo por desabastecimiento. Muestra alertas de solicitud asíncronas en el panel de la tienda proveedora origen.
2.  **En Tránsito:** El muelle de origen verifica existencias, carga camiones de logística, y genera asíncronamente la Guía de Remisión digital. Se descuentan las unidades de stock activo y se envían a Almacén Virtual de Tránsito.
3.  **Recibida:** El camión de logística llega a destino. El bodeguero receptor verifica que las unidades concuerden físicamente de forma satisfactoria en muelle de descarga, cerrando la transferencia.
4.  **Cancelada:** Operación de anulación registrada por retrasos de despacho críticos o decisiones administrativas de la gerencia antes de la salida física en carretera de los materiales.

---

## 7. Arquitectura del Módulo (Bulma + HTMX)
Estructura física de archivos para la implementación técnica de logística:

1.  `logistica_v.php` (Vista Partial): Pantalla responsiva de control logístico en Bulma CSS que detalla los traslados activos. Emplea el atributo `hx-post="logistica_c.php?action=approve_transfer"` para emitir aprobaciones y actualizar el estado físico del camión de forma instantánea mediante HTMX.
2.  `logistica_c.php` (Controlador): Enruta peticiones de traslado AJAX. Procesa registros de camiones y actualiza dinámicamente las listas de existencias en tránsito en el DOM.
3.  `logistica_l.php` (Lógica de Negocio): Contiene las reglas del negocio de transporte de mercancía. Valida la atomicidad de stock en tránsito, la duplicidad de guías, y las identidades del bodeguero mediante sesiones del servidor.
4.  `logistica_d.php` (Datos / Acceso SQL): Consultas SQL PDO seguras contra inyección de código: `SELECT * FROM TRANSFERENCIA_SUCURSAL WHERE estado = 'en_transito'`.
5.  `logistica_m.php` (Mantenimiento): Scripts lógicos encargados de alertar desviaciones de tiempo de entrega estimadas en carretera de muelle a muelle.

---

## 8. Puntos Pendientes de Definición
Antes de iniciar formalmente el desarrollo de software de transporte la próxima semana, se requiere definir:
1.  **Control de Desviaciones de Stock en Carretera:** Establecer el procedimiento administrativo del sistema cuando las unidades realmente recibidas en destino sean menores a las despachadas originalmente en origen (mermas físicas de trayecto).
2.  **Mecanismo de Firma en Muelle:** Confirmar si el doble sign-off digital se resolverá mediante escaneo obligatorio de códigos de barras del carnet de identidad o por ingreso de código PIN confidencial por bodeguero.
3.  **Rutas de Transporte:** Determinar si el sistema de logística de la ferretería debe integrarse con alguna API de GPS externa de terceros para el monitoreo de los camiones de reparto inter-sucursal.
