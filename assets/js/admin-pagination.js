// Long admin tables are split into pages so staff never have to scroll through every record.
(function () {
    const PAGE_SIZE = 10;
    const tables = document.querySelectorAll('.admin-body .admin-content .table-responsive > table');
    tables.forEach(function (table) {
        if (table.closest('.report-page') || table.classList.contains('dashboard-activity-table') || table.hasAttribute('data-no-pagination')) { return; }
        const body = table.tBodies[0];
        if (!body) { return; }
        const rows = Array.from(body.rows);
        if (rows.length <= PAGE_SIZE || rows.some(function (row) { return row.querySelector('td[colspan]'); })) { return; }

        const wrap = table.closest('.table-responsive');
        const pager = document.createElement('nav');
        pager.className = 'table-pager';
        pager.setAttribute('aria-label', 'Table pages');
        wrap.insertAdjacentElement('afterend', pager);
        const pageCount = Math.ceil(rows.length / PAGE_SIZE);
        let current = 1;

        const makeButton = function (label, page, options) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'table-pager-btn' + (options.active ? ' is-active' : '');
            button.innerHTML = label;
            button.disabled = !!options.disabled;
            if (options.aria) { button.setAttribute('aria-label', options.aria); }
            if (options.active) { button.setAttribute('aria-current', 'page'); }
            button.addEventListener('click', function () { render(page, true); });
            return button;
        };

        const visiblePages = function () {
            const pages = [];
            for (let p = 1; p <= pageCount; p++) {
                if (p === 1 || p === pageCount || Math.abs(p - current) <= 1) { pages.push(p); }
                else if (pages[pages.length - 1] !== '…') { pages.push('…'); }
            }
            return pages;
        };

        const render = function (page, scroll) {
            current = Math.min(Math.max(page, 1), pageCount);
            const first = (current - 1) * PAGE_SIZE;
            rows.forEach(function (row, index) { row.hidden = index < first || index >= first + PAGE_SIZE; });
            pager.textContent = '';
            const info = document.createElement('span');
            info.className = 'table-pager-info';
            info.textContent = 'Showing ' + (first + 1) + '–' + Math.min(first + PAGE_SIZE, rows.length) + ' of ' + rows.length;
            const controls = document.createElement('div');
            controls.className = 'table-pager-controls';
            controls.appendChild(makeButton('<i class="bi bi-chevron-left" aria-hidden="true"></i>', current - 1, { disabled: current === 1, aria: 'Previous page' }));
            visiblePages().forEach(function (p) {
                if (p === '…') {
                    const gap = document.createElement('span');
                    gap.className = 'table-pager-gap';
                    gap.textContent = '…';
                    controls.appendChild(gap);
                } else {
                    controls.appendChild(makeButton(String(p), p, { active: p === current, aria: 'Page ' + p }));
                }
            });
            controls.appendChild(makeButton('<i class="bi bi-chevron-right" aria-hidden="true"></i>', current + 1, { disabled: current === pageCount, aria: 'Next page' }));
            pager.appendChild(info);
            pager.appendChild(controls);
            if (scroll) { wrap.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); }
        };
        table.addEventListener('admin-table-sort', function () {
            rows.splice(0, rows.length, ...Array.from(body.rows));
            render(1, false);
        });
        render(1, false);
    });
})();
