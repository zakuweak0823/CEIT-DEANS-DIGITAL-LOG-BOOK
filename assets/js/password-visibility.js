document.querySelectorAll('input[type="password"]').forEach(function (input) {
    if (input.name === 'password_confirmation') return;

    let button = input.parentElement.querySelector('.login-password-toggle');
    if (!button) {
        const wrapper = document.createElement('div');
        wrapper.className = 'password-visibility-wrap';
        input.parentNode.insertBefore(wrapper, input);
        wrapper.appendChild(input);

        button = document.createElement('button');
        button.className = 'login-password-toggle';
        button.type = 'button';
        button.setAttribute('aria-controls', input.id);
        wrapper.appendChild(button);
    }

    const icon = button.querySelector('i') || document.createElement('i');
    icon.className = 'bi bi-eye';
    icon.setAttribute('aria-hidden', 'true');
    if (!icon.parentNode) button.appendChild(icon);
    button.setAttribute('aria-label', 'Show password');
    button.setAttribute('title', 'Show password');
    button.setAttribute('aria-pressed', 'false');
    button.addEventListener('click', function () {
        const showPassword = input.type === 'password';
        input.type = showPassword ? 'text' : 'password';
        button.setAttribute('aria-label', showPassword ? 'Hide password' : 'Show password');
        button.setAttribute('title', showPassword ? 'Hide password' : 'Show password');
        button.setAttribute('aria-pressed', String(showPassword));
        icon.classList.toggle('bi-eye', !showPassword);
        icon.classList.toggle('bi-eye-slash', showPassword);
    });
});
