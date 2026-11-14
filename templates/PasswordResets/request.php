<?php
/**
 * Step one of forgot password: ask for a code.
 *
 * Same .auth-wrapper shell as the sign-in page. Only office accounts (admins,
 * plumbers and the manager) are covered, and the page says so.
 *
 * @var \App\View\AppView $this
 */

$this->assign('title', 'Forgot password');
?>
<?= $this->Html->css('dashboard') ?>

<div class="auth-wrapper">
    <div class="auth-brand">
        <a href="<?= $this->Url->build('/') ?>">
            <img src="<?= $this->Url->image('clean-flow-logo.png') ?>" alt="Clean Flow Plumbing" class="auth-logo">
        </a>
    </div>
    <?= $this->Form->create(null, [
        'url' => ['controller' => 'PasswordResets', 'action' => 'request'],
        'class' => 'auth-form login__form',
    ]) ?>
    <div class="auth-card">
        <h1 class="auth-title"><?= __('Forgot password') ?></h1>

        <?= $this->Flash->render() ?>

        <p>
            <?= __('Staff and admin accounts only. Enter your work email and we will send a 6 digit code to that mailbox.') ?>
        </p>

        <?= $this->Form->control('email', [
            'type' => 'email',
            'label' => __('Email'),
            'required' => true,
            'maxlength' => 254,
            'autocomplete' => 'email',
            'autofocus' => true,
        ]) ?>

        <?= $this->Form->button(__('Send code'), ['class' => 'auth-submit']) ?>

        <p class="auth-secondary">
            <?= $this->Html->link(__('I already have a code'), ['action' => 'reset']) ?>
            <br>
            <?= $this->Html->link(__('Back to sign in'), ['controller' => 'Login', 'action' => 'index']) ?>
        </p>
    </div>
    <?= $this->Form->end() ?>
</div>
