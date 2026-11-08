// Keep every column heading aligned with the content beneath it, at any window size.
(function () {
    // One body-level tooltip for row action buttons; it cannot be clipped by the scrolling table.
    const tooltip = document.createElement('div');
    tooltip.className = 'admin-action-tooltip';
    tooltip.setAttribute('aria-hidden', 'true');
    document.body.appendChild(tooltip);

    const hideActionTooltip = function () { tooltip.classList.remove('is-visible'); };
    const showActionTooltip = function (button) {
        const label = button.querySelector('.action-label');
        if (!label || window.innerWidth < 1200) return;
        tooltip.textContent = label.textContent.trim();
        const rect = button.getBoundingClientRect();
        const width = tooltip.offsetWidth;
        const height = tooltip.offsetHeight;
        const margin = 8;
        const center = rect.left + rect.width / 2;
        const left = Math.min(Math.max(center - width / 2, margin), window.innerWidth - width - margin);
        const placeBelow = rect.top - height - 12 < margin;
        tooltip.dataset.placement = placeBelow ? 'bottom' : 'top';
        tooltip.style.left = left + 'px';
        tooltip.style.top = (placeBelow ? rect.bottom + 10 : rect.top - height - 10) + 'px';
        tooltip.style.setProperty('--arrow-x', (center - left) + 'px');
        tooltip.classList.add('is-visible');
    };
    const actionButtonFrom = function (event) {
        return event.target instanceof Element ? event.target.closest('.document-row-actions .btn') : null;
    };
    document.addEventListener('mouseover', function (event) {
        const button = actionButtonFrom(event);
        if (button) showActionTooltip(button); else hideActionTooltip();
    });
    document.addEventListener('focusin', function (event) {
        const button = actionButtonFrom(event);
        if (button) showActionTooltip(button);
    });
    document.addEventListener('focusout', hideActionTooltip);
    document.addEventListener('scroll', hideActionTooltip, true);
    window.addEventListener('resize', hideActionTooltip);

    const syncTableHeadingAlignment = function () {
        document.querySelectorAll('.admin-content table').forEach(function (table) {
            const headings = table.querySelectorAll(':scope > thead > tr:last-child > th');
            if (headings.length === 0) return;
            const row = Array.from(table.querySelectorAll(':scope > tbody > tr')).find(function (candidate) {
                return candidate.children.length === headings.length && !candidate.querySelector('td[colspan]');
            });
            if (!row) return;
            headings.forEach(function (heading, index) {
                const align = window.getComputedStyle(row.children[index]).textAlign;
                heading.style.textAlign = align === 'center' ? 'center' : (align === 'right' || align === 'end' ? 'right' : 'left');
            });
        });
    };
    let resizeTimer = null;
    document.addEventListener('DOMContentLoaded', syncTableHeadingAlignment);
    window.addEventListener('load', syncTableHeadingAlignment);
    window.addEventListener('resize', function () {
        window.clearTimeout(resizeTimer);
        resizeTimer = window.setTimeout(syncTableHeadingAlignment, 120);
    });
})();
