export function buildManagementReportParams(section, filters = {}, sectionAllowlists = {}, catalogAllowlists = {}) {
    const source = filters || {};
    const catalogKeys = Object.keys(catalogAllowlists);

    if (section === 'catalogs') {
        const catalog = catalogKeys.includes(source.catalog) ? source.catalog : 'products';
        const params = { catalog };
        for (const key of catalogAllowlists[catalog] || []) {
            const value = source[key];
            if (value !== '' && value !== null && value !== undefined) params[key] = value;
        }
        return params;
    }

    const params = {};
    for (const key of sectionAllowlists[section] || []) {
        const value = source[key];
        if (value !== '' && value !== null && value !== undefined) params[key] = value;
    }
    return params;
}
