<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminProductsFrontendTest extends TestCase
{
    public function test_products_view_uses_the_shared_server_side_table_and_preserves_catalog_actions(): void
    {
        $view = file_get_contents(resource_path('js/views/admin/Products.vue'));
        $table = file_get_contents(resource_path('js/components/admin/table/AdminDataTable.vue'));
        $composable = file_get_contents(resource_path('js/composables/useAdminListQuery.js'));

        foreach ([
            '<AdminDataTable', 'row-key="id"',
            'search-placeholder="Buscar productos por nombre o SKU"',
            'current-page-report-template="Mostrando {first} a {last} de {totalRecords} productos"',
            'loading-message="Cargando productos…"', 'mobile-details-label="Detalles del producto {id}"',
            'useAdminListQuery({', "api.get('/admin/products', { params })",
            "sorts: ['created_at', 'id', 'name', 'sku', 'price', 'is_active']",
            'filters: filterKeys', "const filterKeys = ['search', 'sku', 'name', 'category_id', 'brand_id', 'is_active', 'price_min', 'price_max']",
            "{ key: 'name', label: 'Producto', sortable: true", "{ key: 'sku', label: 'SKU', sortable: true",
            "{ key: 'category', label: 'Categoría'", "{ key: 'brand', label: 'Marca'",
            "{ key: 'physical_stock', label: 'Stock físico'", "{ key: 'is_active', label: 'Estado', sortable: true",
            'row.image_url', 'pi pi-image', 'stock físico total', 'categoryOptions', 'brandOptions',
            'sizes="48px"', 'object-contain', 'formatProductPrice(row.price)', 'currency: \'PEN\'',
            'stockStatus(row.physical_stock)', "label: 'Stock bajo'", "label: 'Agotado'", "label: 'Disponible'",
            'productPaginationSummary', 'Mostrando ${from}–${to} de ${meta.total} productos',
            'pi pi-box', 'Registra un producto para comenzar.',
            '/admin/categories', '/admin/brands', 'cantidad_inicial', 'warehouse_id',
            'api.patch(`/admin/products/${product.id}/status`',
            'function confirmStatus(product)', 'list.load()', ':row-hover="true"', ':actions-frozen="true"',
        ] as $expected) {
            $this->assertStringContainsString($expected, $view);
        }

        foreach ([
            "import DataTable from 'primevue/datatable'", '<DataTable', ':lazy="true"', ':paginator="true"',
            ':first="first"', ':rows="pageSize"', ':total-records="totalRecords"', ':loading="loading"',
            ':data-key="rowIdentityContract.rowKey"', '@page="onPage"', '@sort="onSort"',
            ':row-hover="rowHover"', ':frozen="actionsFrozen"', ':align-frozen="actionsFrozen ? \'right\' : undefined"',
            'rowHover: Boolean', 'actionsFrozen: Boolean',
            'orders-mobile-expander md:hidden', 'v-model:expandedRows', '#expansion', 'detailColumns',
            'searchPlaceholder', 'currentPageReportTemplate', 'loadingMessage', 'mobileDetailsLabel',
            'globalFilterFields', 'rowKey: { type: String, required: true, validator:',
            '<slot name="empty">', '<template #loading>', '<slot name="actions"',
        ] as $expected) {
            $this->assertStringContainsString($expected, $table);
        }

        $this->assertStringNotContainsString('<table', $view);
        $this->assertStringNotContainsString('lastPage', $view);
        $this->assertStringNotContainsString('fetchProducts(', $view);

        foreach ([
            'function load()', 'requestSequence', 'sequence !== requestSequence',
            'watch(() => route.query', 'watch(() => route.path', 'function clearFilters()',
            'TEXT_FILTER_KEYS', 'debounceSequence',
        ] as $expected) {
            $this->assertStringContainsString($expected, $composable);
        }

        $this->assertStringContainsString('mobileDetail: true', $view);
        $this->assertStringContainsString('v-model:expandedRows', $table);
        $this->assertStringNotContainsString('via.placeholder.com', $view);
        $this->assertStringNotContainsString('v-html', $view);

        $this->assertStringContainsString('sort-mode="single"', $table);
        $this->assertStringContainsString(':sort-field="sort.column || undefined"', $table);
        $this->assertStringContainsString(':sort-order="sort.direction === \'asc\' ? 1 : -1"', $table);
        $this->assertStringContainsString('emit(\'page\', event.page + 1)', $table);
        $this->assertStringContainsString('emit(\'per-page\', event.rows)', $table);
        $this->assertStringContainsString('emit(\'sort\', event.sortField, event.sortOrder === 1 ? \'asc\' : \'desc\')', $table);
        $this->assertStringContainsString('router.push({ query })', $composable);
        $this->assertStringContainsString('sort: sort.value, direction: direction.value', $composable);
        $this->assertStringContainsString('sort: sorts.includes(source.sort) ? source.sort : sorts[0]', $composable);
        $this->assertStringContainsString("direction: source.direction === 'asc' ? 'asc' : 'desc'", $composable);
        $this->assertStringContainsString('import Menu from \'primevue/menu\'', $view);
        $this->assertStringContainsString('<Menu ref="mobileActionsMenu" :model="mobileActionItems" popup', $view);
        $this->assertStringContainsString('pi pi-ellipsis-v', $view);
        $this->assertStringContainsString('aria-haspopup="menu"', $view);
        $this->assertStringContainsString('v-tooltip.top', $view);
        $this->assertStringContainsString('aria-label="`${row.is_active ? \'Desactivar\' : \'Reactivar\'} ${row.name}`"', $view);
        $this->assertStringContainsString('label="Editar" icon="pi pi-pencil" severity="secondary" outlined size="small"', $view);
        $this->assertStringContainsString("row.is_active ? 'warn' : 'success'", $view);

        $statusConfirmation = $this->extractFunctionBody($view, 'confirmStatus');
        $statusRequest = $this->extractFunctionBody($view, 'changeStatus');
        $mobileActions = $this->sourceBetween($view, 'const mobileActionItems = computed(() => {', "\n});");
        $this->assertStringContainsString('statusBusy.has(product.id)', $statusConfirmation);
        $this->assertStringContainsString('confirm.require({', $statusConfirmation);
        $this->assertStringContainsString('accept: () => changeStatus(product)', $statusConfirmation);
        $this->assertStringContainsString('if (statusBusy.has(product.id)) return;', $statusRequest);
        $this->assertStringContainsString('statusBusy.add(product.id);', $statusRequest);
        $this->assertStringContainsString('statusBusy.delete(product.id);', $statusRequest);
        $this->assertStringContainsString('api.patch(`/admin/products/${product.id}/status`', $statusRequest);
        $this->assertStringContainsString('command: () => openModal(product)', $mobileActions);
        $this->assertStringContainsString('command: () => confirmStatus(product)', $mobileActions);
        $this->assertStringContainsString("label: product.is_active ? 'Desactivar' : 'Reactivar'", $mobileActions);
        $this->assertStringContainsString('disabled: busy', $mobileActions);

        $deleteImage = $this->sourceBetween($view, 'function deleteImage(image) {', 'async function performImageDeletion(');
        $performDelete = $this->sourceBetween($view, 'async function performImageDeletion(', 'async function replaceImageFile(');
        $this->assertSame(0, preg_match('/api\s*\.\s*delete\s*\(\s*`\/admin\/products\/\$\{[^}]+\}`\s*\)/', $view), 'Product removal must remain a status change, not DELETE /admin/products/{id}.');
        $this->assertStringContainsString('api.patch(`/admin/products/${product.id}/status`', $view);
        $this->assertMatchesRegularExpression('/api\s*\.\s*delete\s*\(\s*`\/admin\/products\/\$\{productId\}\/images\/\$\{imageId\}`\s*\)/', $performDelete);
        $this->assertStringContainsString('const productId = Number(product.id);', $deleteImage);
        $this->assertStringContainsString('const imageId = Number(image.id);', $deleteImage);
        $this->assertStringContainsString('confirm.require({', $deleteImage);
        $this->assertStringContainsString('accept: () => performImageDeletion(productId, imageId, opening)', $deleteImage);
        $this->assertStringContainsString('deletingImageIds.value.has(imageId)', $deleteImage.$performDelete);
        $this->assertStringContainsString('deletingImageIds.value.add(imageId)', $performDelete);
        $this->assertStringContainsString('deletingImageIds.value.delete(imageId)', $performDelete);
        $this->assertStringContainsString('await refreshProductImages(productId, opening);', $performDelete);
        $this->assertStringContainsString('detail: safeRequestMessage(error)', $performDelete);
        $this->assertStringContainsString('¿Eliminar esta fotografía de la galería?', $deleteImage);
        $this->assertStringContainsString(':key="image.id"', $view);
    }

    public function test_product_gallery_keeps_logical_images_multi_select_limit_and_local_statuses(): void
    {
        $view = file_get_contents(resource_path('js/views/admin/Products.vue'));
        $gallery = $this->sourceBetween($view, '<section aria-labelledby="product-gallery-title"', '</section>');
        $select = $this->extractFunctionBody($view, 'selectGalleryFiles');
        $remove = $this->extractFunctionBody($view, 'removeImagePreview');
        $status = $this->extractFunctionBody($view, 'imageProcessingLabel');
        $clear = $this->extractFunctionBody($view, 'clearImagePreview');
        $release = $this->extractFunctionBody($view, 'releaseImageObjectUrl');
        $replacement = $this->extractFunctionBody($view, 'chooseReplacement');
        $replacementUpload = $this->extractFunctionBody($view, 'replaceImageFile');
        $unmount = $this->sourceBetween($view, 'onBeforeUnmount(() => {', "\n});");

        $this->assertSame(1, substr_count($gallery, '<FileUpload'));
        $this->assertStringContainsString("import FileUpload from 'primevue/fileupload'", $view);
        $this->assertStringContainsString("import Image from 'primevue/image'", $view);
        $this->assertStringContainsString("import Tag from 'primevue/tag'", $view);
        $this->assertStringContainsString('Galería del producto', $gallery);
        $this->assertStringContainsString('`${galleryImageCount} de 6`', $gallery);
        $this->assertStringContainsString('v-for="(image, index) in selectedProduct.images"', $gallery);
        $this->assertStringNotContainsString('v-for="variant in image.variants"', $gallery);
        $this->assertStringContainsString('image.is_primary', $gallery);
        $this->assertStringContainsString('Principal', $gallery);
        $this->assertStringContainsString('imageProcessingLabel(image.processing_status)', $gallery);
        $this->assertStringContainsString(':multiple="!replacementTargetImageId"', $gallery);
        $this->assertStringContainsString(':auto="false"', $gallery);
        $this->assertStringContainsString(':show-upload-button="false"', $gallery);
        $this->assertStringContainsString(':max-file-size="10 * 1024 * 1024"', $gallery);
        $this->assertStringContainsString('accept="image/jpeg,image/png,image/webp"', $gallery);
        $this->assertStringContainsString(':file-limit="6"', $gallery);
        $this->assertStringContainsString('template #header', $gallery);
        $this->assertStringContainsString('template #content', $gallery);
        $this->assertStringContainsString('template #empty', $gallery);
        $this->assertStringContainsString('<Image', $gallery);
        $this->assertStringContainsString('preview', $gallery);
        $this->assertStringContainsString('<Tag', $gallery);
        $this->assertSame(0, preg_match('/<FileUpload\b[^>]*\burl\s*=/s', $gallery));
        $this->assertStringContainsString('Agregar fotografías', $gallery);
        $this->assertStringContainsString('v-for="(preview, index) in imagePreviews"', $gallery);
        $this->assertStringContainsString('@click="removeImagePreview(index)"', $gallery);
        $this->assertStringContainsString('preview.file.name', $gallery);
        $this->assertStringContainsString('formatFileSize(preview.file.size)', $gallery);
        $this->assertStringContainsString('v-tooltip.top', $gallery);
        $this->assertStringContainsString(':aria-label="', $gallery);
        $this->assertStringContainsString('max-h-[calc(100vh-2rem)] w-[95vw] max-w-[960px]', $view);
        $this->assertStringContainsString('aria-live="polite"', $gallery);
        $this->assertStringContainsString('role="alert" aria-live="assertive"', $gallery);
        $this->assertSame(0, preg_match('/<input\b[^>]*type="file"/i', $view), 'Products.vue must use one PrimeVue FileUpload, not duplicate native file inputs.');

        $this->assertStringContainsString('const existingCount = selectedProduct.value?.images?.length || 0;', $select);
        $this->assertStringContainsString('const currentFiles = Array.isArray(form.value.images) ? form.value.images : [];', $select);
        $this->assertStringContainsString('existingCount + currentFiles.length + files.length > 6', $select);
        $this->assertStringContainsString('form.value.images = [...currentFiles, ...files];', $select);
        $this->assertStringContainsString('imagePreviews.value = [...imagePreviews.value, ...files.map', $select);
        $this->assertStringContainsString('ownImageObjectUrl(file)', $select);
        $this->assertStringContainsString('releaseImageObjectUrl(preview.file)', $remove);
        $this->assertStringContainsString('form.value.images.splice(index, 1);', $remove);
        $this->assertStringContainsString('ownedImageObjectUrls.forEach(url => URL.revokeObjectURL(url))', $clear);
        $this->assertStringContainsString('ownedImageObjectUrls.delete(url)', $release);
        $this->assertStringContainsString('clearGalleryUploadQueue();', $clear);
        $this->assertStringContainsString('replacementTargetImageId.value = Number(image.id);', $replacement);
        $this->assertStringContainsString('galleryUpload.value?.choose();', $replacement);
        $this->assertStringContainsString('Number(item.id) === Number(replacementId)', $select);
        $this->assertStringContainsString('api.post(`/admin/products/${product.id}/images/${image.id}/replace`, data)', $replacementUpload);
        $this->assertStringContainsString('file.objectURL', $view);
        $this->assertStringContainsString('clearImagePreview();', $unmount);
        $this->assertStringNotContainsString('url="/admin/products', $gallery);
        $this->assertStringNotContainsString('uploadCallback()', $gallery);

        foreach ([
            "pending: 'Pendiente'", "processing: 'Procesando'", "ready: 'Lista'", "failed: 'No procesada'",
            "'Estado no disponible'",
        ] as $expected) {
            $this->assertStringContainsString($expected, $status);
        }
        $this->assertStringNotContainsString('response.data', $status);
        $this->assertStringNotContainsString('error.message', $status);
    }

    public function test_product_form_preserves_descriptions_and_guards_stale_openings_and_duplicate_submits(): void
    {
        $view = file_get_contents(resource_path('js/views/admin/Products.vue'));
        $controller = file_get_contents(app_path('Http/Controllers/Api/V1/Admin/ProductController.php'));
        $resource = file_get_contents(app_path('Http/Resources/AdminProductListResource.php'));
        $imageResource = file_get_contents(app_path('Http/Resources/AdminProductImageResource.php'));
        $request = file_get_contents(app_path('Http/Requests/Admin/ListAdminProductsRequest.php'));

        $this->assertStringContainsString("'products.stock', 'products.created_at', 'products.description'", $controller);
        $this->assertStringContainsString("'description' => \$this->description", $resource);
        $this->assertStringNotContainsString("'image_processing_status' =>", $resource);
        $this->assertStringNotContainsString('image_processing_status', $view);
        $this->assertStringContainsString("'processing_status' =>", $imageResource);
        $this->assertStringNotContainsString('image_processing_status', $imageResource);
        $this->assertStringContainsString("description: product.description ?? ''", $view);

        $opening = $this->sourceBetween($view, 'async function openModal(', 'function isCurrentOpening(');
        $this->assertStringContainsString('const sequence = ++openingSequence;', $opening);
        $this->assertStringContainsString("const mode = product ? 'edit' : 'create';", $opening);
        $this->assertStringContainsString('const productId = product?.id ?? null;', $opening);
        $this->assertStringContainsString('const optionsResult = await loadOptions();', $opening);
        $this->assertStringContainsString('if (!isCurrentOpening(sequence, productId, mode)) return;', $opening);
        $this->assertLessThan(strpos($opening, 'await loadOptions()'), strpos($opening, 'form.value ='));
        $this->assertLessThan(strpos($opening, 'await loadOptions()'), strpos($opening, 'showModal.value = true;'));

        $currentOpening = $this->sourceBetween($view, 'function isCurrentOpening(', 'async function retryOptions(');
        $this->assertStringContainsString('openingSequence === sequence', $currentOpening);
        $this->assertStringContainsString('selectedProduct.value?.id === productId', $currentOpening);
        $this->assertStringContainsString("mode === 'edit'", $currentOpening);

        $close = $this->sourceBetween($view, 'function closeModal(', 'async function submitForm(');
        $this->assertStringContainsString('openingSequence++;', $close);
        $this->assertStringContainsString('showModal.value = false;', $close);
        $this->assertStringContainsString('imageSaved.value = false;', $close);
        $this->assertStringContainsString('stopImageStatusPolling();', $close);

        $openImageState = $this->sourceBetween($view, 'async function openModal(', 'function isCurrentOpening(');
        $this->assertStringContainsString('imageSaved.value = false;', $openImageState);

        $selectImage = $this->sourceBetween($view, 'function selectGalleryFiles(', 'function removeImagePreview(');
        $this->assertStringContainsString('if (imageSaved.value) {', $selectImage);

        $options = $this->sourceBetween($view, 'function loadOptions(', 'async function openModal(');
        $this->assertStringContainsString('if (optionsPromise) return optionsPromise;', $options);
        $this->assertStringContainsString('optionsPromise = null;', $options);
        $this->assertStringContainsString('.catch((error) => ({ ok: false, message: safeRequestMessage(error) }))', $options);
        $this->assertStringContainsString('@click="retryOptions"', $view);

        $unmount = $this->sourceBetween($view, 'onBeforeUnmount(() => {', '});');
        $this->assertStringContainsString('isComponentMounted = false;', $unmount);
        $this->assertStringContainsString('openingSequence++;', $unmount);
        $this->assertStringContainsString('submissionSequence++;', $unmount);
        $this->assertStringContainsString('if (isComponentMounted) {', $options);

        $submit = $this->sourceBetween($view, 'async function submitForm() {', 'function confirmStatus(');
        $submitBody = substr($submit, strpos($submit, '{') + 1);
        $guardCondition = 'if (processing.value || imageSaved.value || specificationsLoading.value || specificationsError.value) return;';
        $guard = strpos($submitBody, $guardCondition);
        $this->assertSame(0, strpos(ltrim($submitBody), $guardCondition), 'Submit re-entry, active image-operation, and specification-loading guards must be the first operation.');
        $this->assertLessThan(strpos($submitBody, 'new FormData()'), $guard);
        $this->assertLessThan(strpos($submitBody, 'toast.add('), $guard);
        $this->assertLessThan(strpos($submitBody, 'await api.post('), $guard);
        $this->assertStringContainsString("const mode = isEditing.value ? 'edit' : 'create';", $submit);
        $this->assertStringContainsString('const productId = selectedProduct.value?.id ?? null;', $submit);
        $this->assertLessThan(strpos($submitBody, 'await api.post('), strpos($submitBody, 'const productId = selectedProduct.value?.id ?? null;'));
        $this->assertSame(1, substr_count($submit, "await api.post('/admin/products', data)"));
        $this->assertSame(1, substr_count($submit, 'await api.post(`/admin/products/${productId}`, data)'));
        $this->assertSame(0, preg_match('/api\s*\.\s*post\s*\(\s*`[^`]*\$\{\s*form\.value\.id\s*\}/s', substr($submit, $firstRequest ?? 0)));
        $this->assertStringContainsString("if (mode === 'edit')", $submit);
        $this->assertStringContainsString("data.append('_method', 'PUT');", $submit);
        $this->assertStringContainsString("if (mode === 'create')", $submit);
        $this->assertLessThan(strpos($submitBody, 'await api.post('), strpos($submitBody, 'processing.value = true;'));
        $firstRequest = strpos($submit, 'await api.post(');
        $this->assertNotFalse($firstRequest);
        $this->assertSame(0, preg_match('/api\\s*\\.\\s*post\\s*\\([^;]*form\\.value\\.id/s', substr($submit, $firstRequest)));
        $this->assertStringContainsString('if (isComponentMounted && submissionSequence === submission) processing.value = false;', $submit);
        $this->assertStringContainsString('const submission = ++submissionSequence;', $submit);
        $this->assertStringContainsString('const opening = openingSequence;', $submit);
        $this->assertStringContainsString('if (isCurrentOpening(opening, productId, mode)) {', $submit);
        $this->assertSame(1, preg_match('/:\s*disabled\s*=\s*[\'\"]\s*processing\s*\|\|\s*optionsLoading\s*\|\|\s*imageSaved\s*\|\|\s*specificationsLoading\s*\|\|\s*Boolean\(specificationsError\)\s*[\'\"]/s', $view));
        $this->assertStringContainsString('imageSaved.value = true;', $submit);
        $this->assertStringContainsString('imageSaved.value = false;', $submit);
        $this->assertStringContainsString('safeRequestMessage(error)', $submit);

        $noImageSuccess = $this->sourceBetween($submit, "} else {\n                closeModal();", "\n            }\n        }\n    } catch");
        $this->assertStringNotContainsString('imageSaved.value = true;', $noImageSuccess);

        $polling = $this->sourceBetween($view, 'async function pollImageStatus(', 'function checkImageStatus(');
        $terminalPolling = $this->sourceBetween($polling, "if (status === 'ready' || status === 'failed') {", 'if (Date.now() - imageStatusStartedAt');
        $this->assertStringContainsString('imageSaved.value = false;', $terminalPolling);
        $this->assertStringContainsString('imageFollowupNeeded.value = false;', $terminalPolling);
        $timeoutPolling = $this->sourceBetween($polling, 'if (Date.now() - imageStatusStartedAt >= 120000) {', 'imageStatusTimer = setTimeout');
        $this->assertStringContainsString('imageFollowupNeeded.value = true;', $timeoutPolling);
        $this->assertStringNotContainsString('imageSaved.value = false;', $timeoutPolling);
        $this->assertStringContainsString('return;', $timeoutPolling);
        $this->assertStringContainsString('@click="checkImageStatus"', $view);

        $regeneration = $this->extractFunctionBody($view, 'requestImageRegeneration');
        $this->assertStringContainsString('const targetImage = image || product?.images?.find(item => item.is_primary) || null;', $regeneration);
        $this->assertStringContainsString('const imageId = Number(targetImage.id);', $regeneration);
        $this->assertStringContainsString('!targetImage?.can_regenerate_image', $regeneration);
        $this->assertStringContainsString('imageSaved.value || imageStatusBusy.value', $regeneration);
        $this->assertStringContainsString('imageSaved.value = true;', $regeneration);
        $this->assertStringContainsString('imageSaved.value = false;', $regeneration);
        $this->assertStringContainsString("if (imageSaved.value || imageStatusBusy.value || !isCurrentOpening(requestedOpening, product.id, 'edit')) return;", $regeneration);
        $this->assertStringContainsString('selectedProduct.value?.images || []).some(item => Number(item.id) === imageId)', $regeneration);
        $this->assertStringContainsString('api.post(`/admin/products/${product.id}/images/${imageId}/regenerate`)', $regeneration);
        $this->assertStringContainsString("startImageStatusPolling(product.id, 'regenerate', opening, [imageId]);", $regeneration);
        $this->assertSame(0, preg_match('/(?:product|selectedProduct)\.images\s*\[\s*0\s*\]/', $regeneration));
        $this->assertSame(0, preg_match('/image_processing_status/', $regeneration));

        $pollingGuard = $this->extractFunctionBody($view, 'imageStatusIsCurrent');
        $this->assertStringContainsString('imageStatusSequence === sequence', $pollingGuard);
        $this->assertStringContainsString('Number(selectedProduct.value?.id) === Number(productId)', $pollingGuard);

        $deleteImage = $this->extractFunctionBody($view, 'deleteImage');
        $performDelete = $this->extractFunctionBody($view, 'performImageDeletion');
        $this->assertStringContainsString('confirm.require({', $deleteImage);
        $this->assertStringContainsString('accept: () => performImageDeletion(productId, imageId, opening)', $deleteImage);
        $this->assertSame(0, preg_match('/api\s*\.\s*delete\s*\(/', $deleteImage), 'The DELETE request must occur only after confirmation is accepted.');
        $this->assertStringContainsString('deletingImageIds.value.has(imageId)', $performDelete);
        $this->assertStringContainsString('deletingImageIds.value.add(imageId);', $performDelete);
        $this->assertStringContainsString('deletingImageIds.value.delete(imageId);', $performDelete);
        $this->assertLessThan(strpos($performDelete, 'api.delete('), strpos($performDelete, 'deletingImageIds.value.add(imageId);'));
        $this->assertMatchesRegularExpression('/finally\s*\{\s*deletingImageIds\.value\.delete\(imageId\);\s*\}/s', $performDelete);
        $this->assertStringContainsString('await refreshProductImages(productId, opening);', $performDelete);
        $this->assertStringContainsString('detail: safeRequestMessage(error)', $performDelete);
        $this->assertMatchesRegularExpression('/:disabled="imageSaved\s*\|\|\s*imageStatusBusy\s*\|\|\s*deletingImageIds\.has\(Number\(image\.id\)\)"/', $view);
        $this->assertStringContainsString(':key="image.id"', $view);
        $this->assertStringNotContainsString('processing_status', $performDelete, 'Deletion is allowed during processing; deleting the image row invalidates its worker.');

        $this->assertStringContainsString("'products.description'", $request.$controller);
        $this->assertStringNotContainsString('v-html', $view);
    }

    public function test_function_body_extractor_accepts_parameter_variants_and_balances_nested_blocks(): void
    {
        $noArguments = "function requestImageRegeneration() { if (ready) { try { return { state: 'done' }; } catch (error) { return null; } } return false; }\nfunction outside() { return true; }";
        $withDefaultAndWhitespace = "function requestImageRegeneration(\n image = null\n) { const nested = { first: { second: true } }; if (nested.first.second) { return `literal }`; } return nested; }\nfunction outside() { return true; }";

        foreach ([[$noArguments, 'return { state: \'done\' };'], [$withDefaultAndWhitespace, 'const nested = { first: { second: true } };']] as [$source, $expected]) {
            $body = $this->extractFunctionBody($source, 'requestImageRegeneration');
            $this->assertStringContainsString($expected, $body);
            $this->assertStringNotContainsString('function outside', $body);
        }
    }

    private function extractFunctionBody(string $source, string $functionName): string
    {
        $pattern = '/\b(?:async\s+)?function\s+'.preg_quote($functionName, '/').'\s*\(/';
        $this->assertSame(1, preg_match($pattern, $source, $match, PREG_OFFSET_CAPTURE), 'Function declaration was not found: '.$functionName);
        $openParenthesis = strpos($source, '(', $match[0][1]);
        $closeParenthesis = $this->matchingDelimiter($source, $openParenthesis, '(', ')');
        $bodyStart = $closeParenthesis + 1;
        while ($bodyStart < strlen($source) && ctype_space($source[$bodyStart])) {
            $bodyStart++;
        }
        $this->assertSame('{', $source[$bodyStart] ?? null, 'Function body opening brace was not found: '.$functionName);
        $bodyEnd = $this->matchingDelimiter($source, $bodyStart, '{', '}');

        return substr($source, $bodyStart + 1, $bodyEnd - $bodyStart - 1);
    }

    private function matchingDelimiter(string $source, int $openOffset, string $open, string $close): int
    {
        $depth = 0;
        $quote = null;
        $length = strlen($source);

        for ($index = $openOffset; $index < $length; $index++) {
            $character = $source[$index];
            $next = $source[$index + 1] ?? '';
            if ($quote !== null) {
                if ($character === '\\') {
                    $index++;
                } elseif ($character === $quote) {
                    $quote = null;
                }

                continue;
            }
            if ($character === '/' && $next === '/') {
                $lineEnd = strpos($source, "\n", $index + 2);
                $index = $lineEnd === false ? $length : $lineEnd;

                continue;
            }
            if ($character === '/' && $next === '*') {
                $commentEnd = strpos($source, '*/', $index + 2);
                $this->assertNotFalse($commentEnd, 'Unterminated comment while extracting a function body.');
                $index = $commentEnd + 1;

                continue;
            }
            if (in_array($character, ["'", '"', '`'], true)) {
                $quote = $character;

                continue;
            }
            if ($character === $open) {
                $depth++;
            } elseif ($character === $close) {
                $depth--;
                if ($depth === 0) {
                    return $index;
                }
            }
        }

        $this->fail('Unbalanced source delimiter while extracting a function body.');
    }

    private function sourceBetween(string $source, string $start, string $end): string
    {
        $startOffset = strpos($source, $start);
        $this->assertNotFalse($startOffset, 'Expected source section start was not found.');
        $contentOffset = $startOffset + strlen($start);
        $endOffset = strpos($source, $end, $contentOffset);
        $this->assertNotFalse($endOffset, 'Expected source section end was not found.');

        return substr($source, $startOffset, $endOffset - $startOffset);
    }

    public function test_product_filters_and_errors_use_fixed_safe_messages_and_explicit_contracts(): void
    {
        $view = file_get_contents(resource_path('js/views/admin/Products.vue'));
        $request = file_get_contents(app_path('Http/Requests/Admin/ListAdminProductsRequest.php'));
        $controller = file_get_contents(app_path('Http/Controllers/Api/V1/Admin/ProductController.php'));
        $resource = file_get_contents(app_path('Http/Resources/AdminProductListResource.php'));

        foreach ([
            "'category_id'", "'brand_id'", "'is_active'", "'price_min'", "'price_max'",
            "Rule::in(['0', '1'])", "Rule::in(['asc', 'desc'])", 'parse_str($rawQuery, $query)',
            'MoneyService::class', "ESCAPE '!'", 'orderBy($sortColumn, $direction)',
            'orderBy(\'products.id\', $direction)', 'withSum(\'inventories as inventory_stock\', \'quantity\')',
            '\'physical_stock\' => (int) ($this->inventory_stock ?? $this->stock ?? 0)',
            '\'image_url\' => $this->image_url', "'category' =>", "'brand' =>",
        ] as $expected) {
            $this->assertStringContainsString($expected, $request.$controller.$resource);
        }

        foreach ([
            'No se pudo conectar. Verifica tu conexión e inténtalo nuevamente.',
            'Ocurrió un problema interno. Inténtalo nuevamente.',
            'No se pudo guardar', 'safeRequestMessage(error)',
        ] as $expected) {
            $this->assertStringContainsString($expected, $view);
        }

        foreach ([
            'error.response.data.message', 'error.message',
            'error.response.statusText', 'JSON.stringify(error.response.data)',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $view);
        }

        $this->assertStringContainsString('error?.response?.data?.errors', $view);
        $this->assertStringContainsString("return errors.value[key] ? 'Revisa este campo e inténtalo nuevamente.' : '';", $view);
        $this->assertStringContainsString('detail: safeRequestMessage(error)', $view);
        $this->assertFalse($this->containsRemoteErrorValueInVisibleSink($view));

        $this->assertStringContainsString('if (! $this->rawQueryParameterNamesAllowed)', $request);
        $this->assertStringContainsString('hasAllowedRawQueryParameterNames($rawQuery)', $request);
        $this->assertStringContainsString('Rule::in(self::SORT_COLUMNS)', $request);
        $this->assertStringNotContainsString('Schema::hasTable', $controller);
        $this->assertStringNotContainsString('->filter(', $controller);
    }

    public function test_remote_validation_error_inspection_is_safe_but_presentation_is_not(): void
    {
        $safeCases = [
            'optional response error map inspection' => 'const errors = error?.response?.data?.errors;',
            'allowlisted validation field selection' => 'Object.keys(errors).filter((key) => allowedFields.includes(key));',
            'fixed status mapper' => "const message = messagesByStatus[error?.response?.status] ?? 'No se pudo completar la solicitud.';",
        ];

        foreach ($safeCases as $caseName => $source) {
            $this->assertFalse($this->containsRemoteErrorValueInVisibleSink($source), 'Safe source case failed: '.$caseName);
        }

        $unsafeCases = [
            'field value assigned to visible error message' => 'errorMessage = error.response.data.errors.name[0]',
            'field value used as toast detail' => 'toast.add({ detail: error.response.data.errors.name[0] })',
            'field value interpolated in template' => '<p>{{ error.response.data.errors.name[0] }}</p>',
            'serialized validation body in toast' => 'toast.add({ detail: JSON.stringify(error.response.data.errors) })',
            'optional field value in visible message' => 'toast.add({ detail: error?.response?.data?.errors?.name?.[0] })',
        ];

        foreach ($unsafeCases as $caseName => $source) {
            $this->assertTrue($this->containsRemoteErrorValueInVisibleSink($source), 'Unsafe source case failed: '.$caseName);
        }
    }

    private function containsRemoteErrorValueInVisibleSink(string $source): bool
    {
        $normalized = preg_replace('/\?\s*\./', '.', $source) ?? $source;
        $normalized = preg_replace('/\s*\.\s*/', '.', $normalized) ?? $normalized;
        $remoteErrors = '(?:[A-Za-z_$][A-Za-z0-9_$]*\.)+data\.errors(?:\b|\[)';
        $patterns = [
            '~\b(?:errorMessage|message|description|text)\s*=\s*[^;]*?'.$remoteErrors.'~is',
            '~\b(?:detail|summary|message|description|text)\s*:\s*[^,;}]*?'.$remoteErrors.'~is',
            '~\{\{[^}]*?'.$remoteErrors.'[^}]*\}\}~is',
            '~\b(?:innerHTML|textContent|innerText)\s*=\s*[^;]*?'.$remoteErrors.'~is',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalized) === 1) {
                return true;
            }
        }

        return false;
    }
}
