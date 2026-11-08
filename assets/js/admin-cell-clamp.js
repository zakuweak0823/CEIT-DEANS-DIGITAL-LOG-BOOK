// Long free text in tables is limited to two lines; the full text stays available on hover.
(function () {
    const labels = ['Record', 'Person', 'Subject', 'Recipient', 'Name', 'Item', 'Description'];
    document.querySelectorAll('.admin-body .table-responsive td[data-label]').forEach(function (cell) {
        if (labels.indexOf(cell.getAttribute('data-label')) === -1 || cell.querySelector('.cell-clamp')) { return; }
        if (cell.querySelector('.badge, .btn, form, a, i')) { return; }
        const text = cell.textContent.trim();
        if (text === '' || text === '-') { return; }
        const inner = cell.querySelector(':scope > span');
        if (inner) {
            inner.classList.add('cell-clamp');
            inner.title = text;
            return;
        }
        const wrapper = document.createElement('span');
        wrapper.className = 'cell-clamp';
        wrapper.title = text;
        while (cell.firstChild) { wrapper.appendChild(cell.firstChild); }
        cell.appendChild(wrapper);
    });
})();
