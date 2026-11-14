/**
 * The website assistant.
 *
 * Two rules shape everything here.
 *
 * Nothing from the server is ever written as HTML. Every message goes in with
 * textContent, and links are built as elements from the JSON rather than parsed
 * out of the answer text. A language model's output is untrusted input like any
 * other, and this is the one place in the site where text we did not write ends
 * up on the page.
 *
 * Nothing it does can break the page. The panel is revealed by this script, so
 * if the script fails to load the widget simply is not there and the ordinary
 * contact and job request forms carry on exactly as before.
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-assistant]');
    if (!root) {
        return;
    }

    var launcher = root.querySelector('[data-assistant-launcher]');
    var panel = root.querySelector('[data-assistant-panel]');
    var closeButton = root.querySelector('[data-assistant-close]');
    var log = root.querySelector('[data-assistant-log]');
    var input = root.querySelector('[data-assistant-input]');
    var send = root.querySelector('[data-assistant-send]');
    var badge = root.querySelector('[data-assistant-badge]');
    var badgeText = root.querySelector('[data-assistant-badge-text]');

    if (!launcher || !panel || !closeButton || !log || !input || !send) {
        return;
    }

    var busy = false;
    var unread = 0;

    /**
     * Shows or clears the unread mark on the launcher.
     *
     * The dot is decorative and the text span carries the same news for a
     * screen reader, so both move together. Sitting inside the button, the
     * span's words become part of the button's accessible name, which is why
     * there is no aria-label to keep in step with it.
     */
    function renderUnread() {
        var has = unread > 0;

        if (badge) {
            badge.hidden = !has;
        }

        if (badgeText) {
            badgeText.hidden = !has;
            badgeText.textContent = has
                ? (unread === 1 ? ', 1 new answer' : ', ' + unread + ' new answers')
                : '';
        }
    }

    // The markup ships hidden so that a browser with no JavaScript, or one
    // where this file 404s, shows nothing rather than a dead button.
    root.hidden = false;

    function openPanel() {
        panel.hidden = false;
        launcher.setAttribute('aria-expanded', 'true');
        root.classList.add('is-open');
        unread = 0;
        renderUnread();
        input.focus();
    }

    function closePanel() {
        panel.hidden = true;
        launcher.setAttribute('aria-expanded', 'false');
        root.classList.remove('is-open');
        // Focus goes back to the button that opened it, otherwise a keyboard
        // user is dropped at the top of the document with no idea where they
        // are.
        launcher.focus();
    }

    function addMessage(text, who) {
        var p = document.createElement('p');
        p.className = 'assistant__message assistant__message--' + who;
        p.textContent = text;
        log.appendChild(p);
        log.scrollTop = log.scrollHeight;

        return p;
    }

    /**
     * A reply has arrived. If nobody is looking at the panel, mark it unread.
     *
     * Only counts while the panel is shut, which is the case this is for:
     * asking something and closing the panel before the answer lands. An
     * answer the person is already reading is not news.
     */
    function noteReply() {
        if (!panel.hidden) {
            return;
        }

        unread += 1;
        renderUnread();
    }

    function addLinks(links) {
        if (!Array.isArray(links) || links.length === 0) {
            return;
        }

        var wrap = document.createElement('p');
        wrap.className = 'assistant__links';

        links.forEach(function (link) {
            if (!link || typeof link.url !== 'string' || typeof link.label !== 'string') {
                return;
            }

            // Same-site paths only, checked again here. The server already
            // enforces it, but this is the step that actually creates an href,
            // and "//evil.example" is a protocol-relative URL that looks like a
            // path if you only check the first character.
            if (link.url.charAt(0) !== '/' || link.url.charAt(1) === '/') {
                return;
            }

            var a = document.createElement('a');
            a.className = 'assistant__link';
            a.href = link.url;
            a.textContent = link.label;
            wrap.appendChild(a);
        });

        if (wrap.childNodes.length > 0) {
            log.appendChild(wrap);
            log.scrollTop = log.scrollHeight;
        }
    }

    /**
     * CakePHP's CSRF cookie. Readable because the middleware sets httponly
     * false; it carries no authority on its own and is useless without the
     * session cookie beside it.
     */
    function csrfToken() {
        var match = document.cookie.match(/(?:^|;\s*)csrfToken=([^;]*)/);

        return match ? decodeURIComponent(match[1]) : '';
    }

    function ask() {
        var question = input.value.trim();
        if (question === '' || busy) {
            return;
        }

        busy = true;
        send.disabled = true;
        addMessage(question, 'you');
        input.value = '';

        var thinking = addMessage('Thinking...', 'bot');

        fetch('/assistant/ask', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken(),
                Accept: 'application/json'
            },
            // Needed so the session cookie rides along and the server can work
            // out which answers this person is allowed to see.
            credentials: 'same-origin',
            body: JSON.stringify({ question: question })
        })
            .then(function (response) {
                return response.json().catch(function () {
                    return null;
                });
            })
            .then(function (data) {
                thinking.remove();

                if (!data || typeof data.answer !== 'string') {
                    addMessage(
                        'Something went wrong at my end. Please try again, or contact Clean Flow directly.',
                        'bot'
                    );
                    noteReply();

                    return;
                }

                addMessage(data.answer, 'bot');
                addLinks(data.links);
                noteReply();
            })
            .catch(function () {
                thinking.remove();
                // Offline, blocked, DNS, anything. The person gets a sentence
                // rather than a silent dead widget.
                addMessage(
                    'I could not reach the assistant just now. Please try again, or contact Clean Flow directly.',
                    'bot'
                );
                noteReply();
            })
            .finally(function () {
                busy = false;
                send.disabled = false;
                input.focus();
            });
    }

    launcher.addEventListener('click', function () {
        if (panel.hidden) {
            openPanel();
        } else {
            closePanel();
        }
    });

    closeButton.addEventListener('click', closePanel);
    send.addEventListener('click', ask);

    input.addEventListener('keydown', function (event) {
        // Enter sends, Shift+Enter makes a new line. Expected of a chat box,
        // and it means the keyboard alone is enough to use the whole thing.
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            ask();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !panel.hidden) {
            closePanel();
        }
    });
}());
