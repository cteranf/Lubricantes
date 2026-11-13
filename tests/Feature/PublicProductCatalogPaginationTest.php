<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PublicProductCatalogPaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_filtered_catalog_paginator_reports_the_global_total_for_a_short_last_page_and_zero_results(): void
    {
        $category = $this->category('Aceites', 'aceites');
        $otherCategory = $this->category('Filtros', 'filtros');
        $brand = $this->brand('Marca Uno', 'marca-uno');
        $otherBrand = $this->brand('Marca Dos', 'marca-dos');

        foreach (range(1, 15) as $number) {
            $this->product($category, $brand, 'Aceite '.$number);
        }
        $this->product($category, $otherBrand, 'Aceite otra marca');
        $this->product($otherCategory, $brand, 'Filtro otra categoría');

        $lastPage = $this->getJson('/api/v1/products?category=aceites&brand=marca-uno&sort=newest&page=2')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.total', 15);
        $this->assertSame(3, count($lastPage->json('data')));

        $this->getJson('/api/v1/products?category=aceites')
            ->assertOk()->assertJsonPath('meta.total', 16);
        $this->getJson('/api/v1/products?brand=marca-uno')
            ->assertOk()->assertJsonPath('meta.total', 16);
        $this->getJson('/api/v1/products?category=missing-category')
            ->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/products?search=Filtro%20otra')
            ->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_catalog_and_option_query_counts_do_not_grow_with_products_categories_or_brands(): void
    {
        $category = $this->category('Categoría principal', 'categoria-principal');
        $brand = $this->brand('Marca principal', 'marca-principal');
        foreach (range(1, 2) as $number) {
            $this->product($category, $brand, 'Producto '.$number);
        }

        $smallProductQueryCount = $this->queryCountFor('/api/v1/products');
        foreach (range(3, 18) as $number) {
            $this->product($category, $brand, 'Producto '.$number);
        }
        $manyProductQueryCount = $this->queryCountFor('/api/v1/products');
        $this->assertSame($smallProductQueryCount, $manyProductQueryCount, 'The listing query count must remain constant as products grow.');

        $smallCategoryCount = $this->queryCountFor('/api/v1/categories');
        foreach (range(1, 25) as $number) {
            $this->category('Categoría adicional '.$number, 'categoria-adicional-'.$number);
        }
        $manyCategoryCount = $this->queryCountFor('/api/v1/categories');
        $this->assertSame($smallCategoryCount, $manyCategoryCount, 'Category options must be eager-loaded, not queried per option.');

        $smallBrandCount = $this->queryCountFor('/api/v1/brands');
        foreach (range(1, 25) as $number) {
            $this->brand('Marca adicional '.$number, 'marca-adicional-'.$number);
        }
        $manyBrandCount = $this->queryCountFor('/api/v1/brands');
        $this->assertSame($smallBrandCount, $manyBrandCount, 'Brand options must be returned by a bounded query.');
    }

    private function queryCountFor(string $uri): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson($uri)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function category(string $name, string $slug): Category
    {
        return Category::create(['name' => $name, 'slug' => $slug, 'is_active' => true]);
    }

    private function brand(string $name, string $slug): Brand
    {
        return Brand::create(['name' => $name, 'slug' => $slug, 'is_active' => true]);
    }

    private function product(Category $category, Brand $brand, string $name): Product
    {
        return Product::create([
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'sku' => Str::upper(Str::random(12)),
            'price' => '10.00',
            'is_active' => true,
        ]);
    }
}
