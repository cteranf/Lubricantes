# POS y caja básica

**Estado: base transaccional y permisos implementados; interfaz y registro de ventas pendientes.** Esta entrega prepara cajas, asignaciones, sesiones de efectivo y auditoría. No incluye carrito POS ni crea pedidos vendidos desde caja. La propuesta de venta de las secciones finales describe la siguiente fase, no una capacidad disponible.

## Decisiones aprobadas

- Cada sesión tiene un único cajero responsable; no hay sesiones compartidas.
- El permiso de cajero es una asignación explícita a una caja y es independiente de Tesorería. Tener rol `treasury` no otorga acceso POS por sí solo.
- Los borradores de la futura venta podrán cancelarse antes del cobro sin modificar inventario.
- Una venta cobrada será inmutable desde POS. Las devoluciones POS no se habilitarán hasta integrar y probar el efecto financiero, de inventario y caja con el flujo autorizado existente.
- El comprador ocasional no requiere usuario ficticio ni cuenta web. El correo será opcional. Documentos disponibles para el personal se descargan bajo autorización, nunca desde enlaces públicos.
- Se podrá cerrar con diferencia; se exige motivo si la diferencia no es cero.
- La primera venta será de pago completo en efectivo. Efectivo recibido, vuelto e ingreso neto son importes distintos; no se implementa todavía el cobro.
- Los pedidos identifican el canal `web` o `pos`. Emisión electrónica sigue independiente y conserva los bloqueos actuales.
- No se habilitan ingresos/retiros manuales de caja ni pagos digitales en esta fase.

## Implementado en esta fase

### Persistencia y compatibilidad

La migración [2026_11_25_080000_create_pos_cash_foundation.php](../database/migrations/2026_11_25_080000_create_pos_cash_foundation.php) agrega:

- `pos_registers`, vinculado a un almacén;
- `pos_cashier_assignments`, con actor, vigencia activa/revocada y unicidad por caja/usuario;
- `pos_cash_sessions`, con un responsable, fondo inicial, idempotencia de apertura/cierre y valores de cierre;
- `pos_register_open_sessions` y `pos_cashier_open_sessions`, guardas únicas para impedir sesiones simultáneas incompatibles;
- `pos_cash_movements` y `pos_audit_events`, registros append-only en la capa Eloquent;
- `orders.sales_channel` (por defecto `web`), vendedor, caja, sesión y snapshot de comprador ocasional opcionales.

Los pedidos anteriores permanecen en canal `web`; no se asigna comprador ficticio ni se reescriben pedidos. Los importes usan `DECIMAL(10,2)`, coherente con el dinero existente. El rollback elimina solo las columnas/tablas POS nuevas y preserva pedidos e importes existentes.

La inmutabilidad de movimientos e historial y el bloqueo de mutaciones Eloquent masivas usan modelos y un builder POS que rechaza `update`, `delete`, `forceDelete` y `truncate`; las sesiones cerradas también rechazan cambios/deleciones por instancia. El servicio de cierre persiste su transición bajo lock mediante una escritura SQL puntual dentro de la transacción. Esto protege las APIs y builders Eloquent de aplicación, no SQL directo, `DB::table`, `toBase()` ni credenciales de base con permisos de escritura; no existen triggers de inmutabilidad en esta migración.

### Autorización y operaciones

- Rutas de administración POS: `routes/api.php`, bajo `auth`, usuario activo e `is_admin`.
- Rutas de operación de cajero: requieren `pos_cashier`, middleware que comprueba asignación activa; usuario cliente o inactivo se rechaza.
- `PosRegisterService` crea/activa cajas y asigna/revoca cajeros. El almacén y sede deben estar activos; una caja con sesión abierta no puede desactivarse y no se revoca al responsable de una sesión abierta.
- `PosCashSessionService` abre y cierra dentro de transacciones, registra movimiento de fondo inicial, autor/fecha, caja esperada, efectivo contado, diferencia y motivo; las claves idempotentes devuelven el mismo resultado si la solicitud coincide y producen conflicto si difiere.
- El lock de caja serializa apertura, cierre y la primitiva futura `lockOpenSessionForSale()`. Esa primitiva requiere una transacción y bloquea caja antes de sesión; no equivale a venta POS completa.
- La API solo expone asignaciones, cajas, sesión actual, apertura/cierre y lectura administrativa de sesiones. No hay API de carrito, borrador de venta, cobro, vuelto ni venta.

## Hallazgo y corrección del orden de locks

El checkout web tomaba locks de producto antes de entrar al flujo de `InventoryService`, que tomaba almacenes y saldos, creando inversión frente a consumidores de inventario. Se centralizó la preparación de contexto en [InventoryService.php](../app/Services/InventoryService.php): almacenes ordenados, balances ordenados mediante `InventoryBalanceLockService`, y después productos; las líneas del checkout se reservan reutilizando ese contexto sin volver a entrar al orden de locks.

La carrera InnoDB de dos checkouts por la última unidad detectó además una gap lock real: las lecturas bloqueantes de `checkout_token` inexistentes podían bloquearse entre sí y formar un ciclo con la sede y la inserción. [OrderController.php](../app/Http/Controllers/Api/V1/OrderController.php) ahora hace lectura normal para una clave aún inexistente y bloquea por PK solo un pedido existente. La unicidad de `checkout_token` sigue siendo la autoridad contra duplicados, y el manejo de colisión consulta el pedido existente o rechaza la clave ajena.

La prueba de competencia usa dos peticiones HTTP reales en procesos/conexiones independientes. El primer proceso se pausa desde un listener de prueba tras adquirir locks del checkout, no con locks externos anteriores a la ruta; así se reproduce contención sin crear una inversión artificial.

## Próxima fase: venta en efectivo

El contrato posterior debe mantener `Order` como venta para compatibilidad con pagos, consultas, documentos e informes. Debe recalcular precio y promociones en backend y, al confirmar, realizar en una transacción única: pedido y líneas con snapshots, pago aprobado en efectivo por el neto de la venta, efectivo recibido y vuelto, movimiento neto de caja, descuento de inventario y un movimiento `SALE` idempotente por línea. Las reservas web se conservan: disponibilidad = físico menos reservado; una venta nunca debe consumir una reserva web.

El orden común será: **registro → sesión → borrador POS (cuando exista) → sedes/almacenes en orden → balances en orden → productos en orden → promoción/precio y escrituras**. Venta y cierre bloquearán primero la misma caja y sesión. La transacción debe incluir el pago, pedido, saldo, proyección `products.stock`, movimientos y estado del borrador. La misma clave y contenido retornará el resultado existente; una respuesta perdida se recuperará consultando esa operación antes de reintentar. La carrera venta/cierre todavía no se puede declarar probada porque la confirmación de venta aún no existe.

El cierre implementado suma el fondo inicial y los movimientos netos existentes. En la próxima fase cada venta tendrá `cash_received`, `change_given` y `net_amount = cash_received - change_given`; solo el neto entra en el efectivo esperado. No se registra un movimiento separado por vuelto.

Para comprador ocasional, la venta usará `user_id = NULL` y `pos_buyer_snapshot` validado. No se creará una cuenta web. El correo será opcional. Los PDF comerciales existentes podrán ser descargados por personal expresamente autorizado; no se debe agregar acceso anónimo ni URL pública persistente. La solicitud de boleta/factura y la emisión electrónica permanecen separadas, sujetas a las reglas y readiness actuales.

## Pruebas y estado comprobado

Cobertura de esta entrega:

- [PosCashFoundationTest.php](../tests/Feature/PosCashFoundationTest.php): permisos, asignaciones, apertura/cierre, diferencias, reintentos, inmutabilidad Eloquent y canal web por defecto.
- [MySqlPosCashSessionConcurrencyTest.php](../tests/Feature/MySqlPosCashSessionConcurrencyTest.php): apertura/apertura, cierre/cierre con esperas observadas en InnoDB y rollback/reaplicación conservando un pedido existente.
- [MySqlOrderCheckoutInventoryConcurrencyTest.php](../tests/Feature/MySqlOrderCheckoutInventoryConcurrencyTest.php): dos checkouts concurrentes por la última unidad y una única reserva.

En esta fase no se prueba venta POS/cierre porque no existe la ruta de confirmación POS. No se modificó inventario/pago por ventas POS ni se ejecutó migración en la base normal. Los resultados de la corrida actual se reportan en la entrega de implementación; este documento no certifica otras instalaciones.

## Referencias

- [Rutas API](../routes/api.php)
- [Servicio de caja](../app/Services/PosCashSessionService.php)
- [Servicio de cajas y asignaciones](../app/Services/PosRegisterService.php)
- [Middleware de asignación de cajero](../app/Http/Middleware/HasPosCashierAssignment.php)
- [Servicio de inventario y locks de checkout](../app/Services/InventoryService.php)
- [Locks de saldos](../app/Services/InventoryBalanceLockService.php)
- [Checkout web](../app/Http/Controllers/Api/V1/OrderController.php)
- [PDF comercial](../app/Services/CommercialOrderPdfService.php)
- [Índice documental](README.md)
