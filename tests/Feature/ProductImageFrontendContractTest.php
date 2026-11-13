<?php

namespace Tests\Feature;

use Tests\TestCase;

class ProductImageFrontendContractTest extends TestCase
{
    public function test_catalog_detail_cart_and_checkout_request_responsive_public_variants(): void
    {
        $card = file_get_contents(resource_path('js/components/ProductCard.vue'));
        $detail = file_get_contents(resource_path('js/views/ProductDetail.vue'));
        $normalizedDetail = str_replace("\r\n", "\n", $detail);
        $cart = file_get_contents(resource_path('js/views/Cart.vue'));
        $checkout = file_get_contents(resource_path('js/views/Checkout.vue'));
        $store = file_get_contents(resource_path('js/stores/cart.js'));
        $helper = file_get_contents(resource_path('js/utils/productPresentation.js'));

        // Catalog cards consume the product's primary-image aliases. The detail
        // view instead consumes one logical image selected from its gallery.
        $this->assertMatchesRegularExpression('/<img[^>]*:src="primaryImage"[^>]*:srcset="productImageSrcset\(product, \[\x27card\x27, \x27medium\x27\]\)"[^>]*:sizes="imageSizes"[^>]*:width="imageDimensions\?\.width"[^>]*:height="imageDimensions\?\.height"[^>]*loading="lazy"/s', $card);
        $this->assertStringContainsString('preferredImage.value?.url || resolveProductImages(props.product)[0]', $card);
        $this->assertStringContainsString("productImageSrcset(product, ['card', 'medium'])", $card);

        $mainImage = $this->sourceBetween($detail, '<img v-if="selectedImage', '</div>');
        $galleryTemplate = $this->sourceBetween($detail, '<button v-for="(image, index) in images"', '</button>');
        $detailSelection = $this->sourceBetween($detail, 'const detailImage = value => {', "\nconst isPublicProduct");
        $loadProduct = $this->sourceBetween($normalizedDetail, 'const loadProduct = async () => {', "\n};\n\nfunction resetProductReviews");

        $this->assertStringContainsString(':src="selectedImage.url"', $mainImage);
        $this->assertStringContainsString("productImageSrcset(selectedImage, ['medium', 'detail', 'detail_source', 'zoom'])", $mainImage);
        $this->assertStringContainsString(':width="selectedImageDimensions?.width"', $mainImage);
        $this->assertStringContainsString(':height="selectedImageDimensions?.height"', $mainImage);
        $this->assertStringContainsString('selectedImage.id', $mainImage);
        $this->assertStringContainsString('sizes="(max-width: 1024px) 100vw, 50vw"', $detail);
        $this->assertStringNotContainsString('loading="lazy"', $mainImage);
        $this->assertStringContainsString('loading="lazy"', $galleryTemplate);

        $this->assertStringContainsString('v-for="(image, index) in images"', $galleryTemplate);
        $this->assertStringContainsString('@click="selectedImage = image"', $galleryTemplate);
        $this->assertStringContainsString(':key="image.id || image.url"', $galleryTemplate);
        $this->assertStringNotContainsString('v-for="variant', $detail);
        $this->assertStringNotContainsString('Object.entries(image.variants)', $detail);

        $this->assertStringContainsString('gallery.find(item => item.is_primary) || gallery[0]', $detailSelection);
        $this->assertStringContainsString('const firstImage = detailImage(response.data)', $loadProduct);
        $this->assertStringContainsString('selectedImage.value = firstImage', $loadProduct);
        $this->assertStringContainsString('product.value = null', $loadProduct);
        $this->assertStringContainsString('const gallery = resolveProductGallery(value)', $detailSelection);
        $this->assertStringContainsString('if (!image) return null', $detailSelection);
        $this->assertStringContainsString('failedImages.value = new Set()', $loadProduct);

        foreach ([$cart, $checkout] as $view) {
            $this->assertStringContainsString(':srcset="item.image_srcset"', $view);
            $this->assertStringContainsString(':sizes="item.image_sizes || \'160px\'"', $view);
            $this->assertStringContainsString(':width="item.image_dimensions?.width"', $view);
            $this->assertStringContainsString(':height="item.image_dimensions?.height"', $view);
            $this->assertStringContainsString('loading="lazy"', $view);
            $this->assertStringContainsString('v-if="item.image"', $view);
        }

        // The cart stores a public primary-image snapshot; it does not flatten
        // image variants into separate logical photos.
        $this->assertMatchesRegularExpression('/image:\s*productImageVariants\(product\)\.find\(image => image\.key === [\'\"]thumbnail[\'\"]\)\?\.url\s*\|\|\s*productImageVariants\(product\)\.find\(image => image\.key === [\'\"]card[\'\"]\)\?\.url\s*\|\|\s*resolveProductImages\(product\)\[0\]\s*\|\|\s*null/s', $store);
        $this->assertStringContainsString("productImageSrcset(product, ['thumbnail', 'card'])", $store);
        $this->assertStringContainsString('image_srcset: productImageSrcset(product, [\'thumbnail\', \'card\'])', $store);
        $this->assertStringContainsString('image_dimensions: productImageDimensions(product, [\'thumbnail\', \'card\'])', $store);
        $this->assertStringContainsString("image_sizes: '160px'", $store);
        $this->assertStringContainsString('${item.url} ${item.width}w', $helper);
        $this->assertStringContainsString('if (!Array.isArray(product?.images)) return legacyGallery()', $helper);
        $this->assertStringContainsString('return gallery.length ? gallery : legacyGallery()', $helper);
        $this->assertStringContainsString('variants: image.variants || {}', $helper);
        $this->assertStringContainsString('srcset: typeof image.srcset === \'string\' ? image.srcset : undefined', $helper);
        $this->assertStringContainsString('width: image.width', $helper);
        $this->assertStringContainsString('height: image.height', $helper);
        $this->assertStringContainsString('image?.image_url || image?.image_path', $helper);
        $this->assertStringNotContainsString('v-html', $detail.$cart.$checkout.$card);
    }

    public function test_administration_uses_local_preview_and_safe_individual_regeneration_polling(): void
    {
        $view = file_get_contents(resource_path('js/views/admin/Products.vue'));
        $resource = file_get_contents(app_path('Http/Resources/AdminProductListResource.php'));
        $adminImageResource = file_get_contents(app_path('Http/Resources/AdminProductImageResource.php'));
        $publicImageResource = file_get_contents(app_path('Http/Resources/ProductImageResource.php'));
        $model = file_get_contents(app_path('Models/Product.php'));
        $imageModel = file_get_contents(app_path('Models/ProductImage.php'));
        $publicResource = file_get_contents(app_path('Http/Resources/ProductResource.php'));
        $controller = file_get_contents(app_path('Http/Controllers/Api/V1/Admin/ProductImageController.php'));
        $storeRequest = file_get_contents(app_path('Http/Requests/Admin/StoreProductRequest.php'));
        $updateRequest = file_get_contents(app_path('Http/Requests/Admin/UpdateProductRequest.php'));
        $processor = file_get_contents(app_path('Services/ProductImageProcessor.php'));
        $imageConfig = file_get_contents(config_path('product_images.php'));

        $this->assertStringContainsString('URL.createObjectURL(file)', $view);
        $this->assertStringContainsString('URL.revokeObjectURL(url)', $view);
        $this->assertStringContainsString('releaseImageObjectUrl(preview.file)', $view);
        $this->assertStringContainsString('ownedImageObjectUrls.forEach(url => URL.revokeObjectURL(url))', $view);
        $this->assertStringNotContainsString('data:image', $view);
        $this->assertStringNotContainsString('base64', $view);
        $this->assertStringContainsString('accept="image/jpeg,image/png,image/webp"', $view);
        $uploadHelp = $this->sourceBetween($view, '<p id="product-gallery-help"', '</p>');
        $this->assertMatchesRegularExpression('/jpeg\s*,\s*png\s*o\s*webp/i', $uploadHelp);
        $this->assertMatchesRegularExpression('/máximo\s*10\s*mb\s*por\s*archivo/i', $uploadHelp);
        $this->assertMatchesRegularExpression('/800\s*×\s*800\s*a\s*5000\s*×\s*5000/i', $uploadHelp);
        $this->assertMatchesRegularExpression('/máximo\s*seis\s*fotografías\s*por\s*producto/i', $uploadHelp);

        // The visible help mirrors the server contract: config-backed byte and
        // dimension validation, file rules, and existing + incoming photo count.
        $this->assertMatchesRegularExpression('/[\x27\"]max_bytes[\x27\"]\s*=>\s*10\s*\*\s*1024\s*\*\s*1024/', $imageConfig);
        $this->assertMatchesRegularExpression('/[\x27\"]max_images[\x27\"]\s*=>\s*6\b/', $imageConfig);
        $this->assertMatchesRegularExpression('/[\x27\"]min_width[\x27\"]\s*=>\s*800\b/', $imageConfig);
        $this->assertMatchesRegularExpression('/[\x27\"]min_height[\x27\"]\s*=>\s*800\b/', $imageConfig);
        $this->assertMatchesRegularExpression('/[\x27\"]max_width[\x27\"]\s*=>\s*5000\b/', $imageConfig);
        $this->assertMatchesRegularExpression('/[\x27\"]max_height[\x27\"]\s*=>\s*5000\b/', $imageConfig);
        $this->assertStringContainsString("'images.*' => ['required', 'file', 'max:10240'", $storeRequest);
        $this->assertStringContainsString("'max:'.(int) config('product_images.max_images', 6)", $storeRequest);
        $this->assertStringContainsString('app(ProductImageProcessor::class)->validateUpload($value)', $storeRequest);
        $this->assertStringContainsString('$existing + count($value) > (int) config(\'product_images.max_images\', 6)', $updateRequest);
        $this->assertStringContainsString("'images.*' => ['required', 'file', 'max:10240'", $updateRequest);
        $this->assertStringContainsString('config(\'product_images.max_bytes\')', $processor);
        $this->assertStringContainsString('self::MIME_BY_EXTENSION', $processor);
        $this->assertStringContainsString("'image/jpeg'", $processor);
        $this->assertStringContainsString("'image/png'", $processor);
        $this->assertStringContainsString("'image/webp'", $processor);
        $this->assertStringContainsString("config('product_images.min_width')", $processor);
        $this->assertStringContainsString("config('product_images.max_width')", $processor);
        $this->assertStringContainsString('Regenerar formatos', $view);
        $this->assertStringContainsString('Se volverán a crear las versiones optimizadas usando la configuración y marca de agua actuales. La imagen publicada seguirá disponible durante el proceso', $view);
        $this->assertStringContainsString('api.post(`/admin/products/${product.id}/images/${imageId}/regenerate`)', $view);
        $stopPolling = $this->sourceBetween($view, 'function stopImageStatusPolling() {', "\nfunction startImageStatusPolling");
        $startPolling = $this->sourceBetween($view, 'function startImageStatusPolling(', "\nfunction imageStatusIsCurrent");
        $polling = $this->sourceBetween($view, 'async function pollImageStatus(', "\nfunction checkImageStatus");
        $pollingCatch = $this->sourceBetween($polling, '} catch {', '} finally {');
        $currentGuard = $this->sourceBetween($view, 'function imageStatusIsCurrent(', "\nasync function pollImageStatus");
        $closeModal = $this->sourceBetween($view, 'function closeModal() {', "\nfunction clearImagePreview");
        $openModal = $this->sourceBetween($view, 'async function openModal(', "\nfunction closeModal");
        $unmount = $this->sourceBetween($view, 'onBeforeUnmount(() => {', "\n});");

        $this->assertMatchesRegularExpression('/clearTimeout\s*\(\s*imageStatusTimer\s*\)/', $stopPolling);
        $this->assertMatchesRegularExpression('/\b[A-Za-z_$][\w$]*\s*\?\.\s*abort\s*\(\s*\)/', $stopPolling);
        $this->assertMatchesRegularExpression('/imageStatusSequence\s*\+\+/', $stopPolling);
        $this->assertStringContainsString('stopImageStatusPolling();', $closeModal);
        $this->assertStringContainsString('stopImageStatusPolling();', $openModal);
        $this->assertStringContainsString('stopImageStatusPolling();', $startPolling);
        $this->assertStringContainsString('stopImageStatusPolling();', $unmount);
        $this->assertMatchesRegularExpression('/setTimeout\s*\(\s*\(\)\s*=>\s*pollImageStatus\s*\(/', $startPolling);

        $this->assertMatchesRegularExpression('/if\s*\(!imageStatusIsCurrent\([^;]+\)\s*\|\|\s*imageStatusRequestInFlight\)\s*return/', $polling);
        $this->assertSame(1, preg_match_all('/\bapi\s*\.\s*get\s*\(/', $polling));
        $this->assertMatchesRegularExpression('/new\s+AbortController\s*\(/', $polling);
        $this->assertMatchesRegularExpression('/api\s*\.\s*get\s*\([^;]*\{\s*signal\s*:\s*[A-Za-z_$][\w$]*\.signal\s*\}\s*\)/s', $polling);
        $this->assertMatchesRegularExpression('/imageStatusRequestInFlight\s*=\s*true/', $polling);
        $this->assertMatchesRegularExpression('/if\s*\(imageStatusController\s*===\s*controller\)/', $polling);
        $this->assertMatchesRegularExpression('/if\s*\(!imageStatusIsCurrent\([^;]+\)\)\s*return/', $polling);
        $this->assertStringNotContainsString('toast.add(', $pollingCatch);
        $this->assertStringContainsString('No se pudo consultar el estado de la imagen. Inténtalo nuevamente.', $polling);
        $this->assertMatchesRegularExpression('/imageStatusStartedAt\s*>=\s*120000/', $polling);
        $this->assertMatchesRegularExpression('/status\s*===\s*[\'\"]ready[\'\"]\s*\|\|\s*status\s*===\s*[\'\"]failed[\'\"]/', $polling);
        $this->assertMatchesRegularExpression('/showModal\.value\s*&&/', $currentGuard);
        $this->assertStringContainsString('imageStatusSequence === sequence', $currentGuard);
        $this->assertStringContainsString('Consultar estado', $view);
        $this->assertStringContainsString('images/${imageStatusImageId}/status', $polling);
        $this->assertStringNotContainsString('/image/status', $polling);
        $this->assertStringNotContainsString('image_processing_status', $view);
        $this->assertStringNotContainsString("'image_processing_status' =>", $resource);
        $this->assertStringNotContainsString("'can_regenerate_image' =>", $resource);
        $this->assertStringContainsString("'processing_status' =>", $adminImageResource);
        $this->assertStringContainsString("'can_regenerate_image' =>", $adminImageResource);
        $this->assertStringNotContainsString("'processing_status' =>", $publicImageResource);
        $this->assertStringNotContainsString("'can_regenerate_image' =>", $publicImageResource);
        $this->assertStringContainsString(<<<'PHP'
'can_regenerate_image' => $this->canRegenerate()
PHP, $adminImageResource);
        $this->assertStringNotContainsString('can_regenerate_image', $model);
        $this->assertStringNotContainsString('image_processing_status', $model);
        $this->assertStringContainsString("protected \$hidden = ['original_path', 'pending_original_path', 'generation_id', 'variants'];", $imageModel);
        $this->assertStringNotContainsString('image_original_path', $controller);
        $this->assertStringNotContainsString('v-html', $view);
        $this->assertStringNotContainsString('processing_status', $publicResource);
        $this->assertStringNotContainsString('can_regenerate_image', $publicResource);
    }

    public function test_public_image_contract_keeps_legacy_fallback_and_hides_private_processing_fields(): void
    {
        $model = file_get_contents(app_path('Models/Product.php'));
        $resource = file_get_contents(app_path('Http/Resources/AdminProductListResource.php'));
        $publicController = file_get_contents(app_path('Http/Controllers/Api/V1/ProductController.php'));

        $imageModel = file_get_contents(app_path('Models/ProductImage.php'));
        $this->assertStringContainsString("'image_url' => \$this->image_url", $resource);
        $this->assertStringContainsString("'image_variants' => \$this->image_variants", $resource);
        $this->assertSame(2, preg_match_all("/with\\(\\[\\s*'category',\\s*'brand',\\s*'images',\\s*'specifications:id,product_id,name,value,sort_order',?\\s*\\]\\)/s", $publicController));
        $this->assertStringNotContainsString("with(['category', 'brand', 'images', 'primaryImage'])", $publicController);
        $this->assertStringContainsString(<<<'PHP'
$published->first(fn (ProductImage $image) => $image->is_primary) ?? $published->first()
PHP, $model);
        $this->assertStringContainsString(<<<'PHP'
$legacy = self::safeLegacyImagePath($this->attributes['image_path'] ?? null)
PHP, $model);
        $this->assertStringContainsString(<<<'PHP'
$disk->exists($legacy) ? $disk->url($legacy) : null
PHP, $model);
        $this->assertStringNotContainsString('originals/', $resource);
        $this->assertStringContainsString("protected \$hidden = ['original_path', 'pending_original_path', 'generation_id', 'variants'];", $imageModel);
    }

    private function sourceBetween(string $source, string $start, string $end): string
    {
        $startOffset = strpos($source, $start);
        $this->assertNotFalse($startOffset);
        $endOffset = strpos($source, $end, $startOffset + strlen($start));
        $this->assertNotFalse($endOffset);

        return substr($source, $startOffset, $endOffset - $startOffset);
    }
}
