/**
 * Keeps a customer's job status page up to date without a manual refresh.
 *
 * Every half minute, while the tab is in view, the page asks the server for
 * itself again and swaps in any region marked data-live-region whose content
 * has changed. The server is the same one that rendered the page, and it
 * escapes everything it outputs, so the fresh markup is as safe as the markup
 * already on screen.
 *
 * It stops quietly if the session has ended or the server errors repeatedly,
 * rather than retrying forever or showing the sign-in page inside the status.
 */
function startLiveStatus() {
    // This script is loaded in the page head, so it has to wait for the body
    // before looking for the regions it keeps fresh.
    var regions = document.querySelectorAll('[data-live-region]');
    if (!regions.length || !window.fetch || !window.DOMParser) {
        return;
    }

    var INTERVAL_MS = 30000;
    var MAX_FAILURES = 3;
    var failures = 0;
    var timer = null;

    function stop() {
        if (timer !== null) {
            window.clearInterval(timer);
            timer = null;
        }
    }

    function refresh() {
        if (document.hidden) {
            return;
        }

        fetch(window.location.href, {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (response) {
                // A redirect means the session ended and this is now a sign-in
                // page, which must never be swapped into the status.
                if (!response.ok || response.redirected) {
                    throw new Error('stop');
                }
                return response.text();
            })
            .then(function (html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                regions.forEach(function (region) {
                    var name = region.getAttribute('data-live-region');
                    var fresh = doc.querySelector('[data-live-region="' + name + '"]');
                    if (fresh && fresh.innerHTML !== region.innerHTML) {
                        region.innerHTML = fresh.innerHTML;
                    }
                });

                // The rating form sits outside the live regions, so a job that
                // completes while this page is open needs one reload to show it.
                // Once it is on the page this no longer applies, so it cannot loop.
                if (doc.querySelector('[data-rating-section]') && !document.querySelector('[data-rating-section]')) {
                    window.location.reload();
                    return;
                }
                failures = 0;
            })
            .catch(function (error) {
                failures++;
                if (error.message === 'stop' || failures >= MAX_FAILURES) {
                    stop();
                }
            });
    }

    timer = window.setInterval(refresh, INTERVAL_MS);
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
            refresh();
        }
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', startLiveStatus);
} else {
    startLiveStatus();
}
