<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;
class InventoryMovement extends Model
{
    use HasFactory;
    public const INITIAL='initial', MANUAL_IN='manual_in', MANUAL_OUT='manual_out', TRANSFER_IN='transfer_in', TRANSFER_OUT='transfer_out', SALE='sale', CANCELLATION_RETURN='cancellation_return', CORRECTION='correction';
    public const TYPES=[self::INITIAL,self::MANUAL_IN,self::MANUAL_OUT,self::TRANSFER_IN,self::TRANSFER_OUT,self::SALE,self::CANCELLATION_RETURN,self::CORRECTION];
    public const INCOMING=[self::INITIAL,self::MANUAL_IN,self::TRANSFER_IN,self::CANCELLATION_RETURN];
    public const OUTGOING=[self::MANUAL_OUT,self::TRANSFER_OUT,self::SALE];
    public $timestamps=false;
    protected $fillable=['warehouse_id','product_id','user_id','type','quantity','quantity_before','quantity_after','reason','reference_type','reference_id','idempotency_key','metadata','created_at'];
    protected $casts=['quantity'=>'integer','quantity_before'=>'integer','quantity_after'=>'integer','metadata'=>'array','created_at'=>'datetime'];
    protected static function booted(): void
    {
        static::updating(fn()=>throw new LogicException('Los movimientos de inventario son inmutables.'));
        static::deleting(fn()=>throw new LogicException('Los movimientos de inventario no se pueden eliminar.'));
    }
    public function warehouse(){return $this->belongsTo(Warehouse::class);}
    public function product(){return $this->belongsTo(Product::class);}
    public function user(){return $this->belongsTo(User::class);}
}
