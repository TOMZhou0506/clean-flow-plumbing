/**
 * Show/hide password toggle.
 *
 * Any button with data-toggle-password="<input id>" flips that input
 * between type="password" and type="text", so the field stays masked by
 * default and only reveals itself while the admin is looking.
 */
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-toggle-password]').forEach(function (button) {
        var input = document.getElementById(button.getAttribute('data-toggle-password'));

        if (!input) {
            return;
        }

        button.addEventListener('click', function () {
            var reveal = input.type === 'password';

            input.type = reveal ? 'text' : 'password';
            button.textContent = reveal ? 'Hide' : 'Show';
            button.setAttribute('aria-pressed', reveal ? 'true' : 'false');
            button.setAttribute('aria-label', reveal ? 'Hide password' : 'Show password');
        });
    });
});
