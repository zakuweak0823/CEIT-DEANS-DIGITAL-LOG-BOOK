(function () {
    const modalElement = document.getElementById('statusDetailsModal');
    if (!modalElement) return;
    const modal = new bootstrap.Modal(modalElement);
    const fields = document.getElementById('statusDetailsFields');
    const note = document.getElementById('statusDetailsNote');
    const summary = document.getElementById('statusDetailsSummary');

    document.addEventListener('submit', function (event) {
        const form = event.target;
        const button = event.submitter;
        if (!(form instanceof HTMLFormElement) || !button ||
            !(form.classList.contains('status-menu') && button.name === 'new_status') &&
            !button.hasAttribute('data-status-details')) return;
        event.preventDefault();
        fields.replaceChildren();
        new FormData(form, button).forEach(function (value, name) {
            if (typeof value !== 'string') return;
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = value;
            fields.appendChild(input);
        });
        note.value = '';
        summary.textContent = 'New status: ' + (button.dataset.statusDetails || button.value);
        modal.show();
    });
    modalElement.addEventListener('shown.bs.modal', function () { note.focus(); });
})();
