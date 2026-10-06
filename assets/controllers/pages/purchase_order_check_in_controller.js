import {Controller} from "@hotwired/stimulus";

export default class extends Controller
{
    connect() {
        this.filter = 'all';
        this.handleClick = this._handleClick.bind(this);
        this.handleChange = this._handleChange.bind(this);
        this.element.addEventListener('click', this.handleClick);
        this.element.addEventListener('change', this.handleChange);

        this.element.querySelectorAll('[data-check-in]').forEach((box) => this._updateRow(box, false));
        this._refresh();
    }

    disconnect() {
        this.element.removeEventListener('click', this.handleClick);
        this.element.removeEventListener('change', this.handleChange);
    }

    _handleClick(event) {
        const choice = event.target.closest('[data-check-in-choice]');
        if (choice) {
            const box = choice.closest('[data-check-in-row]').querySelector('[data-check-in]');
            box.checked = choice.dataset.checkInChoice === 'arrived';
            this._updateRow(box);

            return;
        }

        const filter = event.target.closest('[data-check-in-filter]');
        if (filter) {
            this.filter = filter.dataset.checkInFilter;
            this.element.querySelectorAll('[data-check-in-filter]').forEach((button) => {
                const active = button === filter;
                button.classList.toggle('active', active);
                button.setAttribute('aria-pressed', String(active));
            });
            this._refresh();

            return;
        }

        const bulkButton = event.target.closest('[data-check-in-set-visible]');
        if (!bulkButton) {
            return;
        }

        const arrived = bulkButton.dataset.checkInSetVisible === 'arrived';
        this._rows().filter((row) => !row.classList.contains('d-none')).forEach((row) => {
            const box = row.querySelector('[data-check-in]');
            if (box) {
                box.checked = arrived;
                this._updateRow(box, false);
            }
        });
        this._refresh();
    }

    _handleChange(event) {
        if (event.target.matches('[data-check-in]')) {
            this._updateRow(event.target);
        }
    }

    _updateRow(box, refresh = true) {
        const row = box.closest('[data-check-in-row]');
        const missing = row.querySelector('[data-check-in-choice="missing"]');
        const arrived = row.querySelector('[data-check-in-choice="arrived"]');
        const qty = row.querySelector('[data-check-qty]');

        row.dataset.checkInState = box.checked ? 'arrived' : 'missing';
        row.classList.toggle('table-success', box.checked);
        row.classList.toggle('table-warning', !box.checked);

        missing.classList.toggle('btn-warning', !box.checked);
        missing.classList.toggle('btn-outline-warning', box.checked);
        missing.classList.toggle('active', !box.checked);
        missing.setAttribute('aria-pressed', String(!box.checked));
        arrived.classList.toggle('btn-success', box.checked);
        arrived.classList.toggle('btn-outline-success', !box.checked);
        arrived.classList.toggle('active', box.checked);
        arrived.setAttribute('aria-pressed', String(box.checked));

        row.querySelectorAll('[data-check-in-detail]').forEach((control) => {
            control.disabled = !box.checked;
        });
        row.querySelector('[data-check-in-destination]').classList.toggle('opacity-50', !box.checked);

        if (qty && box.checked && (qty.value === '' || Number(qty.value) === 0)) {
            qty.value = qty.dataset.outstanding;
        }

        if (refresh) {
            this._refresh();
        }
    }

    _refresh() {
        const rows = this._rows();
        const counts = {all: rows.length, arrived: 0, missing: 0, unavailable: 0};

        rows.forEach((row) => {
            counts[row.dataset.checkInState] += 1;
            row.classList.toggle('d-none', this.filter !== 'all' && row.dataset.checkInState !== this.filter);
        });

        Object.entries(counts).forEach(([state, count]) => {
            const badge = this.element.querySelector(`[data-check-in-count="${state}"]`);
            if (badge) {
                badge.textContent = count;
            }
            const filter = this.element.querySelector(`[data-check-in-filter="${state}"]`);
            if (filter && state !== 'all') {
                filter.disabled = count === 0;
            }
        });

        this.element.querySelector('[data-check-in-selected-count]').textContent = counts.arrived;
    }

    _rows() {
        return Array.from(this.element.querySelectorAll('[data-check-in-row]'));
    }
}
