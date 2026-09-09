---
document: Auditoría del flujo de pagos y propuesta de Tesorería
document_type: technical-audit
status: current-code-review
scope: read-only
project: LubriStore
reviewed_at: 2026-09-08
---

# Auditoría del flujo de pagos y Tesorería

## Resumen ejecutivo

Decisiones aprobadas para la primera versión de infraestructura: Yape, Plin y banco siguen siendo `orders.payment_method=transferencia` y se distinguen por canal (`yape`, `plin`, `bank_transfer`); no se almacenan imágenes; el cliente declarará número de operación, fecha/hora aproximada y, para Yape/Plin, solo los últimos cuatro dígitos del origen. El importe esperado proviene exclusivamente de `orders.total`; no habrá pagos parciales ni cuotas. Esta implementación inicial crea seguridad, modelos y servicio base, pero todavía no publica endpoints ni aprobación de Tesorería.

LubriStore tiene cuatro valores comerciales de método de pago: `card`, `transferencia`, `contra_entrega` y `pago_en_sede`. Mercado Pago tiene una arquitectura persistente para preferencias, transacciones y webhooks; contraentrega/pago en sede generan una `PaymentTransaction` al confirmarse el cobro. La transferencia bancaria se aprueba desde el flujo operativo de pedidos, pero su aprobación **no crea una `PaymentTransaction`, no guarda evidencia, referencia, importe declarado, aprobador ni historial financiero específico**. Ese es el principal bloqueo para una conciliación confiable.

Todas las rutas administrativas están protegidas por `auth:sanctum`, `active_user` e `is_admin`. No hay separación de permisos financieros: cualquier usuario con `role=admin` puede aprobar una transferencia, iniciar preparación, cancelar y operar entrega. El rol vigente solo admite `admin` y `customer`.

Esta auditoría es de lectura: se inspeccionaron rutas, código, migraciones, modelos y pruebas. No se invocaron endpoints mutantes, pagos, webhooks, migraciones, seeders ni base de datos comercial.

## Situación actual y métodos admitidos

`app/Http/Controllers/Api/V1/OrderController.php::store` valida exactamente:

| Valor persistido en `orders.payment_method` | Modalidad compatible | Fuente pública |
|---|---|---|
| `card` | delivery o pickup | Tarjeta; gateway activo `mock` o Mercado Pago |
| `transferencia` | delivery o pickup | Transferencia bancaria / Yape / Plin |
| `contra_entrega` | solo delivery | Cobro al confirmar entrega |
| `pago_en_sede` | solo pickup | Cobro al registrar recojo |

Yape y Plin **no son métodos independientes**. `PaymentSetting` guarda teléfonos en `transfer_yape_phone` y `transfer_plin_phone`; `PaymentSettingService::getPublicMethods()` los expone como billeteras dentro del único método `transferencia`. No existe `payment_method=yape` ni `payment_method=plin`.

El gateway `mock` es una configuración de tarjeta para pruebas, no un valor de `payment_method`. El webhook mock heredado solo se permite en entorno `testing` cuando se configura expresamente; el tráfico de Mercado Pago usa la ruta firmada.

## Inventario técnico

### `orders`

Modelo: `app/Models/Order.php`. Tabla creada en `2026_01_18_203611_create_orders_table.php` y ampliada por migraciones posteriores.

| Grupo | Campos relevantes |
|---|---|
| Comercial | `status`, `subtotal`, `discount_total`, `shipping_amount`, `total`, `payment_method`, `payment_status` |
| Pago | `payment_id`, `current_payment_preference_id`, `payment_data`, `paid_at` |
| Reserva | `reserved_until`; relación `reservations` |
| Entrega | `delivery_type`, `pickup_branch_id` y snapshots de recojo/envío, `tracking_status`, `fulfillment_status`, `delivered_at` |
| Auditoría operativa | `prepared_by`, `ready_by`, `delivered_by`, timestamps Eloquent |

`payment_status` nació como enum: `pending`, `approved`, `rejected`, `refunded`. El servicio también trata `in_process` como `pending`; Mercado Pago conserva su estado remoto en `PaymentTransaction.provider_status`, no en este campo. `status` comercial admite `pending`, `confirmed`, `shipped`, `delivered`, `canceled`, `rejected` en `OrderStateService`.

Faltan en `orders` los datos suficientes para comprobar una transferencia: operación declarada, fecha declarada, cuenta receptora, evidencia, aprobador/rechazador y motivo de decisión. `payment_data` es JSON, pero no hay flujo que los solicite ni contrato que lo normalice.

### `payment_transactions`

Modelo: `app/Models/PaymentTransaction.php`; migraciones `2026_08_01_070260_create_payment_transactions_table.php` y `2026_09_04_070510_add_gateway_fields_to_payment_transactions_table.php`.

| Campo/grupo | Uso actual |
|---|---|
| Identidad | `order_id` con `restrictOnDelete`, `gateway`, `payment_method`, `transaction_type` (`payment`/`refund`) |
| Resultado | `status` (`pending`, `approved`, `failed`, `canceled`), `amount`, `currency` |
| Idempotencia | `idempotency_key` UNIQUE, `approved_scope_key` UNIQUE nullable, UNIQUE `(gateway, provider_payment_id)` |
| Proveedor | `external_reference`, `provider_payment_id`, `payment_preference_id`, `provider_status`, `provider_status_detail`, `verified_at` |
| Cobro manual | `manual_reference`, `collection_method`, `collected_by`, `collected_at`, `confirmed_by`, `confirmed_at` |
| Fallo | `failed_at`, `failure_reason`, `metadata` |

Índices adicionales: `(order_id,status)` y `(payment_method,transaction_type,status)`. El modelo bloquea `update` y `delete`, por lo que una transacción confirmada es inmutable. La tabla puede registrar referencia manual y aprobador para contraentrega/pago en sede, pero **la aprobación de transferencia vigente no la usa**. Tampoco contiene enlace de comprobante o evidencia.

### `payment_preferences`

Modelo: `PaymentPreference`; migración `2026_09_04_070500_create_payment_preferences_table.php`.

Estados: `creating`, `active`, `superseded`, `failed`, `orphaned`. Tiene `order_id` restrictivo, `provider_preference_id` UNIQUE, `external_reference` UNIQUE, `idempotency_key` UNIQUE y una columna generada `active_order_id` que fuerza una preferencia `creating`/`active` por pedido. Guarda URLs de checkout y fechas de inicio, activación, fallo o sustitución.

### `payment_webhook_events`

Modelo: `PaymentWebhookEvent`; migración `2026_09_04_070520_create_payment_webhook_events_table.php`.

Estados: `received`, `processing`, `processed`, `ignored`, `failed`. Registra gateway, identificadores remotos, hash de payload y request, `idempotency_key` UNIQUE, motivo de fallo y tiempos de recepción/proceso. Índice `(gateway,provider_payment_id)`. No persiste el payload completo, una decisión adecuada para reducir exposición; por ello no sirve como comprobante de transferencia manual.

### `payment_settings`

Modelo: `PaymentSetting`; migración `2026_08_03_070430_create_payment_settings_table.php`. Es configuración singleton por convención (`firstOrCreate`), sin índice único explícito que lo garantice. Define habilitación, títulos e instrucciones de tarjeta, transferencia/Yape/Plin, contraentrega y pago en sede. El token de Mercado Pago usa cast `encrypted` y está oculto; los datos de cuenta/billetera sí son configuración administrativa, no evidencia de un pago.

### Reservas, inventario y evidencia

`inventory_reservations` tiene una fila por `order_item_id` (UNIQUE), `idempotency_key` UNIQUE, estados `active`, `consumed`, `released`, `expired`, tiempos de expiración/consumo/liberación e índices por estado, pedido y producto-almacén. `warehouse_inventories.reserved_quantity` mantiene el saldo comprometido. `InventoryMovement` es inmutable y usa `idempotency_key` UNIQUE; ventas consumidas generan `sale`, devoluciones por cancelación `cancellation_return`.

No se localizó una tabla ni endpoint de comprobantes de transferencia. Las evidencias existentes pertenecen a entrega (`order_delivery_attempts` y almacenamiento de evidencia de delivery), no a pagos.

## Flujos actuales por método

### Creación común del pedido

`POST /api/v1/orders` exige usuario autenticado. `OrderController::store` valida método, modalidad, dirección o sede, recalcula precios desde `Product`, vuelve a cotizar delivery y toma el almacén principal activo. Crea pedido, items y reserva dentro de una transacción. El token se busca con `checkout_token` **y** `user_id` bajo `lockForUpdate`; un token perteneciente a otro usuario obtiene error de validación.

- Tarjeta y transferencia: `status=pending`, `payment_status=pending`, `tracking_status=pending`, reserva con vencimiento configurable (por defecto 30 minutos).
- Contraentrega y pago en sede: `status=confirmed`, `tracking_status=confirmed`, `payment_status` conserva su default `pending`; la reserva se crea con vencimiento de diez años como compromiso operativo hasta consumirla.
- Delivery calcula y snapshottea tarifa; pickup deja envío en cero y snapshottea sede. Los precios, tarifa y snapshots no llegan confiados desde frontend.

### Transferencia, Yape y Plin

No existe endpoint de carga de comprobante ni paso del cliente que marque una transferencia como enviada. Yape/Plin se presentan en las instrucciones de `transferencia`.

La única aprobación es `POST /api/v1/admin/orders/{order}/fulfillment/approve-transfer`, llamada desde `resources/js/views/admin/Orders.vue::runAction` al elegir “Aprobar transferencia”. El componente pide confirmación visual, sin payload de referencia, importe, fecha, comprobante u observación.

`OrderFulfillmentController::approveTransfer` delega en `OrderFulfillmentService::approveTransfer`:

1. abre transacción y bloquea `orders` con `lockForUpdate`;
2. exige `payment_method=transferencia`, estado de fulfillment `reserved` y pago aún no aprobado;
3. consume las reservas mediante `InventoryService::consumeOrderReservation`, que bloquea inventario y registra movimientos `sale` de forma idempotente;
4. actualiza `orders.payment_status=approved`, `paid_at`, `status=confirmed`, `tracking_status=confirmed`;
5. devuelve la presentación de fulfillment.

La repetición después de aprobado devuelve el pedido sin volver a consumir inventario. Sin embargo, no genera `PaymentTransaction`, no escribe `OrderFulfillmentHistory`, no registra usuario aprobador ni notifica. La ruta está dentro de `is_admin`: puede ejecutarla cualquier administrador activo.

```mermaid
sequenceDiagram
  participant C as Cliente
  participant O as Pedido/reserva
  participant A as Administrador
  participant F as Fulfillment
  C->>O: Checkout transferencia
  O-->>C: pending / reserva activa
  A->>F: approve-transfer
  F->>F: lockForUpdate pedido
  F->>O: consumir reserva y movimientos sale
  F->>O: payment_status=approved; paid_at; confirmed
  Note over F,O: No PaymentTransaction ni comprobante
```

### Mercado Pago y tarjeta

`POST /api/v1/payment/create` requiere Sanctum y comprueba propiedad del pedido. Solo admite pedidos `card` pagables, no vencidos, no terminales y no aprobados. `PaymentPreferenceService::createFor` persiste primero una preferencia con claves únicas, bloquea el pedido, realiza la llamada remota después de persistir intención y evita reintentos ciegos: un resultado incierto queda `orphaned` para conciliación.

`GET /api/v1/payment/return` requiere propietario, pero es solo informativo: no llama al proveedor, no cambia inventario ni estado. `GET /api/v1/payment/verify/{paymentId}` también exige propietario, pedido y transacción local existente para el gateway/preferencia; procesa una verificación remota bajo las mismas reglas del procesador.

`POST /api/v1/payment/webhook` es público y no usa sesión Sanctum. `PaymentWebhookSignatureService::validate` comprueba la firma HMAC de Mercado Pago con ventana de tolerancia. Después persiste/bloquea `PaymentWebhookEvent` por clave idempotente, consulta al proveedor y busca preferencia por gateway, preferencia externa y referencia externa. `PaymentProcessingService::process` bloquea preferencia y pedido, valida referencia, importe y moneda para gateway real, evita asociar un pago remoto a otro pedido, crea una transacción y aplica estado.

Cuando se aprueba, se registra una `PaymentTransaction` aprobada, se confirma el pedido y se consume una sola vez la reserva. Webhooks repetidos retornan `ok` sin reprocesar; también hay límites por UNIQUE de evento, pago remoto y ámbito aprobado. `rejected` mantiene el pedido pendiente y la reserva activa para reintentar mientras no venza. `refunded`, `charged_back` y `cancelled` generan advertencia y requieren revisión manual; no cancelan ni reponen inventario automáticamente en `PaymentProcessingService`.

```mermaid
sequenceDiagram
  participant C as Cliente
  participant P as API / Preference
  participant M as Mercado Pago
  participant W as Webhook firmado
  C->>P: POST payment/create
  P->>P: persistir preference e idempotencia
  P->>M: crear preferencia
  M-->>C: checkout remoto
  M->>W: notificación
  W->>W: validar HMAC y persistir evento
  W->>M: verificar pago
  W->>W: bloquear preference/pedido, registrar transacción
  W->>W: aprobar y consumir reserva una vez
```

### Contraentrega y pago al recoger

Ambos se crean confirmados operativamente pero pendientes de pago. Para poder iniciar preparación, `OrderFulfillmentService::startPreparation` exceptúa estos métodos de la exigencia de pago aprobado y consume la reserva. En pickup, `markAsPickedUp` exige `money_received=true` y `collection_method` (`cash`, `card_terminal`, `bank_transfer` u `other`); invoca `OrderPaymentService::confirmCashOnDeliveryCollection` y luego establece `picked_up_at`, `delivered_at`, `status=delivered` y fulfillment entregado.

Para delivery del flujo vigente, la confirmación logística termina en `OrderDeliveryService` y usa el mismo `OrderPaymentService`; las pruebas muestran que crea una sola transacción, marca pago aprobado y no genera otro movimiento de inventario. El servicio bloquea el pedido y usa claves `cod-collection-order-{id}` y `cod-approved-order-{id}`; conserva cobrador, fecha, confirmador, método y referencia manual opcional.

```mermaid
sequenceDiagram
  participant C as Cliente
  participant O as Pedido/reserva
  participant A as Administrador
  participant T as PaymentTransaction
  C->>O: Checkout contraentrega o pago en sede
  O-->>C: confirmed / payment pending
  A->>O: Preparar (consume reserva)
  A->>O: Confirmar entrega o recojo con cobro
  O->>T: Transacción approved idempotente
  O->>O: paid_at + delivered_at
```

## Matriz de transiciones demostradas

| Evento | `payment_status` / pedido | Reserva e inventario | Transacción / historial | Acción posterior |
|---|---|---|---|---|
| Crear tarjeta/transferencia | pending / pending | activa, vence | no transacción | pagar o aprobar transferencia |
| Crear COD/pago en sede | pending / confirmed | activa, compromiso largo | no transacción | preparar sin pago aprobado |
| Aprobar transferencia | approved / confirmed | consume; movimiento `sale` | **sin PaymentTransaction**; sin historial financiero | preparar |
| Webhook tarjeta aprobado | approved / confirmed | consume; `sale` idempotente | transacción aprobada + evento procesado | preparar |
| Webhook repetido | sin cambio | sin cambio adicional | evento/UNIQUE evita duplicado | ninguna |
| Webhook rechazado | rejected / pending | reserva sigue activa | intento fallido cuando se procesa | reintentar antes del vencimiento |
| Expirar reserva de tarjeta/transferencia | pedido se cancela cuando se detecta en flujo de expiración | activa → expired; libera reservado; sin `sale` | sin pago financiero nuevo | nuevo checkout |
| Cancelar antes de consumo | canceled | activa → released; físico igual | historial fulfillment; sin movimiento de retorno | terminal |
| Cancelar después de consumo | canceled | consumida; físico repuesto; `cancellation_return` | historial fulfillment | terminal; no reembolso automático |
| Entregar COD/pago en sede | approved / delivered | ya consumida, físico no cambia | transacción aprobada idempotente e historial de delivery/fulfillment | terminal |
| Reembolso, contracargo o `cancelled` remoto | revisión manual; no transición automática fiable | no reposición automática | evento/procesador puede dejar advertencia | intervención financiera manual |

La columna “historial” es operativa (`OrderFulfillmentHistory`, delivery o handling) y no equivale a un libro mayor financiero. La aprobación de transferencia no crea tampoco una fila de este historial desde `approveTransfer`.

## Aprobación manual, responsabilidades y riesgos

`resources/js/views/admin/Orders.vue` combina la vista de pedido, el botón “Aprobar transferencia”, acciones de fulfillment, el panel de picking/packing y delivery. La ruta administrativa está protegida solo por `is_admin`; no hay policy, FormRequest específico, rol de tesorería ni separación entre quien aprueba y quien prepara.

| Hallazgo | Clasificación | Evidencia / impacto |
|---|---|---|
| Preferencias, webhooks y pagos remotos persistentes e idempotentes | Correcto | `PaymentPreferenceService`, `PaymentWebhookEvent`, índices UNIQUE y `PaymentProcessingService` |
| Cobro COD/pago en sede con transacción inmutable e idempotencia | Correcto | `OrderPaymentService::confirmCashOnDeliveryCollection` |
| Preparación bloqueada para tarjeta/transferencia sin aprobación | Correcto | `OrderFulfillmentService::startPreparation` |
| COD/pago en sede puede prepararse antes del cobro | Aceptable para etapa inicial | Política explícita; riesgo comercial normal de contraentrega |
| Yape/Plin agrupados bajo transferencia | Aceptable para etapa inicial | Configuración clara, pero sin conciliación independiente |
| Aprobar transferencia no registra transacción ni aprobador | Bloqueo para producción | No existe evidencia, importe, cuenta, usuario ni auditoría financiera verificable |
| Cualquier admin puede aprobar y preparar | Riesgo alto | `is_admin` binario; no hay segregación de funciones |
| Sin rechazo/observación/reversión de transferencia | Riesgo alto | No hay rutas, servicio ni modelo para esas decisiones |
| Sin comprobantes, número de operación ni deduplicación de transferencias | Riesgo alto | No existe tabla/endpoints de evidencia; mismo pago puede no ser detectable |
| Reembolso/contracargo no automatiza stock | Correcto como medida de seguridad, pero incompleto | Requiere política y bandeja manual |
| `orders.payment_data` JSON como posible contenedor informal | Riesgo medio | No tiene esquema, evidencia ni auditoría |

## Capacidades ausentes confirmadas

No se localizó operación para rechazar transferencia, marcar comprobante inválido, solicitar corrección, registrar pago parcial o excedente, revertir aprobación, iniciar reembolso, adjuntar observaciones financieras, almacenar comprobante, detectar duplicados por número de operación o conciliar contra cuenta receptora. Tampoco hay endpoint administrativo de listado de transacciones/eventos de pago; solo existe la configuración `GET/PUT /api/v1/admin/payment-settings`.

## Propuesta: módulo Tesorería

Ruta propuesta: `/admin/treasury/payments`. No debe reemplazar operaciones de fulfillment ni mutar directamente `orders` desde Vue. Debe usar un servicio financiero transaccional que, al aprobar, cree la transacción y aplique la proyección de pedido/reserva en una única unidad atómica.

### Bandejas, filtros y detalle

| Bandeja | Origen | Filtros mínimos | Acciones autorizadas |
|---|---|---|---|
| Pendientes de validación | transferencias con comprobante o declaración | fecha, método, importe, cuenta, pedido, cliente anonimizado | aprobar, rechazar, observar |
| Aprobados | transacciones approved | periodo, método, aprobador, referencia | ver auditoría; no editar |
| Rechazados / observados | decisión financiera persistida | motivo, usuario, periodo | solicitar corrección o reabrir bajo regla explícita |
| Mercado Pago | `PaymentTransaction` + `PaymentWebhookEvent` | provider status, preference, pago remoto, periodo | ver estado; derivar a revisión manual |
| COD / pago en sede | transacciones de cobro manual | recojo/delivery, collector, método de cobro | ver confirmación y referencia |
| Reembolsos / revisión manual | transacciones/eventos de excepción | refunded, charged_back, cancelled, motivo | iniciar proceso controlado, sin reposición automática |

Columnas: pedido, método, estado financiero interno, estado proveedor cuando exista, importe y moneda, referencia, fecha declarada/confirmada, usuario que decidió, evidencia disponible y enlace administrativo seguro. KPIs: pendientes, importe pendiente, aprobados del periodo, rechazados, observados, excepciones remotas y cobros COD pendientes; ninguno debe sumar pedidos duplicados ni datos no aprobados.

El detalle debe separar snapshot del pedido, datos declarados, cuenta receptora snapshot, archivos de evidencia con acceso autorizado, decisión, transacciones inmutables y línea de auditoría. No debe mostrar tokens, payload remoto íntegro ni datos personales ajenos al caso.

### Modelo de permisos recomendado

Actualmente no conviene inventar un rol nuevo en frontend: `users.role` solo tiene `admin`/`customer` y `is_admin` protege todo el bloque. Alternativas ordenadas:

1. **Fase inicial controlada:** capacidad booleana explícita y temporal, por ejemplo `can_manage_treasury`, aplicada por middleware/policy a rutas de Tesorería; requiere migración y auditoría de cambios.
2. **Opción preferida escalable:** tablas de roles/permisos o una matriz de capacidades (`payments.view`, `payments.review`, `payments.approve`, `payments.refund`) con middleware/policies. Permite separar consulta, revisión y aprobación.
3. **Ampliar enum de roles:** posible, pero menos flexible y requiere migración, UI de usuarios, middleware y pruebas de compatibilidad.

Debe impedirse que una persona apruebe su propio registro de cobro cuando el proceso comercial lo requiera; al menos deben quedar `submitted_by`, `reviewed_by`, `approved_by` y marcas temporales distintas.

### Datos y garantías que requeriría una fase futura

Se recomienda una entidad de solicitud/evidencia financiera separada de la transacción inmutable, por ejemplo `payment_reviews` o `payment_submissions`: `order_id`, método, estado (`pending_review`, `observed`, `approved`, `rejected`, `manual_review`), importe declarado, fecha declarada, referencia normalizada, cuenta receptora snapshot, evidencia, notas, submitter/reviewer/decision timestamps y clave idempotente. Se necesitarían índices por estado/fecha/pedido/referencia y una UNIQUE contextual para prevenir la misma referencia activa en la misma cuenta y moneda. No debe imponerse una UNIQUE global sin reglas de negocio para referencias vacías o proveedores distintos.

La aprobación debe bloquear pedido, solicitud y reserva; validar importe/cuenta/estado; crear una `PaymentTransaction` con `approved_scope_key`; marcar solicitud aprobada; consumir una reserva activa una vez; y actualizar `orders.payment_status`/`paid_at`. Rechazar u observar no debe consumir stock. Cualquier reversión posterior requiere una transacción de ajuste/reembolso y política explícita de inventario, no mutar la transacción original.

```mermaid
flowchart TD
  S[Cliente registra transferencia y evidencia] --> R[Solicitud pending_review]
  R --> V{Revisor autorizado}
  V -->|Observa| O[observed: solicitar corrección]
  V -->|Rechaza| X[rejected: conservar auditoría]
  V -->|Aprueba| L[Lock pedido, solicitud y reserva]
  L --> T[Crear PaymentTransaction approved idempotente]
  T --> I[Consumir reserva y movimiento sale]
  I --> P[Actualizar proyección del pedido]
  P --> H[Auditoría inmutable]
  M[Webhook Mercado Pago] --> Q[Evento firmado e idempotente]
  Q --> T
```

## Plan de implementación por fases

1. **Fundación financiera:** migraciones para solicitudes/evidencias/auditoría, permisos y claves únicas; Resources que oculten datos sensibles; backfill nulo y compatible con pedidos históricos.
2. **Transferencia:** submit cliente/admin, archivos privados, validación de referencia/importes, aprobar/rechazar/observar transaccionalmente y pruebas de concurrencia.
3. **Bandeja administrativa:** filtros, KPIs, detalle, auditoría, paginación y políticas. Retirar el botón de aprobación desde el modal operativo o convertirlo en enlace a Tesorería.
4. **Conciliación y excepciones:** Mercado Pago, COD/pago en sede, duplicados, conciliación manual y workflow de refund/chargeback sin reposición automática implícita.
5. **Certificación:** pruebas MySQL de locking/UNIQUE, permisos, archivos, privacidad, idempotencia, migración de históricos y smoke de navegador.

Cambios requeridos: migraciones y modelos nuevos; servicio de revisión/conciliación; controladores, Requests y policies/middleware; frontend de Tesorería; integración en Orders.vue solo como enlace; pruebas feature/concurrencia y actualización documental. No se deben cambiar snapshots, reservas, movimientos, picking/packing, pickup, delivery, reportes ni Mercado Pago ya persistente sin pruebas de regresión.

## Evidencia y verificaciones

| Tipo | Fuente inspeccionada |
|---|---|
| Rutas | `routes/api.php`; `php artisan route:list --path=api/v1` |
| Checkout | `app/Http/Controllers/Api/V1/OrderController.php::store` |
| Pago remoto | `PaymentController`, `PaymentPreferenceService`, `PaymentProcessingService`, `PaymentWebhookSignatureService`, gateways |
| Pago manual y fulfillment | `OrderPaymentService`, `OrderFulfillmentService`, `OrderFulfillmentController`, `resources/js/views/admin/Orders.vue` |
| Inventario/reservas | `InventoryService`, `InventoryReservation`, `InventoryMovement` |
| Esquema | migraciones de orders, reservas, settings, preferences, transactions y webhook events |
| Permisos | `routes/api.php`, `User` y middleware `is_admin` |
| Pruebas leídas | `PaymentWebhookAdversarialTest`, `PaymentPersistenceModelTest`, `PaymentConfigurationAndSimulationTest`, `InventoryReservationPhaseThreeTest`, `ReservationExpirationTest`, `OrderFulfillmentPhaseThreeBTest`, `OrderDeliveryPhaseFiveTest`, `DeliveryFleetManagementTest` |
| Estado de migraciones | `php artisan migrate:status`: las migraciones de preferencias, gateway, webhooks y FK de preferencia figuran como ejecutadas localmente |

No se ejecutaron pruebas en esta auditoría. Las afirmaciones de comportamiento se basan en el código y pruebas inspeccionados, no en un pago o webhook disparado durante este trabajo.

## Preguntas de negocio pendientes

- ¿Quién puede registrar una transferencia y quién puede aprobarla; se exige doble control?
- ¿Qué evidencia es obligatoria por importe/método y cuánto tiempo se conserva?
- ¿Qué cuenta receptora, moneda y tolerancia de importe se admiten por canal?
- ¿Cómo se trata pago parcial, excedente, referencia duplicada y operación sin referencia?
- ¿Yape/Plin requieren clasificación separada para conciliación, aunque sigan bajo transferencia comercial?
- ¿Qué política financiera e inventario corresponde a reembolso, contracargo, devolución y pedido ya entregado?
- ¿Cuál es la fuente de verdad de conciliación bancaria y cómo se integrará sin exponer credenciales?

## Conclusión

El sistema tiene una base sólida para tarjeta/Mercado Pago y para cobros COD/pago en sede gracias a transacciones idempotentes. La transferencia sigue siendo una confirmación operativa sin evidencia financiera. Antes de operar en producción con aprobación manual debe implementarse Tesorería con persistencia de solicitud/evidencia, transacción financiera obligatoria, permisos separados, historial de decisión y prevención de duplicados. Hasta entonces, el botón “Aprobar transferencia” dentro de Operaciones constituye un bloqueo de trazabilidad financiera, aunque su consumo de inventario sea transaccional e idempotente.

## Estado de Tesorería: Fases 1 y 2A

La Fase 1 está certificada como infraestructura: rol `treasury`, solicitudes de pago e historial inmutable. La Fase 2A incorpora `PaymentReceivingAccount` como catálogo administrativo cifrado de cuentas receptoras y un disco privado para QR estático. El QR facilita realizar un pago, pero no confirma un abono: la validación permanece manual y todavía no existe un flujo de registro de pago del cliente.

`PaymentSetting` se conserva como compatibilidad legacy. No se migraron ni crearon cuentas desde sus teléfonos, cuentas o instrucciones existentes; `PaymentReceivingAccount` será la fuente para futuras presentaciones controladas. Las cuentas sólo se administran mediante rutas protegidas por autenticación, usuario activo y administrador; Treasury no las administra en esta fase.
