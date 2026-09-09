import assert from 'node:assert/strict';
import { buildManagementReportParams } from '../../resources/js/utils/managementReportParams.mjs';

const sections = { sales: ['period', 'category_id'], catalogs: ['catalog', 'search'] };
const catalogs = { products: ['search', 'is_active', 'category_id', 'brand_id'], vehicles: ['search', 'is_active', 'is_available', 'type'] };

assert.deepEqual(buildManagementReportParams('catalogs', { tab: 'catalogs' }, sections, catalogs), { catalog: 'products' });
assert.deepEqual(buildManagementReportParams('catalogs', { tab: 'catalogs', catalog: 'products', search: 'oil', warehouse_id: 3 }, sections, catalogs), { catalog: 'products', search: 'oil' });
assert.deepEqual(buildManagementReportParams('catalogs', { tab: 'catalogs', catalog: 'vehicles', type: 'van' }, sections, catalogs), { catalog: 'vehicles', type: 'van' });
assert.deepEqual(buildManagementReportParams('catalogs', { tab: 'catalogs', catalog: 'invalid', search: '' }, sections, catalogs), { catalog: 'products' });
assert.deepEqual(buildManagementReportParams('sales', { tab: 'sales', period: 'this_year', category_id: '', catalog: 'vehicles' }, sections, catalogs), { period: 'this_year' });
console.log('managementReportParams: 5 casos OK');
