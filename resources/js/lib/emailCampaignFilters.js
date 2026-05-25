export function defaultFilterConfig() {
    return {
        include_customers: true,
        include_infoprodutors: false,
        all_customers: true,
        product_ids: [],
    };
}

export function normalizeFilterConfigFromCampaign(raw) {
    const fc = raw && typeof raw === 'object' ? raw : {};
    const hasNew = 'include_customers' in fc || 'include_infoprodutors' in fc;

    if (!hasNew) {
        return {
            include_customers: true,
            include_infoprodutors: false,
            all_customers: fc.all_customers !== false,
            product_ids: Array.isArray(fc.product_ids) ? fc.product_ids : [],
        };
    }

    let includeCustomers = !!fc.include_customers;
    let includeInfoprodutors = !!fc.include_infoprodutors;
    if (!includeCustomers && !includeInfoprodutors) {
        includeCustomers = true;
    }

    return {
        include_customers: includeCustomers,
        include_infoprodutors: includeInfoprodutors,
        all_customers: fc.all_customers !== false,
        product_ids: Array.isArray(fc.product_ids) ? fc.product_ids : [],
    };
}

export function recipientTypeLabel(type) {
    return type === 'infoprodutor' ? 'Infoprodutor' : 'Comprador';
}
