import './bootstrap';

document.querySelectorAll('[data-password-toggle]').forEach((button) => {
    const targetId = button.getAttribute('data-password-toggle');
    const input = targetId ? document.getElementById(targetId) : null;

    if (!input) {
        return;
    }

    button.addEventListener('click', () => {
        const revealed = input.type === 'text';
        input.type = revealed ? 'password' : 'text';
        button.setAttribute('aria-pressed', revealed ? 'false' : 'true');
        button.setAttribute('aria-label', revealed ? 'Show password' : 'Hide password');
        button.textContent = revealed ? 'Show' : 'Hide';
    });
});
