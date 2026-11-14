<?php
/**
 * The website assistant, bottom right of every page.
 *
 * Rendered as a launcher button plus a panel that starts closed, so it is one
 * click to open and one key to leave. US 10.1 asks for both ends of that: easy
 * to reach, easy to get out of.
 *
 * There is no form element here on purpose. A form without JavaScript would
 * submit and navigate away, and the assistant has no page to land on. With the
 * script absent nothing renders at all, which is the correct degradation: the
 * contact form and the job request form are untouched and still the way to
 * reach Clean Flow, exactly as US 10.2 requires.
 *
 * The panel is empty markup. Every message is built by the script with
 * textContent, never innerHTML, so nothing a model returns can become markup.
 *
 * @var \App\View\AppView $this
 */

use App\Service\Assistant\Audience;

// Office accounts do not get the assistant at all.
//
// Checked here rather than in the three templates that include this element,
// so there is one rule rather than three that can drift apart. The endpoint
// refuses them as well: hiding a button is a tidiness measure, not a control,
// and anyone can still post to the URL.
if (!Audience::isAvailableTo($this->getRequest())) {
    return;
}

// Emitted here rather than pushed into the css and script blocks.
//
// This element renders at the end of <body>, and the layout's fetch('css') in
// the <head> has already run by then, so anything added to a block at this
// point goes into a bucket nobody reads again. The result was a widget that
// shipped hidden with no stylesheet and no script to reveal it, which looks
// exactly like the feature not existing.
//
// A stylesheet link late in the body is allowed and costs nothing here: the
// panel starts hidden and is only shown once the script runs, so there is no
// unstyled flash to avoid.
echo $this->Html->css('assistant');
echo $this->Html->script('assistant', ['defer' => true]);
?>
<div class="assistant" data-assistant hidden>
    <button
        type="button"
        class="assistant__launcher"
        data-assistant-launcher
        aria-expanded="false"
        aria-controls="assistant-panel"
    >
        <svg class="assistant__launcher-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path
                d="M4 5.5h16v10H8.5L4 19V5.5z"
                fill="none"
                stroke="currentColor"
                stroke-width="1.8"
                stroke-linejoin="round"
            />
        </svg>
        <span class="assistant__launcher-text"><?= __('Ask a question') ?></span>

        <?php
        // The dot is decorative, so it is hidden from assistive tech and the
        // same news is carried as real text in the span below. A red circle
        // means nothing to a screen reader, and colour alone would mean nothing
        // to someone who cannot distinguish it either.
        //
        // The text span sits inside the button, so whatever it says becomes
        // part of the button's accessible name automatically. No aria-label to
        // keep in sync with it.
        ?>
        <span class="assistant__badge" data-assistant-badge aria-hidden="true" hidden></span>
        <span class="assistant__badge-text" data-assistant-badge-text hidden></span>
    </button>

    <section
        class="assistant__panel"
        id="assistant-panel"
        data-assistant-panel
        role="dialog"
        aria-modal="false"
        aria-labelledby="assistant-heading"
        hidden
    >
        <header class="assistant__header">
            <h2 class="assistant__heading" id="assistant-heading"><?= __('Clean Flow assistant') ?></h2>
            <button
                type="button"
                class="assistant__close"
                data-assistant-close
                aria-label="<?= __('Close the assistant') ?>"
            >&times;</button>
        </header>

        <?php
        // aria-live polite, so a screen reader announces each answer as it
        // arrives without interrupting whatever the person is doing. assertive
        // would talk over them mid-sentence.
        ?>
        <div
            class="assistant__log"
            data-assistant-log
            role="log"
            aria-live="polite"
            aria-atomic="false"
        >
            <p class="assistant__message assistant__message--bot">
                <?= __(
                    'Hello. I can help with questions about Clean Flow, our services and how '
                    . 'to send us a job request. I cannot submit anything for you.',
                ) ?>
            </p>
        </div>

        <div class="assistant__composer">
            <label class="assistant__label" for="assistant-question">
                <?= __('Your question') ?>
            </label>
            <textarea
                class="assistant__input"
                id="assistant-question"
                data-assistant-input
                rows="2"
                maxlength="500"
                placeholder="<?= __('How do I send a job request?') ?>"
            ></textarea>
            <button type="button" class="assistant__send" data-assistant-send>
                <?= __('Send') ?>
            </button>
        </div>

        <p class="assistant__footnote">
            <?= __('Answers come from Clean Flow\'s own information. Please check anything important with our team.') ?>
        </p>
    </section>
</div>
