document.addEventListener('DOMContentLoaded', () => {
    const allDay = document.getElementById('unavailable-all-day');
    const timeFields = document.getElementById('availability-time-fields');
    const dateField = document.getElementById('unavailable-date');
    const form = document.getElementById('mark-unavailable');

    if (allDay && timeFields) {
        const updateTimeFields = () => {
            for (const input of timeFields.querySelectorAll('input')) {
                input.disabled = allDay.checked;
            }
        };
        allDay.addEventListener('change', updateTimeFields);
        updateTimeFields();
    }

    if (dateField && form) {
        for (const button of document.querySelectorAll('[data-availability-date]')) {
            button.addEventListener('click', () => {
                dateField.value = button.dataset.availabilityDate;
                form.scrollIntoView({ behavior: 'smooth', block: 'start' });
                dateField.focus({ preventScroll: true });
            });
        }
    }
});
