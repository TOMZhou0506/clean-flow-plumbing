/**
 * Address suggestions on the job request form.
 *
 * Attaches to [data-address-lookup] and nothing else, so this file is inert on
 * every page that does not ask for it. The endpoints come from data attributes
 * rather than being written here, because the paths are decided by routes.php
 * and I would rather they were wrong in one place than two.
 *
 * The rule this whole file is built around: the form must work without it.
 * Every failure path ends by hiding the list and leaving the four fields
 * exactly as the customer left them. No error is shown, nothing is disabled,
 * and the submit button is never touched. A customer whose connection drops,
 * or whose address is not in G-NAF, types it in as they always have.
 *
 * Built as an ARIA combobox rather than a <datalist> because picking a
 * suggestion has to fill four separate fields, which a datalist cannot do, and
 * because a datalist cannot be driven from the keyboard consistently across
 * browsers.
 */
document.addEventListener('DOMContentLoaded', function () {
    var field = document.querySelector('[data-address-lookup]');

    if (!field || !window.fetch) {
        return;
    }

    var form = field.closest('form');
    var suggestUrl = field.getAttribute('data-suggest-url');
    var resolveUrl = field.getAttribute('data-resolve-url');

    if (!form || !suggestUrl || !resolveUrl) {
        return;
    }

    // Set when Photon is the provider. The browser then asks Photon itself,
    // which answers in a few hundred milliseconds where the round trip through
    // our server takes seconds. The two URLs above are the fallback.
    var photonUrl = field.getAttribute('data-photon-url');
    var photonBox = field.getAttribute('data-photon-bbox') || '';
    var photonState = (field.getAttribute('data-photon-state') || '').toUpperCase();

    /**
     * The three fields a chosen suggestion fills in besides this one. Looked
     * up by the name CakePHP gives them.
     */
    function partner(name) {
        return form.querySelector('[name="' + name + '"]');
    }

    var suburb = partner('suburb');
    var state = partner('state');
    var postcode = partner('postcode');

    /** Minimum characters before asking. Matches the server, which ignores less. */
    var MIN_LENGTH = 4;

    /** The shortest gap between searches while typing; requests never overlap anyway. */
    var DEBOUNCE_MS = 150;

    var list = document.createElement('ul');
    var status = document.createElement('p');
    var timer = null;
    var controller = null;
    var suggestions = [];
    var active = -1;
    /** Set while a suggestion is being applied, so the fill does not retrigger the search. */
    var applying = false;
    /**
     * One search at a time. Cancelling the last one on every keystroke meant a
     * fast typist never saw an answer at all, because each search takes a
     * moment and was always cancelled before it came back. Now a search runs
     * to the end, and if more was typed meanwhile, the newest text is searched
     * straight after.
     */
    var inFlight = false;
    var queued = false;
    var lastSearched = '';
    /** The last answer from the server, narrowed locally while the next one is on its way. */
    var latest = [];

    list.className = 'address-suggestions';
    list.setAttribute('role', 'listbox');
    list.id = 'address-suggestions';
    list.hidden = true;

    // A screen reader gets told how many matches arrived. Without this the
    // list appearing is a silent event for anyone not looking at it.
    status.className = 'address-suggestions__status visually-hidden';
    status.setAttribute('role', 'status');
    status.setAttribute('aria-live', 'polite');

    var container = field.closest('.input') || field.parentNode;
    container.classList.add('address-field');
    container.appendChild(list);
    container.appendChild(status);

    field.setAttribute('role', 'combobox');
    field.setAttribute('aria-expanded', 'false');
    field.setAttribute('aria-controls', list.id);
    field.setAttribute('aria-autocomplete', 'list');
    // The browser's own autofill dropdown would sit on top of this one.
    field.setAttribute('autocomplete', 'off');

    function close() {
        list.hidden = true;
        list.innerHTML = '';
        field.setAttribute('aria-expanded', 'false');
        field.removeAttribute('aria-activedescendant');
        suggestions = [];
        active = -1;
    }

    function highlight(index) {
        var options = list.querySelectorAll('[role="option"]');

        options.forEach(function (option, position) {
            var isActive = position === index;

            option.classList.toggle('is-active', isActive);
            option.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });

        active = index;

        if (index >= 0 && options[index]) {
            field.setAttribute('aria-activedescendant', options[index].id);
            options[index].scrollIntoView({ block: 'nearest' });
        } else {
            field.removeAttribute('aria-activedescendant');
        }
    }

    function render(items) {
        list.innerHTML = '';
        suggestions = items;
        active = -1;

        if (!items.length) {
            close();
            status.textContent = '';

            return;
        }

        items.forEach(function (item, index) {
            var option = document.createElement('li');

            option.id = 'address-suggestion-' + index;
            option.setAttribute('role', 'option');
            option.setAttribute('aria-selected', 'false');
            option.className = 'address-suggestions__option';
            // textContent, never innerHTML. The label comes back from an
            // external service and is written straight into the page.
            option.textContent = item.label;

            // mousedown rather than click: click arrives after the field has
            // already blurred, and the blur handler closes the list first.
            option.addEventListener('mousedown', function (event) {
                event.preventDefault();
                choose(index);
            });

            list.appendChild(option);
        });

        list.hidden = false;
        field.setAttribute('aria-expanded', 'true');
        status.textContent = items.length === 1
            ? '1 address found. Use the arrow keys to review it.'
            : items.length + ' addresses found. Use the arrow keys to review them.';
    }

    /**
     * Fill a field only if it is there, and tell the rest of the page it
     * changed so the shared validation in form-enhancements.js clears any
     * error already showing against it.
     */
    function fill(input, value) {
        if (!input || typeof value !== 'string') {
            return;
        }

        input.value = value;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function choose(index) {
        var item = suggestions[index];

        if (!item) {
            return;
        }

        // The label goes in straight away so the field reflects the choice
        // even if the resolve call is slow or never answers. If it does
        // answer, the proper street line replaces this a moment later.
        applying = true;
        field.value = item.label;
        close();

        // Straight from Photon, the fields are already here.
        if (item.fields) {
            fill(field, item.fields.street);
            fill(suburb, item.fields.suburb);
            fill(state, item.fields.state);
            fill(postcode, item.fields.postcode);
            applying = false;

            return;
        }

        request(resolveUrl + '?id=' + encodeURIComponent(item.id))
            .then(function (data) {
                var address = data && data.address;

                if (!address) {
                    // Resolving failed. The label is still in the field and
                    // the other three are still editable, so the customer can
                    // finish by hand.
                    return;
                }

                fill(field, address.street);
                fill(suburb, address.suburb);
                fill(state, address.state);
                fill(postcode, address.postcode);
            })
            .catch(function () {
                // Deliberately silent. See the note at the top.
            })
            .then(function () {
                applying = false;
            });
    }

    function request(url) {
        if (controller) {
            controller.abort();
        }

        controller = typeof AbortController === 'function' ? new AbortController() : null;

        return fetch(url, {
            headers: { Accept: 'application/json' },
            // So the customer's session rides along. Without it the endpoint
            // cannot tell who is asking and refuses.
            credentials: 'same-origin',
            signal: controller ? controller.signal : undefined
        }).then(function (response) {
            if (!response.ok) {
                return null;
            }

            return response.json().catch(function () {
                return null;
            });
        });
    }

    /**
     * Whether a suggestion still fits what has been typed: every typed word is
     * the start of a word in the label, so "12 wattle s" still matches
     * "12 Wattle Street, Prahran VIC 3181".
     */
    function fits(item, term) {
        var words = item.label.toLowerCase().split(/[\s,\/]+/);

        return term.toLowerCase().split(/[\s,]+/).filter(Boolean).every(function (typed) {
            return words.some(function (word) {
                return word.indexOf(typed) === 0;
            });
        });
    }

    /** A "Searching…" line, shown only while there is nothing better to show. */
    function showSearching() {
        if (!list.hidden && list.querySelector('[role="option"]')) {
            return;
        }

        list.innerHTML = '';
        suggestions = [];
        active = -1;

        var row = document.createElement('li');
        row.className = 'address-suggestions__searching';
        row.setAttribute('role', 'presentation');
        row.textContent = 'Searching…';
        list.appendChild(row);
        list.hidden = false;
        field.setAttribute('aria-expanded', 'true');
    }

    /**
     * The same rules as App\Service\Address\PhotonAddress on the server, so a
     * suggestion looks the same whichever route it came by. Change both
     * together.
     */
    var STATES = {
        'australian capital territory': 'ACT',
        'new south wales': 'NSW',
        'northern territory': 'NT',
        'queensland': 'QLD',
        'south australia': 'SA',
        'tasmania': 'TAS',
        'victoria': 'VIC',
        'western australia': 'WA'
    };

    function text(value) {
        return (typeof value === 'string' || typeof value === 'number') ? String(value).trim() : '';
    }

    function photonFields(properties, term) {
        if (!properties || text(properties.countrycode).toUpperCase() !== 'AU') {
            return null;
        }

        var state = STATES[text(properties.state).toLowerCase()] || '';
        var postcode = text(properties.postcode);
        // In a city the suburb arrives as "district" and the city as "Melbourne".
        var suburb = text(properties.district) || text(properties.city) || text(properties.locality);
        var street = '';

        if (properties.type === 'house') {
            if (text(properties.housenumber) && text(properties.street)) {
                street = text(properties.housenumber) + ' ' + text(properties.street);
            }
        } else if (properties.type === 'street') {
            street = text(properties.name);
            // OpenStreetMap lacks many house numbers, so a typed one is kept.
            var typed = term.match(/^\s*(\d+[A-Za-z]?(?:\/\d+[A-Za-z]?)?)\s+\S/);
            if (street && typed) {
                street = typed[1] + ' ' + street;
            }
        } else {
            return null;
        }

        if (!street || !suburb || !state || !/^\d{4}$/.test(postcode)) {
            return null;
        }

        return { street: street.slice(0, 255), suburb: suburb, state: state, postcode: postcode };
    }

    function askPhoton(term) {
        var url = photonUrl + '?q=' + encodeURIComponent(term)
            + '&limit=15&lang=en&layer=house&layer=street'
            + (photonBox ? '&bbox=' + encodeURIComponent(photonBox) : '');

        return fetch(url, {
            headers: { Accept: 'application/json' },
            // Photon gets the typed text and nothing about which page asked.
            referrerPolicy: 'no-referrer',
            credentials: 'omit'
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('photon');
            }

            return response.json();
        }).then(function (data) {
            var seen = {};
            var items = [];

            (data && Array.isArray(data.features) ? data.features : []).forEach(function (feature) {
                var fields = photonFields(feature && feature.properties, term);
                if (!fields || (photonState && fields.state !== photonState) || items.length >= 6) {
                    return;
                }

                var label = fields.street + ', ' + fields.suburb + ' ' + fields.state + ' ' + fields.postcode;
                if (seen[label]) {
                    return;
                }
                seen[label] = true;
                items.push({ id: 'photon-' + items.length, label: label, fields: fields });
            });

            return items;
        });
    }

    function fetchSuggestions(term) {
        if (photonUrl) {
            // If Photon cannot be reached from this network, our server asks
            // it instead: slower, but the suggestions still arrive.
            return askPhoton(term).catch(function () {
                return askServer(term);
            });
        }

        return askServer(term);
    }

    function askServer(term) {
        return fetch(suggestUrl + '?q=' + encodeURIComponent(term), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin'
        }).then(function (response) {
            return response.ok ? response.json() : null;
        }).then(function (data) {
            var items = (data && Array.isArray(data.suggestions)) ? data.suggestions : [];

            return items.filter(function (item) {
                return item && typeof item.id === 'string' && typeof item.label === 'string';
            });
        });
    }

    function search() {
        var term = field.value.trim();

        if (term.length < MIN_LENGTH) {
            latest = [];
            lastSearched = '';
            close();

            return;
        }

        // Narrow what is already on screen straight away, so the list answers
        // every keystroke even while the server is still thinking.
        var narrowed = latest.filter(function (item) {
            return fits(item, term);
        });
        if (narrowed.length) {
            render(narrowed);
        } else {
            showSearching();
        }

        if (inFlight) {
            queued = true;

            return;
        }
        if (term === lastSearched) {
            return;
        }

        inFlight = true;
        lastSearched = term;

        fetchSuggestions(term)
            .then(function (items) {
                latest = items;

                if (document.activeElement !== field) {
                    return;
                }

                var current = field.value.trim();
                if (current === term) {
                    render(items);
                } else if (current.length >= MIN_LENGTH) {
                    // More was typed while this was on its way. Show what still
                    // fits; the queued search below brings the rest.
                    var stillFits = items.filter(function (item) {
                        return fits(item, current);
                    });
                    if (stillFits.length) {
                        render(stillFits);
                    }
                }
            })
            .catch(function () {
                // Deliberately quiet: no suggestions, and the field still works.
                if (!queued) {
                    close();
                }
            })
            .then(function () {
                inFlight = false;
                if (queued) {
                    queued = false;
                    search();
                }
            });
    }

    field.addEventListener('input', function () {
        if (applying) {
            return;
        }

        // A pause between searches rather than a wait for typing to stop, so a
        // steady typist gets suggestions while still typing.
        if (timer === null) {
            timer = window.setTimeout(function () {
                timer = null;
                search();
            }, DEBOUNCE_MS);
        }
    });

    field.addEventListener('keydown', function (event) {
        if (list.hidden) {
            // Alt+Down is the standard way to reopen a combobox that has been
            // closed without changing what is in it.
            if (event.key === 'ArrowDown' && event.altKey) {
                event.preventDefault();
                search();
            }

            return;
        }

        if (event.key === 'ArrowDown') {
            event.preventDefault();
            highlight(active + 1 >= suggestions.length ? 0 : active + 1);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            highlight(active - 1 < 0 ? suggestions.length - 1 : active - 1);
        } else if (event.key === 'Enter') {
            if (active >= 0) {
                // Only swallowed when a suggestion is highlighted. With the
                // list open but nothing chosen, Enter still submits the form,
                // which is what a customer who has typed the address in full
                // expects.
                event.preventDefault();
                choose(active);
            }
        } else if (event.key === 'Escape') {
            close();
        } else if (event.key === 'Tab') {
            close();
        }
    });

    field.addEventListener('blur', function () {
        // Delayed so a click on an option is not cancelled by the list
        // vanishing underneath the pointer.
        window.setTimeout(close, 150);
    });
});
