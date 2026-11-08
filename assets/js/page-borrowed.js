function editItem(id, item, borrower, borrowerPhone, categoryId) {
    document.getElementById('editId').value = id;
    document.getElementById('editItem').value = item;
    document.getElementById('editBorrower').value = borrower;
    document.getElementById('editBorrowerPhone').value = borrowerPhone;
    document.getElementById('editCategory').value = categoryId || '';
    new bootstrap.Modal(document.getElementById('editModal')).show();
}

document.addEventListener('DOMContentLoaded', function () {
    const addModal = document.getElementById('borrowedForm');
    if (addModal.dataset.openOnLoad === 'true') {
        new bootstrap.Modal(addModal).show();
    }
});
