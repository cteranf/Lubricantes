<?php

namespace Tests\Feature;

use Tests\TestCase;

class BrandImageFrontendContractTest extends TestCase
{
    public function test_brand_management_uses_contained_logo_previews_and_preserves_generic_category_defaults(): void
    {
        $brands = file_get_contents(resource_path('js/views/admin/Brands.vue'));
        $manager = file_get_contents(resource_path('js/components/admin/CatalogEntityManager.vue'));

        $this->assertStringContainsString('image-enabled', $brands);
        $this->assertStringContainsString('image-label="Logotipo de marca"', $brands);
        $this->assertStringContainsString('image-alt-prefix="Logo de"', $brands);
        $this->assertStringContainsString('image-fit-class="object-contain"', $brands);
        $this->assertStringContainsString('image-preview-class="object-contain"', $brands);
        $this->assertStringContainsString('image-frame-class="h-12 w-12', $brands);
        $this->assertStringContainsString('image-help="JPEG, PNG o WebP. Máximo 2 MB;', $brands);
        $this->assertStringContainsString('image-remove-confirmation="¿Eliminar el logotipo de esta marca?', $brands);
        $this->assertStringContainsString('image-removal-message="La marca se conservó sin imagen."', $brands);

        $this->assertStringContainsString('`${imageAltPrefix} ${item.name}`', $manager);
        $this->assertStringContainsString('width="48" height="48"', $manager);
        $this->assertStringContainsString('v-else class="flex h-12 w-12', $manager);
        $this->assertStringContainsString(':class="imagePreviewClass"', $manager);
        $this->assertStringContainsString('previewUrl', $manager);
        $this->assertStringContainsString('function clearSelectedImage()', $manager);
        $this->assertStringContainsString('api.delete(`${props.endpoint}/${form.value.id}/image`)', $manager);
        $this->assertStringContainsString('if (props.imageEnabled && selectedFile.value)', $manager);
        $this->assertStringContainsString("imageFitClass: { type: String, default: 'object-cover' }", $manager);
        $this->assertStringContainsString("imageLabel: { type: String, default: 'Imagen de categoría' }", $manager);
    }
}
