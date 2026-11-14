<?php
/**
 * The single sign-in page.
 *
 * One form for all four roles (customer, plumber, admin, manager): an email
 * and a password, and nothing that asks which kind of account it is. The
 * account decides where the person lands.
 *
 * Same .auth-wrapper shell as Customers/register.php - centered logo, no top
 * nav (the shared layout's nav hides itself whenever .auth-wrapper is on the
 * page, see dashboard.css), one card. Matching that page rather than the
 * bare admin dashboard shell, since anyone reaching this page has not signed
 * in yet.
 *
 * @var \App\View\AppView $this
 */

$this->assign('title', 'Sign in');
?>
<?= $this->Html->css('dashboard') ?>

<div class="auth-wrapper">
    <div class="auth-brand">
        <a href="<?= $this->Url->build('/') ?>">
            <img src="<?= $this->Url->image('clean-flow-logo.png') ?>" alt="Clean Flow Plumbing" class="auth-logo">
        </a>
    </div>
    <?php
    // data-session-start: signing in counts as being on the site, so the
    // closed-site check in session-guard.js does not sign this visit straight out.
    ?>
    <?= $this->Form->create(null, [
        'url' => ['controller' => 'Login', 'action' => 'index'],
        'class' => 'auth-form login__form',
        'data-session-start' => true,
    ]) ?>
    <div class="auth-card">
        <h1 class="auth-title"><?= __('Sign in') ?></h1>

        <?= $this->Flash->render() ?>

        <?php
        // One address for every kind of account. Customers and office accounts all
        // sign in with their email, so there is a single label.
        ?>
        <?= $this->Form->control('email', [
            'type' => 'email',
            'label' => __('Email'),
            'required' => true,
            'maxlength' => 254,
            'autocomplete' => 'email',
            'autofocus' => true,
        ]) ?>

        <div class="input password">
            <?= $this->Form->label('password', __('Password'), ['for' => 'login-password']) ?>
            <div class="auth-password-wrap">
                <?= $this->Form->password('password', [
                    'id' => 'login-password',
                    // Never send a typed password back to the browser, even after a failed attempt.
                    'value' => '',
                    'required' => true,
                    'autocomplete' => 'current-password',
                ]) ?>
                <button
                    type="button"
                    class="auth-password-toggle"
                    data-toggle-password="login-password"
                    aria-pressed="false"
                    aria-label="<?= __('Show password') ?>"
                >
                    <?= __('Show') ?>
                </button>
            </div>
        </div>

        <?= $this->Form->button(__('Sign in'), ['class' => 'auth-submit']) ?>

        <p class="auth-secondary">
            <?= $this->Html->link(__('Forgot password?'), ['controller' => 'PasswordResets', 'action' => 'request']) ?>
            <?= __('(staff and admin accounts only)') ?>
        </p>

        <p class="auth-secondary">
            <?= __('New customer?') ?>
            <?= $this->Html->link(__('Create an account'), [
                'controller' => 'Customers',
                'action' => 'register',
            ]) ?>
        </p>
    </div>
    <?= $this->Form->end() ?>
</div>

<?= $this->Html->script('password-toggle') ?>
