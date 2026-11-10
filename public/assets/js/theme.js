/* Tailwick's data-theme is the single source of truth for shared components. */
(() => {
    const root = document.documentElement;
    if (!root.hasAttribute('data-theme')) {
        let theme;
        try { theme = JSON.parse(sessionStorage.getItem('__SICRAT_CONFIG__') || 'null')?.theme; } catch {}
        root.dataset.theme = ['light','dark'].includes(theme) ? theme : matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }
    let previous;
    const update = () => {
        const dark = root.dataset.theme === 'dark';
        if (previous === dark) return;
        previous = dark;
        root.style.colorScheme = dark ? 'dark' : 'light';
        window.dispatchEvent(new CustomEvent('sicrat:theme', {detail: {dark}}));
    };
    new MutationObserver(update).observe(root, {attributes: true, attributeFilter: ['data-theme']});
    update();
})();
