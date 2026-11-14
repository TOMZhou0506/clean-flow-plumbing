/**
 * Saves a compliance tick as soon as it is made.
 *
 * Each checklist item is its own small form, so ticking one saves only that
 * one and cannot overwrite a colleague's decision on another. This submits the
 * form when its checkbox changes, and locks the rest of the list for the
 * moment it takes so two quick clicks cannot race each other.
 *
 * Without script the form shows a Save button instead (see the <noscript>
 * block in the template), so the checklist still works.
 */
document.addEventListener('DOMContentLoaded', function () {
    var inputs = document.querySelectorAll('.checklist__input[data-autosubmit]');

    inputs.forEach(function (input) {
        input.addEventListener('change', function () {
            var form = input.form;
            if (!form) {
                return;
            }

            document.querySelectorAll('.checklist__input').forEach(function (other) {
                if (other !== input) {
                    other.disabled = true;
                }
            });

            var item = input.closest('.checklist__item');
            if (item) {
                item.classList.add('is-saving');
            }

            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
            } else {
                form.submit();
            }
        });
    });
});
