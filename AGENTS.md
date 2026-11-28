# Guía de trabajo para LubriStore

## Proyecto y fuentes

LubriStore es una aplicación Laravel 13 / PHP 8.5 con frontend Vue 3, Vite y PrimeVue. Composer y npm declaran las dependencias; consulta `composer.json`, `package.json`, `routes/`, `app/`, `resources/js/` y `database/migrations/` antes de asumir un contrato.

El mapa de documentación está en [`docs/README.md`](docs/README.md). Los documentos de fases y auditorías registran evidencia en una fecha o commit concretos: no certifican por sí mismos el estado actual de una base, una instalación desplegada o SUNAT. Para comportamiento vigente contrasta código, esquema, pruebas y configuración del entorno.

## Mapa funcional

- Catálogo, carrito, checkout, promociones y modelos: `app/Models/Product.php`, `app/Models/ProductPromotion.php`, `app/Services/ProductPricingService.php`, `app/Http/Controllers/Api/V1/OrderController.php` y vistas Vue de catálogo.
- Pedidos, estado operativo y seguimiento: `app/Models/Order.php`, `app/Support/OrderOperationalStatus.php`, `app/Services/OrderFulfillmentService.php`, `app/Services/OrderDeliveryService.php` y `app/Http/Controllers/Api/V1/OrderTrackingController.php`.
- Inventario y reservas: `app/Services/InventoryService.php`, `app/Services/InventoryBalanceLockService.php`, `app/Services/InventoryReservationExpirationService.php` y modelos `WarehouseInventory`, `InventoryReservation` e `InventoryMovement`.
- Pagos y Tesorería: `app/Services/PaymentProcessingService.php`, `app/Services/TreasuryPaymentApprovalService.php`, `app/Services/TreasuryPaymentRefundService.php`, `app/Support/TransferPaymentInstallments.php` y rutas `/api/v1/treasury`.
- Facturación: servicios `Electronic*`/`Sunat*`, modelos documentales, `config/electronic_invoicing.php`, vistas administrativas de Facturación y documentos de fases 1–5.
- Documentos comerciales PDF: `CommercialOrderPdfService` y sus rutas autenticadas. Son resúmenes comerciales, no comprobantes tributarios y no dependen de la emisión electrónica.

## Invariantes de negocio respaldadas por el código

### Precios, promociones y pedidos

- El servidor cotiza precios y vuelve a calcularlos al crear el pedido. La promoción vigente se aplica desde `ProductPricingService`; `sale_price` conserva una ruta de compatibilidad.
- Un cambio de precio requiere la confirmación prevista por checkout. Las líneas guardan precio regular, precio aplicado, cantidad, subtotal y referencia promocional cuando aplica; no reconstruyas importes históricos desde el catálogo actual.
- `orders.total` es el importe final del pedido. Los desgloses de ahorro informan la rebaja, no la descuentan otra vez.
- `checkout_token` protege la idempotencia de creación; conserva las verificaciones de propietario y las transacciones existentes.

### Inventario, reservas y estados

- Con inventario por almacén, `warehouse_inventories.quantity` representa el físico y `reserved_quantity` las unidades comprometidas; la disponibilidad se calcula descontando reservas. `products.stock` es una proyección agregada sincronizada por los servicios de inventario.
- No actualices solo `products.stock` ni manipules reservas directamente para simular una operación. Reutiliza `InventoryService` y el orden de locks/transacciones del flujo correspondiente; los movimientos y sus claves idempotentes son parte de la trazabilidad.
- El checkout reserva stock. La expiración, liberación, reasignación y consumo son transiciones distintas y deben conservar sus protecciones e idempotencia.
- `OrderOperationalStatus` es la clasificación canónica para lectura, filtros y presentación de estados operativos legacy. No la uses por sí sola como prueba de que una transición persistente fue completada ni para autorizar una operación financiera.
- Los estados de pago y operación son dimensiones separadas. `OrderStateService` conserva el rechazo de `refunded` para pedidos ya entregados/recogidos; no canceles un pedido por `payment_status=rejected`. Preserva este comportamiento salvo que la tarea solicite modificarlo explícitamente; en ese caso, revisa sus consecuencias y actualiza las pruebas y documentación relacionadas.

### Pagos, Tesorería y roles

- No confundas voucher, abono en revisión o ticket de pasarela con pago aprobado. La aprobación se decide en el backend y debe mantener los servicios transaccionales, locks, conciliación y consumo único de reserva.
- El pago por transferencia admite varios abonos; sus estados y cupos se calculan con `TransferPaymentInstallments`. Cambios de Tesorería deben preservar la suma de aprobados, el saldo y la trazabilidad financiera.
- Los roles vigentes incluyen `customer`, `admin`, `treasury` y `billing`. Las rutas agrupan `auth:sanctum` y `active_user` con `is_admin`, `is_treasury` o `can_access_billing`; administra permisos en backend, no solo ocultando controles Vue. Acceso a un pedido privado requiere además la propiedad cuando el contrato es de cliente.
- El refund interno no equivale a una devolución automática del gateway ni a una nota de crédito tributaria. No alteres pagos, estados, inventario o referencias financieras fuera del servicio que posee esa transición.

### Documentos y SUNAT

- Los PDF de resumen de pedido y constancia de pago son documentos comerciales privados. No usan serie fiscal, XML, CDR ni aceptación SUNAT. Una constancia requiere pago aprobado verificable; un voucher pendiente no basta.
- La facturación electrónica es un módulo separado y opcional (`ELECTRONIC_INVOICING_ENABLED`, apagado por defecto). Boletas pueden generarse localmente en el alcance implementado; la comunicación local simulada se identifica como `LOCAL-SIM` y no es respuesta SUNAT.
- El adaptador Beta de resúmenes está separado de `LOCAL-SIM`, opt-in independiente y limitado por el backend a configuraciones/ambientes de pruebas. Un HTTP 200 o ticket no equivale a aceptación; se requiere resultado documental validado. No infieras que hubo llamadas reales o aceptación porque exista el adaptador o evidencia de pruebas interceptadas.
- No habilites producción, no fabriques CDR ni llames a servicios SUNAT salvo autorización expresa y específica para esa prueba. Client.0160 de las facturas históricas permanece sin diagnóstico externo confirmado según la documentación disponible.
- La configuración técnica/comercial del módulo y las credenciales/certificados no deben llegar desde payloads del cliente. No registres contraseñas, claves privadas, PFX ni tokens en Git, logs, respuestas o artefactos públicos.

## POS

La base transaccional inicial de POS/caja está implementada: cajas asociadas a almacén, asignaciones explícitas de cajero, aperturas/cierres de sesión auditados, movimientos de apertura, canal de pedido y aislamiento por middleware. Su alcance y límites están descritos en [docs/POS_DISENO_TECNICO.md](docs/POS_DISENO_TECNICO.md). Esto no significa que exista la interfaz de venta ni la confirmación de ventas POS: el carrito, borrador de venta, cobro en efectivo, vuelto y descuento de inventario siguen pendientes.

La asignación POS es explícita e independiente del rol de Tesorería. No introduzcas permisos por rol implícitos. Las sesiones tienen un solo cajero responsable; las ventas cobradas serán inmutables y no se permiten devoluciones POS hasta integrar y probar un flujo autorizado de reversión financiera, inventario y caja.

## Datos, configuración y QA

- No leas valores secretos de `.env` para incluirlos en respuestas o documentación. Usa `.env.example` para nombres y valores seguros de ejemplo; las variables deben leerse mediante `config/` en ejecución.
- Para pruebas y validaciones que escriban en MySQL, utiliza una base QA desechable y verifica desde la conexión efectiva de Laravel `SELECT DATABASE()` y el motor. Rechaza bases normales, compartidas o ajenas al caso. Para operaciones reales autorizadas, verifica el destino y respeta el alcance solicitado.
- Prefiere SQLite `:memory:` cuando sea compatible. Para concurrencia, locks, índices/FK o semántica InnoDB se requiere MySQL/InnoDB aislado y conexiones independientes.
- Los fixtures deben ser sintéticos. No cambies pedidos, pagos, inventario ni documentos reales para probar una interfaz. No ejecutes migraciones en la base normal sin autorización expresa del propietario para esa operación concreta.

## Validación, documentación y Git

Selecciona las pruebas afectadas por el contrato y ejecuta primero el caso focalizado cuando exista. Para PHP usa `php -l` y Pint en los PHP modificados; para Vue/JavaScript ejecuta el build (`npm run build`) y pruebas frontend pertinentes. Ejecuta `git diff --check` antes de entregar. No afirmes una validación visual que no hayas realizado.

Actualiza documentación cuando cambie un contrato, configuración, procedimiento de operación o capacidad visible. En informes separa: implementado, probado en la ejecución actual, evidencia histórica y pendiente. Identifica bloqueos concretos y no conviertas intentos interceptados o simulados en pruebas de servicio externo.

Preserva cambios locales no relacionados; inspecciona también archivos nuevos y no descartes trabajo ajeno. Staging, commit, push, merge, despliegue y migraciones requieren autorización expresa dentro del alcance correspondiente. Una autorización expresa se aplica a la acción y alcance indicados y no debe solicitarse de nuevo mientras siga vigente; no se extiende automáticamente a otras acciones o entornos.
