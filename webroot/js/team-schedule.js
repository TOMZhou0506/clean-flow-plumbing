/**
 * Office team schedule.
 *
 * - [data-now-line]   moves the red "now" line along the day view every
 *                     minute, and hides it once the working day is over.
 * - [data-team-clock] the page reloads itself every five minutes while it is
 *                     open and visible, so who is on a job stays current.
 */
document.addEventListener('DOMContentLoaded', function () {
    var day = document.querySelector('[data-now-line]');
    var line = day ? day.querySelector('.team-day__now') : null;

    if (day && line) {
        var start = parseInt(day.dataset.dayStart, 10);
        var length = parseInt(day.dataset.dayLength, 10);
        var loadedMinute = parseInt(day.dataset.nowMinute, 10);
        var loadedAt = Date.now();
        var label = line.querySelector('span');

        var moveLine = function () {
            var minute = loadedMinute + Math.floor((Date.now() - loadedAt) / 60000);
            if (minute > start + length) {
                line.hidden = true;
                return;
            }
            line.style.setProperty('--now', String((minute - start) / length));
            if (label) {
                var hour = Math.floor(minute / 60) % 12 || 12;
                label.textContent = hour + ':' + String(minute % 60).padStart(2, '0');
            }
        };

        setInterval(moveLine, 30000);
    }

    if (document.querySelector('[data-team-clock]')) {
        setInterval(function () {
            if (document.visibilityState === 'visible') {
                window.location.reload();
            }
        }, 5 * 60 * 1000);
    }
});
