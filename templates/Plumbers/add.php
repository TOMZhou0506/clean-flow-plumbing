<?php
use App\Model\Table\AdminsTable;

/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Admin $plumber
 */
$this->assign('title', 'New plumber');
$this->Html->script('password-validation', ['block' => true]);
?>
<?= $this->Html->css('dashboard') ?>
<?= $this->element('admin_sidebar') ?>

<div class="dash portal-content">
    <div class="dash__header">
        <h1 class="dash__title"><?= __('New plumber') ?></h1>
        <div class="dash__header-actions">
            <?= $this->Html->link(__('All plumbers'), ['action' => 'index'], [
                'class' => 'dash__btn dash__btn--ghost',
            ]) ?>
        </div>
    </div>

    <?= $this->Flash->render() ?>

    <div class="dash__card">
        <p><?= __(
            'Enter the email address the plumber will sign in with, a username to show in lists, '
            . 'and a temporary password to give them securely.',
        ) ?></p>
        <?= $this->Form->create($plumber, ['data-password-form' => true]) ?>
            <?= $this->Form->control('admin_email', [
                'type' => 'email',
                'label' => __('Email (they sign in with this)'),
                'placeholder' => 'name@' . AdminsTable::officeDomain(),
                'required' => true,
                'maxlength' => 254,
                'autocomplete' => 'off',
            ]) ?>
            <?= $this->Form->control('admin_username', [
                'label' => __('Username (shown in lists and greetings, not used to sign in)'),
                'minlength' => 3,
                'maxlength' => 32,
                'pattern' => '[A-Za-z0-9_-]+',
                'title' => __('Use 3–32 English letters, numbers, underscores, or hyphens.'),
                'required' => true,
            ]) ?>
            <small><?= __(
                'Use 3–32 English letters, numbers, underscores, or hyphens. '
                . 'Admin, manager, and root are reserved.',
            ) ?></small>
            <?= $this->Form->control('admin_password', [
                'type' => 'password',
                'label' => __('Temporary password'),
                'value' => '',
                'autocomplete' => 'new-password',
                'data-password-field' => true,
            ]) ?>
            <?= $this->Form->control('confirm_password', [
                'type' => 'password',
                'label' => __('Confirm temporary password'),
                'value' => '',
                'autocomplete' => 'new-password',
                'data-confirm-password' => true,
            ]) ?>
            <div class="password-guidance" aria-label="Password requirements">
                <p>Password must include:</p>
                <ul>
                    <li>At least one uppercase letter</li>
                    <li>At least one number</li>
                    <li>At least one special character</li>
                </ul>
            </div>
            <?= $this->Form->button(__('Create plumber'), ['class' => 'dash__btn']) ?>
        <?= $this->Form->end() ?>
    </div>
</div>
