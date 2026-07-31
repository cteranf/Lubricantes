# Fase 2: sedes, almacenes e inventario

## Alcance

Esta fase incorpora sedes, almacenes, SKU estable, saldo por producto/almacén y movimientos auditables. No incluye reservas, picking, packing, códigos de barras ni abastecimiento multi-almacén automático.

`products.stock` se conserva como total físico global temporal y `InventoryService` lo sincroniza con la suma de `warehouse_inventories.quantity`. Administración muestra ese total. El catálogo público muestra únicamente el saldo vendible de `ALM-PRINCIPAL`, exactamente el mismo saldo que valida y descuenta checkout.

## Aplicación manual

1. Respaldar la base `lubricantes` y poner el checkout en mantenimiento durante la aplicación.
2. Revisar pendientes sin ejecutar: `php artisan migrate:status`.
3. Ejecutar una sola vez: `php artisan migrate`.
4. No ejecutar seeders para transferir stock productivo.
5. Validar los controles SQL siguientes antes de reabrir el checkout.

Las migraciones se aplican en este orden:

1. `2026_07_31_070100_create_branches_table.php`
2. `2026_07_31_070110_create_warehouses_table.php`
3. `2026_07_31_070120_create_warehouse_inventories_table.php`
4. `2026_07_31_070130_create_inventory_movements_table.php`
5. `2026_07_31_070140_add_warehouse_id_to_order_items_table.php`
6. `2026_07_31_070150_backfill_initial_inventory_and_skus.php`

La última migración crea o reutiliza `PRINCIPAL` y `ALM-PRINCIPAL`, genera SKU `LUB-000001`, copia cada `products.stock` una sola vez y usa `initial-product-{id}` en la columna `idempotency_key`, protegida por `UNIQUE`.

Si `PRINCIPAL` está inactiva, si `ALM-PRINCIPAL` pertenece a otra sede, está inactivo, no es predeterminado o la sede ya tiene otro predeterminado activo, la migración aborta completa y no sobrescribe datos. Si ya existe inventario para un producto, omite tanto la copia como el movimiento inicial.

## Validación posterior

```sql
SELECT COUNT(*) AS products_without_sku FROM products WHERE sku IS NULL OR sku = '';
SELECT sku, COUNT(*) FROM products GROUP BY sku HAVING COUNT(*) > 1;
SELECT warehouse_id, product_id, COUNT(*) FROM warehouse_inventories GROUP BY warehouse_id, product_id HAVING COUNT(*) > 1;
SELECT COUNT(*) AS negative_stock FROM warehouse_inventories WHERE quantity < 0;
SELECT p.id, p.stock, COALESCE(SUM(wi.quantity), 0) inventory_total
FROM products p LEFT JOIN warehouse_inventories wi ON wi.product_id = p.id
GROUP BY p.id, p.stock HAVING p.stock <> inventory_total;
SELECT type, COUNT(*) FROM inventory_movements GROUP BY type;
```

Después, configurar la dirección real de la sede principal desde `/admin/branches` y confirmar el almacén predeterminado en `/admin/warehouses`.

## Tipos de movimiento

| Tipo | Efecto | Uso |
|---|---:|---|
| `initial` | + | Migración o inicialización compatible |
| `manual_in` | + | Entrada manual |
| `manual_out` | - | Salida manual |
| `transfer_in` | + | Entrada de traslado |
| `transfer_out` | - | Salida de traslado |
| `sale` | - | Venta asociada a pedido |
| `cancellation_return` | + | Reposición idempotente por cancelación |
| `correction` | +/- | `quantity` es el nuevo saldo físico contado; el backend calcula la diferencia |

## Ejemplos API

Todas las rutas requieren Sanctum y usuario `admin`.

```json
POST /api/v1/admin/branches
{"code":"LIMA-NORTE","name":"Lima Norte","address":"Av. Principal 100","is_active":true}

POST /api/v1/admin/warehouses
{"branch_id":2,"code":"ALM-NORTE","name":"Almacén Norte","is_default":true,"is_active":true}

POST /api/v1/admin/inventories/adjustments
{"product_id":10,"warehouse_id":2,"action":"manual_in","quantity":20,"reason":"Compra OC-100","notes":"Recepción completa"}

POST /api/v1/admin/inventories/adjustments
{"product_id":10,"warehouse_id":2,"action":"manual_out","quantity":2,"reason":"Merma verificada"}

POST /api/v1/admin/inventories/adjustments
{"product_id":10,"warehouse_id":2,"action":"correction","quantity":17,"reason":"Conteo físico mensual"}

POST /api/v1/admin/inventories/transfers
{"product_id":10,"source_warehouse_id":1,"destination_warehouse_id":2,"quantity":5,"reason":"Reposición de sede"}
```

En `correction`, el frontend no define el signo ni puede definir los saldos anterior/posterior. El movimiento registra `counted_quantity`, `difference` y `direction` en metadata. Si el saldo contado coincide con el registrado, la operación responde 422 y no crea movimiento.

Una venta crea `sale` con `reference_type=order`; una cancelación crea `cancellation_return`. Ambas usan el `warehouse_id` guardado en `order_items`. La clave por detalle impide descontar o reponer dos veces.

## Reversión

Si la migración falla, Laravel revierte la transacción de datos. Mantener el checkout cerrado, conservar el error y restaurar el respaldo si hubiera una interrupción del motor o un DDL parcialmente aplicado. No usar `migrate:fresh` ni `refresh`.

Para una reversión controlada antes de recibir operaciones reales de Fase 2, usar el respaldo como mecanismo preferido. `migrate:rollback --step=6` elimina las tablas nuevas; el backfill no borra SKU generados porque son identificadores estables. No hacer rollback después de registrar movimientos reales sin exportarlos y aprobar explícitamente la pérdida funcional.

## Pendiente para Fase 3

- Reservas e idempotencia formal del checkout/pago.
- `reserved_quantity` y cálculo físico menos reservado.
- Selección multi-almacén, picking, packing y entregas parciales.
- Roles operativos específicos y permisos granulares.
- Retiro de `products.stock` tras confirmar que ya no tiene consumidores.
- Código de barras e integración SUNAT.
