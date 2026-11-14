/**
 * Opens and closes the customer portal's fixed sidebar.
 *
 * The rail is genuinely fixed now (see dashboard.css), which is what lets it
 * reach the top of the page, but it also means it has to be toggled off
 * screen by script rather than by scrolling past it. The state is
 * remembered in localStorage so it does not reset itself on every page - a
 * customer who closes it while reading a page does not expect the click to
 * a different page to open it back up.
 *
 * With no stored preference yet, the default follows viewport width: open
 * on a screen wide enough for the rail to sit beside the content, closed on
 * one narrow enough that it would otherwise cover the page (see the same
 * breakpoint in dashboard.css).
 */
document.addEventListener('DOMContentLoaded', function () {
    var nav = document.getElementById('portal-nav');
    var toggle = document.getElementById('portal-nav-toggle');

    if (!nav || !toggle) {
        return;
    }

    var STORAGE_KEY = 'portalNavOpen';
    var narrowScreen = window.matchMedia('(max-width: 56.25em)');

    function readStored() {
        try {
            return window.localStorage.getItem(STORAGE_KEY);
        } catch (error) {
            // Private browsing or storage disabled. The toggle still works
            // for this page view, it just will not be remembered.
            return null;
        }
    }

    function writeStored(open) {
        try {
            window.localStorage.setItem(STORAGE_KEY, open ? '1' : '0');
        } catch (error) {
            // Nothing to do - see readStored().
        }
    }

    function setOpen(open) {
        document.body.classList.toggle('portal-nav-open', open);
        document.body.classList.toggle('portal-nav-closed', !open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    var stored = readStored();
    setOpen(stored === null ? !narrowScreen.matches : stored === '1');

    toggle.addEventListener('click', function () {
        var open = !document.body.classList.contains('portal-nav-open');
        setOpen(open);
        writeStored(open);
    });

    // On a narrow screen the rail is drawn over the page rather than beside
    // it, so a click anywhere else on the page is a reasonable way to
    // dismiss it - the same behaviour as any other off-canvas menu.
    document.addEventListener('click', function (event) {
        if (!narrowScreen.matches || !document.body.classList.contains('portal-nav-open')) {
            return;
        }

        if (nav.contains(event.target) || toggle.contains(event.target)) {
            return;
        }

        setOpen(false);
        writeStored(false);
    });
});
