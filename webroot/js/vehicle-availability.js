document.addEventListener('DOMContentLoaded', function () {
    var form = document.querySelector('form[data-vehicle-availability-url]');
    if (!form) {
        return;
    }

    var start = form.querySelector('[name="scheduled_start"]');
    var duration = form.querySelector('[name="duration_hours"]');
    var vehicles = form.querySelector('[name="vehicle_id"]');
    var note = document.getElementById('vehicle-availability-note');
    var requestNumber = 0;

    function refresh() {
        var thisRequest = ++requestNumber;
        if (!start.value || !duration.value) {
            note.textContent = 'Choose a start and duration to check vehicle availability.';
            return;
        }

        var url = new URL(form.dataset.vehicleAvailabilityUrl, window.location.href);
        url.searchParams.set('start', start.value);
        url.searchParams.set('duration', duration.value);
        note.textContent = 'Checking vehicles for this visit…';
        fetch(url.toString(), { credentials: 'same-origin' })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (thisRequest !== requestNumber) {
                    return;
                }
                if (data.error) {
                    note.textContent = data.error;
                    return;
                }
                data.vehicles.forEach(function (vehicle) {
                    var option = Array.prototype.find.call(vehicles.options, function (entry) {
                        return entry.value === String(vehicle.id);
                    });
                    if (!option) {
                        return;
                    }
                    option.textContent = vehicle.label + ' — ' + vehicle.status
                        + (vehicle.plumber ? ' (' + vehicle.plumber + ')' : '');
                    option.disabled = vehicle.status !== 'Available';
                    if (option.disabled && option.selected) {
                        vehicles.value = '';
                    }
                });
                note.textContent = 'Availability shown for the selected visit. It is checked again when you assign.';
            })
            .catch(function () {
                if (thisRequest === requestNumber) {
                    note.textContent = 'Could not check vehicles right now. Availability is checked when you assign.';
                }
            });
    }

    start.addEventListener('change', refresh);
    duration.addEventListener('change', refresh);
    refresh();
});
