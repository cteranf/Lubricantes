<?php
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\Branch;
use App\Models\BusinessTaxConfiguration;
use App\Models\Product;
use App\Models\ProductPromotion;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Models\User;
use App\Models\UserAddress;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
$db = DB::connection();
$identity = $db->selectOne('SELECT DATABASE() AS db, @@default_storage_engine AS engine');
$target = 'lubristore_einvoice_phase2_20261119_a7f3';
if ($identity->db !== $target || strtolower($identity->engine) !== 'innodb') { fwrite(STDERR, "ABORT target verification failed\n"); exit(2); }
if ($db->table('products')->where('sku', 'like', 'QA-F2-%')->exists() || $db->table('branches')->where('code', 'QA-TAX-P2-20261119')->exists()) { fwrite(STDERR, "ABORT QA fixture already exists\n"); exit(3); }
$user = User::where('email', 'phase2-qa-admin@example.test')->firstOrFail();
$branch = Branch::create(['code'=>'QA-TAX-P2-20261119','name'=>'Sede QA tributaria','address'=>'Av. QA 100','district'=>'Miraflores','province'=>'Lima','department'=>'Lima','business_hours'=>'Lunes a viernes 09:00-18:00','pickup_instructions'=>'Pedido sintético de QA','allows_pickup'=>true,'serves_public'=>true,'is_main'=>false,'is_active'=>true]);
$warehouse = Warehouse::create(['branch_id'=>$branch->id,'code'=>'QA-WH-P2-20261119','name'=>'Almacén QA F2','is_default'=>false,'is_active'=>true]);
$inventory = app(InventoryService::class);
$definitions = [
 ['taxable','QA F2 Gravado 118','QA-F2-118','118.00','10'],
 ['promotion','QA F2 Promoción','QA-F2-PROMO','130.00','10'],
 ['exempt','QA F2 Exonerado','QA-F2-EXEMPT','59.00','20'],
 ['unaffected','QA F2 Inafecto','QA-F2-UNAFFECTED','45.00','30'],
];
$products = [];
foreach ($definitions as [$key,$name,$sku,$price,$affectation]) {
 $product = Product::create(['name'=>$name,'slug'=>Str::slug($name).'-qa','sku'=>$sku,'description'=>'Producto sintético para validación tributaria Fase 2.','price'=>$price,'tax_affectation'=>$affectation,'is_active'=>true,'is_featured'=>false]);
 $inventory->initializeProduct($product, 20, $warehouse, $user);
 $products[$key] = $product;
}
$now = now();
ProductPromotion::create(['product_id'=>$products['promotion']->id,'promotion_price'=>'109.00','starts_at'=>$now->copy()->subDay(),'ends_at'=>$now->copy()->addDays(2),'is_active'=>true]);
$zone = ShippingZone::create(['code'=>'QA-TAX-P2-20261119','name'=>'Cobertura QA tributaria','estimated_days_min'=>1,'estimated_days_max'=>2,'is_active'=>true]);
$normalizer = app(App\Services\TerritoryNormalizer::class);
foreach ([['QA mixto','15.00'],['QA gratis','0.00']] as [$district,$amount]) {
 ShippingRate::create(['shipping_zone_id'=>$zone->id,'department'=>'Lima','province'=>'Lima','district'=>$district,'amount'=>$amount,'estimated_days_min'=>1,'estimated_days_max'=>2,'is_active'=>true]);
 UserAddress::create(['user_id'=>$user->id,'label'=>'QA '.$district,'recipient_name'=>$user->name,'phone'=>'999999999','address'=>'Av. QA 100','department'=>'Lima','province'=>'Lima','district'=>$district,'is_default'=>$district==='QA mixto','is_active'=>true]);
}
echo "database={$identity->db}\nengine={$identity->engine}\nbranch_id={$branch->id}\nwarehouse_id={$warehouse->id}\n";
foreach ($products as $key=>$product) { echo "product_{$key}_id={$product->id} stock={$product->fresh()->stock} physical=".$product->inventories()->sum('quantity')." reserved=".$product->inventories()->sum('reserved_quantity')."\n"; }
echo "promotion=109.00 (regular 130.00)\nshipping_mixed=15.00 shipping_free=0.00\n";