<?php

namespace App\Services;

use App\Exceptions\InventoryException;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Warehouse;
use App\Models\WarehouseInventory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class InventoryService
{
    public function defaultWarehouse(): Warehouse
    {
        $warehouse = Warehouse::where('is_active', true)->where('is_default', true)
            ->whereHas('branch', fn($q) => $q->where('is_active', true))
            ->orderByRaw('CASE WHEN code = ? THEN 0 ELSE 1 END', [Warehouse::INITIAL_CODE])->first();
        if (!$warehouse) throw new InventoryException('No existe un almacén principal activo configurado.');
        return $warehouse;
    }

    public function stockTotal(Product $product): int
    {
        return Schema::hasTable('warehouse_inventories') ? (int)$product->inventories()->sum('quantity') : (int)$product->stock;
    }

    public function sellableStock(Product $product): int
    {
        if (!Schema::hasTable('warehouse_inventories')) return (int)$product->stock;
        return $this->availableQuantity($product, $this->defaultWarehouse());
    }

    public function availableQuantity(Product $product, ?Warehouse $warehouse = null): int
    {
        if (!Schema::hasTable('warehouse_inventories')) return (int) $product->stock;
        $inventory = WarehouseInventory::where('warehouse_id', ($warehouse ?: $this->defaultWarehouse())->id)
            ->where('product_id', $product->id)->first();
        return $inventory ? max(0, (int) $inventory->quantity - (int) $inventory->reserved_quantity) : 0;
    }

    public function reserveForOrder(OrderItem $item, \DateTimeInterface $expiresAt): InventoryReservation
    {
        return DB::transaction(function () use ($item, $expiresAt) {
            $warehouse = $item->warehouse ?: $this->defaultWarehouse();
            if (!$item->warehouse_id) $item->update(['warehouse_id' => $warehouse->id]);
            $inventory = $this->lockInventory($item->product, $warehouse);
            $key = 'reservation-order-item-'.$item->id;
            if ($existing = InventoryReservation::where('idempotency_key', $key)->first()) return $existing;
            if ($inventory->available_quantity < $item->quantity) throw new InventoryException("No hay stock disponible suficiente para {$item->product->name}.");
            $reservation = InventoryReservation::create([
                'order_id'=>$item->order_id, 'order_item_id'=>$item->id, 'product_id'=>$item->product_id,
                'warehouse_id'=>$warehouse->id, 'quantity'=>$item->quantity, 'status'=>InventoryReservation::ACTIVE,
                'expires_at'=>$expiresAt, 'idempotency_key'=>$key,
            ]);
            $inventory->update(['reserved_quantity'=>(int)$inventory->reserved_quantity+$item->quantity]);
            return $reservation;
        });
    }

    public function releaseOrderReservation(Order $order, string $status = InventoryReservation::RELEASED): array
    {
        if (!in_array($status, [InventoryReservation::RELEASED, InventoryReservation::EXPIRED], true)) throw new InventoryException('Estado de liberacion de reserva invalido.');
        return DB::transaction(function () use ($order, $status) {
            Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $processed = 0; $skipped = 0;
            $reservations = InventoryReservation::where('order_id',$order->id)->orderBy('id')->lockForUpdate()->get();
            foreach ($reservations as $reservation) {
                if ($reservation->status !== InventoryReservation::ACTIVE) { $skipped++; continue; }
                $inventory = WarehouseInventory::where('warehouse_id',$reservation->warehouse_id)->where('product_id',$reservation->product_id)->lockForUpdate()->firstOrFail();
                if ($inventory->reserved_quantity < $reservation->quantity) throw new InventoryException('El saldo reservado es inconsistente.');
                $inventory->update(['reserved_quantity'=>$inventory->reserved_quantity-$reservation->quantity]);
                $reservation->update(['status'=>$status,'released_at'=>now(),'metadata'=>array_merge($reservation->metadata ?? [], ['release_reason'=>$status === InventoryReservation::EXPIRED ? 'reservation_expired' : 'order_canceled'])]);
                $processed++;
            }
            return compact('processed','skipped');
        });
    }

    public function consumeOrderReservation(Order $order, ?User $user = null): array
    {
        return DB::transaction(function () use ($order, $user) {
            Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $processed = 0; $skipped = 0;
            $reservations = InventoryReservation::where('order_id',$order->id)->orderBy('id')->lockForUpdate()->get();
            if ($reservations->count() !== $order->items()->count()) throw new InventoryException('El pedido no tiene todas sus reservas de inventario.');
            foreach ($reservations as $reservation) {
                if ($reservation->status === InventoryReservation::CONSUMED) { $skipped++; continue; }
                if ($reservation->status !== InventoryReservation::ACTIVE) throw new InventoryException('La reserva ya no esta activa y no puede consumirse.');
                $inventory = WarehouseInventory::where('warehouse_id',$reservation->warehouse_id)->where('product_id',$reservation->product_id)->lockForUpdate()->firstOrFail();
                if ($inventory->reserved_quantity < $reservation->quantity || $inventory->quantity < $reservation->quantity) throw new InventoryException('El inventario reservado es inconsistente.');
                $before=(int)$inventory->quantity; $after=$before-$reservation->quantity;
                $inventory->update(['quantity'=>$after,'reserved_quantity'=>$inventory->reserved_quantity-$reservation->quantity]);
                $reservation->update(['status'=>InventoryReservation::CONSUMED,'consumed_at'=>now()]);
                InventoryMovement::firstOrCreate(['idempotency_key'=>'sale-order-item-'.$reservation->order_item_id], [
                    'warehouse_id'=>$reservation->warehouse_id,'product_id'=>$reservation->product_id,'user_id'=>$user?->id,
                    'type'=>InventoryMovement::SALE,'quantity'=>$reservation->quantity,'quantity_before'=>$before,'quantity_after'=>$after,
                    'reason'=>'Venta por pedido','reference_type'=>'order','reference_id'=>(string)$order->id,'metadata'=>[], 'created_at'=>now(),
                ]);
                $this->syncProductStock($reservation->product_id);
                $processed++;
            }
            return compact('processed','skipped');
        });
    }

    public function expireOrderReservation(InventoryReservation $reservation): bool
    {
        return DB::transaction(function () use ($reservation) {
            $order = Order::whereKey($reservation->order_id)->lockForUpdate()->firstOrFail();
            $locked = InventoryReservation::whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== InventoryReservation::ACTIVE || $locked->expires_at->isFuture()) return false;
            if ($order->effectiveFulfillmentStatus() !== Order::FULFILLMENT_RESERVED) return false;
            $this->releaseOrderReservation($order, InventoryReservation::EXPIRED);
            if ($order->status === 'pending' && $order->payment_status === 'pending') $order->update(['status'=>'canceled','tracking_status'=>'canceled']);
            return true;
        });
    }

    public function orderHasConsumedReservation(Order $order): bool
    {
        return InventoryReservation::where('order_id',$order->id)->where('status',InventoryReservation::CONSUMED)->exists();
    }

    public function orderUsesReservationFlow(Order $order): bool
    {
        return $order->reserved_until !== null || InventoryReservation::where('order_id',$order->id)->exists();
    }

    public function initializeProduct(Product $product, int $quantity, ?Warehouse $warehouse=null, ?User $user=null): ?InventoryMovement
    {
        if ($quantity < 0) throw new InventoryException('El stock inicial no puede ser negativo.');
        if (!Schema::hasTable('warehouse_inventories')) {
            return DB::transaction(function() use($product,$quantity){
                Product::whereKey($product->id)->lockForUpdate()->update(['stock'=>$quantity]);
                return null;
            });
        }
        if ($quantity === 0) return null;
        if (!$warehouse) throw new InventoryException('Debe seleccionar un almacén para el stock inicial.');
        return $this->change($product,$warehouse,$quantity,InventoryMovement::INITIAL,'Stock inicial del producto',$user,[],'product',(string)$product->id,'initial-product-'.$product->id);
    }

    public function manualIn(Product $product, Warehouse $warehouse, int $quantity, string $reason, ?User $user=null, ?string $notes=null): InventoryMovement
    {
        return $this->change($product,$warehouse,$quantity,InventoryMovement::MANUAL_IN,$reason,$user,['notes'=>$notes]);
    }

    public function manualOut(Product $product, Warehouse $warehouse, int $quantity, string $reason, ?User $user=null, ?string $notes=null): InventoryMovement
    {
        return $this->change($product,$warehouse,-$quantity,InventoryMovement::MANUAL_OUT,$reason,$user,['notes'=>$notes]);
    }

    public function adjust(Product $product, Warehouse $warehouse, int $targetQuantity, string $reason, ?User $user=null, ?string $notes=null): InventoryMovement
    {
        if ($targetQuantity < 0) throw new InventoryException('El saldo ajustado no puede ser negativo.');
        return DB::transaction(function() use($product,$warehouse,$targetQuantity,$reason,$user,$notes){
            $inventory=$this->lockInventory($product,$warehouse);
            if ($targetQuantity < $inventory->reserved_quantity) throw new InventoryException('El saldo fisico no puede quedar por debajo del stock reservado.');
            $delta=$targetQuantity-$inventory->quantity;
            if ($delta===0) throw new InventoryException('El saldo contado coincide con el saldo registrado; no se creó ningún movimiento.');
            return $this->applyLocked($inventory,$delta,InventoryMovement::CORRECTION,$reason,$user,null,null,null,['notes'=>$notes,'counted_quantity'=>$targetQuantity,'difference'=>$delta,'direction'=>$delta>0?'increase':'decrease']);
        });
    }

    public function transfer(Product $product, Warehouse $source, Warehouse $destination, int $quantity, string $reason, ?User $user=null, ?string $notes=null): array
    {
        $this->positive($quantity);
        if ($source->is($destination)) throw new InventoryException('El almacén de destino debe ser diferente al de origen.');
        return DB::transaction(function() use($product,$source,$destination,$quantity,$reason,$user,$notes){
            $this->assertActive($source); $this->assertActive($destination);
            $now=now();
            WarehouseInventory::insertOrIgnore(['warehouse_id'=>$source->id,'product_id'=>$product->id,'quantity'=>0,'created_at'=>$now,'updated_at'=>$now]);
            WarehouseInventory::insertOrIgnore(['warehouse_id'=>$destination->id,'product_id'=>$product->id,'quantity'=>0,'created_at'=>$now,'updated_at'=>$now]);
            $locked=WarehouseInventory::where('product_id',$product->id)->whereIn('warehouse_id',[$source->id,$destination->id])->orderBy('id')->lockForUpdate()->get()->keyBy('warehouse_id');
            $id=(string)Str::uuid();
            $meta=['notes'=>$notes,'source_warehouse_id'=>$source->id,'destination_warehouse_id'=>$destination->id];
            $out=$this->applyLocked($locked[$source->id],-$quantity,InventoryMovement::TRANSFER_OUT,$reason,$user,'inventory_transfer',$id,'transfer-'.$id.'-out',$meta);
            $in=$this->applyLocked($locked[$destination->id],$quantity,InventoryMovement::TRANSFER_IN,$reason,$user,'inventory_transfer',$id,'transfer-'.$id.'-in',$meta);
            return [$out,$in];
        });
    }

    public function sell(OrderItem $item, ?User $user=null): ?InventoryMovement
    {
        return DB::transaction(function() use($item,$user){
            if (!Schema::hasTable('warehouse_inventories')) {
                $product=Product::whereKey($item->product_id)->lockForUpdate()->firstOrFail();
                if ($product->stock<$item->quantity) throw new InventoryException("No hay stock suficiente para {$product->name}.");
                $product->decrement('stock',$item->quantity); return null;
            }
            $warehouse=$item->warehouse ?: $this->defaultWarehouse();
            $item->update(['warehouse_id'=>$warehouse->id]);
            return $this->change($item->product,$warehouse,-$item->quantity,InventoryMovement::SALE,'Venta por pedido',$user,[],'order',(string)$item->order_id,'sale-order-item-'.$item->id);
        });
    }

    public function returnCancellation(OrderItem $item, ?User $user=null): ?InventoryMovement
    {
        return DB::transaction(function() use($item,$user){
            if (!Schema::hasTable('warehouse_inventories')) { optional($item->product)->increment('stock',$item->quantity); return null; }
            $key='cancellation-order-item-'.$item->id;
            $warehouse=$item->warehouse ?: $this->defaultWarehouse();
            return $this->change($item->product,$warehouse,$item->quantity,InventoryMovement::CANCELLATION_RETURN,'Reposición por cancelación de pedido',$user,[],'order',(string)$item->order_id,$key);
        });
    }

    private function change(Product $product, Warehouse $warehouse, int $delta, string $type, string $reason, ?User $user, array $metadata=[], ?string $referenceType=null, ?string $referenceId=null, ?string $referenceKey=null): InventoryMovement
    {
        $this->positive(abs($delta));
        return DB::transaction(function() use($product,$warehouse,$delta,$type,$reason,$user,$metadata,$referenceType,$referenceId,$referenceKey){
            // La fila de saldo serializa operaciones del mismo producto/almacén. La consulta
            // idempotente ocurre después del lock y el UNIQUE sigue siendo la garantía final.
            $inventory=$this->lockInventory($product,$warehouse);
            if ($referenceKey && ($existing=InventoryMovement::where('idempotency_key',$referenceKey)->first())) return $existing;
            return $this->applyLocked($inventory,$delta,$type,$reason,$user,$referenceType,$referenceId,$referenceKey,$metadata);
        });
    }

    private function lockInventory(Product $product, Warehouse $warehouse): WarehouseInventory
    {
        $warehouse=Warehouse::with('branch')->whereKey($warehouse->id)->lockForUpdate()->firstOrFail();
        $this->assertActive($warehouse);
        $initial = $warehouse->is_default ? max(0, (int) $product->stock) : 0;
        $inserted = WarehouseInventory::insertOrIgnore(['warehouse_id'=>$warehouse->id,'product_id'=>$product->id,'quantity'=>$initial,'created_at'=>now(),'updated_at'=>now()]);
        if ($inserted && $initial > 0) {
            InventoryMovement::create(['idempotency_key'=>'initial-product-'.$product->id,'warehouse_id'=>$warehouse->id,'product_id'=>$product->id,'user_id'=>null,'type'=>InventoryMovement::INITIAL,'quantity'=>$initial,'quantity_before'=>0,'quantity_after'=>$initial,'reason'=>'Inicialización de compatibilidad desde products.stock','reference_type'=>'product','reference_id'=>(string)$product->id,'metadata'=>['source'=>'products.stock'],'created_at'=>now()]);
        }
        return WarehouseInventory::where('warehouse_id',$warehouse->id)->where('product_id',$product->id)->lockForUpdate()->firstOrFail();
    }

    private function applyLocked(WarehouseInventory $inventory, int $delta, string $type, string $reason, ?User $user, ?string $referenceType, ?string $referenceId, ?string $referenceKey, array $metadata): InventoryMovement
    {
        $before=(int)$inventory->quantity; $after=$before+$delta;
        if ($after<0) throw new InventoryException('La operación supera el stock disponible.');
        if ($after < (int)$inventory->reserved_quantity) throw new InventoryException('La operacion supera el stock disponible no reservado.');
        $inventory->update(['quantity'=>$after]);
        $movement=InventoryMovement::create(['warehouse_id'=>$inventory->warehouse_id,'product_id'=>$inventory->product_id,'user_id'=>$user?->id,'type'=>$type,'quantity'=>abs($delta),'quantity_before'=>$before,'quantity_after'=>$after,'reason'=>trim($reason),'reference_type'=>$referenceType,'reference_id'=>$referenceId,'idempotency_key'=>$referenceKey,'metadata'=>array_filter($metadata,fn($v)=>$v!==null&&$v!==''),'created_at'=>now()]);
        $this->syncProductStock($inventory->product_id);
        return $movement;
    }

    private function syncProductStock(int $productId): void
    {
        $total=(int)WarehouseInventory::where('product_id',$productId)->sum('quantity');
        Product::whereKey($productId)->lockForUpdate()->update(['stock'=>$total]);
    }

    private function assertActive(Warehouse $warehouse): void
    {
        $warehouse->loadMissing('branch');
        if (!$warehouse->is_active || !$warehouse->branch?->is_active) throw new InventoryException('El almacén y su sede deben estar activos.');
    }
    private function positive(int $quantity): void { if($quantity<=0) throw new InventoryException('La cantidad debe ser un entero positivo.'); }
}
