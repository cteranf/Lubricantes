<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminCatalogManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_lists_searches_and_paginates_categories_and_brands(): void
    {
        Sanctum::actingAs($this->admin());
        Category::create(['name'=>'Filtros','slug'=>'filtros']); Brand::create(['name'=>'Mobil','slug'=>'mobil']);
        $this->getJson('/api/v1/admin/categories?search=Filt')->assertOk()->assertJsonPath('data.0.name','Filtros')->assertJsonStructure(['data','current_page','last_page']);
        $this->getJson('/api/v1/admin/brands?search=Mob')->assertOk()->assertJsonPath('data.0.name','Mobil')->assertJsonStructure(['data','current_page','last_page']);
    }

    public function test_customer_cannot_manage_categories_or_brands(): void
    {
        Sanctum::actingAs(User::factory()->create(['role'=>'customer']));
        $this->getJson('/api/v1/admin/categories')->assertForbidden();
        $this->getJson('/api/v1/admin/brands')->assertForbidden();
        $this->postJson('/api/v1/admin/categories',['name'=>'Nueva'])->assertForbidden();
        $this->postJson('/api/v1/admin/brands',['name'=>'Nueva'])->assertForbidden();
    }

    public function test_admin_creates_normalized_categories_and_brands_and_requires_names(): void
    {
        Sanctum::actingAs($this->admin());
        $this->postJson('/api/v1/admin/categories',['name'=>'  Aceites   de Motor  '])->assertCreated()->assertJsonPath('name','Aceites de Motor');
        $this->postJson('/api/v1/admin/brands',['name'=>'  Shell   Lubricants  '])->assertCreated()->assertJsonPath('name','Shell Lubricants');
        $this->postJson('/api/v1/admin/categories',[])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/v1/admin/brands',[])->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_trivial_case_and_space_duplicates_are_rejected(): void
    {
        Sanctum::actingAs($this->admin());
        Category::create(['name'=>'Aceites Premium','slug'=>'aceites-premium']); Brand::create(['name'=>'Castrol','slug'=>'castrol']);
        $this->postJson('/api/v1/admin/categories',['name'=>'  ACEITES   PREMIUM '])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/v1/admin/brands',['name'=>' CASTROL '])->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_admin_edits_and_changes_status_of_categories_and_brands(): void
    {
        Sanctum::actingAs($this->admin());
        $category=Category::create(['name'=>'Original','slug'=>'original']); $brand=Brand::create(['name'=>'Marca original','slug'=>'marca-original']);
        $this->putJson("/api/v1/admin/categories/{$category->id}",['name'=>'Categoria editada'])->assertOk()->assertJsonPath('name','Categoria editada');
        $this->putJson("/api/v1/admin/brands/{$brand->id}",['name'=>'Marca editada'])->assertOk()->assertJsonPath('name','Marca editada');
        $this->patchJson("/api/v1/admin/categories/{$category->id}/status",['is_active'=>false])->assertOk()->assertJsonPath('is_active',false);
        $this->patchJson("/api/v1/admin/brands/{$brand->id}/status",['is_active'=>false])->assertOk()->assertJsonPath('is_active',false);
    }

    public function test_options_contain_only_active_records_and_minimal_fields(): void
    {
        Sanctum::actingAs($this->admin());
        Category::create(['name'=>'Activa','slug'=>'activa','is_active'=>true]); Category::create(['name'=>'Inactiva','slug'=>'inactiva','is_active'=>false]);
        Brand::create(['name'=>'Visible','slug'=>'visible','is_active'=>true]); Brand::create(['name'=>'Oculta','slug'=>'oculta','is_active'=>false]);
        $this->getJson('/api/v1/admin/categories/options')->assertExactJson([['id'=>1,'name'=>'Activa']]);
        $this->getJson('/api/v1/admin/brands/options')->assertExactJson([['id'=>1,'name'=>'Visible']]);
    }

    public function test_deactivation_and_delete_preserve_entities_and_product_relations(): void
    {
        Sanctum::actingAs($this->admin());
        $category=Category::create(['name'=>'Relacionada','slug'=>'relacionada']); $brand=Brand::create(['name'=>'Relacionada','slug'=>'marca-relacionada']);
        $product=Product::create(['name'=>'Producto','slug'=>'producto-relacionado','sku'=>'REL-1','price'=>10,'category_id'=>$category->id,'brand_id'=>$brand->id]);
        $this->deleteJson("/api/v1/admin/categories/{$category->id}")->assertOk(); $this->deleteJson("/api/v1/admin/brands/{$brand->id}")->assertOk();
        $this->assertDatabaseHas('categories',['id'=>$category->id,'is_active'=>false]); $this->assertDatabaseHas('brands',['id'=>$brand->id,'is_active'=>false]);
        $this->assertSame($category->id,$product->refresh()->category->id); $this->assertSame($brand->id,$product->brand->id);
    }

    public function test_product_accepts_active_relations_rejects_invalid_or_inactive_and_returns_relations(): void
    {
        Sanctum::actingAs($this->admin());
        $category=Category::create(['name'=>'Activa','slug'=>'producto-activa']); $brand=Brand::create(['name'=>'Activa','slug'=>'marca-activa']);
        $payload=['name'=>'Producto catalogado','sku'=>'CAT-1','price'=>20,'cantidad_inicial'=>0,'category_id'=>$category->id,'brand_id'=>$brand->id];
        $response=$this->postJson('/api/v1/admin/products',$payload)->assertCreated()->assertJsonPath('category.name','Activa')->assertJsonPath('brand.name','Activa');
        $product=Product::findOrFail($response->json('id'));
        $this->getJson('/api/v1/admin/products')->assertOk()->assertJsonPath('data.0.category.name','Activa')->assertJsonPath('data.0.brand.name','Activa')->assertJsonPath('data.0.slug',$product->slug);
        $this->postJson('/api/v1/admin/products',array_merge($payload,['sku'=>'CAT-2','category_id'=>99999,'brand_id'=>99999]))->assertUnprocessable()->assertJsonValidationErrors(['category_id','brand_id']);
        $category->update(['is_active'=>false]); $brand->update(['is_active'=>false]);
        $this->postJson('/api/v1/admin/products',array_merge($payload,['sku'=>'CAT-3']))->assertUnprocessable()->assertJsonValidationErrors(['category_id','brand_id']);
        $this->putJson("/api/v1/admin/products/{$product->id}",['name'=>'Producto editado','category_id'=>$category->id,'brand_id'=>$brand->id])->assertOk()->assertJsonPath('category.id',$category->id)->assertJsonPath('brand.id',$brand->id);
        $this->getJson('/api/v1/products/undefined')->assertNotFound();
    }

    private function admin(): User { return User::factory()->create(['role'=>'admin']); }
}
