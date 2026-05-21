/**
 * Remove o prefixo /m/{slug} de links internos da área de membros.
 * Evita 404 em domínio próprio quando o menu foi salvo com URL no formato /m/xxx/rota.
 */
export function stripMemberAreaPathPrefix(path) {
    const raw = String(path ?? '').trim();
    if (!raw) {
        return '/';
    }
    const withSlash = raw.startsWith('/') ? raw : `/${raw}`;
    if (!/^\/m\/[a-zA-Z0-9-]+/i.test(withSlash)) {
        return withSlash;
    }
    const stripped = withSlash.replace(/^\/m\/[a-zA-Z0-9-]+/i, '') || '/';
    return stripped.startsWith('/') ? stripped : `/${stripped}`;
}

/** Normaliza link do menu do header para rota relativa (/loja, /comunidade, etc.). */
export function normalizeMemberMenuLink(link) {
    const raw = String(link ?? '').trim();
    if (!raw) {
        return '/';
    }
    if (/^https?:\/\//i.test(raw)) {
        try {
            const u = new URL(raw);
            return stripMemberAreaPathPrefix(`${u.pathname}${u.search}${u.hash}`);
        } catch {
            return raw;
        }
    }
    return stripMemberAreaPathPrefix(raw);
}

/**
 * Monta href para navegação na área de membros (path /m/slug vs domínio na raiz).
 */
export function resolveMemberAreaHref(link, { usesPathPrefix, basePath, baseUrl = '', openExternal = false }) {
    const prefix = String(basePath ?? '');
    const base = String(baseUrl ?? '').replace(/\/$/, '');

    if (!link) {
        return usesPathPrefix && prefix ? prefix : '/';
    }

    let raw = String(link).trim();
    if (!raw) {
        return usesPathPrefix && prefix ? prefix : '/';
    }

    if (openExternal) {
        return raw;
    }

    if (/^https?:\/\//i.test(raw)) {
        if (base && raw.startsWith(base)) {
            raw = raw.slice(base.length) || '/';
        } else {
            return raw;
        }
    }

    let path = normalizeMemberMenuLink(raw);

    if (prefix && (path === prefix || path.startsWith(`${prefix}/`))) {
        path = path.slice(prefix.length) || '/';
    }

    if (!usesPathPrefix) {
        return path;
    }

    if (!prefix) {
        return path;
    }

    return path === '/' ? prefix : `${prefix}${path}`;
}
