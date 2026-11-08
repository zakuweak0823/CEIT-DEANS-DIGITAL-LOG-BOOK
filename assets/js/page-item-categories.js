document.addEventListener('DOMContentLoaded', function () {
    const addModal = document.getElementById('addCategoryModal');
    if (addModal.dataset.openOnLoad === 'true') {
        new bootstrap.Modal(addModal).show();
    }
});
