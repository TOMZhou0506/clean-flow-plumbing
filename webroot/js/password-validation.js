/**
 * Submit-time password feedback shared by customer registration and admin forms.
 * The server repeats every rule; this script only makes errors easier to fix.
 */
document.addEventListener('DOMContentLoaded', function () {
    var messages = {
        required: 'Password is required.',
        length: 'Password must be between 8 and 32 characters.',
        uppercase: 'Password must include at least one uppercase letter.',
        lowercase: 'Password must include at least one lowercase letter.',
        number: 'Password must include at least one number.',
        special: 'Password must include at least one special character.'
    };

    function passwordErrors(value) {
        if (value === '') {
            return [messages.required];
        }

        var errors = [];
        if (value.length < 8 || value.length > 32) {
            errors.push(messages.length);
        }
        if (!/[A-Z]/.test(value)) {
            errors.push(messages.uppercase);
        }
        if (!/[a-z]/.test(value)) {
            errors.push(messages.lowercase);
        }
        if (!/\d/.test(value)) {
            errors.push(messages.number);
        }
        if (!/[^A-Za-z0-9\s]/.test(value)) {
            errors.push(messages.special);
        }

        return errors;
    }

    function displayErrors(field, errors) {
        var container = field.closest('.input') || field.parentNode;
        container.querySelectorAll('.error-message').forEach(function (node) {
            node.remove();
        });
        container.classList.toggle('error', errors.length > 0);
        if (errors.length > 0) {
            field.setAttribute('aria-invalid', 'true');
        } else {
            field.removeAttribute('aria-invalid');
        }

        errors.forEach(function (message) {
            var node = document.createElement('div');
            node.className = 'error-message password-error';
            node.textContent = message;
            container.appendChild(node);
        });
    }

    document.querySelectorAll('form[data-password-form]').forEach(function (form) {
        var password = form.querySelector('[data-password-field]');
        var confirmation = form.querySelector('[data-confirm-password]');
        if (!password || !confirmation) {
            return;
        }

        function validate() {
            var optional = form.hasAttribute('data-password-optional');
            var errors = optional && password.value === ''
                ? []
                : passwordErrors(password.value);
            var confirmationErrors = [];

            if (password.value !== '' || !optional) {
                if (confirmation.value === '') {
                    confirmationErrors.push('Please confirm the password.');
                } else if (confirmation.value !== password.value) {
                    confirmationErrors.push('Passwords do not match.');
                }
            }

            displayErrors(password, errors);
            displayErrors(confirmation, confirmationErrors);
            return errors.length === 0 && confirmationErrors.length === 0;
        }

        form.addEventListener('submit', function (event) {
            if (!validate()) {
                event.preventDefault();
                var first = form.querySelector('.input.error [data-password-field],'
                    + ' .input.error [data-confirm-password]');
                if (first) {
                    first.focus();
                }
            }
        });

        [password, confirmation].forEach(function (field) {
            field.addEventListener('input', function () {
                var container = field.closest('.input') || field.parentNode;
                if (form.querySelector('.password-error') || container.querySelector('.error-message')) {
                    validate();
                }
            });
        });
    });
});
