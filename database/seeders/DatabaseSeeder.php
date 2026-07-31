<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run()
    {
        // Users
        User::create([
            'name' => 'Admin User',
            'email' => 'admin@lubristore.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]);

        User::create([
            'name' => 'Cliente Test',
            'email' => 'cliente@test.com',
            'password' => Hash::make('password'),
            'role' => 'customer',
        ]);

        // Categories
        $cats = ['Aceites de Motor', 'Transmisión', 'Frenz', 'Refrigerantes', 'Grasas'];
        $categories = [];
        foreach ($cats as $c) {
            $categories[] = Category::create([
                'name' => $c,
                'slug' => Str::slug($c),
            ]);
        }

        // Brands
        $brandsNames = ['Mobil 1', 'Castrol', 'Motul', 'Shell', 'Liqui Moly'];
        $brands = [];
        foreach ($brandsNames as $b) {
            $brands[] = Brand::create([
                'name' => $b,
                'slug' => Str::slug($b),
            ]);
        }

        // Products
        $types = ['Sintético', 'Semi-Sintético', 'Mineral'];
        $viscosities = ['5W-30', '10W-40', '20W-50', '0W-20'];

        for ($i = 1; $i <= 20; $i++) {
            $brand = $brands[array_rand($brands)];
            $cat = $categories[0]; // Mostly motor oils
            $type = $types[array_rand($types)];
            $visc = $viscosities[array_rand($viscosities)];

            $stock = rand(5, 50);
            $product = Product::create([
                'category_id' => $cat->id,
                'brand_id' => $brand->id,
                'name' => "Aceite {$brand->name} {$type} {$visc}",
                'slug' => Str::slug("Aceite {$brand->name} {$type} {$visc} " . Str::random(5)),
                'sku' => 'SKU-' . Str::upper(Str::random(8)),
                'description' => 'Aceite de alta calidad diseñado para proteger su motor en condiciones extremas.',
                'specifications' => "Viscosidad: {$visc}\nTipo: {$type}\nAPI SN/CF",
                'price' => rand(80, 250) + (rand(0, 99) / 100),
                'viscosity' => $visc,
                'type' => $type,
                'presentation' => '1 Galón',
                'image_path' => null, // Placeholder handled in frontend
                'is_active' => true,
                'is_featured' => rand(0, 1) == 1,
            ]);
            $inventory = app(InventoryService::class);
            $inventory->initializeProduct($product, $stock, $inventory->defaultWarehouse());
        }
    }
}
