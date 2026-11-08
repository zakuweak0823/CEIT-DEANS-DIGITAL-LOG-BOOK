document.querySelectorAll('.login-password-toggle').forEach(function (button) {
    button.addEventListener('click', function () {
        const input = document.getElementById(button.getAttribute('aria-controls'));
        const icon = button.querySelector('i');
        if (!input || !icon) {
            console.error('The login password visibility control could not find its password field.');
            return;
        }

        const showPassword = input.type === 'password';
        input.type = showPassword ? 'text' : 'password';
        button.setAttribute('aria-label', showPassword ? 'Hide password' : 'Show password');
        button.setAttribute('title', showPassword ? 'Hide password' : 'Show password');
        button.setAttribute('aria-pressed', String(showPassword));
        icon.classList.toggle('bi-eye', !showPassword);
        icon.classList.toggle('bi-eye-slash', showPassword);
    });
});
