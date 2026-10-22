document.addEventListener('DOMContentLoaded', () => {
    const search = document.getElementById('admin-user-search');
    const role = document.getElementById('admin-user-role');
    const status = document.getElementById('admin-user-status');
    const clear = document.getElementById('admin-user-filters-clear');
    const count = document.getElementById('admin-user-filter-count');
    const body = document.getElementById('admin-user-table-body');

    if (!search || !role || !status || !clear || !count || !body) return;

    const rows = Array.from(body.querySelectorAll('[data-admin-user-row]'));
    const noResults = document.getElementById('admin-user-no-results');

    const applyFilters = () => {
        const query = search.value.trim().toLocaleLowerCase();
        const selectedRole = role.value;
        const selectedStatus = status.value;
        let visibleCount = 0;

        rows.forEach(row => {
            const matchesSearch = (row.dataset.search || '').toLocaleLowerCase().includes(query);
            const matchesRole = !selectedRole || row.dataset.role === selectedRole;
            const matchesStatus = !selectedStatus || row.dataset.status === selectedStatus;
            const visible = matchesSearch && matchesRole && matchesStatus;
            row.hidden = !visible;
            if (visible) visibleCount += 1;
        });

        if (noResults) noResults.hidden = visibleCount !== 0;
        count.textContent = `Showing ${visibleCount} of ${rows.length} users`;
    };

    search.addEventListener('input', applyFilters);
    role.addEventListener('change', applyFilters);
    status.addEventListener('change', applyFilters);
    clear.addEventListener('click', () => {
        search.value = '';
        role.value = '';
        status.value = '';
        applyFilters();
        search.focus();
    });

    applyFilters();
});
