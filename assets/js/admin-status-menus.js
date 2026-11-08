// Fixed positioning keeps status menus from being clipped by scrolling tables.
document.querySelectorAll('.status-menu-toggle').forEach(function (toggle) {
    new bootstrap.Dropdown(toggle, { popperConfig: { strategy: 'fixed' } });
});
