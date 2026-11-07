/* Shared by the worker and the read-only interface. No dynamic HTML or media here. */
globalThis.SicratPwaPolicy = (() => {
    const uuid = '[a-fA-F0-9-]{36}';
    const rules = [
        ['personas', new RegExp(`^/api/persons(?:/${uuid})?$`), 'network-first'],
        ['estructura', new RegExp(`^/api/structures(?:/${uuid}(?:/members/${uuid})?)?$`), 'stale-while-revalidate'],
        ['operaciones', new RegExp(`^/api/operations/(?:tareas|peticiones|incidencias)(?:/${uuid})?$`), 'network-first'],
        ['mensajes', new RegExp(`^/api/operations/conversaciones(?:/${uuid}(?:/messages)?)?$`), 'network-first'],
        ['jornada', new RegExp(`^/api/jornada/(?:booths(?:/${uuid})?|summary|versions/${uuid})$`), 'network-first']
    ];
    function match(url) {
        const rule = rules.find(([, pattern]) => pattern.test(url.pathname));
        return rule ? { module: rule[0], strategy: rule[2] } : null;
    }
    function canonical(url) {
        const value = new URL(url);
        value.searchParams.delete('_'); // jQuery cache-buster is not part of a query.
        value.searchParams.sort();
        return value.pathname + value.search;
    }
    return { match, canonical, lifetime: 12 * 60 * 60 * 1000, maxRecords: 100, maxBytes: 12 * 1024 * 1024 };
})();
