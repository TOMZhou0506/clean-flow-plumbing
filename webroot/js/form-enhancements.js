/**
 * Shared behaviour for the customer-facing forms.
 *
 * Enhancements are opted into with a data attribute so this file can be
 * loaded by any template without changing what that page already does:
 *
 * - [data-digits-only]      drops anything that is not a digit as it is typed
 *                           or pasted, so a postcode cannot collect letters.
 * - [data-au-phone]         accepts digits only and formats 03 landlines as
 *                           03 XXXX XXXX or 04 mobiles as 04XX XXX XXX.
 * - [data-char-counter]     renders a live "used / limit" count under a textarea.
 * - [data-meaningful-text]  rejects obvious filler in description fields. data-short-message is
 *                           the message for a value under minlength, data-meaningful-message for filler.
 * - form[data-validate-all] replaces the browser's native bubble, which stops
 *                           at the first offending field, with inline messages
 *                           under every invalid field at once.
 *
 * The server is still the authority: these forms carry `novalidate` so the
 * browser stays out of the way, and table validation re-checks everything.
 */
document.addEventListener('DOMContentLoaded', function () {
    /**
     * The container CakePHP wraps each control in. Error messages are appended
     * here so they land in the same place as server-rendered ones.
     */
    function containerFor(field) {
        return field.closest('.input') || field.parentNode;
    }

    function clearError(field) {
        var container = containerFor(field);

        container.classList.remove('error');
        container.querySelectorAll('.error-message').forEach(function (node) {
            node.remove();
        });
        field.removeAttribute('aria-invalid');
        field.removeAttribute('aria-describedby');
    }

    function showError(field, message) {
        var container = containerFor(field);
        var node = document.createElement('div');

        node.className = 'error-message';
        node.textContent = message;
        // Linked to the input so a screen reader announces it with the field.
        if (field.id) {
            node.id = field.id + '-error';
            field.setAttribute('aria-describedby', node.id);
        }

        container.classList.add('error');
        container.appendChild(node);
        field.setAttribute('aria-invalid', 'true');
    }

    /**
     * CakePHP's own oninvalid handler only applies its custom message when the
     * field is empty, so a value in the wrong format falls back to the
     * browser's generic wording. data-format-message covers that case.
     */
    function messageFor(field) {
        if (field.validity.valueMissing && field.dataset.validityMessage) {
            return field.dataset.validityMessage;
        }

        if (!field.validity.valueMissing && field.dataset.formatMessage) {
            return field.dataset.formatMessage;
        }

        return field.validationMessage;
    }

    function validatableFields(form) {
        return Array.prototype.filter.call(form.elements, function (element) {
            return element.willValidate;
        });
    }

    function meaningfulTextError(field) {
        if (!field.hasAttribute('data-meaningful-text')) {
            return '';
        }

        var text = field.value.trim();
        var minimum = parseInt(field.getAttribute('minlength'), 10) || 0;

        // Nothing typed: the browser's own "required" check gives the empty message. Only spaces
        // typed counts as empty too, so it gets the same words.
        if (text === '') {
            return field.value === '' ? '' : (field.dataset.validityMessage || field.dataset.meaningfulMessage);
        }

        if (text.length < minimum) {
            return field.dataset.shortMessage || field.dataset.meaningfulMessage;
        }

        if (!/[A-Za-z]/.test(text)) {
            return field.dataset.meaningfulMessage;
        }

        if (/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(text)
            || /^(https?:\/\/|ftp:\/\/|www\.)\S+$/i.test(text)
        ) {
            return field.dataset.meaningfulMessage;
        }

        var alphanumeric = text.toLowerCase().replace(/[^a-z0-9]/g, '');
        if (/^([a-z0-9])\1*$/.test(alphanumeric)) {
            return field.dataset.meaningfulMessage;
        }

        return '';
    }

    function businessNow(timeZone) {
        var parts = new Intl.DateTimeFormat('en-CA', {
            timeZone: timeZone,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            hourCycle: 'h23'
        }).formatToParts(new Date());
        var values = {};

        parts.forEach(function (part) {
            values[part.type] = part.value;
        });

        return {
            date: values.year + '-' + values.month + '-' + values.day,
            time: values.hour + ':' + values.minute
        };
    }

    function isWeekday(date) {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) {
            return false;
        }
        var day = new Date(date + 'T12:00:00Z').getUTCDay();

        return day >= 1 && day <= 5;
    }

    var preferredDate = document.querySelector('[data-preferred-date]');
    var preferredTime = document.querySelector('[data-preferred-time]');
    if (preferredDate && preferredTime) {
        function updatePreferredSlots() {
            var now = businessNow(preferredDate.dataset.businessTimeZone);
            var date = preferredDate.value;
            var availableDate = date >= now.date && isWeekday(date);

            preferredDate.min = now.date;
            Array.prototype.forEach.call(preferredTime.options, function (option) {
                if (!option.value) {
                    return;
                }
                option.disabled = !availableDate || (date === now.date && option.value <= now.time);
            });
            if (preferredTime.selectedOptions.length && preferredTime.selectedOptions[0].disabled) {
                preferredTime.value = '';
            }
        }

        preferredDate.addEventListener('input', updatePreferredSlots);
        preferredDate.addEventListener('change', updatePreferredSlots);
        preferredTime.addEventListener('focus', updatePreferredSlots);
        updatePreferredSlots();
    }

    function applyCustomRules(field) {
        var message = meaningfulTextError(field);

        if (!message && field.hasAttribute('data-preferred-date') && field.value) {
            var now = businessNow(field.dataset.businessTimeZone);
            if (!isWeekday(field.value) || field.value < now.date) {
                message = field.dataset.formatMessage;
            }
        }
        if (!message && field.hasAttribute('data-preferred-time') && field.value && preferredDate) {
            var localNow = businessNow(preferredDate.dataset.businessTimeZone);
            if (field.value < '08:00' || field.value > '16:00'
                || (preferredDate.value === localNow.date && field.value <= localNow.time)
            ) {
                message = field.dataset.formatMessage;
            }
        }

        field.setCustomValidity(message);

        return message;
    }

    document.querySelectorAll('[data-digits-only]').forEach(function (field) {
        field.addEventListener('input', function () {
            var cleaned = field.value.replace(/\D/g, '');

            if (cleaned === field.value) {
                return;
            }

            // Keep the caret where the typing was, minus whatever was dropped,
            // otherwise editing the middle of a value throws the caret to the end.
            var caret = field.selectionStart === null
                ? cleaned.length
                : field.selectionStart - (field.value.length - cleaned.length);

            field.value = cleaned;
            field.setSelectionRange(caret, caret);
        });

        // Pasting needs its own pass. maxlength truncates the pasted text
        // before the input handler ever sees it, so pasting "VIC 3168" into a
        // four-character field would otherwise leave nothing but "VIC " to
        // strip. Cleaning the clipboard text first keeps the digits.
        field.addEventListener('paste', function (event) {
            var clipboard = event.clipboardData || window.clipboardData;

            if (!clipboard) {
                return;
            }

            event.preventDefault();

            var digits = clipboard.getData('text').replace(/\D/g, '');
            var limit = parseInt(field.getAttribute('maxlength'), 10) || Infinity;
            var start = field.selectionStart;
            var end = field.selectionEnd;

            field.value = (
                field.value.slice(0, start) + digits + field.value.slice(end)
            ).slice(0, limit);

            var caret = Math.min(start + digits.length, limit);
            field.setSelectionRange(caret, caret);
            field.dispatchEvent(new Event('input', { bubbles: true }));
        });
    });

    document.querySelectorAll('[data-au-phone]').forEach(function (field) {
        function formatPhone() {
            var digits = field.value.replace(/\D/g, '').slice(0, 10);
            var parts = [];

            if (digits.startsWith('03')) {
                parts.push(digits.slice(0, 2));
                if (digits.length > 2) {
                    parts.push(digits.slice(2, 6));
                }
                if (digits.length > 6) {
                    parts.push(digits.slice(6, 10));
                }
            } else {
                if (digits.length > 0) {
                    parts.push(digits.slice(0, 4));
                }
                if (digits.length > 4) {
                    parts.push(digits.slice(4, 7));
                }
                if (digits.length > 7) {
                    parts.push(digits.slice(7, 10));
                }
            }

            field.value = parts.join(' ');
        }

        field.addEventListener('input', formatPhone);
        formatPhone();
    });

    document.querySelectorAll('[data-char-counter]').forEach(function (field) {
        var limit = parseInt(field.getAttribute('maxlength'), 10);

        if (!limit) {
            return;
        }

        var counter = document.createElement('small');
        counter.className = 'char-counter';
        field.insertAdjacentElement('afterend', counter);

        function update() {
            counter.textContent = field.value.length + ' / ' + limit;
        }

        field.addEventListener('input', update);
        update();
    });

    document.querySelectorAll('form[data-validate-all]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            var fields = validatableFields(form);
            var firstInvalid = null;

            // Clearing first makes the pass deterministic: a custom message left
            // over from the previous attempt would otherwise keep a field
            // invalid even after it was corrected.
            fields.forEach(function (field) {
                field.setCustomValidity('');
                clearError(field);
            });

            fields.forEach(function (field) {
                var customMessage = applyCustomRules(field);

                if (field.checkValidity()) {
                    return;
                }

                showError(field, customMessage || messageFor(field));

                if (!firstInvalid) {
                    firstInvalid = field;
                }
            });

            if (firstInvalid) {
                event.preventDefault();
                firstInvalid.focus();
            }
        });

        ['input', 'change'].forEach(function (eventName) {
            form.addEventListener(eventName, function (event) {
                var field = event.target;

                if (!field.willValidate) {
                    return;
                }

                applyCustomRules(field);

                if (field.checkValidity()) {
                    clearError(field);
                }
            });
        });
    });
});
