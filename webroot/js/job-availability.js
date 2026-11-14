/**
 * The assign form: who is free for the visit being scheduled, and a summary
 * of what is about to be assigned.
 *
 * Once a start time and duration are chosen this asks the server who is free
 * for exactly that interval, puts a status badge on every plumber and vehicle
 * card, and disables any card that is not free so it cannot be picked. It is
 * guidance only: the server checks everything again when the job is assigned.
 *
 * Names and details come from the database, so they are written with
 * textContent rather than innerHTML.
 */
document.addEventListener('DOMContentLoaded', function () {
    var form = document.querySelector('form[data-availability-url]');
    if (!form) {
        return;
    }

    var start = form.querySelector('[name="scheduled_start"]');
    var note = document.getElementById('availability-note');
    var summary = document.getElementById('assign-summary');
    var groups = {
        plumbers: form.querySelector('[data-availability-group="plumbers"]'),
        vehicles: form.querySelector('[data-availability-group="vehicles"]')
    };
    var latest = 0;
    var idleText = note.textContent;
    var idleSummary = summary.textContent;

    // A card's detail line starts with what the server rendered (a vehicle's
    // registration); availability detail is added after it.
    form.querySelectorAll('[data-detail]').forEach(function (detail) {
        detail.dataset.base = detail.textContent.trim();
    });

    var durationInput = form.querySelector('[name="duration_hours"]');
    var durationLabel = document.getElementById('duration-label');

    function duration() {
        return durationInput ? durationInput.value : '';
    }

    // "2 hours · ends 1:00pm" beside the slider, updated while it is dragged.
    function updateDurationLabel() {
        if (!durationLabel) {
            return;
        }
        var hours = parseInt(duration(), 10);
        var text = hours + (hours === 1 ? ' hour' : ' hours');
        var begins = start.value ? new Date(start.value) : null;
        if (begins && !isNaN(begins.getTime())) {
            var ends = new Date(begins.getTime() + hours * 3600000);
            text += ' · ends ' + ends.toLocaleTimeString('en-AU', { hour: 'numeric', minute: '2-digit' });
        }
        durationLabel.textContent = text;
    }

    function checkedName(field) {
        var input = form.querySelector('[name="' + field + '"]:checked');
        if (!input) {
            return '';
        }
        return input.closest('.assign-card').querySelector('.assign-card__name').textContent.trim();
    }

    function formatVisit() {
        if (!start.value || !duration()) {
            return '';
        }
        var begins = new Date(start.value);
        if (isNaN(begins.getTime())) {
            return '';
        }
        var ends = new Date(begins.getTime() + parseInt(duration(), 10) * 3600000);
        var time = function (date) {
            return date.toLocaleTimeString('en-AU', { hour: 'numeric', minute: '2-digit' });
        };
        return begins.toLocaleDateString('en-AU', { weekday: 'short', day: 'numeric', month: 'short' })
            + ', ' + time(begins) + '–' + time(ends);
    }

    function updateSummary() {
        var visit = formatVisit();
        var plumber = checkedName('plumber_admin_id');
        var vehicle = checkedName('vehicle_id');
        if (!visit && !plumber && !vehicle) {
            summary.textContent = idleSummary;
            return;
        }
        summary.textContent = [
            plumber || 'No plumber yet',
            vehicle || 'no vehicle yet',
            visit || 'no time yet'
        ].join(' · ');
        summary.classList.toggle('is-ready', Boolean(visit && plumber && vehicle));
    }

    function resetCards() {
        Object.keys(groups).forEach(function (key) {
            if (!groups[key]) {
                return;
            }
            groups[key].querySelectorAll('.assign-card').forEach(function (card) {
                card.classList.remove('is-free', 'is-taken');
                card.querySelector('input').disabled = false;
                card.querySelector('[data-badge]').textContent = '';
                var detail = card.querySelector('[data-detail]');
                detail.textContent = detail.dataset.base;
            });
        });
    }

    function applyStatus(group, items) {
        if (!group) {
            return;
        }
        group.querySelectorAll('.assign-card').forEach(function (card) {
            var match = items.filter(function (item) {
                return String(item.id) === card.dataset.optionId;
            })[0];
            if (!match) {
                return;
            }
            var free = match.status === 'Available';
            var input = card.querySelector('input');
            card.classList.toggle('is-free', free);
            card.classList.toggle('is-taken', !free);
            card.querySelector('[data-badge]').textContent = match.status;
            var detail = card.querySelector('[data-detail]');
            detail.textContent = [detail.dataset.base, match.detail].filter(Boolean).join(' · ');
            input.disabled = !free;
            if (!free && input.checked) {
                input.checked = false;
            }
        });
    }

    function refresh() {
        var thisRequest = ++latest;
        updateDurationLabel();
        updateSummary();

        if (!start.value || !duration()) {
            note.textContent = idleText;
            note.className = 'assign-status';
            resetCards();
            return;
        }

        var url = new URL(form.dataset.availabilityUrl, window.location.href);
        url.searchParams.set('start', start.value);
        url.searchParams.set('duration', duration());
        if (form.dataset.jobId) {
            url.searchParams.set('job', form.dataset.jobId);
        }
        note.textContent = 'Checking who is free…';
        note.className = 'assign-status is-loading';

        fetch(url.toString(), { credentials: 'same-origin' })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (thisRequest !== latest) {
                    return;
                }

                if (data.error) {
                    note.textContent = data.error;
                    note.className = 'assign-status is-error';
                    resetCards();
                    return;
                }

                applyStatus(groups.plumbers, data.plumbers);
                applyStatus(groups.vehicles, data.vehicles);
                // Count the cards on the form, not the server's list, which
                // also includes vehicles that are out of service.
                var count = function (group, selector) {
                    return group ? group.querySelectorAll(selector).length : 0;
                };
                note.textContent = count(groups.plumbers, '.assign-card.is-free') + ' of '
                    + count(groups.plumbers, '.assign-card') + ' plumbers and '
                    + count(groups.vehicles, '.assign-card.is-free') + ' of '
                    + count(groups.vehicles, '.assign-card') + ' vehicles are free for this visit.';
                note.className = 'assign-status is-ready';
                updateSummary();
            })
            .catch(function () {
                if (thisRequest === latest) {
                    note.textContent = 'Could not check availability right now. '
                        + 'It is checked again when you assign.';
                    note.className = 'assign-status is-error';
                }
            });
    }

    form.querySelectorAll('[data-use-start]').forEach(function (button) {
        button.addEventListener('click', function () {
            start.value = button.dataset.useStart;
            refresh();
        });
    });

    start.addEventListener('change', refresh);
    // The label follows the slider live; availability is checked once it is let go.
    durationInput.addEventListener('input', function () {
        updateDurationLabel();
        updateSummary();
    });
    durationInput.addEventListener('change', refresh);
    form.querySelectorAll('[name="plumber_admin_id"], [name="vehicle_id"]').forEach(function (input) {
        input.addEventListener('change', updateSummary);
    });
    refresh();
});
