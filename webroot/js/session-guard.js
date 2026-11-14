/**
 * Signs somebody out when they come back to the site after closing it.
 *
 * Closing a tab does not end a browser session, so the session cookie
 * survives it, and browsers that restore the last session keep it across a
 * restart too. A cookie setting cannot fix that, so this works out in the
 * browser whether the site was closed:
 *
 * - Every page of the site, signed in or not, marks its tab as "on the site"
 *   in sessionStorage as it is left. sessionStorage belongs to one tab and is
 *   gone when the tab closes, so the next page in the same tab finds the mark
 *   and carries on as normal.
 * - A signed-in page that finds no recent mark is in a new tab. It asks the
 *   site's other open tabs whether they are there (BroadcastChannel). If one
 *   answers, the site is still open (a link opened in a new tab, say) and
 *   nothing happens. If none does, the site was closed: the session is ended
 *   and the visitor goes to the sign-in page.
 * - Reopening a closed tab (Ctrl+Shift+T) brings its sessionStorage back, so
 *   the mark carries the time it was made, and a mark older than a few seconds
 *   counts as the site having been closed.
 *
 * The server's idle timeout still applies on top of this.
 */
(function () {
    var MARK = 'cleanflow.onSite';
    var CHANNEL = 'cleanflow-site';
    // Long enough for the slowest page of the site to load after a click.
    var MARK_FRESH_MS = 10 * 1000;
    // How long a new tab waits for another tab to say it is there.
    var ANSWER_WAIT_MS = 700;

    var channel = null;
    try {
        channel = typeof BroadcastChannel === 'function' ? new BroadcastChannel(CHANNEL) : null;
    } catch (error) {
        channel = null;
    }

    function tabStore() {
        try {
            return window.sessionStorage;
        } catch (error) {
            return null;
        }
    }

    function mark() {
        var store = tabStore();
        if (store) {
            try {
                store.setItem(MARK, String(Date.now()));
            } catch (error) {
                // Storage blocked: this tab simply looks new next time.
            }
        }
    }

    function hasFreshMark() {
        var store = tabStore();
        var at = store ? Number(store.getItem(MARK)) : 0;

        return at > 0 && Date.now() - at < MARK_FRESH_MS;
    }

    // In a tab that was not just on the site, keep the page hidden until the
    // check below has run, so a signed-in page is not on screen for the moment
    // before it signs out. Never for longer than a few seconds, whatever happens.
    var CHECKING = 'cleanflow-session-check';
    function reveal() {
        document.documentElement.classList.remove(CHECKING);
    }
    if (!hasFreshMark()) {
        var hide = document.createElement('style');
        hide.textContent = 'html.' + CHECKING + ' body { visibility: hidden; }';
        (document.head || document.documentElement).appendChild(hide);
        document.documentElement.classList.add(CHECKING);
        window.setTimeout(reveal, 3000);
    }

    // Any open page of the site answers "is anyone there?".
    if (channel) {
        channel.addEventListener('message', function (event) {
            if (event.data === 'anyone-there') {
                channel.postMessage('here');
            }
        });
    }

    function csrfToken() {
        var match = document.cookie.match(/(?:^|;\s*)csrfToken=([^;]+)/);

        return match ? decodeURIComponent(match[1]) : '';
    }

    function signOut(guard) {
        var body = new URLSearchParams();
        body.set('_csrfToken', csrfToken());

        fetch(guard.getAttribute('data-logout-url'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
            redirect: 'manual'
        }).catch(function () {
            // Going to the sign-in page anyway. The server's idle timeout ends
            // the session even if this request never arrived.
        }).then(function () {
            // The logout route leaves its usual "You have been logged out"
            // message for the sign-in page to show.
            window.location.replace(guard.getAttribute('data-login-url'));
        });
    }

    /** Resolves true when another tab of the site answers. */
    function anotherTabIsOpen() {
        return new Promise(function (resolve) {
            if (!channel) {
                // Without BroadcastChannel there is no way to ask, so assume
                // the site is open rather than sign somebody out wrongly.
                resolve(true);

                return;
            }
            var answered = false;
            var listen = function (event) {
                if (event.data === 'here') {
                    answered = true;
                    channel.removeEventListener('message', listen);
                    resolve(true);
                }
            };
            channel.addEventListener('message', listen);
            channel.postMessage('anyone-there');
            window.setTimeout(function () {
                if (!answered) {
                    channel.removeEventListener('message', listen);
                    resolve(false);
                }
            }, ANSWER_WAIT_MS);
        });
    }

    function check() {
        var guard = document.querySelector('[data-session-guard]');
        if (!guard || !tabStore() || hasFreshMark()) {
            mark();
            reveal();

            return;
        }

        // A signed-in page in a tab that was not just on the site.
        anotherTabIsOpen().then(function (open) {
            if (open) {
                mark();
                reveal();
            } else {
                // Stays hidden while it goes to the sign-in page.
                signOut(guard);
            }
        });
    }

    function start() {
        // Signing in or registering is being on the site.
        document.querySelectorAll('form[data-session-start]').forEach(function (form) {
            form.addEventListener('submit', mark);
        });
        check();
    }

    // Leaving any page of the site (a link, a refresh, or closing the tab)
    // marks this tab, so the next page in it carries straight on.
    window.addEventListener('pagehide', mark);
    // A page brought back from the back/forward cache did not load again, so
    // check it as if it had.
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) {
            check();
        }
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
