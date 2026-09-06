# Reconciliación de requisitos con el enunciado oficial

## Resultado ejecutivo

El enunciado oficial R1–R10 es la autoridad de alcance. Los ocho documentos de equipo aportan cobertura funcional útil, pero también mezclan extensiones de negocio, supuestos regulatorios y mecanismos técnicos. Esta corrección vuelve atómica la trazabilidad: cada fila expresa un solo comportamiento, política o mecanismo y recibe una única clasificación.

**Criterio de clasificación.** `SUPPORTED` equivale de forma explícita a R1–R10. `DERIVED` es indispensable para que un requisito oficial sea verificable y muestra su cadena. `OPTIONAL` es una ampliación compatible y prescindible. `UNSUPPORTED` incorpora alcance no autorizado o contradice un resultado oficial. `CONFLICTING` expresa reglas documentadas incompatibles entre sí que impedirían una decisión única. `TECHNICAL DESIGN` es una elección de arquitectura, mecanismo, algoritmo, estructura o parámetro, no un requisito de negocio. La repetición en documentos de equipo no eleva autoridad.

## Autoridad oficial reconstruida

| Requisito oficial | Núcleo autorizado |
|---|---|
| R1 | Productos, categoría, precio, stock, ubicación de almacén y controles periódicos. |
| R2 | Ventas registradas, disponibilidad actualizada en tiempo real y recomendaciones por historial/compatibilidad. |
| R3 | Órdenes a proveedores automatizadas por nivel de stock y *lead time*; negociación basada en volúmenes históricos. |
| R4 | Reportes de stock, ventas, demanda proyectada, reabastecimiento y visualización/BI. |
| R5 | Controles, alerta de obsolescencia y escáner para actualizar inventario en tiempo real. |
| R6 | Registro de ajustes, ventas y órdenes; seguimiento de usuario y control de acceso a información sensible. |
| R7 | ETL de inventario, ventas y órdenes hacia un Data Warehouse. |
| R8 | Auditorías, respaldos, mejora de rendimiento y monitoreo/alerta de inconsistencias. |
| R9 | Administración central de sucursales/almacenes, visibilidad de stock/ventas y transferencias intersucursal. |
| R10 | Documentación de ventas y distribución conforme a la regulación aplicable, con validación y auditoría de datos. |

## Análisis por documento

| Documento | Cobertura oficial significativa | Adiciones que no pasan a línea base |
|---|---|---|
| `especificacion_requerimientos_sistema_ferretero.md` | Reproduce los dominios R1–R10. | Pila, modelo físico, SLAs, offline, criterios de proveedor, mecanismos de seguridad y fiscalización electrónica concreta. |
| `mod_cuentas_accesos.md` | Identidad, autorización y trazabilidad de usuario derivan de R6; el contexto de sucursal apoya R9. | Taxonomía RBAC, recuperación, bloqueo, bcrypt, sesiones y 2FA. |
| `mod_inventarios_catalogo.md` | Catálogo, ubicación/stock, reorden y escáner respaldan R1, R3 y R5. | SKU/EAN, granularidad de estante, lotes, vencimientos, FIFO, máximos y ACID. |
| `mod_ventas_pos.md` | Registro de ventas, stock inmediato, compatibilidad y atribución del cajero respaldan R2 y R6. | Clientes, crédito, pagos, descuentos, offline, impresión y facturación electrónica concreta. |
| `mod_compras_proveedores.md` | Proveedores, términos/*lead time*, reorden y recepción apoyan R3. | Calificación/costo como criterio de selección, *fill rate*, cadencia nocturna, B2B y aprobaciones monetarias. |
| `mod_logistica_transferencias.md` | Transferencias, confirmación, trazabilidad y documentación regulada apoyan R9, R6 y R10. | Almacén virtual, doble firma, Serializable, PDF, GPS y formato de firma. |
| `mod_auditoria_mantenimiento.md` | Registro de acciones, respaldos y monitoreo de inconsistencias/rendimiento reproducen R6 y R8. | Append-only, IP, JSON, frecuencia/RPO/RTO, cifrados y cron. |
| `mod_analitico_datawarehouse.md` | ETL y visualización de stock, ventas y demanda respaldan R4 y R7. | Estrella, SCD2, servidor separado, nocturnidad, SLA, métricas adicionales y herramienta BI. |

## Matriz obligatoria de trazabilidad atómica

| ID | Documento | Requisito detallado atómico | Clasificación | Autoridad | Evidencia exacta | Fundamentación | Impacto de alcance |
|---|---|---|---|---|---|---|---|
| SRS-01 | SRS | Gestionar producto, categoría, precio, cantidad de stock y ubicación de almacén. | SUPPORTED | R1 | §2 RF-01, l.36 | Equivale a R1. | Núcleo de catálogo/inventario. |
| SRS-02 | SRS | Usar SKU y código de barras como identificadores de producto. | OPTIONAL | R1/R5 | §2 RF-01, l.36 | R1/R5 no imponen identificadores concretos. | Elegible al especificar captura. |
| SRS-03 | SRS | Descontar existencias al consolidar una venta. | SUPPORTED | R2 | §2 RF-02, l.37 | Equivale a actualización en tiempo real. | Núcleo de ventas. |
| SRS-04 | SRS | Recomendar productos por compatibilidad e historial de ventas. | SUPPORTED | R2 | §2 RF-03, l.38 | Equivale a R2. | Núcleo de ventas. |
| SRS-05 | SRS | Generar pedidos automáticamente ante el nivel de reorden. | SUPPORTED | R3 | §2 RF-04, l.39 | R3 exige automatizar por stock. | Núcleo de compras. |
| SRS-06 | SRS | Seleccionar al proveedor de mejor calificación. | CONFLICTING | R3 | §2 RF-04, l.39; §5.1, l.113; Compras §2 RF-03, l.28 | Compite con menor plazo o costo; R3 no fija precedencia. | Decisión de negocio para automatizar. |
| SRS-07 | SRS | Conservar precios de compra y *lead time* por proveedor. | DERIVED | R3 | §2 RF-05, l.40 | R3 → automatizar por *lead time* y negociar términos exige conservarlos. | Datos mínimos de proveedores. |
| SRS-08 | SRS | Calcular o usar *fill rate* de proveedor. | OPTIONAL | R3 | §2 RF-05, l.40 | Métrica adicional, no condición oficial. | Puede diferirse. |
| SRS-09-ESC | SRS | Integrar escáner de códigos de barras en operación de inventario. | SUPPORTED | R5 | §2 RF-06, l.41 | R5 lo exige para actualización en tiempo real. | Integración de captura. |
| SRS-10 | SRS | Usar escáner en recepción, transferencias, ajustes, inventario y caja. | OPTIONAL | R5 | §2 RF-06, l.41 | R5 no enumera todos los flujos. | Priorizar por proceso. |
| SRS-11 | SRS | Controlar lotes, ingreso y vencimiento para alertas. | OPTIONAL | R5 | §2 RF-07, l.42 | Obsolescencia no exige lote ni vencimiento. | No bloquea R5. |
| SRS-12-TR | SRS | Definir estados y firmas para transferencias. | OPTIONAL | R9 | §2 RF-08, l.43 | R9 exige transferir, no estados o firmas concretos. | Definir sólo si el cambio lo requiere. |
| SRS-09 | SRS | Registrar ajustes, ventas y órdenes con usuario responsable. | SUPPORTED | R6 | §2 RF-09, l.44 | Equivale al registro y seguimiento de R6. | Auditoría mínima obligatoria. |
| SRS-09a | SRS | Hacer inmutable la bitácora. | OPTIONAL | R6 | §2 RF-09, l.44 | R6 exige registro, no append-only ni inmutabilidad absoluta. | Política de auditoría futura. |
| SRS-09b | SRS | Registrar dirección IP en la bitácora. | OPTIONAL | R6 | §2 RF-09, l.44 | Identificar usuario no exige IP. | Evaluar privacidad y necesidad. |
| SRS-09c | SRS | Registrar la operación SQL en la bitácora. | TECHNICAL DESIGN | NONE | §2 RF-09, l.44 | Expone un mecanismo interno de persistencia. | Diseño de observabilidad. |
| SRS-09d | SRS | Guardar estados antes/después de una acción. | OPTIONAL | R6 | §2 RF-09, l.44 | R6 no exige diffs de datos. | Política de alcance de auditoría. |
| SRS-09e | SRS | Serializar estados antes/después en JSON. | TECHNICAL DESIGN | NONE | §2 RF-09, l.44 | JSON es formato de implementación. | Diseño de datos. |
| SRS-19-ETL | SRS | Transferir datos mediante ETL al Data Warehouse. | SUPPORTED | R7 | §2 RF-10, l.45 | Equivale a R7. | DW obligatorio. |
| SRS-20 | SRS | Usar estrella o copo de nieve para el DW. | TECHNICAL DESIGN | NONE | §2 RF-10, l.45 | R7 no prescribe modelado. | Diseño de datos. |
| SRS-21 | SRS | Visualizar stock, ventas, demanda proyectada y reposición. | SUPPORTED | R4 | §2 RF-11, l.46 | Equivale a R4. | Reportes/BI mínimos. |
| SRS-22 | SRS | Visualizar margen y rotación de stock. | OPTIONAL | R4 | §2 RF-11, l.46 | Métricas compatibles no enumeradas en R4. | Priorización posterior. |
| SRS-12 | SRS | Gestionar documentación regulada de ventas y distribución. | SUPPORTED | R10 | §2 RF-12, l.47 | Equivale a R10. | Requiere identificar normativa aplicable. |
| SRS-12a | SRS | Emitir factura electrónica con validación/timbrado fiscal. | UNSUPPORTED | NONE | §2 RF-12, l.47; Ventas §2 RF-05, l.30 | R10 no prescribe factura electrónica, timbrado ni jurisdicción. | Fuera de línea base hasta norma aplicable. |
| SRS-25 | SRS | Cumplir p95 POS <500 ms. | OPTIONAL | NONE | §3 RNF-01, l.57 | Objetivo cuantificado compatible, no oficial. | No es aceptación inicial. |
| SRS-25a | SRS | Cumplir disponibilidad anual de 99.9%. | OPTIONAL | NONE | §3 RNF-02, l.58 | Objetivo cuantificado compatible, no oficial. | No es aceptación inicial. |
| SRS-26 | SRS | Exigir transacciones ACID. | TECHNICAL DESIGN | NONE | §3 RNF-03, l.59 | Es un mecanismo de persistencia. | Diseño transaccional. |
| SRS-26a | SRS | Usar bloqueo pesimista. | TECHNICAL DESIGN | NONE | §5.1, l.112 | Es una estrategia de concurrencia concreta. | Diseño transaccional. |
| SRS-27 | SRS | Mantener POS offline y sincronizar después. | CONFLICTING | R2 | §3 RNF-04, l.60 | La disponibilidad central diferida contradice actualización en tiempo real de R2. | Fuera de línea base. |
| SRS-16 | SRS | Controlar acceso a información sensible. | SUPPORTED | R6 | §3 RNF-05, l.61 | Equivale al resultado de R6. | Capacidad transversal. |
| SRS-16a | SRS | Usar una taxonomía RBAC concreta. | OPTIONAL | R6 | §3 RNF-05, l.61 | R6 no prescribe roles. | Política de autorización. |
| SRS-16b | SRS | Usar bcrypt para contraseñas. | TECHNICAL DESIGN | NONE | §3 RNF-05, l.61 | Algoritmo específico. | Diseño de seguridad. |
| SRS-16c | SRS | Usar JWT para sesiones. | TECHNICAL DESIGN | NONE | §3 RNF-05, l.61 | Mecanismo de sesión. | Diseño de seguridad. |
| SRS-32 | SRS | Usar TLS 1.3. | TECHNICAL DESIGN | NONE | §3 RNF-06, l.62 | Protocolo específico. | Diseño de seguridad. |
| SRS-32a | SRS | Usar AES-256. | TECHNICAL DESIGN | NONE | §3 RNF-06, l.62 | Algoritmo específico. | Diseño de seguridad. |
| SRS-33 | SRS | Aislar físicamente DW de OLTP. | OPTIONAL | R7 | §3 RNF-07, l.63 | R7 exige DW/ETL, no topología física. | Evaluar capacidad. |
| SRS-19 | SRS | Realizar respaldos. | SUPPORTED | R8 | §3 RNF-08, l.64 | Equivale a R8. | Operación obligatoria. |
| SRS-19a | SRS | Ejecutar respaldos incrementales horarios y completos diarios. | OPTIONAL | R8 | §3 RNF-08, l.64 | R8 no fija frecuencia ni tipo de copia. | Política operativa. |
| SRS-19b | SRS | Cumplir RPO ≤1 h y RTO ≤2 h. | OPTIONAL | R8 | §3 RNF-08, l.64 | Parámetros de servicio no oficiales. | Política operativa. |
| SRS-37 | SRS | Operar logs append-only. | OPTIONAL | R6 | §3 RNF-09, l.65 | Reitera una política no exigida por R6. | Política de auditoría. |
| SRS-38 | SRS | Operar interfaces sólo con teclado. | OPTIONAL | NONE | §3 RNF-10, l.66 | Mejora de UX sin autoridad oficial. | Mejora diferible. |
| SRS-39 | SRS | Usar PHP/PDO/HTMX/Bulma, MariaDB/SQL Server, No-Build, 3FN y cinco archivos. | TECHNICAL DESIGN | NONE | §1, l.21–27; §5–7, l.84–144 | Todas son decisiones de implementación. | Proposal/Design, no línea base. |
| ACC-01 | Accesos | Identificar al usuario antes de permitir operaciones sensibles. | DERIVED | R6 | §2 RF-01, l.27 | R6 → sin identidad no hay seguimiento ni control. | Capacidad base. |
| ACC-02 | Accesos | Recuperar contraseña. | OPTIONAL | R6 | §2 RF-01, l.27 | No es consecuencia necesaria de R6. | Política de soporte. |
| ACC-03 | Accesos | Limitar una cuenta a una sucursal. | OPTIONAL | R9 | §2 RF-03, l.29 | R9 exige operación multisucursal, no asignación única. | Política de operación. |
| ACC-04 | Accesos | Definir cuatro roles fijos y excluyentes. | OPTIONAL | R6 | §2 RF-02, l.28 | R6 no fija taxonomía ni exclusividad. | Política de autorización. |
| ACC-05 | Accesos | Usar bcrypt para contraseñas. | TECHNICAL DESIGN | NONE | §3 RNF-01, l.38 | Algoritmo específico. | Diseño de seguridad. |
| ACC-05a | Accesos | Usar sesión de servidor con expiración de 20 minutos. | TECHNICAL DESIGN | NONE | §3 RNF-02, l.39 | Mecanismo y parámetro concretos. | Diseño de seguridad. |
| ACC-05b | Accesos | Sanitizar con PHP/HTML y sentencias PDO. | TECHNICAL DESIGN | NONE | §3 RNF-03, l.40 | Mecanismos concretos de validación. | Diseño de seguridad. |
| ACC-06 | Accesos | Usar baja lógica y bloquear tras cinco intentos. | OPTIONAL | R6 | §5.1, l.74–76; §6, l.95–98 | Reglas de ciclo y umbral no oficiales. | Política pendiente. |
| INV-01 | Inventarios | Mantener catálogo nacional de productos. | SUPPORTED | R1 | §2 RF-01, l.26 | R1 exige gestionar productos. | Núcleo catálogo. |
| INV-02 | Inventarios | Exigir SKU/EAN, unidades y descripción detallada. | OPTIONAL | R1 | §2 RF-01, l.26 | Campos adicionales no prescritos. | Definir ficha mínima. |
| INV-03 | Inventarios | Registrar stock por almacén/ubicación. | SUPPORTED | R1/R9 | §2 RF-02, l.27 | R1 exige ubicación; R9 exige almacenes/sucursales. | Núcleo inventario multisucursal. |
| INV-04 | Inventarios | Modelar stock con entidad intermedia por ubicación. | TECHNICAL DESIGN | NONE | §2 RF-02, l.27 | Estructura de datos concreta. | Diseño de datos. |
| INV-05 | Inventarios | Alertar/generar abastecimiento por punto de reorden. | DERIVED | R3 | §2 RF-03, l.28 | R3 → automatizar por stock requiere una regla/umbral equivalente. | Regla mínima. |
| INV-06 | Inventarios | Capturar códigos con lectores en picking, POS y auditoría. | OPTIONAL | R5 | §2 RF-04, l.29 | R5 exige escáner, no flujos exhaustivos. | Priorizar integración. |
| INV-07 | Inventarios | Gestionar productos perecederos por lote. | OPTIONAL | R5 | §2 RF-05, l.30 | Es una forma de tratar obsolescencia, no una necesidad. | No bloquear R5. |
| INV-07a | Inventarios | Alertar 30 días antes del vencimiento. | OPTIONAL | R5 | §2 RF-05, l.30 | Umbral y vencimiento no están prescritos. | Política diferible. |
| INV-07b | Inventarios | Despachar con FIFO. | OPTIONAL | R5 | §4, l.50 | Política de despacho no oficial. | Política diferible. |
| INV-08 | Inventarios | Prohibir stock negativo. | OPTIONAL | R1 | §5.1, l.106–108 | Invariante adicional, no oficial. | Definir si el cambio lo necesita. |
| INV-08a | Inventarios | Definir stock máximo por ubicación. | OPTIONAL | R1 | §5, l.92–95 | Parámetro operativo adicional. | Definir si el cambio lo necesita. |
| INV-09 | Inventarios | Usar transacciones ACID. | TECHNICAL DESIGN | NONE | §3 RNF-02, l.40 | Mecanismo de persistencia. | Diseño. |
| INV-09a | Inventarios | Generar SKU automáticamente. | TECHNICAL DESIGN | NONE | §5.1, l.107–108 | Algoritmo de identificación concreto. | Diseño. |
| VEN-01 | Ventas | Registrar venta con ítems y cantidades. | SUPPORTED | R2 | §2 RF-01, l.26 | Equivale a registrar ventas. | Núcleo. |
| VEN-02 | Ventas | Asociar la venta a sucursal. | DERIVED | R9 | §2 RF-01, l.26 | R9 → visibilidad de ventas por sucursal exige su contexto. | Datos mínimos multisucursal. |
| VEN-03 | Ventas | Asociar la venta al cajero/usuario. | SUPPORTED | R6 | §2 RF-01, l.26 | R6 exige rastrear usuario en ventas. | Auditoría mínima. |
| VEN-04 | Ventas | Actualizar stock inmediatamente al vender. | SUPPORTED | R2 | §2 RF-02, l.27 | Equivale a R2. | Núcleo. |
| VEN-05 | Ventas | Recomendar por una tabla de compatibilidades. | TECHNICAL DESIGN | NONE | §2 RF-03, l.28 | R2 exige resultado, no estructura de relaciones. | Diseño de recomendación. |
| VEN-06 | Ventas | Gestionar clientes y crédito. | OPTIONAL | R2 | §2 RF-04, l.29 | Política comercial adicional. | No imponer inicialmente. |
| VEN-06a | Ventas | Gestionar impuestos, pagos, descuentos y anulaciones. | OPTIONAL | R2/R10 | §2 RF-04, l.29; §5–6, l.57–115 | Reglas comerciales; norma puede exigir parte después. | No imponer inicialmente. |
| VEN-07 | Ventas | Emitir factura electrónica y firma fiscal nacional. | UNSUPPORTED | NONE | §2 RF-05, l.30 | Coincide con SRS-12a: R10 no fija formato ni país. | Fuera de línea base. |
| VEN-08 | Ventas | Usar offline-first. | CONFLICTING | R2 | §3 RNF-02, l.40 | Offline contradice actualización central en tiempo real. | Fuera de línea base. |
| VEN-08a | Ventas | Imprimir tickets POS. | OPTIONAL | NONE | §8, l.130–134 | Mejora operativa no oficial. | Diferible. |
| COM-01 | Compras | Registrar proveedores. | DERIVED | R3 | §2 RF-01, l.26 | R3 → gestionar órdenes requiere identificar contraparte. | Núcleo de compras. |
| COM-02 | Compras | Conservar costos pactados y *lead times*. | DERIVED | R3 | §2 RF-02, l.27 | R3 → negociación/términos y *lead time* requieren esos datos. | Datos mínimos. |
| COM-03 | Compras | Exigir volumen mínimo por proveedor. | OPTIONAL | R3 | §2 RF-02, l.27 | Parámetro comercial adicional. | Política de compras. |
| COM-04 | Compras | Generar automáticamente borrador al bajar del reorden. | SUPPORTED | R3 | §2 RF-03, l.28 | Equivale al automatismo de R3. | Núcleo. |
| COM-05 | Compras | Elegir proveedor por mejor calificación y menor costo. | CONFLICTING | R3 | §2 RF-03, l.28; SRS §2 RF-04, l.39; SRS §5.1, l.113 | Criterios múltiples sin precedencia y distintos entre documentos. | Requiere política antes de automatizar. |
| COM-06 | Compras | Registrar recepción y contrastar pedido contra recibido. | DERIVED | R3 | §2 RF-04, l.29 | R3 → el ciclo de orden requiere conocer cumplimiento/recepción. | Flujo mínimo. |
| COM-07 | Compras | Calcular *fill rate*. | OPTIONAL | R3 | §3 RNF-01, l.38 | Métrica adicional no oficial. | Política posterior. |
| COM-07a | Compras | Ejecutar el reorden de noche. | OPTIONAL | R3 | §3 RNF-02, l.39 | R3 exige automatizar, no cadencia. | Diseño operativo. |
| COM-07b | Compras | Usar ACID al recibir existencias. | TECHNICAL DESIGN | NONE | §3 RNF-03, l.40 | Mecanismo de persistencia. | Diseño. |
| COM-08 | Compras | Enviar órdenes por correo/PDF o API B2B. | OPTIONAL | NONE | §8, l.134–138 | Canales compatibles, no oficiales. | Política posterior. |
| COM-08a | Compras | Aprobar órdenes por umbral monetario. | OPTIONAL | NONE | §8, l.134–138 | Gobierno comercial no oficial. | Política posterior. |
| LOG-01 | Logística | Solicitar y ejecutar transferencias entre sucursales. | SUPPORTED | R9 | §2 RF-01, l.26 | Equivale a R9. | Núcleo multisucursal. |
| LOG-02 | Logística | Confirmar físicamente la recepción de una transferencia. | DERIVED | R9 | §2 RF-03, l.28 | R9 → una transferencia verificable necesita confirmación de destino. | Flujo mínimo. |
| LOG-03 | Logística | Registrar identidad y cambios de estado de transferencias. | DERIVED | R6/R9 | §3 RNF-01, l.38 | R6/R9 → trazabilidad de operación intersucursal requiere actor y evento. | Auditoría logística. |
| LOG-04 | Logística | Usar almacén virtual de tránsito. | OPTIONAL | R9 | §2 RF-02, l.27 | Es una forma compatible de controlar traslado. | Política/diseño de flujo. |
| LOG-04a | Logística | Exigir doble firma de bodegueros. | OPTIONAL | R6/R9 | §2 RF-03, l.28 | R6/R9 no exigen dos actores. | Política de control. |
| LOG-05 | Logística | Gestionar documentación regulada de distribución. | SUPPORTED | R10 | §2 RF-04, l.29 | Equivale a R10. | Requiere norma aplicable. |
| LOG-06 | Logística | Emitir guía de remisión fiscal concreta. | OPTIONAL | R10 | §2 RF-04, l.29 | El tipo de documento depende de jurisdicción. | Resolver con autoridad regulatoria. |
| LOG-07 | Logística | Usar aislamiento Serializable. | TECHNICAL DESIGN | NONE | §3 RNF-02, l.39 | Nivel de aislamiento concreto. | Diseño. |
| LOG-07a | Logística | Limitar la guía PDF a 100 KB. | OPTIONAL | NONE | §3 RNF-03, l.40 | Métrica de entrega no oficial. | Diferible. |
| LOG-07b | Logística | Usar append-only para historial de transferencias. | OPTIONAL | R6/R9 | §5.1, l.84–86 | Política adicional de retención/inmutabilidad. | Política posterior. |
| LOG-07c | Logística | Integrar GPS para rutas. | OPTIONAL | NONE | §8, l.119–123 | Capacidad logística adicional no oficial. | Diferible. |
| LOG-07d | Logística | Autenticar firma con PIN o carnet. | TECHNICAL DESIGN | NONE | §8, l.119–123 | Mecanismo concreto de firma. | Diseño. |
| AUD-01 | Auditoría | Registrar acciones sensibles con identidad de usuario. | SUPPORTED | R6 | §2 RF-01, l.26 | Equivale a R6. | Núcleo transversal. |
| AUD-02 | Auditoría | Guardar estados antes/después. | OPTIONAL | R6 | §2 RF-01, l.26 | R6 no exige diffs. | Política de auditoría. |
| AUD-03 | Auditoría | Usar append-only para logs. | OPTIONAL | R6 | §3 RNF-01, l.37 | R6 exige registro, no inmutabilidad absoluta. | Política de auditoría. |
| AUD-03a | Auditoría | Serializar el payload de auditoría en JSON. | TECHNICAL DESIGN | NONE | §2 RF-01, l.26; §5, l.55–69 | Formato de persistencia concreto. | Diseño de datos. |
| AUD-03b | Auditoría | Guardar IP de origen en auditoría. | OPTIONAL | R6 | §5, l.55–69 | R6 exige usuario, no IP. | Política de privacidad/auditoría. |
| AUD-04 | Auditoría | Ejecutar respaldos. | SUPPORTED | R8 | §2 RF-02, l.27 | Equivale a R8. | Operación obligatoria. |
| AUD-05 | Auditoría | Ejecutar respaldos horarios y diarios. | OPTIONAL | R8 | §2 RF-02, l.27 | R8 no fija frecuencia ni tipo de copia. | Política operativa. |
| AUD-05a | Auditoría | Cumplir RPO/RTO concretos. | OPTIONAL | R8 | §3 RNF-02, l.38 | Parámetros de servicio no oficiales. | Política operativa. |
| AUD-06 | Auditoría | Monitorear rendimiento e inconsistencias. | SUPPORTED | R8 | §2 RF-03, l.28 | Equivale a R8. | Operación obligatoria. |
| AUD-07 | Auditoría | Optimizar índices de base de datos. | TECHNICAL DESIGN | NONE | §4–7, l.46–98 | Índices son un mecanismo concreto de rendimiento. | Diseño operativo. |
| AUD-07a | Auditoría | Ejecutar mantenimiento mediante cron. | TECHNICAL DESIGN | NONE | §4–7, l.46–98 | Planificador concreto. | Diseño operativo. |
| AUD-07b | Auditoría | Usar TLS 1.3 para datos de auditoría. | TECHNICAL DESIGN | NONE | §3 RNF-03, l.39 | Protocolo específico. | Diseño de seguridad. |
| AUD-07c | Auditoría | Usar AES-256 para datos de auditoría. | TECHNICAL DESIGN | NONE | §3 RNF-03, l.39 | Algoritmo específico. | Diseño de seguridad. |
| ANA-01 | Analítica | Ejecutar ETL de inventario, ventas y órdenes. | SUPPORTED | R7 | §2 RF-01, l.26 | Equivale a R7. | Núcleo DW. |
| ANA-02 | Analítica | Incluir traslados en ETL. | OPTIONAL | R7 | §2 RF-01, l.26 | R7 no enumera traslados. | Extensión futura. |
| ANA-03 | Analítica | Usar hechos/dimensiones en estrella. | TECHNICAL DESIGN | NONE | §2 RF-02, l.27 | R7 no prescribe modelo dimensional. | Diseño de datos. |
| ANA-04 | Analítica | Visualizar stock, ventas y demanda proyectada. | SUPPORTED | R4 | §2 RF-03, l.28 | Equivale a R4. | Reportes/BI mínimos. |
| ANA-05 | Analítica | Visualizar margen, rotación y desempeño de proveedores. | OPTIONAL | R4 | §2 RF-03, l.28 | Métricas adicionales no enumeradas. | Priorización posterior. |
| ANA-06 | Analítica | Usar servidor físico separado para el DW. | TECHNICAL DESIGN | NONE | §3 RNF-01, l.37 | Topología concreta. | Diseño posterior. |
| ANA-06a | Analítica | Ejecutar ETL nocturno con latencia menor de 24 horas. | OPTIONAL | R7 | §3 RNF-02, l.38 | R7 exige ETL, no cadencia ni latencia. | Política operativa. |
| ANA-06b | Analítica | Usar SCD tipo 2. | TECHNICAL DESIGN | NONE | §5.1, l.84–86 | Patrón de modelado específico. | Diseño de datos. |
| ANA-06c | Analítica | Cargar dashboards en menos de 1.5 segundos. | OPTIONAL | R4 | §3 RNF-03, l.39 | SLA cuantificado no oficial. | Objetivo posterior. |

## Revisión de requisitos sospechosos

| Ítem | Clasificación confirmada | Filas de trazabilidad | Bucket de alcance | Motivo |
|---|---|---|---|---|
| POS offline / sincronización offline | CONFLICTING | SRS-27, VEN-08 | No respaldado | Diferen la actualización central exigida por R2. |
| Lotes, perecibles, vencimientos, FIFO | OPTIONAL | SRS-11, INV-07 | Opcional | Son tratamientos posibles, no equivalentes a alerta de obsolescencia. |
| RBAC fijo | OPTIONAL | SRS-16a, ACC-04 | Opcional | R6 exige control, no taxonomía. |
| bcrypt, JWT y sesiones de servidor | TECHNICAL DESIGN | SRS-16b, SRS-16c, ACC-05 | Diseño técnico | Son mecanismos alternativos, no alcance funcional. |
| Facturación electrónica / timbrado | UNSUPPORTED | SRS-12a, VEN-07 | No respaldado | R10 exige cumplimiento regulado, no formato electrónico ni país. |
| Guía de remisión concreta | OPTIONAL | LOG-06 | Opcional condicionado | El documento depende de jurisdicción y norma aplicable. |
| Frecuencia de respaldos y RPO/RTO | OPTIONAL | SRS-19a, SRS-19b, AUD-05 | Opcional | R8 exige respaldos, no parámetros. |
| Append-only, IP, JSON y SQL en bitácora | OPTIONAL / TECHNICAL DESIGN | SRS-09a–09e, AUD-02–03 | Opcional / diseño | La acción y el usuario son R6; formato, atributo y restricción no. |
| Selección automática de proveedor | SUPPORTED | SRS-05, COM-04 | Canónico | R3 exige automatización por stock y *lead time*. |
| Criterio de proveedor (calificación/costo/plazo) | CONFLICTING | SRS-06, COM-05 | Decisión requerida | Los documentos proponen reglas incompatibles sin precedencia. |
| Pila, motores, portabilidad y cinco archivos | TECHNICAL DESIGN | SRS-39 | Diseño técnico | Ninguno es requisito oficial. |
| GPS, B2B, 2FA, teclado, impresión | OPTIONAL | LOG-07c, COM-08, SRS-38, VEN-08a | Opcional | Mejoras compatibles que requieren aprobación separada. |

## Reconciliación de alcance

### Alcance canónico

R1–R10, tal como se reconstruyen en la tabla de autoridad, más estas derivaciones estrictas: identidad para acciones sensibles (R6), contexto de sucursal en ventas (R9), datos de proveedores y recepción de órdenes (R3), umbral/regla equivalente de reorden (R3), confirmación/actor de transferencia (R6/R9) y trazabilidad de carga ETL (R7). Ninguna derivación fija mecanismo, algoritmo, métrica o parámetro.

### Opcional

SKU/EAN, flujos concretos de escaneo, lotes/perecibles/vencimientos/FIFO, roles fijos, campos y políticas comerciales, almacenamiento en tránsito/doble firma, guía regulada concreta, respaldo con frecuencias y objetivos, métricas analíticas, BI externo, GPS, B2B, 2FA, teclado e impresión.

### No respaldado

POS offline con sincronización diferida, facturación electrónica/timbrado nacional antes de fijar jurisdicción, y cualquier requisito que afirme esos alcances como obligatorios. Los criterios de selección de proveedor no son no respaldados: son un conflicto de reglas documentadas que requiere decisión.

### Diseño técnico

PHP/PDO/HTMX/Bulma, motores y portabilidad, 3FN, modelo estrella, SCD2, ACID/bloqueos/Serializable, JSON, SQL en logs, append-only implementado con permisos, cron, topología OLTP/OLAP, algoritmos de cifrado y JWT o sesión de servidor. Se decidirán sólo en Proposal/Spec/Design cuando corresponda.

### Conflictos reales

1. **Proveedor:** SRS RF-04 privilegia calificación; SRS §5.1 incorpora menor plazo o precio; Compras RF-03 combina calificación y costo. No hay precedencia ni desempate, aunque R3 exige automatizar usando stock y *lead time*.
2. **Offline POS:** SRS/Ventas exigen facturación local y sincronización posterior; R2 exige actualizar disponibilidad en tiempo real. La línea base rechaza el modo offline hasta una autorización explícita que redefina ese resultado.

## Consistency check

- Cada ítem sospechoso cita filas atómicas y usa el mismo bucket de alcance que la matriz.
- Facturación electrónica/timbrado es `UNSUPPORTED` tanto en SRS-12a/VEN-07 como en la revisión y el alcance no respaldado; R10 sólo conserva documentación regulada genérica.
- Auditoría de acción/usuario es `SUPPORTED` (SRS-09/AUD-01); inmutabilidad, IP y estados antes/después son `OPTIONAL`; SQL/JSON son `TECHNICAL DESIGN`.
- Respaldos son `SUPPORTED` (SRS-19/AUD-04); frecuencia y RPO/RTO son `OPTIONAL` (SRS-19a–19b/AUD-05).
- Acceso y atribución son R6; roles fijos son `OPTIONAL` y bcrypt/JWT/sesiones son `TECHNICAL DESIGN` (SRS-16–16c).

## Resultado obligatorio final

### Recommended Canonical Baseline

Sistema integrado de inventario, ventas, órdenes a proveedores, sucursales/almacenes, transferencias, reportes/BI y DW/ETL; con controles de inventario y obsolescencia, escáner, registro de acciones con usuario, control de información sensible, respaldos, monitoreo y documentación regulada. El baseline no adopta proveedor “mejor calificado”, factura electrónica, parámetros de respaldo ni mecanismos técnicos.

### Minimum Decisions Required Before /sdd-new

**Ninguna para iniciar un cambio SDD estrictamente acotado a un requisito oficial no conflictivo.** Un cambio de reorden automático requiere definir precedencia/desempate entre *lead time*, precio, volumen histórico y desempeño. Un cambio R10 requiere jurisdicción, regulación y documentos aplicables. Un cambio que proponga offline POS exige autorización explícita porque contradice R2.

### READY FOR SDD-NEW: YES

La matriz corregida deja un baseline oficial verificable y separa los bloqueos por cambio. La preparación no autoriza fases posteriores ni adopta extensiones: sólo permite que el usuario revise y, si lo decide después, inicie un cambio acotado sobre alcance canónico.
