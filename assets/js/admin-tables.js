(function () {
    const statusGroups = {
        documents: {
            title: 'Documents',
            entries: [
                ['Received', 'Logged by the office'],
                ['In Review', 'Being reviewed'],
                ['Forwarded', 'Sent onward for action'],
                ['Incomplete', 'Requirements are missing'],
                ['On Hold', 'Processing is paused'],
                ['Approved', 'Approved for the next step'],
                ['For Release', 'Ready for collection or delivery'],
                ['Released', 'Handed over'],
                ['Returned', 'Returned to the sender'],
                ['Cancelled', 'Processing stopped'],
            ],
        },
        incoming: {
            title: 'Incoming documents',
            entries: [
                ['Received', 'Logged by the office'],
                ['In Review', 'Being reviewed'],
                ['Forwarded', 'Sent onward for action'],
                ['Incomplete', 'Requirements are missing'],
                ['On Hold', 'Processing is paused'],
                ['Approved', 'Approved for the next step'],
                ['Returned', 'Returned to the sender'],
                ['Cancelled', 'Processing stopped'],
            ],
        },
        outgoing: {
            title: 'Outgoing documents',
            entries: [
                ['For Release', 'Ready for collection or delivery'],
                ['Released', 'Handed over'],
                ['Returned', 'Returned to the sender'],
                ['Cancelled', 'Processing stopped'],
            ],
        },
        borrowed: {
            title: 'Borrowing history',
            entries: [
                ['Borrowed', 'The item is currently with a borrower'],
                ['Returned', 'The item is back with the office'],
            ],
        },
        inventory: {
            title: 'Inventory availability',
            entries: [
                ['Available', 'Ready to be selected'],
                ['Borrowed', 'Currently with a borrower'],
                ['Unavailable', 'Not selectable'],
            ],
        },
        sms: {
            title: 'SMS delivery',
            entries: [
                ['Pending / Queued', 'Awaiting action or confirmation'],
                ['Sent', 'Sent; delivery not yet confirmed'],
                ['Delivered', 'Delivery confirmed'],
                ['Failed', 'Delivery failed'],
            ],
        },
    };

    document.querySelectorAll('.admin-body .admin-content .table-responsive > table').forEach(function (table) {
        const header = table.tHead && table.tHead.rows[table.tHead.rows.length - 1];
        const body = table.tBodies[0];
        if (!header || !body) return;
        const headings = Array.from(header.cells);
        const groupNames = (table.dataset.statusGuide || (
            table.classList.contains('report-table--all') ? 'documents,borrowed,sms'
                : table.classList.contains('report-table--borrowed') ? 'borrowed'
                    : table.classList.contains('report-table--sms') || table.classList.contains('sms-log-table') ? 'sms'
                        : table.classList.contains('report-table--documents') || table.classList.contains('report-table--bulk') ? 'documents'
                            : ''
        )).split(',').map(function (name) { return name.trim(); }).filter(function (name) {
            return Object.prototype.hasOwnProperty.call(statusGroups, name);
        });
        if (groupNames.length) {
            const guideContent = groupNames.map(function (name) {
                const group = statusGroups[name];
                return '<div class="status-guide-section"><div class="status-guide-group">' + group.title + '</div>' +
                    group.entries.map(function (entry) {
                        return '<div class="status-guide-entry"><strong>' + entry[0] + '</strong><span>' + entry[1] + '</span></div>';
                    }).join('') + '</div>';
            }).join('');
            const addStatusGuide = function (container) {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'admin-status-info';
                button.setAttribute('aria-label', 'What statuses mean');
                button.innerHTML = '<i class="bi bi-info-circle" aria-hidden="true"></i>';
                container.appendChild(button);
                new bootstrap.Popover(button, {
                    container: 'body',
                    title: 'Status guide',
                    content: guideContent,
                    html: true,
                    customClass: 'admin-status-popover',
                    placement: 'auto',
                    trigger: 'hover focus',
                });
            };
            headings.forEach(function (cell) {
                if (cell.textContent.trim().toLowerCase() !== 'status') return;
                addStatusGuide(cell);
                const mobileGuide = document.createElement('div');
                mobileGuide.className = 'admin-mobile-status-guide' + (table.classList.contains('batch-history-data-table') ? ' admin-mobile-status-guide--batch' : '');
                mobileGuide.appendChild(document.createTextNode('Status guide'));
                addStatusGuide(mobileGuide);
                table.closest('.table-responsive').insertAdjacentElement('beforebegin', mobileGuide);
            });
        }

        if (table.classList.contains('dashboard-activity-table')) return;
        const isBatchTable = table.classList.contains('batch-history-data-table');
        const originalRows = Array.from(body.rows).filter(function (row) {
            return !row.querySelector('td[colspan]') && row.cells.length === headings.length;
        });
        if (originalRows.length < 2) return;
        const batchDetails = isBatchTable ? originalRows.map(function (row) { return row.nextElementSibling; }) : [];
        const cardContainer = isBatchTable ? table.closest('.table-responsive').nextElementSibling.querySelector('.d-grid') : null;
        const batchCards = cardContainer ? Array.from(cardContainer.children) : [];
        const options = headings.map(function (cell, index) {
            return { label: cell.textContent.trim(), index: index, disabled: cell.hasAttribute('data-sort-disabled') };
        }).filter(function (option) {
            return option.label && !option.disabled && !/^(actions?|tracking qr)$/i.test(option.label);
        });
        if (!options.length) return;

        let sortIndex = null;
        let sortDirection = null;
        const sortButtons = [];
        const sortRows = function (index) {
            if (sortIndex !== index) {
                sortIndex = index;
                sortDirection = 'ascending';
            } else if (sortDirection === 'ascending') {
                sortDirection = 'descending';
            } else {
                sortIndex = null;
                sortDirection = null;
            }
            const sorted = originalRows.slice();
            if (sortIndex !== null) {
                const readValue = function (row) {
                    const cell = row.cells[sortIndex];
                    const activeStatus = cell.querySelector('.status-menu-toggle');
                    return (cell.getAttribute('data-sort-value') ?? cell.getAttribute('data-csv-value') ?? (activeStatus ? activeStatus.textContent : cell.textContent)).trim();
                };
                const multiplier = sortDirection === 'ascending' ? 1 : -1;
                sorted.sort(function (a, b) {
                    return multiplier * readValue(a).localeCompare(readValue(b), undefined, { numeric: true, sensitivity: 'base' });
                });
            }
            const placeholder = isBatchTable ? null : Array.from(body.rows).find(function (row) { return !originalRows.includes(row); }) || null;
            sorted.forEach(function (row) {
                body.insertBefore(row, placeholder);
                if (isBatchTable) {
                    const index = originalRows.indexOf(row);
                    if (batchDetails[index]) body.appendChild(batchDetails[index]);
                    if (batchCards[index]) cardContainer.appendChild(batchCards[index]);
                }
            });
            sortButtons.forEach(function (item) {
                const active = item.index === sortIndex;
                if (active) item.cell.setAttribute('aria-sort', sortDirection);
                else item.cell.removeAttribute('aria-sort');
                item.button.dataset.sortDirection = active ? sortDirection : '';
                const next = !active ? 'ascending' : (sortDirection === 'ascending' ? 'descending' : 'default order');
                item.button.setAttribute('aria-label', 'Sort by ' + item.label + ': ' + next);
                item.button.title = 'Sort by ' + item.label + ': ' + next;
            });
            table.dispatchEvent(new CustomEvent('admin-table-sort'));
        };

        const addSortButton = function (cell, option) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'admin-sort-heading';
            button.textContent = option.label;
            button.setAttribute('aria-label', 'Sort by ' + option.label + ': ascending');
            button.title = 'Sort by ' + option.label + ': ascending';
            button.addEventListener('click', function () { sortRows(option.index); });
            cell.insertBefore(button, cell.firstChild);
            sortButtons.push({ cell: cell, button: button, index: option.index, label: option.label });
        };
        options.forEach(function (option) {
            const cell = headings[option.index];
            Array.from(cell.childNodes).forEach(function (node) {
                if (node.nodeType === Node.TEXT_NODE) node.remove();
            });
            addSortButton(cell, option);
        });

        {
            const mobileWrap = document.createElement('div');
            mobileWrap.className = 'admin-mobile-sort-headings' + (isBatchTable ? ' admin-mobile-sort-headings--batch' : '');
            const mobileTable = document.createElement('table');
            mobileTable.setAttribute('aria-label', 'Sort records by column');
            const mobileRow = mobileTable.createTHead().insertRow();
            options.forEach(function (option) {
                const cell = document.createElement('th');
                cell.scope = 'col';
                mobileRow.appendChild(cell);
                addSortButton(cell, option);
            });
            mobileWrap.appendChild(mobileTable);
            table.closest('.table-responsive').insertAdjacentElement('beforebegin', mobileWrap);
        }
    });
})();
