<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class Branch extends Model
{
    use HasFactory;
    protected $fillable = ['code','name','description','address','district','province','department','phone','email','is_active'];
    protected $casts = ['is_active' => 'boolean'];
    public function warehouses() { return $this->hasMany(Warehouse::class); }
}
