document.querySelectorAll('[data-status-search]').forEach(input => {
    const overview = input.closest('.hv-overview');
    const groups = Array.from(overview.querySelectorAll('[data-status-group]'));
    const empty = overview.querySelector('[data-status-empty]');
    const saved = new Map();
    input.addEventListener('input', () => {
        const query = input.value.trim().toLocaleLowerCase();
        let visible = 0;
        for (const group of groups) {
            const rows = Array.from(group.querySelectorAll('[data-status-row]'));
            const labelMatches = group.querySelector('summary').textContent.toLocaleLowerCase().includes(query);
            let hits = 0;
            for (const row of rows) {
                row.hidden = !!query && !labelMatches && !row.textContent.toLocaleLowerCase().includes(query);
                if (!row.hidden) hits++;
            }
            group.hidden = !!query && !labelMatches && !hits;
            if (!group.hidden) visible++;
            if (query) {
                if (!saved.has(group)) saved.set(group, group.open);
                if (!group.hidden) group.open = true;
            } else if (saved.has(group)) {
                group.open = saved.get(group);
                saved.delete(group);
            }
        }
        empty.hidden = query === '' || visible > 0;
    });
});
