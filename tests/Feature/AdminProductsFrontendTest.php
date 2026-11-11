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
            '/admin/categories', '/admin/brands', 'cantidad_inicial', 'warehouse_id',
            'api.patch(`/admin/products/${product.id}/status`',
            'function confirmStatus(product)', 'list.load()',
        ] as $expected) {
            $this->assertStringContainsString($expected, $view);
        }

        foreach ([
            "import DataTable from 'primevue/datatable'", '<DataTable', ':lazy="true"', ':paginator="true"',
            ':first="first"', ':rows="pageSize"', ':total-records="totalRecords"', ':loading="loading"',
            ':data-key="rowIdentityContract.rowKey"', '@page="onPage"', '@sort="onSort"',
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
        $this->assertStringNotContainsString('api.delete(', $view);
    }

    public function test_product_form_preserves_descriptions_and_guards_stale_openings_and_duplicate_submits(): void
    {
        $view = file_get_contents(resource_path('js/views/admin/Products.vue'));
        $controller = file_get_contents(app_path('Http/Controllers/Api/V1/Admin/ProductController.php'));
        $resource = file_get_contents(app_path('Http/Resources/AdminProductListResource.php'));
        $request = file_get_contents(app_path('Http/Requests/Admin/ListAdminProductsRequest.php'));

        $this->assertStringContainsString("'products.stock', 'products.created_at', 'products.description'", $controller);
        $this->assertStringContainsString("'description' => \$this->description", $resource);
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
        $guard = strpos($submitBody, 'if (processing.value) return;');
        $this->assertSame(0, strpos(ltrim($submitBody), 'if (processing.value) return;'), 'Submit re-entry guard must be the first operation.');
        $this->assertLessThan(strpos($submitBody, 'new FormData()'), $guard);
        $this->assertLessThan(strpos($submitBody, 'toast.add('), $guard);
        $this->assertLessThan(strpos($submitBody, 'await api.post('), $guard);
        $this->assertStringContainsString("const mode = isEditing.value ? 'edit' : 'create';", $submit);
        $this->assertStringContainsString('const productId = selectedProduct.value?.id ?? null;', $submit);
        $this->assertLessThan(strpos($submitBody, 'await api.post('), strpos($submitBody, 'const productId = selectedProduct.value?.id ?? null;'));
        $this->assertSame(1, substr_count($submit, "await api.post('/admin/products', data)"));
        $this->assertSame(1, substr_count($submit, 'await api.post(`/admin/products/${productId}`, data)'));
        $this->assertStringContainsString("if (mode === 'edit')", $submit);
        $this->assertStringContainsString("data.append('_method', 'PUT');", $submit);
        $this->assertStringContainsString("if (mode === 'create')", $submit);
        $this->assertLessThan(strpos($submitBody, 'await api.post('), strpos($submitBody, 'processing.value = true;'));
        $firstRequest = strpos($submit, 'await api.post(');
        $this->assertNotFalse($firstRequest);
        $this->assertStringNotContainsString('form.value.id', substr($submit, $firstRequest));
        $this->assertStringContainsString('if (isComponentMounted && submissionSequence === submission) processing.value = false;', $submit);
        $this->assertStringContainsString('const submission = ++submissionSequence;', $submit);
        $this->assertStringContainsString('const opening = openingSequence;', $submit);
        $this->assertStringContainsString('if (isCurrentOpening(opening, productId, mode)) {', $submit);
        $this->assertStringContainsString(':disabled="processing || optionsLoading"', $view);
        $this->assertStringContainsString('safeRequestMessage(error)', $submit);

        $this->assertStringContainsString("'products.description'", $request.$controller);
        $this->assertStringNotContainsString('v-html', $view);
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
