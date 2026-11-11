<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminOrdersFrontendTest extends TestCase
{
    public function test_orders_view_statically_retires_the_legacy_approval_action_for_treasury_submissions(): void
    {
        $view = file_get_contents(resource_path('js/views/admin/Orders.vue'));

        foreach (['has_payment_submission', 'payment_submission_status', '!selectedOrder.has_payment_submission', 'paymentSubmissionLabel', 'Pendiente de Tesorería', 'Observado por Tesorería', 'Aprobado por Tesorería', 'Rechazado por Tesorería', 'Presentación vencida', 'Presentación cancelada', 'Este pago se gestiona desde el flujo de Tesorería.', 'approve-transfer'] as $expected) {
            $this->assertStringContainsString($expected, $view);
        }
        foreach (['/treasury/payments', 'v-html', 'localStorage', 'sessionStorage', 'operation_number', 'duplicate_fingerprint', 'idempotency_key', 'receiving_account_snapshot'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $view);
        }
    }

    public function test_admin_list_contract_preserves_route_queries_and_uses_stable_table_keys(): void
    {
        $composable = file_get_contents(resource_path('js/composables/useAdminListQuery.js'));
        $table = file_get_contents(resource_path('js/components/admin/table/AdminDataTable.vue'));
        $view = file_get_contents(resource_path('js/views/admin/Orders.vue'));
        $entrypoint = file_get_contents(resource_path('js/app.js'));
        $package = file_get_contents(base_path('package.json'));

        $this->assertStringContainsString('unrelatedQuery(source)', $composable);
        $this->assertStringContainsString('...unrelatedQuery(route.query)', $composable);
        $this->assertStringContainsString('fetchPage(params())', $composable);
        $this->assertStringContainsString('watch(() => route.query', $composable);
        $this->assertStringContainsString('cancelDebounce()', $composable);
        $this->assertStringContainsString('debounceSequence', $composable);
        $this->assertStringContainsString('onBeforeUnmount', $composable);
        $this->assertStringContainsString('rowKey: { type: String, required: true, validator:', $table);
        $this->assertStringContainsString('row-key="id"', $view);
        $this->assertStringContainsString("import DataTable from 'primevue/datatable'", $table);
        $this->assertStringContainsString("import Column from 'primevue/column'", $table);
        $this->assertStringContainsString(':lazy="true"', $table);
        $this->assertStringContainsString(':paginator="true"', $table);
        $this->assertStringContainsString(':data-key="rowIdentityContract.rowKey"', $table);
        $this->assertStringContainsString(':first="first"', $table);
        $this->assertStringContainsString(':rows="pageSize"', $table);
        $this->assertStringContainsString(':total-records="totalRecords"', $table);
        $this->assertStringContainsString(':rows-per-page-options="pageSizeOptions"', $table);
        $this->assertStringContainsString(':loading="loading"', $table);
        $this->assertStringContainsString(':sort-field="sort.column || undefined"', $table);
        $this->assertStringContainsString(':sort-order="sort.direction === \'asc\' ? 1 : -1"', $table);
        $this->assertStringContainsString('@page="onPage"', $table);
        $this->assertStringContainsString('@sort="onSort"', $table);
        $this->assertStringContainsString('emit(\'page\', event.page + 1)', $table);
        $this->assertStringContainsString("emit('per-page', event.rows)", $table);
        $this->assertStringContainsString("emit('sort', event.sortField", $table);
        $this->assertStringContainsString('v-model:expandedRows', $table);
        $this->assertStringContainsString('#expansion', $table);
        $this->assertStringContainsString('visibleTokens', $table);
        $this->assertStringContainsString("searchPlaceholder: { type: String, default: 'Buscar pedido o cliente' }", $table);
        $this->assertStringContainsString("currentPageReportTemplate: { type: String, default: 'Mostrando {first} a {last} de {totalRecords} pedidos' }", $table);
        $this->assertStringContainsString("loadingMessage: { type: String, default: 'Cargando pedidos…' }", $table);
        $this->assertStringContainsString("mobileDetailsLabel: { type: String, default: 'Detalle del pedido {id}' }", $table);
        $this->assertStringContainsString(':page="list.meta.value.current_page"', $view);
        $this->assertStringContainsString(':page-size="list.meta.value.per_page"', $view);
        $this->assertStringContainsString(':total-records="list.meta.value.total"', $view);
        $this->assertStringContainsString('@page="list.setPage"', $view);
        $this->assertStringContainsString('@per-page="list.setPerPage"', $view);
        $this->assertStringContainsString('@sort="list.setSort"', $view);
        $this->assertStringNotContainsString('AdminPagination', $view);
        $this->assertStringNotContainsString('AdminTableToolbar', $view);
        $this->assertStringNotContainsString("{ key: 'fulfillment_status', label: 'Estado', sortable: true", $view);
        $this->assertStringNotContainsString('<option value="status">', $view);
        $this->assertStringNotContainsString("sorts: ['created_at', 'total', 'status', 'id']", $view);
        $this->assertSame(1, substr_count($entrypoint, 'app.use(PrimeVue'));
        $this->assertStringContainsString("import Aura from '@primeuix/themes/aura'", $entrypoint);
        $this->assertStringContainsString('preset: Aura', $entrypoint);
        $this->assertStringNotContainsString("emptyMessage: 'No hay pedidos para mostrar.'", $entrypoint);
        $this->assertStringContainsString('<template #empty>', $table);
        $this->assertStringContainsString('Mostrando {first} a {last} de {totalRecords} pedidos', $table);
        $this->assertStringContainsString('pageSizeOptions = [15, 25, 50, 100]', $table);
        $this->assertStringNotContainsString('datatables.net', strtolower($package));
        $this->assertStringNotContainsString('jquery', strtolower($package));
        $this->assertStringNotContainsString('v-html', $view);
    }

    public function test_primevue_dependencies_and_removed_table_components_are_scoped_to_the_orders_pilot(): void
    {
        $package = json_decode(file_get_contents(base_path('package.json')), true, flags: JSON_THROW_ON_ERROR);
        $lock = file_get_contents(base_path('package-lock.json'));
        $view = file_get_contents(resource_path('js/views/admin/Orders.vue'));

        $this->assertSame('4.5.4', ltrim($package['dependencies']['primevue'], '^'));
        $this->assertSame('2.0.2', $package['dependencies']['@primeuix/themes']);
        $this->assertArrayNotHasKey('@primevue/themes', $package['dependencies']);
        $this->assertStringContainsString('node_modules/@primeuix/themes', $lock);
        $this->assertStringNotContainsString('node_modules/jquery', $lock);
        $this->assertStringNotContainsString('node_modules/datatables.net', $lock);
        $this->assertStringContainsString('stateLabel(row.fulfillment_status || legacyState(row), row)', $view);
        $this->assertStringContainsString("row.payment_status === 'approved'", $view);
        $this->assertStringContainsString('row.reservation_status || \'legacy\'', $view);

        foreach ([
            'resources/js/components/admin/table/AdminPagination.vue',
            'resources/js/components/admin/table/AdminMobileRecord.vue',
            'resources/js/components/admin/table/AdminTableToolbar.vue',
            'resources/js/components/admin/table/AdminTableSkeleton.vue',
            'resources/js/components/admin/table/AdminTableEmpty.vue',
        ] as $relativePath) {
            $this->assertFileDoesNotExist(base_path($relativePath));
        }
    }

    public function test_admin_datatable_rejects_invalid_or_colliding_row_identities_without_exposing_them(): void
    {
        $table = file_get_contents(resource_path('js/components/admin/table/AdminDataTable.vue'));

        $this->assertStringContainsString('rowKey: { type: String, required: true, validator:', $table);
        $this->assertStringNotContainsString('rowKey: { type: [String, Function]', $table);
        $this->assertStringContainsString(':data-key="rowIdentityContract.rowKey"', $table);
        $this->assertStringContainsString('const rowIdentityContract = computed(() => {', $table);
        $this->assertStringContainsString("row === null || typeof row !== 'object' || Array.isArray(row)", $table);
        $this->assertStringContainsString('value === null || value === undefined', $table);
        $this->assertStringContainsString("typeof value === 'string' && value.trim() !== ''", $table);
        $this->assertStringContainsString("typeof value === 'number' && Number.isFinite(value)", $table);
        $this->assertStringContainsString('if (!validString && !validNumber)', $table);
        $this->assertStringContainsString('const token = String(value)', $table);
        $this->assertStringContainsString('if (seen.has(token))', $table);
        $this->assertStringContainsString('seen.add(token)', $table);
        $this->assertStringContainsString('const validatedRows = computed(() => rowIdentityContract.value.valid ? props.rows : [])', $table);
        $this->assertStringContainsString('v-else-if="!rowIdentityContract.valid"', $table);
        $this->assertStringContainsString('role="alert"', $table);
        $this->assertStringContainsString('clave de fila inválida o duplicada', $table);
        $this->assertStringContainsString(':value="validatedRows"', $table);
        $this->assertStringContainsString('const visibleTokens = new Set(rowIdentityContract.value.valid ? rowIdentityContract.value.tokens : [])', $table);
        $this->assertStringContainsString('visibleTokens.has(token)', $table);
        $this->assertStringContainsString('watch(() => rowIdentityContract.value.tokens', $table);
        $this->assertStringContainsString('expandedRowState.value = pruneExpandedRows()', $table);
        $this->assertStringNotContainsString(':key="index"', $table);
        $this->assertStringNotContainsString('console.', $table);
        $this->assertStringNotContainsString('{{ token }}', $table);
        $this->assertStringNotContainsString('{{ rowKey }}', $table);

        $this->assertStringContainsString('const rowKey = props.rowKey', $table);
        $this->assertStringContainsString("typeof rowKey !== 'string'", $table);
        $this->assertStringContainsString('const canonicalRowKey = rowKey.trim()', $table);
        $this->assertStringContainsString('!canonicalRowKey || canonicalRowKey !== rowKey', $table);
        $this->assertStringContainsString('row[canonicalRowKey]', $table);
        $this->assertStringNotContainsString('row[props.rowKey]', $table);
        $this->assertStringNotContainsString(':data-key="props.rowKey"', $table);
        $this->assertStringContainsString('return { valid: false, rowKey: null, tokens: [] }', $table);

        $guardPosition = strpos($table, 'v-else-if="!rowIdentityContract.valid"');
        $tablePosition = strpos($table, '<DataTable');
        $this->assertNotFalse($guardPosition);
        $this->assertNotFalse($tablePosition);
        $this->assertLessThan($tablePosition, $guardPosition);

        $runtimeKeyTypePosition = strpos($table, "typeof rowKey !== 'string'");
        $rowIterationPosition = strpos($table, 'for (const row of props.rows)');
        $this->assertNotFalse($runtimeKeyTypePosition);
        $this->assertNotFalse($rowIterationPosition);
        $this->assertLessThan($rowIterationPosition, $runtimeKeyTypePosition);
    }

    public function test_orders_filters_use_primevue_menus_and_flat_server_side_query_contract(): void
    {
        $table = file_get_contents(resource_path('js/components/admin/table/AdminDataTable.vue'));
        $view = file_get_contents(resource_path('js/views/admin/Orders.vue'));
        $productsView = file_get_contents(resource_path('js/views/admin/Products.vue'));
        $composable = file_get_contents(resource_path('js/composables/useAdminListQuery.js'));
        $request = file_get_contents(app_path('Http/Requests/Admin/ListAdminOrdersRequest.php'));
        $controller = file_get_contents(app_path('Http/Controllers/Api/V1/Admin/OrderController.php'));

        $this->assertStringContainsString('filter-display="menu"', $table);
        $this->assertStringContainsString('label="Limpiar filtros"', $table);
        $this->assertStringContainsString(':placeholder="searchPlaceholder"', $table);
        $this->assertStringContainsString("searchPlaceholder: { type: String, default: 'Buscar pedido o cliente' }", $table);
        $this->assertStringContainsString(':current-page-report-template="currentPageReportTemplate"', $table);
        $this->assertStringContainsString("currentPageReportTemplate: { type: String, default: 'Mostrando {first} a {last} de {totalRecords} pedidos' }", $table);
        $this->assertStringContainsString('{{ loadingMessage }}', $table);
        $this->assertStringContainsString("loadingMessage: { type: String, default: 'Cargando pedidos…' }", $table);
        $this->assertStringContainsString('mobileDetailsLabel.replace(\'{id}\', String(data.id))', $table);
        $this->assertStringContainsString("mobileDetailsLabel: { type: String, default: 'Detalle del pedido {id}' }", $table);
        $this->assertStringContainsString('<slot name="empty">No hay registros para mostrar.</slot>', $table);
        $this->assertStringContainsString('<template #empty>', $view);
        $this->assertStringContainsString('No hay pedidos para mostrar', $view);
        $this->assertStringContainsString('<AdminDataTable', $view);
        $this->assertStringNotContainsString('search-placeholder=', $view);
        $this->assertStringNotContainsString('current-page-report-template=', $view);
        $this->assertStringNotContainsString('loading-message=', $view);
        $this->assertStringNotContainsString('mobile-details-label=', $view);
        foreach ([
            'search-placeholder="Buscar productos por nombre o SKU"',
            'current-page-report-template="Mostrando {first} a {last} de {totalRecords} productos"',
            'loading-message="Cargando productos…"',
            'mobile-details-label="Detalles del producto {id}"',
            '<template #empty>',
        ] as $productOverride) {
            $this->assertStringContainsString($productOverride, $productsView);
        }
        $this->assertStringContainsString('import Button from \'primevue/button\'', $table);
        $this->assertStringContainsString('import InputText from \'primevue/inputtext\'', $table);
        $this->assertStringContainsString('import IconField from \'primevue/iconfield\'', $table);
        $this->assertStringContainsString('import InputIcon from \'primevue/inputicon\'', $table);
        $this->assertStringContainsString(':filter="Boolean(column.filterKey)"', $table);
        $this->assertStringContainsString('v-if="$slots.actions"', $table);
        $this->assertStringNotContainsString("filterKey: 'actions'", $view);
        $this->assertStringContainsString('filterKey: \'order_id\'', $view);
        $this->assertStringContainsString('filterKey: \'customer\'', $view);
        $this->assertStringContainsString('filterKey: \'total_range\'', $view);
        $this->assertStringContainsString('filterKey: \'date_range\'', $view);
        $this->assertStringContainsString('option-value="value"', $view);
        $this->assertStringContainsString('value: String(warehouse.id), label: warehouse.name', $view);
        $this->assertStringContainsString('const values = { search: tableFilters.value.global?.value || \'\' }', $table);
        $this->assertStringContainsString('values.total_min = value?.[0] || \'\'', $table);
        $this->assertStringContainsString('values.date_from = toCivilDate(value?.[0])', $table);
        $this->assertStringContainsString('emit(\'filters-change\', filterValuesFromTable())', $table);
        $this->assertStringContainsString('...Object.fromEntries(filterKeys.filter((key) => filterValues[key] !== \'\')', $composable);
        $this->assertStringContainsString('TEXT_FILTER_KEYS', $composable);
        $this->assertStringContainsString('debounceMs = 350', $composable);
        $this->assertStringContainsString('if (resetPage) next.page = \'1\'', $composable);
        $this->assertStringContainsString('unrelatedQuery(route.query)', $composable);
        $this->assertStringContainsString('this->whereEffectiveFulfillmentStatus($query', $controller);
        $this->assertStringContainsString('->addDay()->startOfDay()', $controller);
        $this->assertStringContainsString('MoneyService', $controller);
        $this->assertStringContainsString('Se recibió un parámetro no permitido.', $request);
        $this->assertStringContainsString('date_format:Y-m-d', $request);
        $this->assertStringContainsString('total_min', $request);
        $this->assertStringContainsString('warehouse_id', $request);
        $this->assertStringNotContainsString('filters: list.filters', $view);
        $this->assertStringNotContainsString('JSON.stringify(tableFilters', $view);
        $this->assertStringNotContainsString('v-html', $view);
    }

    public function test_mobile_filter_panel_shares_state_applies_once_and_primevue_locale_is_spanish(): void
    {
        $view = file_get_contents(resource_path('js/views/admin/Orders.vue'));
        $table = file_get_contents(resource_path('js/components/admin/table/AdminDataTable.vue'));
        $composable = file_get_contents(resource_path('js/composables/useAdminListQuery.js'));
        $entrypoint = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString('id="orders-mobile-filters-toggle"', $view);
        $this->assertStringContainsString('ref="mobileFiltersToggle"', $view);
        $this->assertStringContainsString('aria-expanded="mobileFiltersOpen"', $view);
        $this->assertStringContainsString('aria-controls="orders-mobile-filters-panel"', $view);
        $this->assertStringContainsString('id="orders-mobile-filters-panel"', $view);
        $this->assertStringContainsString('class="space-y-3 p-3 lg:hidden"', $view);
        $this->assertStringContainsString(':placeholder="searchPlaceholder"', $table);

        foreach ([
            'orders-mobile-order-id' => 'list.filters.order_id',
            'orders-mobile-customer' => 'list.filters.customer',
            'orders-mobile-total-min' => 'list.filters.total_min',
            'orders-mobile-total-max' => 'list.filters.total_max',
            'orders-mobile-fulfillment' => 'list.filters.fulfillment_status',
            'orders-mobile-payment-method' => 'list.filters.payment_method',
            'orders-mobile-payment-status' => 'list.filters.payment_status',
            'orders-mobile-warehouse' => 'list.filters.warehouse_id',
            'orders-mobile-reservation' => 'list.filters.reservation_status',
            'orders-mobile-delivery' => 'list.filters.delivery_type',
            'orders-mobile-date-from' => 'list.filters.date_from',
            'orders-mobile-date-to' => 'list.filters.date_to',
        ] as $id => $model) {
            $this->assertStringContainsString('id="'.$id.'"', $view);
            $this->assertStringContainsString('v-model="'.$model.'"', $view);
        }

        $this->assertStringContainsString(':filter-values="list.filters"', $view);
        $this->assertStringContainsString('watch(() => props.filterValues', $table);
        $this->assertStringContainsString('@submit.prevent="applyMobileFilters"', $view);
        $this->assertStringContainsString('@click="clearMobileFilters"', $view);
        $this->assertStringContainsString('@click="toggleMobileFilters"', $view);
        $this->assertStringContainsString('@click="closeMobileFilters"', $view);
        $this->assertStringContainsString('@keydown.esc.prevent="closeMobileFilters()"', $view);
        $this->assertStringContainsString('async function closeMobileFilters({ restoreFocus = true } = {})', $view);
        $this->assertStringContainsString('mobileFiltersOpen.value = false', $view);
        $this->assertSame(1, substr_count($view, 'mobileFiltersOpen.value = false'));
        $this->assertStringContainsString('await nextTick()', $view);
        $this->assertStringContainsString('if (!restoreFocus || !componentMounted || sequence !== mobileFilterCloseSequence) return;', $view);
        $this->assertStringContainsString('if (toggle?.isConnected && toggle.getClientRects().length > 0)', $view);
        $this->assertStringContainsString('toggle.focus()', $view);
        $this->assertStringContainsString('void closeMobileFilters({ restoreFocus: false });', $view);
        $this->assertStringContainsString('const activeFilterCount = computed(() => {', $view);
        $this->assertStringContainsString('{{ activeFilterCount }} activos', $view);
        $this->assertStringContainsString('function applyFilters()', $composable);
        $this->assertStringContainsString('function clearFilters()', $composable);
        $this->assertStringContainsString('cancelDebounce();', $composable);
        $this->assertStringContainsString('return updateQuery({}, true)', $composable);
        $this->assertStringContainsString('return updateQuery(Object.fromEntries(filterKeys.map((key) => [key, undefined])), true)', $composable);
        $this->assertSame(0, preg_match('/\.slice\s*\(\s*0\s*,\s*120\s*\)/', $composable));
        $this->assertSame(0, preg_match('/\b(?:slice|substring|substr)\s*\(/i', $composable));
        $this->assertStringContainsString("if (typeof source[key] === 'string' && source[key] !== '') result[key] = source[key]", $composable);
        $this->assertStringContainsString("if (values[key] !== null && typeof values[key] !== 'string') continue", $composable);
        $this->assertStringContainsString("if (value !== null && typeof value !== 'string') return", $composable);

        $clearStart = strpos($composable, 'function clearFilters()');
        $clearEnd = strpos($composable, 'function applyFilters()', $clearStart);
        $clearBody = substr($composable, $clearStart, $clearEnd - $clearStart);
        $this->assertLessThan(strpos($clearBody, 'for (const key of filterKeys)'), strpos($clearBody, 'cancelDebounce();'));
        $this->assertSame(1, substr_count($clearBody, 'return updateQuery('));

        $this->assertSame(1, preg_match('/function applyMobileFilters\(\)\s*\{(.*?)\n\}/s', $view, $applyMatches));
        $applyBody = $applyMatches[1];
        $authorizedApplyPattern = '/\blist\s*\.\s*applyFilters\s*\(/';
        $this->assertSame(1, preg_match_all($authorizedApplyPattern, $applyBody));
        $returnsApplyResult = preg_match('/\breturn\s+list\s*\.\s*applyFilters\s*\(/', $applyBody) === 1
            || preg_match('/(?:const|let)\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*list\s*\.\s*applyFilters\s*\(\s*\)\s*;.*?\breturn\s+\1\s*;/s', $applyBody) === 1;
        $this->assertTrue($returnsApplyResult);
        $applyBodyWithoutAuthorizedCall = preg_replace($authorizedApplyPattern, '__AUTHORIZED_FILTER_SUBMISSION__(', $applyBody, 1);
        $this->assertIsString($applyBodyWithoutAuthorizedCall);
        // This source-level check catches explicit call expressions, not arbitrary dynamic dispatch.
        $this->assertSame(0, preg_match('/\bapplyFilters\s*\(/', $applyBodyWithoutAuthorizedCall));
        $this->assertStringContainsString('closeMobileFilters()', $applyBody);

        $this->assertSame(1, preg_match('/function clearMobileFilters\(\)\s*\{(.*?)\n\}/s', $view, $clearMatches));
        $clearMobileBody = $clearMatches[1];
        $authorizedClearPattern = '/\blist\s*\.\s*clearFilters\s*\(/';
        $this->assertSame(1, preg_match_all($authorizedClearPattern, $clearMobileBody));
        $returnsClearResult = preg_match('/\breturn\s+list\s*\.\s*clearFilters\s*\(/', $clearMobileBody) === 1
            || preg_match('/(?:const|let)\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*list\s*\.\s*clearFilters\s*\(\s*\)\s*;.*?\breturn\s+\1\s*;/s', $clearMobileBody) === 1;
        $this->assertTrue($returnsClearResult);
        $clearMobileBodyWithoutAuthorizedCall = preg_replace($authorizedClearPattern, '__AUTHORIZED_FILTER_CLEAR__(', $clearMobileBody, 1);
        $this->assertIsString($clearMobileBodyWithoutAuthorizedCall);
        $this->assertSame(0, preg_match('/\b(?:applyFilters|clearFilters|setFilters|setFilter)\s*\(/', $clearMobileBodyWithoutAuthorizedCall));
        $this->assertStringContainsString('closeMobileFilters()', $clearMobileBody);

        $this->assertSame(1, preg_match('/async function closeMobileFilters\([^)]*\)\s*\{(.*?)\n\}/s', $view, $closeMatches));
        $closeBody = $closeMatches[1];
        $this->assertStringContainsString('await nextTick()', $closeBody);
        $this->assertStringContainsString('if (!restoreFocus || !componentMounted || sequence !== mobileFilterCloseSequence) return;', $closeBody);
        $this->assertSame(0, preg_match('/\b(?:applyFilters|clearFilters|setFilters|setFilter)\s*\(/', $closeBody));

        foreach ([
            "apply: 'Aplicar'",
            "clear: 'Limpiar'",
            "contains: 'Contiene'",
            "selectionMessage: 'Se seleccionaron {0} elementos'",
            "today: 'Hoy'",
            "dayNames: ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado']",
            "dayNamesShort: ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb']",
            "dayNamesMin: ['do', 'lu', 'ma', 'mi', 'ju', 'vi', 'sá']",
            "monthNames: ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre']",
            "monthNamesShort: ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic']",
            "emptyFilterMessage: 'No se encontraron resultados'",
            "emptyMessage: 'No hay opciones disponibles'",
            "dateFormat: 'dd/mm/yy'",
            "showFilterMenu: 'Mostrar filtros'",
            "navigation: 'Navegación'",
            "jumpToPageDropdownLabel: 'Ir al selector de página'",
            "jumpToPageInputLabel: 'Ir a la página'",
            "prevMonth: 'Mes anterior'",
            "nextMonth: 'Mes siguiente'",
        ] as $localeKey) {
            $this->assertStringContainsString($localeKey, $entrypoint);
        }

        foreach (["apply: 'Apply'", "clear: 'Clear'", "today: 'Today'", 'v-html', 'DataTable filter()'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $view.$table.$entrypoint);
        }
        $this->assertSame(0, preg_match('/^        (?:firstPageLabel|lastPageLabel|nextPageLabel|prevPageLabel|rowsPerPageLabel|jumpToPageLabel):/m', $entrypoint));
    }

    public function test_order_action_errors_are_mapped_to_fixed_messages_without_server_details(): void
    {
        $view = file_get_contents(resource_path('js/views/admin/Orders.vue'));

        foreach ([
            '401: \'Tu sesión ha expirado. Inicia sesión nuevamente.\'',
            '403: \'No tienes permisos para realizar esta acción.\'',
            '404: \'El pedido solicitado ya no está disponible.\'',
            '409: \'El pedido cambió mientras realizabas la operación. Actualiza la información e inténtalo nuevamente.\'',
            '422: \'No se pudo completar la acción con los datos actuales. Revisa la información e inténtalo nuevamente.\'',
            '429: \'Se realizaron demasiadas solicitudes. Espera un momento e inténtalo nuevamente.\'',
            'status >= 500 && status <= 599',
            'Ocurrió un problema interno al procesar la solicitud. Inténtalo nuevamente.',
            'No se pudo completar la solicitud. Verifica tu conexión e inténtalo nuevamente.',
        ] as $expected) {
            $this->assertStringContainsString($expected, $view);
        }

        $this->assertFalse($this->containsUnsafeRemoteMessageAccess($view));
        $this->assertStringContainsString('error?.response?.status', $view);
        $this->assertStringContainsString('detail: safeActionErrorMessage(error)', $view);
        $this->assertStringContainsString('actionContextIsCurrent(context)', $view);
    }

    public function test_error_presentation_detector_catches_server_details_and_allows_safe_status_handling(): void
    {
        $unsafeCases = [
            'isolated response data message' => 'response.data.message',
            'optional response data message' => 'response?.data?.message',
            'arbitrary base data message' => 'payload.data.message',
            'isolated payload data errors' => 'payload.data.errors',
            'nested arbitrary base data errors' => 'foo.bar.data.errors',
            'optional payload data errors' => 'payload?.data?.errors',
            'spaces around dots' => 'response . data . message',
            'spaces around optional chain' => 'payload  ?.  data  ?.  errors',
            'multiline dot access' => "payload.data\n    .message",
            'multiline optional chain' => "payload?\n    .data?\n    .errors",
            'bracket notation single quotes' => "payload['data']['message']",
            'bracket notation double quotes with spaces' => 'payload [ "data" ] [ "errors" ]',
            'mixed dot and bracket notation' => "error.response['data']?.message",
            'data errors member' => 'result.data.errors',
            'direct error message' => 'error.message',
            'optional error message' => 'error?.message',
            'spaced exception message' => "exception\n    .message",
            'response status text' => 'response.statusText',
            'optional response status text' => 'response ?. statusText',
            'serialized remote body in detail sink' => 'detail: JSON.stringify(error.response.data)',
            'remote body presented directly' => 'toast.add({ detail: error.response.data })',
            'interpolated remote message' => 'toast.add({ detail: `Failure: ${response.data.message}` })',
            'v html remote message' => '<div v-html="response.data.message"></div>',
            'alert direct remote data' => 'alert(payload.data)',
            'window alert remote data' => 'window.alert(response.data)',
            'confirm remote data' => 'confirm(body.data)',
            'prompt remote data' => 'prompt(result.data)',
            'description direct remote data' => 'description: payload.data',
            'text direct remote data' => 'text: result.data',
            'detail template interpolation' => 'detail: `${payload.data}`',
            'description optional template interpolation' => 'description: `Error: ${response?.data}`',
            'alert template interpolation' => 'window.alert(`Error: ${payload.data.message}`)',
            'text concatenation remote data' => "text: 'Error: ' + payload.data",
            'alert concatenation remote message' => "alert('Error: ' + response.data.message)",
            'inner html remote data' => 'element.innerHTML = payload.data',
            'text content remote data' => 'element.textContent = payload.data',
            'inner text remote data' => 'element.innerText = payload.data',
            'description bracket remote data' => 'description: payload [ "data" ]',
            'optional dialog remote data' => 'window?.alert(payload.data)',
            'detail serializes arbitrary payload' => 'detail: JSON.stringify(payload)',
            'description serializes request body' => 'description: JSON.stringify(body)',
            'text serializes result' => 'text: JSON.stringify(result)',
            'alert serializes payload' => 'alert(JSON.stringify(payload))',
            'window alert serializes body' => 'window.alert(JSON.stringify(body))',
            'confirm serializes result' => 'confirm(JSON.stringify(result))',
            'prompt serializes second argument' => "prompt('Detalle', JSON.stringify(payload))",
            'text content serializes body' => 'element.textContent = JSON.stringify(body)',
            'inner text serializes payload' => 'element.innerText = JSON.stringify(payload)',
            'inner html serializes result' => 'element.innerHTML = JSON.stringify(result)',
            'multiline serialization in sink' => "detail:\n    JSON . stringify (\n        payload\n    )",
            'template interpolation serializes payload' => 'detail: `${JSON.stringify(payload)}`',
        ];

        foreach ($unsafeCases as $caseName => $source) {
            $this->assertTrue(
                $this->containsUnsafeRemoteMessageAccess($source),
                'Unsafe detector case failed: '.$caseName
            );
        }

        $safeCases = [
            'safe nested status' => 'error.response.status',
            'safe optional status' => 'response?.status',
            'safe status code' => 'response.statusCode',
            'safe helper call' => 'safeActionErrorMessage(error)',
            'safe literal message' => "return 'Fixed local message';",
            'safe local object property' => "const result = { message: 'Texto fijo' };",
            'safe local message variable' => "const message = 'Texto fijo';",
            'safe function name' => 'showSafeMessage()',
            'safe message variable name' => 'messageForToast',
            'safe http comparison' => 'status === 422',
            'isolated remote data without sink' => 'payload.data',
            'serialization outside presentation sink' => 'JSON.stringify(payload)',
            'serialized remote body outside sink' => 'const serialized = JSON.stringify(error.response.data);',
            'serialize response outside sink' => 'const serialized = JSON.stringify(response);',
            'serialize error outside sink' => 'const serialized = JSON.stringify(error);',
            'serialize exception outside sink' => 'const serialized = JSON.stringify(exception);',
            'serialize payload outside sink' => 'const serialized = JSON.stringify(payload);',
            'telemetry serialization outside presentation sink' => 'sendToTelemetry(JSON.stringify(body));',
            'internal data assignment' => 'const data = payload.data',
            'internal data count' => 'const count = payload.data.length',
            'transform without presentation sink' => 'transform(payload.data)',
            'fixed description' => "description: 'Texto fijo'",
            'safe helper in text sink' => 'text: safeActionErrorMessage(error)',
            'fixed native dialog' => "window.alert('Texto fijo')",
            'local template in presentation sink' => 'detail: `Operación completada`',
            'local literal concatenation' => "text: 'Operación ' + 'completada'",
            'safe dom text assignment' => 'element.textContent = safeMessage',
        ];

        foreach ($safeCases as $caseName => $source) {
            $this->assertFalse(
                $this->containsUnsafeRemoteMessageAccess($source),
                'Safe detector case failed: '.$caseName
            );
        }
    }

    public function test_action_response_guards_cover_success_error_finally_and_modal_lifecycle(): void
    {
        $view = file_get_contents(resource_path('js/views/admin/Orders.vue'));
        $normalized = preg_replace('/\s+/', ' ', $view);

        $this->assertStringContainsString('invalidateModalActions(); detailSequence++; selectedOrder.value = null', $normalized);
        $this->assertStringContainsString('async function openOrder(order) { invalidateModalActions();', $normalized);
        $this->assertStringContainsString('onBeforeUnmount(() => { componentMounted = false;', $normalized);
        $this->assertStringContainsString('const targetOrderId = targetOrder.id', $normalized);
        $this->assertStringContainsString('modalSequence: targetModalSequence', $normalized);
        $this->assertStringContainsString('if (!actionContextIsCurrent(context) || data.order?.id !== targetOrderId) return;', $normalized);
        $this->assertStringContainsString('catch (error) { if (actionContextIsCurrent(context)) showActionError(error); }', $normalized);
        $this->assertStringContainsString('const contextWasCurrent = actionContextIsCurrent(context);', $normalized);
        $this->assertStringContainsString('if (contextWasCurrent) actionLoading.value = false;', $normalized);
        $this->assertStringContainsString(':disabled="actionLoading"', $view);
    }

    private function containsUnsafeRemoteMessageAccess(string $source): bool
    {
        $source = $this->normalizeSourceForSinkAnalysis($source);

        $globalUnsafePatterns = [
            '~\.data\.(?:message|errors)\b~i',
            '~\b(?:error|exception)\.message\b~i',
            '~\.statusText\b~i',
            '~\bv-html\s*=~i',
        ];

        foreach ($globalUnsafePatterns as $pattern) {
            if (preg_match($pattern, $source) === 1) {
                return true;
            }
        }

        foreach ($this->presentationExpressions($source) as $expression) {
            if ($this->containsRemoteErrorExpression($expression)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeSourceForSinkAnalysis(string $source): string
    {
        $source = preg_replace_callback('~`((?:\\\\.|[^`\\\\])*)`~s', static function (array $matches): string {
            preg_match_all('~\\$\\{([^}]*)\\}~s', $matches[1], $expressions);

            return implode(' ', $expressions[1] ?? []);
        }, $source) ?? $source;

        $source = preg_replace_callback('~\\[\\s*([\'\"])(data|message|errors|statusText)\\1\\s*\\]~i', static function (array $matches): string {
            return '.'.$matches[2];
        }, $source) ?? $source;

        $source = preg_replace('~"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'~s', ' ', $source) ?? $source;
        $source = preg_replace('~/\\*.*?\\*/|//[^\\r\\n]*~s', ' ', $source) ?? $source;
        $source = preg_replace('~\\?\\s*\\.\\s*~', '.', $source) ?? $source;
        $source = preg_replace('~\\s*\\.\\s*~', '.', $source) ?? $source;
        $source = preg_replace('/\\s+/', ' ', $source) ?? $source;

        return $source;
    }

    private function presentationExpressions(string $source): array
    {
        $expressions = [];
        $patterns = [
            '~\\b(?:detail|message|summary|description|text)\\s*:\\s*([^,;}]+)~i',
            '~(?:\\bwindow\\s*\\.\\s*)?\\b(?:alert|confirm|prompt)\\s*\\(([^)]*)\\)~i',
            '~\\b(?:innerHTML|textContent|innerText)\\s*=\\s*([^,;}]+)~i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $source, $matches) > 0) {
                array_push($expressions, ...$matches[1]);
            }
        }

        return $expressions;
    }

    private function containsRemoteErrorExpression(string $expression): bool
    {
        $patterns = [
            '~\\.data(?:\\.(?:message|errors))?\\b~i',
            '~\\b(?:error|exception)\\.message\\b~i',
            '~\\.statusText\\b~i',
            '~\\bJSON\\.stringify\\s*\\(~i',
            '~\\bJSON\\.stringify\\s*\\([^)]*\\.data\\b~i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $expression) === 1) {
                return true;
            }
        }

        return false;
    }
}
