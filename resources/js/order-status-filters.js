export function initializeOrderStatusFilters() {
    const filters = Array.from(document.querySelectorAll('[data-status-filter]'));
    for (const filter of filters) {
        const details = filter.querySelector('[data-status-details]');
        const summary = filter.querySelector('[data-status-summary]');
        const inputs = Array.from(filter.querySelectorAll('input[type="checkbox"]'));
        const refresh = () => {
            const selected = inputs.filter(input => input.checked);
            summary.textContent = selected.length === 0 ? filter.dataset.emptyLabel
                : selected.length === 1 ? selected[0].dataset.statusLabel : `${selected.length} حالات محددة`;
        };
        filter.addEventListener('change', refresh);
        filter.querySelector('[data-status-clear]').addEventListener('click', () => {
            inputs.forEach(input => { input.checked = false; });
            refresh();
        });
        details.addEventListener('toggle', () => {
            if (details.open) filters.forEach(other => {
                if (other !== filter) other.querySelector('details').open = false;
            });
        });
        filter.addEventListener('keydown', event => {
            if (event.key === 'Escape' && details.open) {
                event.preventDefault();
                details.open = false;
                details.querySelector('summary').focus();
            }
        });
        document.addEventListener('click', event => {
            if (!filter.contains(event.target)) details.open = false;
        });
        refresh();
    }
}
