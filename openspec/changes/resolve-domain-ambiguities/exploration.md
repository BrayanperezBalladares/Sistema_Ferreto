# Análisis de decisiones de dominio no resueltas

## Exploración: resolve-domain-ambiguities

Este refinamiento se limita a las diez ambigüedades solicitadas. El espacio de trabajo es documental; no existe implementación que aporte una práctica operativa adicional. Las referencias citan documento, sección y líneas del archivo vigente.

## 1. Modelo canónico de inventario

### DOCUMENTED FACT

- El SRS define `INVENTARIO_STOCK` por producto y almacén, con `stock_actual`, mínimo y punto de reorden; `LOTE_PRODUCTO` solo contiene producto, fechas y cantidad. `especificacion_requerimientos_sistema_ferretero.md`, §5, líneas 90–99.
- Inventarios define el stock por producto, almacén y ubicación, y ubica físicamente cada saldo. `mod_inventarios_catalogo.md`, §2 RF-02, líneas 26–29; §5 `INVENTARIO_STOCK`, líneas 86–95.
- Los productos perecederos requieren lote, FIFO, alerta previa al vencimiento y traslado a merma virtual. `mod_inventarios_catalogo.md`, §2 RF-05, líneas 26–30; §6, líneas 112–130.
- El almacén pertenece a una sucursal y la ubicación pertenece a un almacén. `especificacion_requerimientos_sistema_ferretero.md`, §5, líneas 90–92; `mod_inventarios_catalogo.md`, §5, líneas 74–84.

### CONFLICT

El SRS hace autoritativo un saldo producto–almacén, mientras Inventarios lo hace producto–almacén–ubicación. Ninguno conecta formalmente lote con ubicación ni con los movimientos que consumen, reservan, trasladan o merman cantidad. Por ello no se puede demostrar qué unidades son vendibles, cuáles están comprometidas ni qué lote satisface FIFO.

### OPTION

1. Saldo autoritativo por producto–ubicación–lote cuando el producto requiera trazabilidad; el saldo por almacén es una agregación.
   - Consecuencia: permite asignar y auditar FIFO, merma, reserva y tránsito a unidades concretas.
   - Coste: exige definir si los productos no perecederos usan un lote operativo implícito o no usan lote.
2. Saldo autoritativo por producto–almacén; ubicación y lote se asignan en recepción o picking.
   - Consecuencia: simplifica el saldo central.
   - Coste: no garantiza que la reserva o venta corresponda a una ubicación y lote físicamente disponibles.
3. Saldo autoritativo por producto–ubicación sin asignación de lote en movimientos.
   - Consecuencia: conserva la localización física.
   - Coste: contradice el despacho FIFO exigido para perecederos y debilita la trazabilidad de merma.

### RECOMMENDATION

La evidencia permite recomendar que la ubicación sea la granularidad física y que los lotes trazables participen en los movimientos que los consumen. El saldo por almacén debe tratarse como agregación de consulta, no como sustituto de la ubicación.

### USER DECISION REQUIRED

Debe decidirse explícitamente si los productos no perecederos se controlan sin lote o mediante un lote operativo, y la definición canónica de: cantidad física, disponible, reservada, en tránsito y merma. Sin ello no es seguro fijar claves, restricciones de no sobreventa ni modelo de movimientos.

## 2. Reserva de stock y ciclo de transferencia

### DOCUMENTED FACT

- En `solicitada`, el SRS indica que el stock solicitado se reserva y bloquea en origen; en `recibida`, se incrementa destino y se libera la reserva. `especificacion_requerimientos_sistema_ferretero.md`, §6.B, líneas 129–133.
- Logística indica que la disminución de origen y el alta en tránsito ocurren al confirmar despacho. `mod_logistica_transferencias.md`, §5.1, líneas 84–86; §6, líneas 101–104.
- Las transferencias requieren doble control de bodegueros, guía normativa, historial inmutable y transacciones ACID Serializable. `mod_logistica_transferencias.md`, §2 RF-02–04, líneas 26–29; §3 RNF-01–02, líneas 38–40.

### CONFLICT

La reserva al solicitar y el descuento solo al despachar pueden coexistir, pero el modelo no define sus saldos ni transiciones. Tampoco define qué sucede al cancelar una solicitud reservada, al cancelar después del despacho, o ante faltantes al recibir. Esto afecta disponibilidad para POS y evita establecer una transacción correcta.

### OPTION

1. Reservar en `solicitada`; convertir reserva en tránsito en `en_transito`; acreditar disponible en destino en `recibida`; liberar o revertir según el estado de cancelación.
   - Consecuencia: conserva el requisito de bloqueo sin retirar físicamente el stock antes del despacho.
   - Coste: requiere reglas de expiración de reservas, cancelación y manejo de discrepancias.
2. No reservar; descontar solo en despacho y mover a tránsito.
   - Consecuencia: simplifica el flujo inicial.
   - Coste: contradice la reserva/bloqueo del SRS y permite que el stock solicitado se venda antes del despacho.
3. Descontar de origen al solicitar y registrarlo inmediatamente en tránsito.
   - Consecuencia: evita competencia posterior por el saldo.
   - Coste: representa como fuera de origen mercancía que aún no fue despachada.

### RECOMMENDATION

La opción 1 es la única interpretación que conserva simultáneamente la reserva del SRS y el movimiento a tránsito al despacho de Logística.

### USER DECISION REQUIRED

Se requieren reglas de negocio para expiración, autoridad de cancelación y tratamiento de una transferencia ya despachada o recibida parcialmente. Esas reglas no pueden inferirse de la documentación.

## 3. POS offline, sincronización, idempotencia y conflictos

### DOCUMENTED FACT

- El POS debe operar con búfer local offline-first y sincronización automática, asíncrona e idempotente al recuperar la WAN. `especificacion_requerimientos_sistema_ferretero.md`, §3 RNF-04, línea 60; `mod_ventas_pos.md`, §3 RNF-02, líneas 37–41.
- El SRS deja pendiente elegir SQLite compilado a WebAssembly o LocalStorage. `especificacion_requerimientos_sistema_ferretero.md`, §8, líneas 148–154.
- POS descuenta stock al pago y exige evitar sobreventas mediante bloqueo central; Inventarios también exige que nunca haya stock negativo. `mod_ventas_pos.md`, §5.1, líneas 95–97; `mod_inventarios_catalogo.md`, §5.1, líneas 106–108.
- El modo offline de bodega es una decisión pendiente, no un requisito confirmado. `mod_inventarios_catalogo.md`, §8, líneas 145–149.

### CONFLICT

La idempotencia está exigida, pero no se define la identidad estable de una operación, el orden de reintento, el límite de operación desconectada, el propietario de la resolución ni qué ocurre cuando una venta offline compite con una venta, reserva o ajuste central. El bloqueo central documentado no puede aplicarse mientras la caja está desconectada.

### OPTION

1. POS offline con cola durable, identificador global estable por operación y reconciliación manual cuando el servidor rechace la operación.
   - Consecuencia: prioriza trazabilidad y evita aceptar automáticamente un conflicto no resuelto.
   - Coste: puede detener el cierre definitivo de una venta al sincronizar.
2. POS offline con cuota local de disponibilidad o reservas preasignadas por terminal.
   - Consecuencia: limita conflictos de inventario durante la desconexión.
   - Coste: requiere política de distribución, expiración y reasignación de cuota que no está documentada.
3. POS y bodega offline con una cola común.
   - Consecuencia: maximiza continuidad operativa.
   - Coste: amplía los conflictos a recepciones, lotes y transferencias; el alcance offline de bodega no está aprobado.

### RECOMMENDATION

No hay evidencia suficiente para recomendar almacenamiento local, política de conflicto o alcance offline de bodega.

### USER DECISION REQUIRED

Debe aprobarse el almacenamiento local, el identificador idempotente, límite de operación desconectada, orden/reintento, política de conflicto y responsable de reconciliación. No se debe asumir una política de aceptación automática por conveniencia técnica.

## 4. Facturación electrónica offline

### DOCUMENTED FACT

- La venta facturada requiere timbrado fiscal electrónico y la anulación cancela el comprobante fiscal. `mod_ventas_pos.md`, §6, líneas 101–115.
- El SRS exige facturas electrónicas y guías conforme a normativa vigente, pero deja pendiente proveedor o API de facturación. `especificacion_requerimientos_sistema_ferretero.md`, §2 RF-12, línea 47; §8, líneas 148–152.
- POS exige integrar la entidad tributaria correspondiente, pero el país, autoridad, proveedor, certificado, folios y contingencia no se identifican. `mod_ventas_pos.md`, §2 RF-05, línea 30; §8, líneas 130–134.

### CONFLICT

No hay contradicción textual; hay una ausencia crítica: el requisito de facturar offline no define si se permite emitir, reservar folio, generar comprobante de contingencia, diferir el timbrado o bloquear la operación. Cada alternativa cambia los estados de venta, la sincronización y el cumplimiento legal.

### OPTION

1. Permitir venta offline con documento de contingencia y timbrado posterior.
   - Consecuencia: mantiene continuidad de caja.
   - Coste: solo es válido si la jurisdicción y autoridad fiscal lo permiten.
2. Permitir cobro offline, pero bloquear la emisión fiscal hasta recuperar conexión.
   - Consecuencia: separa pago y timbrado.
   - Coste: debe ser legalmente admisible y definir entrega al cliente, vencimientos y anulación.
3. Impedir ventas que requieran factura fiscal cuando no exista conectividad.
   - Consecuencia: reduce riesgo regulatorio.
   - Coste: restringe el requisito de continuidad offline del POS.

### RECOMMENDATION

No procede una recomendación: la documentación no declara jurisdicción ni régimen de contingencia.

### USER DECISION REQUIRED

Debe identificarse jurisdicción, autoridad/proveedor fiscal y conducta autorizada para emisión, contingencia, reintento, numeración, cancelación y guías offline.

## 5. JWT frente a sesiones de servidor

### DOCUMENTED FACT

- El SRS exige JWT para validar sesiones. `especificacion_requerimientos_sistema_ferretero.md`, §3 RNF-05, línea 61.
- Accesos exige sesiones de servidor con expiración; para cajeros, 20 minutos de inactividad. `mod_cuentas_accesos.md`, §3 RNF-02, línea 39.
- El diseño de Accesos usa `$_SESSION`; la baja de un usuario invalida tokens y accesos. `mod_cuentas_accesos.md`, §7, líneas 102–109; §6, líneas 95–99.

### CONFLICT

JWT autosuficiente y sesión de servidor no son el mismo mecanismo de revocación, expiración ni almacenamiento. El texto no define si “tokens” son identificadores de sesión, JWT o ambos, ni si existen integraciones externas que justifiquen dos modelos.

### OPTION

1. Sesiones de servidor revocables para toda interfaz y API.
   - Consecuencia: coincide con la implementación descrita para PHP/HTMX y facilita revocación inmediata.
   - Coste: no satisface literalmente el JWT del SRS sin aclaración o cambio.
2. JWT de corta duración con revocación central.
   - Consecuencia: satisface literalmente el SRS.
   - Coste: requiere definir renovación, lista de revocación y protección de tokens.
3. Sesión de servidor para la interfaz y JWT exclusivamente para integraciones declaradas.
   - Consecuencia: puede reconciliar ambos documentos.
   - Coste: presupone integraciones y obliga a delimitar emisor, audiencia y permisos.

### RECOMMENDATION

No hay evidencia de integraciones que permita elegir la opción 3 ni de una enmienda que descarte JWT. La contradicción requiere decisión explícita.

### USER DECISION REQUIRED

Debe elegirse un modelo único o una separación formal de audiencias, junto con revocación, expiración, renovación y el significado de “invalidar tokens”.

## 6. Autorización de compras, transferencias, descuentos, cancelaciones y crédito

### DOCUMENTED FACT

- Compras tiene “automatización/aprobación” de órdenes; el ciclo muestra aprobación por Compras y también validación por Gerencia. `mod_cuentas_accesos.md`, §2 RF-02, línea 28; `mod_compras_proveedores.md`, §6, líneas 100–119; SRS §6.A, líneas 122–127.
- El ciclo de transferencia menciona aprobación de origen y cancelación administrativa/gerencial, sin rol autorizador único. `mod_logistica_transferencias.md`, §6, líneas 90–104.
- La anulación de venta es exclusiva de Administrador. `mod_ventas_pos.md`, §6, líneas 109–115.
- El descuento existe como campo y cálculo, pero el porcentaje manual y aprobación superior quedan pendientes. `mod_ventas_pos.md`, §5, líneas 65–77; §8, líneas 130–134.
- “Gestión de Clientes y Crédito” solo exige datos de identificación y dirección; no define límite, plazo, cobranza ni aprobador. `mod_ventas_pos.md`, §2 RF-04, línea 29.

### CONFLICT

La aprobación de compras se asigna a Compras y a Gerencia. Para transferencias, descuentos y crédito faltan autoridad, umbral y evidencia. La anulación sí tiene autoridad definida, pero no una ventana temporal ni la interacción fiscal detallada. Sin una matriz de autorización, RBAC no es verificable.

### OPTION

1. Matriz central con permiso por acción, rol, umbral, sucursal y evidencia de aprobación.
   - Consecuencia: cubre de forma uniforme compras, transferencias, descuentos, cancelaciones y crédito.
   - Coste: el propietario debe suministrar valores y segregación de funciones.
2. Autoridad fija por módulo sin umbrales.
   - Consecuencia: menor operación administrativa.
   - Coste: no resuelve la contradicción Compras/Gerencia ni el control de exposición económica.
3. Autorización discrecional del Administrador.
   - Consecuencia: flexible ante excepciones.
   - Coste: no ofrece una regla repetible ni suficiente segregación de funciones.

### RECOMMENDATION

La evidencia solo permite mantener que la anulación corresponde a Administrador. No permite asignar las demás acciones ni fijar límites.

### USER DECISION REQUIRED

Debe aprobarse una matriz por acción con creador, modificador, aprobador, cancelador, límites monetarios/porcentuales, evidencia y relación del crédito con pago/factura.

## 7. Selección determinista de proveedor

### DOCUMENTED FACT

- El SRS dirige el borrador al proveedor histórico de mejor calificación. `especificacion_requerimientos_sistema_ferretero.md`, §2 RF-04, línea 39.
- El SRS también indica menor plazo de entrega o menor precio pactado. `especificacion_requerimientos_sistema_ferretero.md`, §5.1, líneas 111–114.
- Compras exige mejor calificación y menor costo; registra costo, lead time, volumen mínimo y fill rate. `mod_compras_proveedores.md`, §2 RF-02–03, líneas 26–29; §3 RNF-01, línea 38; §5, líneas 66–73.

### CONFLICT

Los criterios de calificación, costo y plazo no tienen orden, pesos, vigencia ni desempate. El operador “o” del SRS puede producir resultados diferentes para los mismos proveedores. Además, no se define deduplicación de borradores ni consolidación de líneas.

### OPTION

1. Ranking lexicográfico con orden de criterios y desempate estable.
   - Consecuencia: determinista y auditable.
   - Coste: el orden de prioridad sería una política de negocio que no está documentada.
2. Ranking ponderado y versionado.
   - Consecuencia: expresa un equilibrio comercial configurable.
   - Coste: requiere pesos, período de medición, revisión y explicación de cada resultado.
3. Borrador automático sin proveedor preasignado.
   - Consecuencia: evita una selección automática injustificada.
   - Coste: no cumple literalmente el direccionamiento automático requerido sin modificar el requisito.

### RECOMMENDATION

No hay evidencia suficiente para priorizar calificación, costo, plazo o volumen mínimo.

### USER DECISION REQUIRED

Debe aprobarse una política ordenada, vigencia de términos, desempates y deduplicación/consolidación de borradores. No es seguro transformar criterios inconexos en un algoritmo.

## 8. Frecuencia de evaluación de reorden

### DOCUMENTED FACT

- Inventarios y Compras describen evaluación continua cuando el disponible cae bajo el punto de reorden. `mod_inventarios_catalogo.md`, §2 RF-03, línea 28; `mod_compras_proveedores.md`, §2 RF-03, línea 28.
- Compras exige además un proceso nocturno en segundo plano. `mod_compras_proveedores.md`, §3 RNF-02, línea 39; §7, línea 130.
- El SRS permite un trigger o cron que monitoree continuamente. `especificacion_requerimientos_sistema_ferretero.md`, §5.1, líneas 111–114.

### CONFLICT

“Continua” y “nocturna” pueden ser detección y generación separadas, o dos cadencias incompatibles. No se especifica cuál crea borradores, en qué zona horaria, cómo se reintenta ni cómo se evita duplicar un caso ya atendido.

### OPTION

1. Lote nocturno único para detección y creación.
   - Consecuencia: control operativo predecible.
   - Coste: un quiebre detectado durante el día espera a la siguiente ventana.
2. Evaluación y creación continua.
   - Consecuencia: máxima rapidez de respuesta.
   - Coste: exige deduplicación y control de concurrencia más estrictos.
3. Alerta continua y creación nocturna de borradores.
   - Consecuencia: separa visibilidad inmediata de la acción automática.
   - Coste: requiere estados de alerta y conciliación con intervención manual.

### RECOMMENDATION

No se puede seleccionar una cadencia: los documentos no priorizan tiempo de respuesta frente a control operativo.

### USER DECISION REQUIRED

Debe decidirse la cadencia, zona horaria, ventana de corte, reintentos y relación entre alerta, intervención manual y creación de borrador.

## 9. Alcance y retención de eventos de auditoría

### DOCUMENTED FACT

- El SRS exige registrar cada evento del sistema con INSERT/UPDATE, identidad, IP y valores JSON. `especificacion_requerimientos_sistema_ferretero.md`, §2 RF-09, línea 44.
- Auditoría exige acciones sensibles, incluidos ajustes de inventario, precios, bajas lógicas y pedidos; su modelo añade DELETE y AJUSTE_STOCK. `mod_auditoria_mantenimiento.md`, §2 RF-01, línea 26; §5, líneas 55–65.
- Los logs son append-only y se deniegan UPDATE/DELETE. `especificacion_requerimientos_sistema_ferretero.md`, §3 RNF-09, línea 65; `mod_auditoria_mantenimiento.md`, §3 RNF-01, línea 37.
- La retención de logs es un punto pendiente; los respaldos se describen con ejemplo de más de seis meses, no como plazo normativo. `mod_auditoria_mantenimiento.md`, §6, líneas 73–87; §8, líneas 102–106.

### CONFLICT

El SRS promete “cada evento”, mientras el módulo delimita acciones sensibles y una taxonomía DML. No se define si autenticación, autorizaciones, sincronización offline, emisión/cancelación fiscal, cambios de estado y lecturas sensibles son auditables. Tampoco se determina retención, archivo, purga, acceso o minimización de datos personales.

### OPTION

1. Solo auditoría técnica DML con una retención global.
   - Consecuencia: alcance inicial reducido.
   - Coste: no expresa adecuadamente bajas lógicas, autorizaciones, sincronización ni hechos fiscales.
2. Catálogo de eventos de dominio y técnicos con retención por clase.
   - Consecuencia: permite trazabilidad de negocio y técnica.
   - Coste: necesita clasificación de datos, acceso y plazos aprobados.
3. Registrar todo evento observable sin clasificación.
   - Consecuencia: maximiza captura.
   - Coste: puede recolectar datos excesivos y no resuelve retención ni acceso.

### RECOMMENDATION

No corresponde elegir alcance ni retención. La inmutabilidad está documentada, pero el catálogo y los períodos son política de negocio, cumplimiento y privacidad no definida.

### USER DECISION REQUIRED

Debe aprobarse el catálogo mínimo de eventos, campos/payload permitidos, acceso, retención, archivo y eliminación conforme a la jurisdicción aplicable.

## 10. Carácter obligatorio de la pila y portabilidad

### DOCUMENTED FACT

- El SRS establece una pila “Sin Compilación” con PHP/PDO, HTMX, Bulma y soporte para MariaDB o SQL Server. `especificacion_requerimientos_sistema_ferretero.md`, §1, líneas 21–27.
- El SRS define además convenciones PHP/HTMX y reglas de portabilidad MariaDB/SQL Server. `especificacion_requerimientos_sistema_ferretero.md`, §5.1, líneas 111–114; §7, líneas 137–144.
- Los siete documentos modulares repiten la pila como enfoque elegido o seguido; por ejemplo, Accesos §1, líneas 14–18, POS §1, líneas 14–17, e Inventarios §1, líneas 14–17.
- `openspec/config.yaml` llama a la pila y arquitectura “proposed”, pero es configuración del proceso SDD y no requisito de producto. `openspec/config.yaml`, contexto, líneas 3–9.

### CONFLICT

La única discrepancia es de autoridad documental: el SRS y módulos establecen/reiteran la pila; la configuración SDD la resume como propuesta. No hay conflicto funcional entre los documentos de requisitos.

### OPTION

1. Tratar PHP/PDO, HTMX, Bulma, No-Build y portabilidad MariaDB/SQL Server como restricciones vigentes.
   - Consecuencia: conserva la evidencia primaria y condiciona diseño, SQL, despliegue y pruebas.
   - Coste: la portabilidad debe validarse en el diseño físico sin usar rasgos exclusivos sin alternativa.
2. Tratar toda la pila como propuesta abierta.
   - Consecuencia: permite rediscutir tecnologías.
   - Coste: contradice la redacción repetida de los requisitos y bloquea cualquier arquitectura hasta una nueva selección.

### RECOMMENDATION

La opción 1 está suficientemente respaldada por el SRS y los módulos. `openspec/config.yaml` no tiene autoridad para degradar un requisito repetido a propuesta.

## Matriz de decisiones

ID | Decision | Existing evidence | Options | Recommended option | Impact | Blocking? | User decision required?
--- | --- | --- | --- | --- | --- | --- | ---
DOM-001 | Modelo canónico de inventario | SRS §5 l. 90–99; Inventarios §2 l. 26–30 y §5 l. 86–104 | Saldo por ubicación/lote; por almacén; por ubicación sin lote | Ubicación física y lote trazable; faltan definiciones de saldo | Claves, movimientos, FIFO, merma, POS, reorden | Sí | Sí
DOM-002 | Reserva y transferencia | SRS §6.B l. 129–133; Logística §5.1 l. 84–86 | Reserva→tránsito→destino; sin reserva; tránsito al solicitar | Reserva→tránsito→destino | Disponibilidad, sobreventa, cancelación, auditoría | Sí | Sí
DOM-003 | POS offline y sincronización | SRS §3 l. 60 y §8 l. 148–154; POS §3 l. 37–41 | Cola durable; cuota local; POS+bodega offline | Ninguna | Consistencia, APIs, reconciliación, auditoría | Sí | Sí
DOM-004 | Facturación electrónica offline | SRS §2 l. 47 y §8 l. 148–152; POS §2 l. 30 y §6 l. 101–115 | Contingencia; cobro diferido; bloqueo fiscal | Ninguna | Estados de venta, cumplimiento, sincronización | Sí | Sí
DOM-005 | JWT o sesiones de servidor | SRS §3 l. 61; Accesos §3 l. 39 y §7 l. 102–109 | Sesión; JWT; separación por audiencia | Ninguna | Identidad, revocación, middleware | Sí | Sí
DOM-006 | Autorizaciones comerciales | Accesos §2 l. 28; Compras §6 l. 100–119; POS §6 l. 109–115 | Matriz con umbrales; fija; discrecional | Solo anulación por Administrador | RBAC, estados, crédito, auditoría | Sí | Sí
DOM-007 | Proveedor determinista | SRS §2 l. 39 y §5.1 l. 111–114; Compras §2 l. 26–29 | Lexicográfico; ponderado; sin proveedor | Ninguna | Reorden, deduplicación, auditoría | Sí | Sí
DOM-008 | Frecuencia de reorden | Inventarios §2 l. 28; Compras §3 l. 39; SRS §5.1 l. 111–114 | Nocturno; continuo; alerta+nocturno | Ninguna | Scheduler, carga, borradores duplicados | Sí | Sí
DOM-009 | Auditoría y retención | SRS §2 l. 44 y §3 l. 65; Auditoría §2 l. 26, §5 l. 55–69, §8 l. 102–106 | DML; híbrida por clase; todo sin clasificar | Ninguna | Esquema, privacidad, capacidad, cumplimiento | Sí | Sí
DOM-010 | Obligatoriedad de pila | SRS §1 l. 21–27, §5.1 l. 111–114; módulos §1; config l. 3–9 | Restricción vigente; propuesta abierta | Restricción vigente | Diseño técnico, SQL, despliegue | No | No

## Minimum decisions required before architecture

### Bloqueadores completos de arquitectura

- DOM-001: granularidad, relación lote–ubicación y definición de todos los saldos.
- DOM-002: reglas completas de reserva, despacho, recepción y cancelación de transferencias.
- DOM-003: contrato offline de POS, idempotencia y conflicto.
- DOM-004: jurisdicción y contingencia de facturación electrónica offline.
- DOM-005: modelo de autenticación, sesión y revocación.
- DOM-006: matriz de autorización para compras, transferencias, descuentos, cancelaciones y crédito.
- DOM-007: política determinista de selección de proveedor y deduplicación.
- DOM-008: cadencia y semántica de creación de reorden.
- DOM-009: alcance mínimo, protección y retención de auditoría.

### Decisión segura para una etapa posterior de diseño acotada

- DOM-010 no requiere decisión del usuario antes de arquitectura: la pila es una restricción documentada. La comprobación de portabilidad concreta entre MariaDB y SQL Server corresponde al diseño físico, sin cambiar esta restricción.

Mientras los bloqueadores completos permanezcan abiertos, no es seguro iniciar Proposal, Spec ni Design.
