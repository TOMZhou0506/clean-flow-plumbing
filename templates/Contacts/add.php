<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\ContactSubmission $contact
 * @var string $turnstileSiteKey
 */
$this->disableAutoLayout();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?= $this->Html->charset() ?>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Contact Us - Clean Flow Plumbing Services</title>
    <meta name="description" content="Get in touch with Clean Flow Plumbing Services — request a quote online.">
    <link rel="icon" type="image/png" href="<?= $this->Url->image('favicon.png') ?>">
    <link rel="apple-touch-icon" href="<?= $this->Url->image('apple-touch-icon.png') ?>">
    <?= $this->Html->css(['normalize.min', 'milligram.min', 'fonts', 'cake', 'dashboard', 'landing', 'contact']) ?>
    <script
        src="https://challenges.cloudflare.com/turnstile/v0/api.js"
        async
        defer>
    </script>
    <?php
    // These two pages build their own head rather than using the
    // default layout, so anything an element adds with
    // ['block' => true] needs fetching here or it never loads.
    ?>
    <?= $this->fetch('css') ?>
    <?= $this->fetch('script') ?>
    <?php // On every page, so a tab that is still on the site is never taken for a closed one. ?>
    <?= $this->Html->script('session-guard') ?>
</head>
<body>
    <a class="skip-link" href="#main">Skip to main content</a>

    <nav class="top-nav" aria-label="Main navigation">
        <div class="top-nav-title">
            <a href="<?= $this->Url->build('/') ?>">
                <img src="<?= $this->Url->image('clean-flow-logo.png') ?>" alt="Clean Flow Plumbing" class="brand-logo">
            </a>
        </div>
        <div class="top-nav-links">
            <a href="<?= $this->Url->build('/') ?>#services">Services</a>
            <a href="<?= $this->Url->build('/') ?>#how-it-works">How it works</a>
            <a href="<?= $this->Url->build('/') ?>#faq">FAQ</a>
            <?= $this->Content->phoneLink() ?>
            <?php
            // Was /admins/login, which is the old office-only page. Everything
            // goes to the shared /login now and it asks which kind of account
            // you have. This page is reached by members of the public, so
            // sending them to a staff login was the wrong door entirely.
            ?>
            <a href="<?= $this->Url->build('/login') ?>">Log in</a>
        </div>
    </nav>

    <main class="main" id="main">
        <div class="container">
            <div class="contacts form content contact-form-container">
                <h1>Contact Us</h1>

                <p><?= $this->Content->paragraph('contact.intro') ?></p>

                <?= $this->Flash->render() ?>

                <?php
                // novalidate + data-validate-all hands validation to
                // form-enhancements.js, which lists every problem at once
                // instead of letting the browser stop at the first one. Keeping
                // it client-side also means a missed field does not cost the
                // visitor a fresh Turnstile challenge. ContactSubmissionsTable
                // still re-checks everything on save.
                ?>
                <?= $this->Form->create($contact, [
                    'novalidate' => true,
                    'data-validate-all' => true,
                ]) ?>

                <?php
                // The maxlength values below match ContactSubmissionsTable::validationDefault
                // exactly. They were 128 and 255 against limits of 100 and 254, so the
                // browser let you type something the server then threw back at you. These
                // attributes are a convenience, not a control: the table validation is
                // what actually enforces the limits.
                ?>
                <?= $this->Form->control('name', [
                    'label' => 'Name',
                    'required' => true,
                    'maxlength' => 100,
                    'placeholder' => 'Enter your name',
                ]) ?>

                <?= $this->Form->control('email', [
                    'label' => 'Email',
                    'type' => 'email',
                    'required' => true,
                    'maxlength' => 254,
                    'placeholder' => 'name@example.com',
                    'data-format-message' => 'Please enter a valid email address.',
                ]) ?>

                <?= $this->Form->control('phone', [
                    'label' => 'Phone (optional)',
                    'type' => 'tel',
                    'required' => false,
                    'maxlength' => 12,
                    'placeholder' => '0412 345 678 or 03 9123 4567',
                    'pattern' => '(?:04[0-9]{2} [0-9]{3} [0-9]{3}|03 [0-9]{4} [0-9]{4})',
                    'autocomplete' => 'tel',
                    'inputmode' => 'numeric',
                    'data-au-phone' => true,
                    'data-format-message' => 'Use a 03 landline (03 9123 4567) or 04 mobile (0412 345 678).',
                ]) ?>
                <small>Enter a 03 landline (03 9123 4567) or 04 mobile (0412 345 678).</small>

                <?= $this->Form->control('subject', [
                    'label' => 'Subject (optional)',
                    'required' => false,
                    'maxlength' => 150,
                    'placeholder' => 'What is this about?',
                ]) ?>

                <?= $this->Form->control('message', [
                    'label' => 'Message',
                    'type' => 'textarea',
                    'required' => true,
                    'rows' => 6,
                    'minlength' => 5,
                    'maxlength' => 500,
                    'data-char-counter' => true,
                    'data-meaningful-text' => true,
                    'data-validity-message' => 'Please enter a message.',
                    'data-short-message' => 'Please enter at least 5 characters.',
                    'data-meaningful-message' => 'Please write a message with letters, '
                        . 'not just repeated characters or a link.',
                    'placeholder' => 'Enter your message',
                ]) ?>

                <div
                    class="cf-turnstile"
                    data-sitekey="<?= h($turnstileSiteKey) ?>"
                    data-theme="auto">
                </div>
                <?= $this->Form->button('Submit', ['class' => 'button']) ?>

                <button type="reset" class="button button-outline">
                    Reset
                </button>

                <?= $this->Form->end() ?>
            </div>
        </div>
    </main>

    <?= $this->Html->script('form-enhancements') ?>

    <footer class="container">
        <p>&copy; <?= date('Y') ?> Clean Flow Plumbing Services</p>
    </footer>
    <?= $this->element('assistant') ?>
</body>
</html>
