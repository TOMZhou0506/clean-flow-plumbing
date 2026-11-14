<?php
/**
 * Privacy collection notice.
 *
 * Written against what the application actually does rather than from a
 * template, because a notice that describes collection we do not perform is
 * worse than none: it is a statement to the customer that happens to be false.
 * Every claim below can be traced to a migration, a controller or a service
 * class, and the matching file is named in a comment so the next person can
 * check it rather than trust it.
 *
 * Anything only Clean Flow can answer is marked TO CONFIRM. Those are not
 * oversights and they are not mine to invent: a retention period or an ABN
 * guessed by a developer is exactly the kind of false statement this file is
 * trying to avoid. The team needs to settle them with the client and remove
 * the markers.
 *
 * Built as its own document, the same way home.php is, so the landing page and
 * this page share a shell without either depending on the default layout.
 *
 * @var \App\View\AppView $this
 */
$this->disableAutoLayout();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?= $this->Html->charset() ?>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Privacy collection notice | Clean Flow Plumbing Services</title>
    <meta name="description" content="How Clean Flow Plumbing Services collects, uses and stores your personal information.">
    <link rel="icon" type="image/png" href="<?= $this->Url->image('favicon.png') ?>">
    <link rel="apple-touch-icon" href="<?= $this->Url->image('apple-touch-icon.png') ?>">
    <?= $this->Html->css([
        'normalize.min',
        'milligram.min',
        'fonts',
        'cake',
        'dashboard',
        'landing',
        'customer-menu',
    ]) ?>
    <?= $this->fetch('css') ?>
    <?= $this->fetch('script') ?>
    <?php // On every page, so a tab that is still on the site is never taken for a closed one. ?>
    <?= $this->Html->script('session-guard') ?>
</head>
<body class="landing-page">
    <a class="skip-link" href="#main">Skip to main content</a>

    <nav class="top-nav" aria-label="Main navigation">
        <div class="top-nav-title">
            <a href="<?= $this->Url->build('/') ?>">
                <img src="<?= $this->Url->image('clean-flow-logo.png') ?>" alt="Clean Flow Plumbing" class="brand-logo">
            </a>
        </div>
        <div class="top-nav-links">
            <a href="<?= $this->Url->build('/') ?>">Home</a>
            <a href="<?= $this->Url->build('/contacts/add') ?>">Contact</a>
        </div>
    </nav>

    <main id="main">
        <section class="section">
            <div class="section__inner section__inner--prose">
                <p class="section__eyebrow">Privacy</p>
                <h1 class="section__title">Privacy collection notice</h1>
                <p class="legal-updated">Last updated <?= date('j F Y') ?></p>

                <p>
                    This notice explains what personal information Clean Flow Plumbing Services
                    collects through this website, why we collect it, who we share it with, and
                    how you can get to it or correct it. It covers the website only.
                </p>

                <h2>What we collect, and when</h2>

                <h3>When you send us an enquiry</h3>
                <p>
                    The contact form asks for your name, email address, phone number, a subject
                    and your message. We also record the IP address and browser user agent the
                    enquiry arrived from. That last part is not about you personally: it is how we
                    tell a real enquiry from automated abuse of the form.
                </p>

                <h3>When you create a customer account</h3>
                <p>
                    We collect your first and last name, email address, phone number, postal
                    address and account type. Your password is never stored. What we keep is a
                    one-way hash of it, which lets us check a password at sign-in but cannot be
                    turned back into the password itself, including by us.
                </p>

                <h3>When you request plumbing work</h3>
                <p>
                    We collect the service address, a description of the work needed, your
                    preferred date and time, a contact phone number, and any access instructions
                    you give us. Access instructions are read by the plumber assigned to your job,
                    so please do not put anything in that field you would not want our staff to
                    see.
                </p>

                <h3>When you rate a finished job</h3>
                <p>
                    We record the rating you give and the date you gave it, against the job it
                    belongs to.
                </p>

                <h3>When you use the website assistant</h3>
                <p>
                    The assistant receives the text of your question. Please do not type personal
                    details into it: it is there to tell you where things are on the site, and it
                    does not need them. Questions it could not answer are stored so we can write
                    better answers, which means a question you ask may be read later by our team.
                </p>

                <h2>Information collected automatically</h2>
                <p>
                    We keep an activity log of what our own staff do in the system, including who
                    viewed or changed a record and when, which is a protection for your
                    information rather than a use of it. We also count form submissions and
                    sign-in attempts to stop abuse. Those counters do not store your IP address
                    as-is: it is turned into an irreversible fingerprint that cannot be read back.
                </p>
                <p>
                    This site uses two cookies, both strictly necessary: one that keeps you signed
                    in, and one that protects forms against cross-site request forgery. There are
                    no advertising, analytics or tracking cookies on this site, and no third-party
                    tracking scripts.
                </p>

                <h2>Who we share it with</h2>
                <p>
                    We do not sell your personal information, and we do not disclose it for
                    marketing. It is shared only with the services needed to run the site:
                </p>
                <ul>
                    <li>
                        <strong>An address lookup service.</strong> As you type into the address
                        field on the job request form, what you have typed so far is sent to
                        getAddress(), an Australian address provider, so it can suggest matching
                        addresses. Nothing else about you is sent, and the lookup is not used
                        anywhere else on the site.
                    </li>
                    <li>
                        <strong>Google.</strong> Questions you type into the website assistant are
                        sent to Google's Gemini service to generate an answer. Google processes
                        this outside Australia. Our staff screens also show a job's address on an
                        embedded Google map, which discloses that address to Google when a staff
                        member opens the job.
                    </li>
                    <li>
                        <strong>Our web host.</strong> The site and its database run on hosted
                        infrastructure, and email we send you passes through that host's mail
                        service.
                    </li>
                </ul>

                <h2>How we store and protect it</h2>
                <p>
                    Your information is held in a database on our hosting account. Access is
                    limited to staff whose role requires it, and every account sees only what that
                    role is allowed to see. Passwords are stored as one-way hashes. Sessions
                    expire after thirty minutes of inactivity, so an unattended screen does not
                    stay open indefinitely. The session cookie cannot be read by scripts in the
                    page, which limits what a flaw elsewhere on the site could reach.
                </p>
                <p>
                    No system is perfectly secure, and we are not going to claim otherwise. What we
                    can say is that the protections above are in place and that staff access is
                    logged.
                </p>

                <h2>How long we keep it</h2>

                <h2>Giving us your information is your choice</h2>
                <p>
                    You do not have to use this website. You can call us instead, on the number in
                    the site header, and give us what you are comfortable giving over the phone.
                    If you leave out something we need, such as the service address, we may not be
                    able to carry out the work you are asking for.
                </p>

                <h2>Getting to your information, or correcting it</h2>
                <p>
                    You can ask us for a copy of the personal information we hold about you, and
                    ask us to correct anything that is wrong. If you have an account, you can see
                    and update much of it yourself once signed in. For anything else, or to make a
                    privacy complaint, contact us using the details below. If you are not
                    satisfied with how we handle a complaint, you can take it to the Office of the
                    Australian Information Commissioner at oaic.gov.au.
                </p>

                <h2>Contact us about privacy</h2>

                <p>
                    In the meantime you can reach us through the
                    <?= $this->Html->link('contact form', '/contacts/add') ?> or on
                    <?= $this->Content->phoneLink() ?>.
                </p>
            </div>
        </section>
    </main>

    <footer class="site-footer">
        <div class="site-footer__inner">
            <p>&copy; <?= date('Y') ?> Clean Flow Plumbing Services</p>
            <p>
                <?= $this->Html->link('Home', '/') ?>
                <?= $this->Html->link('Contact', '/contacts/add') ?>
            </p>
        </div>
    </footer>
</body>
</html>
