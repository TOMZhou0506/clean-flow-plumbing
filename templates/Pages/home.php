<?php
/**
 * Name：Jingdong Gao
 * modified date: 19/08/2026
 * Clean Flow Plumbing Services — Landing page
 * Built on CakePHP's default scaffold styling (milligram + cake.css)
 * @var \App\View\AppView $this
 */
use App\Service\Content\ContentCatalogue;

$this->disableAutoLayout();
// The wording on this page comes from the website content editor through
// $this->Content, which escapes everything it returns. Each item's original
// text lives in ContentCatalogue, so the page reads the same until it is edited.
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?= $this->Html->charset() ?>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Clean Flow Plumbing Services</title>
    <meta name="description" content="Licensed plumbing services across Victoria — request a quote online.">
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
    <?php
    // These two pages build their own head rather than using the
    // default layout, so anything an element adds with
    // ['block' => true] needs fetching here or it never loads.
    ?>
    <?= $this->fetch('css') ?>
    <?= $this->fetch('script') ?>
    <?php // On every page, so a tab that is still on the site is never taken for a closed one. ?>
    <?= $this->Html->script('session-guard') ?>
    <?= $this->Html->script('landing', ['defer' => true]) ?>
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
            <a href="#services">Services</a>
            <a href="#how-it-works">How it works</a>
            <a href="#faq">FAQ</a>
            <?= $this->Content->phoneLink() ?>
            <?php
            // One login link, not two. There used to be an "Admin login" here
            // and a separate "Log in" inside the customer menu, and the client
            // could not tell which one they wanted. /login asks which kind of
            // account you have and sends you to the matching dashboard.
            //
            // The customer menu stays, because once somebody is signed in it
            // carries their own links. It no longer offers a second way in.
            ?>
            <?= $this->element('customer_menu') ?>
            <a class="button" href="<?= $this->Url->build('/job-requests/add') ?>">Request service</a>
        </div>
    </nav>

    <main id="main">
        <section class="hero" aria-labelledby="hero-title">
            <div class="hero__inner">
                <div class="hero-text">
                    <p class="hero-eyebrow"><?= $this->Content->text('home.hero.eyebrow') ?></p>
                    <h1 id="hero-title"><?= $this->Content->text('home.hero.heading') ?></h1>
                    <p class="hero-lead"><?= $this->Content->paragraph('home.hero.lead') ?></p>
                    <div class="hero-actions">
                        <a class="button hero-button" href="<?= $this->Url->build('/job-requests/add') ?>">Request service</a>
                        <a class="button hero-button hero-button--ghost" href="<?= $this->Url->build('/contacts/add') ?>">Contact Us</a>
                    </div>
                    <ul class="hero-trust">
                        <li>
                            <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                            <?= $this->Content->text('home.trust.1') ?>
                        </li>
                        <li>
                            <svg aria-hidden="true" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                            <?= $this->Content->text('home.trust.2') ?>
                        </li>
                        <li>
                            <svg aria-hidden="true" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                            <?= $this->Content->text('home.trust.3') ?>
                        </li>
                    </ul>
                </div>
            </div>
            <a class="hero-scroll" href="#services" aria-label="Scroll down to our services">
                <span class="hero-scroll__label">See more</span>
                <svg aria-hidden="true" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
            </a>
        </section>

        <section class="section" id="services" aria-labelledby="services-title">
            <div class="section__inner">
                <p class="section__eyebrow"><?= $this->Content->text('home.services.eyebrow') ?></p>
                <h2 id="services-title" class="section__title"><?= $this->Content->text('home.services.title') ?></h2>
                <p class="section__intro"><?= $this->Content->paragraph('home.services.intro') ?></p>
                <ul class="service-grid">
                    <li class="service-card">
                        <div class="service-card__media">
                            <img src="<?= $this->Url->image('services/pipe-repairs.jpg') ?>" alt="" loading="lazy" width="800" height="600">
                            <span class="service-card__icon"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg></span>
                        </div>
                        <div class="service-card__body">
                            <h3><?= $this->Content->text('service.1.title') ?></h3>
                            <p><?= $this->Content->paragraph('service.1.text') ?></p>
                        </div>
                    </li>
                    <li class="service-card">
                        <div class="service-card__media">
                            <img src="<?= $this->Url->image('services/drainage.jpg') ?>" alt="" loading="lazy" width="800" height="600">
                            <span class="service-card__icon"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M12 2.69l5.66 5.66a8 8 0 1 1-11.31 0z"/></svg></span>
                        </div>
                        <div class="service-card__body">
                            <h3><?= $this->Content->text('service.2.title') ?></h3>
                            <p><?= $this->Content->paragraph('service.2.text') ?></p>
                        </div>
                    </li>
                    <li class="service-card">
                        <div class="service-card__media">
                            <img src="<?= $this->Url->image('services/hot-water.jpg') ?>" alt="" loading="lazy" width="800" height="600">
                            <span class="service-card__icon"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M14 14.76V3.5a2.5 2.5 0 0 0-5 0v11.26a4.5 4.5 0 1 0 5 0z"/></svg></span>
                        </div>
                        <div class="service-card__body">
                            <h3><?= $this->Content->text('service.3.title') ?></h3>
                            <p><?= $this->Content->paragraph('service.3.text') ?></p>
                        </div>
                    </li>
                    <li class="service-card">
                        <div class="service-card__media">
                            <img src="<?= $this->Url->image('services/leak-detection.jpg') ?>" alt="" loading="lazy" width="800" height="600">
                            <span class="service-card__icon"><svg aria-hidden="true" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg></span>
                        </div>
                        <div class="service-card__body">
                            <h3><?= $this->Content->text('service.4.title') ?></h3>
                            <p><?= $this->Content->paragraph('service.4.text') ?></p>
                        </div>
                    </li>
                    <li class="service-card">
                        <div class="service-card__media">
                            <img src="<?= $this->Url->image('services/maintenance.jpg') ?>" alt="" loading="lazy" width="800" height="600">
                            <span class="service-card__icon"><svg aria-hidden="true" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></span>
                        </div>
                        <div class="service-card__body">
                            <h3><?= $this->Content->text('service.5.title') ?></h3>
                            <p><?= $this->Content->paragraph('service.5.text') ?></p>
                        </div>
                    </li>
                </ul>
            </div>
        </section>

        <section class="section section--tint" id="how-it-works" aria-labelledby="how-title">
            <div class="section__inner">
                <p class="section__eyebrow"><?= $this->Content->text('home.how.eyebrow') ?></p>
                <h2 id="how-title" class="section__title"><?= $this->Content->text('home.how.title') ?></h2>
                <ol class="steps-list">
                    <?php for ($step = 1; $step <= 4; $step++) : ?>
                        <li>
                            <span class="steps-list__number"><?= $step ?></span>
                            <strong class="steps-list__title">
                                <?= $this->Content->text("home.how.$step.title") ?>
                            </strong>
                            <span class="steps-list__text">
                                <?= $this->Content->paragraph("home.how.$step.text") ?>
                            </span>
                        </li>
                    <?php endfor; ?>
                </ol>
            </div>
        </section>

        <section class="section" id="faq" aria-labelledby="faq-title">
            <div class="section__inner section__inner--narrow">
                <p class="section__eyebrow"><?= $this->Content->text('home.faq.eyebrow') ?></p>
                <h2 id="faq-title" class="section__title"><?= $this->Content->text('home.faq.title') ?></h2>
                <div class="faq">
                    <?php // Empty slots are left out, so the office can have one to six questions. ?>
                    <?php for ($faq = 1; $faq <= ContentCatalogue::FAQ_SLOTS; $faq++) : ?>
                        <?php if ($this->Content->has("faq.$faq.question")) : ?>
                            <details>
                                <summary><?= $this->Content->text("faq.$faq.question") ?></summary>
                                <p><?= $this->Content->paragraph("faq.$faq.answer") ?></p>
                            </details>
                        <?php endif; ?>
                    <?php endfor; ?>
                </div>
            </div>
        </section>

        <section class="cta-band" aria-labelledby="cta-title">
            <div class="cta-band__inner">
                <h2 id="cta-title"><?= $this->Content->text('home.cta.title') ?></h2>
                <p><?= $this->Content->paragraph('home.cta.text') ?></p>
                <a class="button hero-button" href="<?= $this->Url->build('/job-requests/add') ?>">Request service</a>
            </div>
        </section>
    </main>

    <footer class="site-footer">
        <div class="site-footer__inner">
            <p>&copy; <?= date('Y') ?> Clean Flow Plumbing Services</p>
            <p><a href="#services">Services</a> <a href="#faq">FAQ</a> <a href="<?= $this->Url->build('/contacts/add') ?>">Contact</a> <a href="<?= $this->Url->build('/privacy') ?>">Privacy</a></p>
        </div>
        <?php
        // The short form of the collection notice, on the page where somebody
        // decides whether to hand us anything.
        //
        // It is here rather than only on /privacy because a notice reached by
        // following a link is not a notice given at the point of collection.
        // The full page carries the detail; this says the three things a
        // customer would want to know before they start typing.
        ?>
        <p class="site-footer__privacy">
            When you send an enquiry, create an account or request work, we collect the details
            you give us so we can quote and carry out that work. We share them only with the
            services needed to run this site, never for marketing.
            <?= $this->Html->link(__('How we handle your information'), '/privacy') ?>
        </p>
    </footer>
    <?= $this->element('assistant') ?>
</body>
</html>
