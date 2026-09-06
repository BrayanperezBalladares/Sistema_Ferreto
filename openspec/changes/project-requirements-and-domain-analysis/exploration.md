# Exploración: requisitos y análisis de dominio del proyecto

Análisis greenfield basado exclusivamente en la documentación disponible. No se tomó ninguna decisión de arquitectura adicional ni se evaluó código porque no existe una implementación en el espacio de trabajo.

## Inventario documental

### Documentos relevantes revisados

| Documento | Alcance revisado |
|---|---|
| `especificacion_requerimientos_sistema_ferretero.md` | SRS general: propósito, RF/RNF transversales, módulos, modelo OLTP, ciclos de vida, restricciones técnicas y pendientes (§1–8, líneas 9–154). |
| `mod_cuentas_accesos.md` | Identidad, roles, sesiones, usuarios y sucursales (§1–8, líneas 9–117). |
| `mod_ventas_pos.md` | POS, ventas, clientes, facturación, recomendación y operación offline (§1–8, líneas 9–134). |
| `mod_inventarios_catalogo.md` | Catálogo, almacenes, ubicaciones, stock, lotes y lectores (§1–8, líneas 9–149). |
| `mod_compras_proveedores.md` | Proveedores, reorden, órdenes de compra y recepción (§1–8, líneas 9–138). |
| `mod_logistica_transferencias.md` | Transferencias, tránsito, doble firma y guías de remisión (§1–8, líneas 9–123). |
| `mod_auditoria_mantenimiento.md` | Auditoría, respaldos y monitoreo (§1–8, líneas 9–106). |
| `mod_analitico_datawarehouse.md` | ETL, Data Warehouse dimensional y BI (§1–8, líneas 9–128). |

### Archivos revisados y excluidos como fuente de requisitos de dominio

| Archivo | Motivo de exclusión |
|---|---|
| `openspec/config.yaml` | Configuración del proceso SDD; confirma que no hay implementación y reproduce una pila propuesta, pero no es una fuente funcional de negocio (líneas 3–19). |
| `openspec/testing-capabilities.md` | Inventario de capacidades de prueba; confirma que no hay proyecto implementado ni pruebas, pero no define requisitos del producto (líneas 6–38). |
| `openspec/specs/.gitkeep`, `openspec/changes/.gitkeep`, `openspec/changes/archive/.gitkeep` | Marcadores de directorio sin contenido de requisitos. |
| `.atl/skill-registry.md` | Registro de habilidades del entorno, no documentación de producto. |

## 1. System Summary

- **REQUIREMENT:** El producto es un sistema integral para la cadena nacional «Ferreterías El Constructor», orientado a operar ventas e inventario multisucursal, abastecimiento, logística y analítica, para reducir desabastecimiento, descoordinación entre sucursales y obsolescencia. Fuente: `especificacion_requerimientos_sistema_ferretero.md` §1, líneas 9–19.
- **REQUIREMENT:** Debe combinar operación transaccional diaria OLTP con analítica OLAP/BI para optimizar la cadena de suministro y la toma de decisiones. Fuente: SRS §1, líneas 11–14; `mod_analitico_datawarehouse.md` §1, líneas 9–17.
- **INFERENCE:** El alcance inicial abarca siete módulos de negocio/transversales: accesos, inventario, ventas/POS, compras, logística, auditoría y analítica. La «facturación electrónica» aparece integrada en POS y «sucursales» dentro de logística, no como módulos independientes. Fuente: SRS §4, líneas 70–80.
- **REQUIREMENT:** No existe implementación ni proyecto de pruebas detectado; el repositorio es documental. Fuente: `openspec/testing-capabilities.md` §Projects y §Resolution, líneas 6–38.

## 2. Actors & Roles

- **REQUIREMENT:** Administrador: acceso completo, incluida auditoría global, respaldos, configuraciones fiscales y reportería estratégica. Fuente: `mod_cuentas_accesos.md` §2 RF-02, líneas 27–29.
- **REQUIREMENT:** Cajero: acceso limitado al POS, cobros, arqueos y asignación de clientes a ventas; su sesión expira tras 20 minutos de inactividad. Fuente: `mod_cuentas_accesos.md` §2 RF-02, líneas 27–29; §3 RNF-02, líneas 38–40.
- **REQUIREMENT:** Bodeguero: recepción de mercancía, muelle, ubicaciones, lotes y despacho/recepción de transferencias; debe estar vinculado a una sucursal activa. Fuente: `mod_cuentas_accesos.md` §2 RF-02–03, líneas 27–30.
- **REQUIREMENT:** Compras: gestión de proveedores, costos pactados y automatización/aprobación de órdenes de compra. Fuente: `mod_cuentas_accesos.md` §2 RF-02, líneas 27–29.
- **REQUIREMENT:** Cada operación crítica debe asociarse a una identidad digital verificada; ventas, ajustes, compras y traslados están citados explícitamente. Fuente: `mod_cuentas_accesos.md` §1, líneas 9–18.
- **INFERENCE:** Gerencia/Junta Directiva son actores de aprobación mencionados en los flujos de compras, aunque no figuran como roles RBAC configurados. Fuente: SRS §6.A, líneas 122–127; `mod_compras_proveedores.md` §8.3, líneas 134–138.
- **INFERENCE:** Cliente y proveedor son actores externos/de negocio registrados por el sistema, no usuarios internos autenticados. Fuente: `mod_ventas_pos.md` §2 RF-04, líneas 26–30; `mod_compras_proveedores.md` §2 RF-01, líneas 24–29.

## 3. Module Inventory

| Módulo | Hallazgos clasificados |
|---|---|
| Cuentas y accesos | **REQUIREMENT:** login, recuperación de contraseña, RBAC, vínculo usuario–sucursal y ciclo creado/activo/bloqueado/inactivo. Fuente: `mod_cuentas_accesos.md` §2 y §6, líneas 22–30 y 80–99. |
| Inventarios y catálogo | **REQUIREMENT:** catálogo nacional, stock por almacén/ubicación, puntos de reorden, lectores, lotes, alertas de vencimiento y FIFO para perecederos. Fuente: `mod_inventarios_catalogo.md` §2, §4 y §6, líneas 21–30, 45–51 y 112–130. |
| Ventas y POS | **REQUIREMENT:** venta, disminución de stock, sugerencias por compatibilidad, clientes/crédito y factura electrónica. Fuente: `mod_ventas_pos.md` §2, líneas 21–30. |
| Compras y proveedores | **REQUIREMENT:** proveedores, matriz producto–proveedor, reorden automático, órdenes y recepción física. Fuente: `mod_compras_proveedores.md` §2, líneas 21–30. |
| Logística y transferencias | **REQUIREMENT:** solicitud, tránsito controlado, doble validación y guía de remisión. Fuente: `mod_logistica_transferencias.md` §2, líneas 21–30. |
| Auditoría y mantenimiento | **REQUIREMENT:** bitácora inmutable, respaldos automatizados y monitoreo de rendimiento/inconsistencias. Fuente: `mod_auditoria_mantenimiento.md` §2, líneas 21–29. |
| Analítico/Data Warehouse | **REQUIREMENT:** ETL periódico, esquema estrella y dashboards de ventas, inventario y proveedores. Fuente: `mod_analitico_datawarehouse.md` §2, líneas 21–29. |

## 4. Module Dependency Map

- **REQUIREMENT:** Cuentas y accesos es transversal: identidad, rol y sucursal condicionan operaciones y auditoría. Fuente: `mod_cuentas_accesos.md` §1, líneas 9–18; SRS §4, líneas 74–80.
- **REQUIREMENT:** Inventario provee catálogo, existencias, ubicaciones, lotes y punto de reorden a POS, compras y logística. Fuente: SRS §2 RF-01, RF-02, RF-04, RF-06–08, líneas 36–44; §5, líneas 90–99.
- **REQUIREMENT:** POS depende de clientes, usuarios/sucursales, productos, compatibilidades e inventario. Fuente: `mod_ventas_pos.md` §5, líneas 57–97.
- **REQUIREMENT:** Compras depende de productos, almacenes, usuarios e inventario; la recepción incrementa existencias y recalcula la calificación del proveedor. Fuente: `mod_compras_proveedores.md` §5.1 y §6, líneas 94–119.
- **REQUIREMENT:** Logística depende de almacenes, productos, usuarios e inventario; mueve stock por un almacén virtual de tránsito y genera documentos normativos. Fuente: `mod_logistica_transferencias.md` §5–6, líneas 53–104.
- **REQUIREMENT:** Auditoría recibe acciones sensibles de todos los módulos y mantenimiento observa POS, índices y stock. Fuente: `mod_auditoria_mantenimiento.md` §2, líneas 21–29.
- **REQUIREMENT:** Analítica extrae ventas, compras y traslados desde OLTP hacia un servidor OLAP aislado. Fuente: `mod_analitico_datawarehouse.md` §2 RF-01 y §3 RNF-01, líneas 21–29 y 32–39.

```text
Cuentas/roles ─┬─> POS ───────────────┐
               ├─> Compras ───────────┤
               ├─> Logística ─────────┼─> Auditoría
               └─> Inventarios ───────┘
Inventarios ─────> POS / Compras / Logística
POS + Compras + Logística + Inventarios ──> ETL/BI
```

## 5. Business Rules

- **REQUIREMENT:** No se permite stock negativo; ventas, ajustes, recepciones y traslados se ejecutan en transacciones ACID. Fuente: `mod_inventarios_catalogo.md` §5.1, líneas 106–108; SRS §3 RNF-03, líneas 57–60.
- **REQUIREMENT:** Una venta pagada reduce stock atómicamente; una anulación, autorizada solo al Administrador, no elimina la venta y restituye el stock atómicamente. Fuente: `mod_ventas_pos.md` §5.1 y §6, líneas 95–115.
- **REQUIREMENT:** Las sugerencias POS se basan en relaciones explícitas de compatibilidad entre productos. Fuente: `mod_ventas_pos.md` §2 RF-03 y §5, líneas 26–30 y 88–93.
- **REQUIREMENT:** Cuando el stock cae bajo el punto de reorden se generan borradores de compra; los pedidos tienen los estados borrador, aprobada, recibida parcial, completada y cancelada. Fuente: SRS §2 RF-04 y §6.A, líneas 34–45 y 122–127; `mod_compras_proveedores.md` §6, líneas 100–119.
- **AMBIGUITY:** La prioridad para elegir proveedor del reorden es inconsistente: «mejor calificación» (SRS RF-04), «menor plazo o menor precio» (SRS §5.1) y «mejor calificación y menor costo» (Compras RF-03). No se definen desempates ni ponderaciones. Fuentes: SRS líneas 39 y 111–114; `mod_compras_proveedores.md` líneas 26–29.
- **REQUIREMENT:** Los lotes perecederos se despachan FIFO, alertan a 30 días de vencer y pasan a merma cuando caducan o quedan inutilizables. Fuente: `mod_inventarios_catalogo.md` §2 RF-05 y §6, líneas 26–30 y 112–130.
- **REQUIREMENT:** Una transferencia sigue solicitada → en tránsito → recibida o cancelada, requiere doble firma de bodega y guía de remisión; el tránsito se representa como stock en almacén virtual. Fuente: SRS §6.B, líneas 129–133; `mod_logistica_transferencias.md` §2 y §5.1, líneas 21–30 y 84–86.
- **REQUIREMENT:** Usuarios, sucursales con transacciones y proveedores no se borran físicamente; usuarios/sucursales usan baja lógica y proveedores se bloquean comercialmente. Fuente: `mod_cuentas_accesos.md` §5.1, líneas 74–76; `mod_compras_proveedores.md` §5.1, líneas 94–97.
- **AMBIGUITY:** Se exige control de «crédito» de clientes, pero no se define límite, aprobación, plazo, cobranza ni su relación con los estados de pago. Fuente: `mod_ventas_pos.md` §2 RF-04, líneas 26–30.

## 6. Primary Workflows

1. **REQUIREMENT: Venta POS.** Cajero autenticado registra cliente e ítems, recibe sugerencias, confirma pago, descuenta stock, timbra factura y envía comprobante; un Administrador puede anular y restituir stock. Fuente: `mod_ventas_pos.md` §6, líneas 101–115.
2. **REQUIREMENT: Reabastecimiento.** El reorden genera un borrador; se modifica antes de aprobarlo, se recibe total/parcialmente en muelle, se incrementa stock y se recalcula el Fill Rate. Fuente: `mod_compras_proveedores.md` §6, líneas 100–119; §3 RNF-01, líneas 33–40.
3. **REQUIREMENT: Gestión de lotes.** La recepción crea un lote ingresado, bodega lo habilita, se despacha FIFO y se gestiona alerta/merma. Fuente: `mod_inventarios_catalogo.md` §6, líneas 112–130.
4. **REQUIREMENT: Transferencia intersucursal.** Destino solicita, origen aprueba y despacha al tránsito con guía, destino verifica y firma la recepción. Fuente: `mod_logistica_transferencias.md` §6, líneas 90–104.
5. **REQUIREMENT: Auditoría y recuperación.** Acciones sensibles generan log append-only; respaldos incrementales horarios y completos diarios se validan y almacenan redundante/remotamente. Fuente: `mod_auditoria_mantenimiento.md` §2 y §6, líneas 21–29 y 73–87.
6. **REQUIREMENT: Analítica.** Proceso nocturno extrae, transforma y carga datos de ventas, compras y traslados; los dashboards leen el Data Warehouse, no el OLTP. Fuente: `mod_analitico_datawarehouse.md` §6, líneas 90–109; §3 RNF-01, líneas 32–39.

## 7. Explicit Data Model

- **REQUIREMENT:** El modelo OLTP explícito incluye `SUCURSAL`, `ALMACEN`, `UBICACION`, `CATEGORIA`, `PRODUCTO`, `INVENTARIO_STOCK`, `LOTE_PRODUCTO`, `COMPATIBILIDAD_PRODUCTO`, `CLIENTE`, `VENTA`, `DETALLE_VENTA`, `PROVEEDOR`, `PRODUCTO_PROVEEDOR`, `ORDEN_COMPRA`, `DETALLE_ORDEN_COMPRA`, `TRANSFERENCIA_SUCURSAL`, `DETALLE_TRANSFERENCIA`, `USUARIO`, `REGISTRO_ACCION_LOG` y `DOCUMENTO_NORMATIVO`. Fuente: SRS §5, líneas 84–109.
- **REQUIREMENT:** El catálogo documenta SKU y código de barras únicos, categoría, unidad de medida, precio base y condición de perecedero. Fuente: `mod_inventarios_catalogo.md` §5, líneas 58–72.
- **REQUIREMENT:** El modelo de ventas conserva cabecera, detalle, importe, impuestos, descuento y estado de pago; las líneas conservan precio unitario aplicado. Fuente: `mod_ventas_pos.md` §5, líneas 65–93.
- **REQUIREMENT:** El modelo de compras conserva proveedor, usuario responsable, fechas, estado, costo pactado, lead time, volumen mínimo, cantidades pedidas/recibidas y total. Fuente: `mod_compras_proveedores.md` §5, líneas 56–92.
- **REQUIREMENT:** La auditoría contiene usuario, tabla/registro afectado, tipo de acción, valores anterior/nuevo, fecha e IP de origen. Fuente: `mod_auditoria_mantenimiento.md` §5, líneas 55–69.
- **REQUIREMENT:** El modelo OLAP explícito incluye `HECHO_VENTAS`, `HECHO_INVENTARIO`, y dimensiones de producto, sucursal, cliente, tiempo y proveedor; se especifica SCD Tipo 2 para producto y sucursal. Fuente: `mod_analitico_datawarehouse.md` §5, líneas 52–86.

## 8. Inferred Data Relationships

- **INFERENCE:** La cardinalidad principal es sucursal 1–N almacén 1–N ubicación, y producto N–N proveedor mediante `PRODUCTO_PROVEEDOR`; ambas relaciones se deducen de las FK documentadas. Fuente: `mod_inventarios_catalogo.md` §5, líneas 63–104; `mod_compras_proveedores.md` §5, líneas 66–73.
- **INFERENCE:** `VENTA` 1–N `DETALLE_VENTA`, `ORDEN_COMPRA` 1–N `DETALLE_ORDEN_COMPRA` y `TRANSFERENCIA_SUCURSAL` 1–N `DETALLE_TRANSFERENCIA`. Fuente: SRS §5, líneas 98–106; módulos respectivos §5.
- **INFERENCE:** `COMPATIBILIDAD_PRODUCTO` es una autorrelación N–N dirigida de `PRODUCTO` hacia `PRODUCTO`. Fuente: SRS §5, líneas 94–96; `mod_ventas_pos.md` §5, líneas 88–93.
- **INFERENCE:** Los eventos de venta, recepción y transferencia deben afectar el stock y originar trazas de auditoría, pues son acciones sensibles y las reglas exigen transacciones atómicas. Fuente: SRS §3 RNF-03 y §2 RF-09, líneas 57–65 y 41–45.
- **AMBIGUITY:** No se documenta la relación entre `LOTE_PRODUCTO` y `INVENTARIO_STOCK`/ubicación; por tanto no puede verificarse qué lote abastece cada venta, transferencia, recepción o merma. Fuentes: SRS §5, líneas 96–99; `mod_inventarios_catalogo.md` §5, líneas 86–104.

## 9. Security & Authorization Requirements

- **REQUIREMENT:** Toda pantalla operativa exige sesión activa y verificada, con RBAC de permisos mutuamente excluyentes. Fuente: `mod_cuentas_accesos.md` §2 RF-01–02, líneas 25–29.
- **REQUIREMENT:** Contraseñas con bcrypt; se prohíbe texto plano y hashes reversibles/obsoletos. Fuente: `mod_cuentas_accesos.md` §3 RNF-01, líneas 36–40.
- **REQUIREMENT:** Entradas deben validarse en cliente y servidor, con filtros PHP y sentencias PDO preparadas frente a SQLi/XSS. Fuente: `mod_cuentas_accesos.md` §3 RNF-03, líneas 38–40.
- **REQUIREMENT:** Comunicaciones mediante HTTPS/TLS 1.3 y datos sensibles cifrados en reposo con AES-256. Fuente: SRS §3 RNF-06, líneas 61–63.
- **REQUIREMENT:** La bitácora es append-only; se deniegan `UPDATE` y `DELETE` por permisos de base de datos, incluso para administradores según el módulo de auditoría. Fuente: SRS §3 RNF-09, líneas 64–66; `mod_auditoria_mantenimiento.md` §5.1, líneas 67–69.
- **REQUIREMENT:** El bloqueo se activa tras más de cinco intentos fallidos en diez minutos y requiere desbloqueo administrativo. Fuente: `mod_cuentas_accesos.md` §6, líneas 80–99.
- **AMBIGUITY:** El SRS exige JWT, mientras que el módulo de accesos exige sesiones de servidor y ejemplifica `$_SESSION`; no se define un modelo único, la emisión/revocación ni la interacción entre ambos. Fuentes: SRS §3 RNF-05, líneas 60–62; `mod_cuentas_accesos.md` §3 RNF-02 y §7, líneas 36–40 y 102–109.

## 10. Non-Functional Requirements

- **REQUIREMENT:** POS p95 menor a 500 ms; búsquedas de catálogo menores a 300 ms; reportes BI menores a 1.5 s. Fuentes: SRS §3 RNF-01, líneas 55–58; `mod_inventarios_catalogo.md` §3 RNF-01, líneas 34–41; `mod_analitico_datawarehouse.md` §3 RNF-03, líneas 32–39.
- **REQUIREMENT:** OLTP debe alcanzar 99.9% de disponibilidad anual, RPO ≤ 1 hora y RTO ≤ 2 horas. Fuente: SRS §3 RNF-02 y RNF-08, líneas 57–65.
- **REQUIREMENT:** POS debe seguir operando offline con búfer local y sincronización automática, asíncrona e idempotente al recuperar WAN. Fuente: SRS §3 RNF-04, líneas 59–61; `mod_ventas_pos.md` §3 RNF-02, líneas 37–41.
- **REQUIREMENT:** Las interfaces POS y recepción deben ser operables íntegramente por teclado/hotkeys y escáner. Fuente: SRS §3 RNF-10, líneas 64–66.
- **REQUIREMENT:** OLAP/BI debe residir físicamente separado de OLTP y no consultar tablas operativas activas; el ETL nocturno debe mantener latencia inferior a 24 horas. Fuente: `mod_analitico_datawarehouse.md` §3, líneas 32–39.
- **REQUIREMENT:** Transferencias exigen aislamiento Serializable y PDFs de guías de menos de 100 KB. Fuente: `mod_logistica_transferencias.md` §3, líneas 33–40.
- **AMBIGUITY:** Se prescribe un stack «No-Build», PHP/PDO, HTMX, Bulma y portabilidad MariaDB/SQL Server, pero `openspec/config.yaml` lo rotula como «propuesto». Las SRS y los siete módulos lo presentan como estándar/elegido; debe aclararse si es una restricción aprobada o una propuesta. Fuentes: SRS §1, líneas 21–27; `mod_cuentas_accesos.md` §1, líneas 14–18; `openspec/config.yaml`, líneas 3–9.

## 11. Ambiguities / Contradictions

- **AMBIGUITY — duplicado coherente:** Los requisitos transversales de POS (<500 ms, offline-first, teclado/escáner), backups (horario/diario, RPO/RTO), auditoría append-only y ETL/BI aislado se repiten entre el SRS y sus módulos. Son duplicados consistentes, pero deben consolidarse para evitar divergencia. Fuentes: SRS §3, líneas 55–66; módulos POS §3, Auditoría §2–3, Analítico §2–3.
- **AMBIGUITY — contradicción:** `INVENTARIO_STOCK` se define por producto+almacén en el SRS, pero por producto+almacén+ubicación en Inventarios. Esto altera disponibilidad, bloqueo concurrente y reorden. Fuentes: SRS §5, líneas 96–97; `mod_inventarios_catalogo.md` §5, líneas 86–95.
- **AMBIGUITY — contradicción:** La selección automática de proveedor tiene criterios conflictivos/no ordenados. Fuentes: SRS RF-04, línea 39; SRS §5.1, líneas 111–114; Compras RF-03, líneas 26–29.
- **AMBIGUITY — contradicción:** JWT del SRS frente a sesión de servidor del módulo de accesos. Fuentes: SRS RNF-05, líneas 60–62; `mod_cuentas_accesos.md` §3 y §7, líneas 36–40 y 102–109.
- **AMBIGUITY — contradicción:** La aprobación de orden se atribuye a Gerencia en el SRS, a Compras en el ciclo del módulo, y el rol Compras incluye «automatización/aprobación»; no se define autoridad final ni umbrales. Fuentes: SRS §6.A, líneas 122–127; `mod_compras_proveedores.md` §6, líneas 100–119; `mod_cuentas_accesos.md` §2, líneas 27–29.
- **AMBIGUITY:** El SRS dice que la transferencia solicitada reserva stock en origen; Logística describe descuento hacia tránsito solo al despacho. Falta definir si reserva y stock disponible son saldos distintos y cómo se liberan. Fuentes: SRS §6.B, líneas 129–133; `mod_logistica_transferencias.md` §5.1 y §6, líneas 84–86 y 90–104.
- **AMBIGUITY:** El reorden se describe tanto como evaluación continua como proceso nocturno. No se define la frecuencia autorizada ni prevención de órdenes duplicadas. Fuentes: SRS RF-04 y §5.1, líneas 39 y 111–114; `mod_compras_proveedores.md` §2–3, líneas 26–40.
- **AMBIGUITY:** El SRS contempla operaciones SQL de auditoría INSERT/UPDATE; el módulo añade DELETE y AJUSTE_STOCK, a la vez que varias entidades prohíben borrado físico. Debe definirse qué eventos se auditan y cómo se registra una baja lógica. Fuentes: SRS RF-09, líneas 43–45; `mod_auditoria_mantenimiento.md` §5, líneas 55–65.

## 12. Missing Decisions

- **AMBIGUITY:** Debe definirse proveedor/API y normativa jurisdiccional de facturación electrónica. Fuente: SRS §8.1, líneas 148–154; `mod_ventas_pos.md` §8.1, líneas 130–134.
- **AMBIGUITY:** Debe decidirse el mecanismo del búfer POS offline; el SRS contrasta SQLite compilado a WebAssembly con LocalStorage. Fuente: SRS §8.2, líneas 148–154.
- **AMBIGUITY:** Deben confirmarse interfaz de lectores y necesidad de drivers, impresión térmica local y modo offline de bodega. Fuentes: SRS §8.3, líneas 148–154; `mod_ventas_pos.md` §8.2, líneas 130–134; `mod_inventarios_catalogo.md` §8.2, líneas 145–149.
- **AMBIGUITY:** Deben fijarse periodicidad ETL, retención analítica y eventual integración/exportación Power BI/Tableau. Fuente: `mod_analitico_datawarehouse.md` §8, líneas 124–128.
- **AMBIGUITY:** Deben decidirse 2FA, expiración de contraseñas de cajeros, usuarios temporales y política de IP sospechosa. Fuentes: `mod_cuentas_accesos.md` §8, líneas 113–117; `mod_auditoria_mantenimiento.md` §8, líneas 102–106.
- **AMBIGUITY:** Faltan límites de descuentos, crédito, tolerancia de recepción, presupuestos de órdenes, discrepancias de trayecto, mecanismo de doble firma, GPS y retención de logs. Fuentes: módulos POS §8, Compras §8, Logística §8, Auditoría §8.
- **RECOMMENDATION:** Antes de diseño, consolidar un glosario de estados, stock disponible/reservado/en tránsito y las fuentes normativas fiscales aplicables; son decisiones de dominio necesarias para que los requisitos se puedan verificar.

## 13. Technical Risks

- **RECOMMENDATION:** Tratar la sincronización offline con idempotencia, concurrencia y facturación fiscal como riesgo crítico hasta definir identificadores de transacción, conflicto y comportamiento de timbrado desconectado. La documentación exige los resultados, pero no esos mecanismos. Fuentes: SRS §3 RNF-04, líneas 59–61; `mod_ventas_pos.md` §3 RNF-02, líneas 37–41.
- **RECOMMENDATION:** Resolver el modelo de stock y reservas antes de diseñar bloqueos; las definiciones contradictorias pueden provocar sobreventa, doble disponibilidad o reorden erróneo. Fuentes: SRS §5, líneas 96–97; `mod_inventarios_catalogo.md` §5, líneas 86–95; SRS §6.B, líneas 129–133.
- **RECOMMENDATION:** Validar la viabilidad de las garantías de portabilidad simultánea MariaDB/SQL Server para JSON, bloqueos, Serializable, permisos append-only y mantenimiento de índices antes de fijar diseño físico. Fuentes: SRS §5.1, líneas 111–114; `mod_auditoria_mantenimiento.md` §5.1, líneas 67–69; `mod_logistica_transferencias.md` §3, líneas 33–40.
- **RECOMMENDATION:** Acordar retención, acceso y minimización de payloads de auditoría porque incluyen valores previos/nuevos, IP e identificaciones de clientes. Fuente: `mod_auditoria_mantenimiento.md` §5, líneas 55–69; §8.2, líneas 102–106.

## 14. Suggested implementation order

1. **RECOMMENDATION:** Resolver las preguntas bloqueantes y consolidar las contradicciones de dominio antes de cualquier diseño.
2. **RECOMMENDATION:** Establecer maestros y seguridad base: sucursales, usuarios/roles, catálogo, almacenes, ubicaciones y auditoría transversal.
3. **RECOMMENDATION:** Implementar el núcleo de inventario, lotes, ajustes y reglas de consistencia antes de flujos que alteren stock.
4. **RECOMMENDATION:** Incorporar compras/recepción y transferencias, porque ambos dependen del modelo de stock, identidad y trazabilidad.
5. **RECOMMENDATION:** Construir POS y facturación cuando inventario, clientes, permisos y estrategia offline/fiscal estén definidos.
6. **RECOMMENDATION:** Añadir backups, monitoreo y operaciones de mantenimiento con los componentes transaccionales reales.
7. **RECOMMENDATION:** Implementar ETL/Data Warehouse/BI al estabilizar el modelo OLTP y acordar la periodicidad y retención analíticas.

## 15. Questions that should be resolved before architecture/design

1. **AMBIGUITY:** ¿Cuál es el modelo canónico de inventario: por almacén o por ubicación, y cómo se representan saldos disponible, reservado, tránsito, lote y merma?
2. **AMBIGUITY:** ¿Cuál es la política ordenada y verificable para seleccionar proveedor y evitar órdenes automáticas duplicadas?
3. **AMBIGUITY:** ¿Quién puede aprobar/cancelar órdenes, transferencias, descuentos, ventas anuladas y crédito, bajo qué umbrales y con qué evidencia?
4. **AMBIGUITY:** ¿Se adopta JWT, sesiones de servidor o una combinación? ¿Cómo se gestionan expiración, revocación, 2FA y usuarios temporales?
5. **AMBIGUITY:** ¿Qué autoridad fiscal, proveedor/API y reglas operativas aplican a la factura electrónica y a guías de remisión, incluido el modo offline?
6. **AMBIGUITY:** ¿Qué mecanismo de sincronización offline se autoriza para POS y bodega, y cuáles son sus reglas de idempotencia, conflicto y recuperación?
7. **AMBIGUITY:** ¿Cuál es la periodicidad definitiva de reorden y ETL, y cuál será la política de retención de logs, respaldos y datos analíticos?
8. **AMBIGUITY:** ¿Qué dispositivos, impresión local, firma de muelle, integración B2B/GPS y almacenamiento remoto de respaldos forman parte del alcance confirmado?
