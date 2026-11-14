<?php
/**
 * Step two of forgot password: the code and a new password.
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Admin $admin
 */

$this->assign('title', 'Reset password');
?>
<?= $this->Html->css('dashboard') ?>

<div class="auth-wrapper">
    <div class="auth-brand">
        <a href="<?= $this->Url->build('/') ?>">
            <img src="<?= $this->Url->image('clean-flow-logo.png') ?>" alt="Clean Flow Plumbing" class="auth-logo">
        </a>
    </div>
    <?= $this->Form->create($admin, [
        'url' => ['controller' => 'PasswordResets', 'action' => 'reset'],
        'class' => 'auth-form login__form',
    ]) ?>
    <div class="auth-card">
        <h1 class="auth-title"><?= __('Reset password') ?></h1>

        <?= $this->Flash->render() ?>

        <?= $this->Form->control('email', [
            'type' => 'email',
            'label' => __('Email'),
            'required' => true,
            'maxlength' => 254,
            'autocomplete' => 'email',
            'value' => (string)$this->request->getData('email'),
        ]) ?>
        <?= $this->Form->control('code', [
            'type' => 'text',
            'label' => __('6 digit code'),
            'required' => true,
            'maxlength' => 6,
            'inputmode' => 'numeric',
            'pattern' => '[0-9]{6}',
            'autocomplete' => 'one-time-code',
            'value' => '',
        ]) ?>
        <?= $this->Form->control('admin_password', [
            'type' => 'password',
            'label' => __('New password'),
            'required' => true,
            'autocomplete' => 'new-password',
            'value' => '',
        ]) ?>
        <?= $this->Form->control('confirm_password', [
            'type' => 'password',
            'label' => __('Confirm new password'),
            'required' => true,
            'autocomplete' => 'new-password',
            'value' => '',
        ]) ?>

        <?= $this->Form->button(__('Change password'), ['class' => 'auth-submit']) ?>

        <p class="auth-secondary">
            <?= $this->Html->link(__('Ask for a new code'), ['action' => 'request']) ?>
            <br>
            <?= $this->Html->link(__('Back to sign in'), ['controller' => 'Login', 'action' => 'index']) ?>
        </p>
    </div>
    <?= $this->Form->end() ?>
</div>
