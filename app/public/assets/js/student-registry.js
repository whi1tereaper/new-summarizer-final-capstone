document.addEventListener('DOMContentLoaded', () => {
    const ids = document.getElementById('student-ids');
    const count = document.getElementById('registry-detected');
    ids?.addEventListener('input', () => {
        const total = ids.value.split(/[\r\n,]+/).filter(id => id.trim()).length;
        count.textContent = `${total} IDs detected (server validation is authoritative)`;
    });
    document.querySelectorAll('[data-registry-busy]').forEach(form => {
        form.addEventListener('submit', event => {
            if (form.dataset.busy) { event.preventDefault(); return; }
            form.dataset.busy = '1';
            form.querySelectorAll('button').forEach(button => { button.disabled = true; });
            const progress = form.querySelector('[data-registry-progress]');
            if (progress) progress.textContent = 'Processing securely. Please wait…';
            form.setAttribute('aria-busy', 'true');
        });
    });
});
