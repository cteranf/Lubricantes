export function isAccountMenuRouteActive(item, currentPath) {
    if (!item || typeof currentPath !== 'string' || !item.to) return false;

    if (item.activeScope === 'admin') {
        return currentPath === '/admin' || currentPath.startsWith('/admin/');
    }

    if (item.activeScope === 'treasury') {
        return currentPath === '/treasury' || currentPath.startsWith('/treasury/');
    }

    const targetPath = typeof item.to === 'string' ? item.to : item.to.path;

    if (typeof targetPath !== 'string') return false;

    return currentPath === targetPath
        || (item.activeScope === 'subtree' && currentPath.startsWith(`${targetPath}/`));
}
