<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Admin $admin
 */
$this->assign('title', 'New admin');
$this->Html->script('password-validation', ['block' => true]);
?>
<?= $this->Html->css('dashboard') ?>

<?= $this->element('admin_sidebar') ?>

<div class="dash portal-content">

    <div class="dash__header">
        <h1 class="dash__title"><?= __('New admin') ?></h1>
        <div class="dash__header-actions">
            <?= $this->Html->link(__('All admins'), ['action' => 'index'], ['class' => 'dash__btn dash__btn--ghost']) ?>
        </div>
    </div>

    <?= $this->Flash->render() ?>

    <div class="dash__card">
        <?= $this->Form->create($admin, ['data-password-form' => true]) ?>
            <?php
                echo $this->Form->control('admin_email', [
                    'type' => 'email',
                    'label' => __('Email (they sign in with this)'),
                    'placeholder' => 'name@' . \App\Model\Table\AdminsTable::officeDomain(),
                    'required' => true,
                    'maxlength' => 254,
                    'autocomplete' => 'off',
                ]);
                echo $this->Form->control('admin_username', ['label' => __('Username (shown in lists and greetings, not used to sign in)')]);
                // Type password, so it is not shown on screen as it is typed
                // and browsers offer to store it properly. The value is
                // hashed by Admin::_setAdminPassword() on save.
                echo $this->Form->control('admin_password', [
                    'type' => 'password',
                    'required' => false,
                    'label' => __('Password'),
                    'value' => '',
                    'autocomplete' => 'new-password',
                    'data-password-field' => true,
                ]);
                echo $this->Form->control('confirm_password', [
                    'type' => 'password',
                    'required' => false,
                    'label' => __('Confirm password'),
                    'autocomplete' => 'new-password',
                    'data-confirm-password' => true,
                ]);
                ?>
            <div class="password-guidance" aria-label="Password requirements">
                <p>Password must include:</p>
                <ul>
                    <li>At least one uppercase letter</li>
                    <li>At least one number</li>
                    <li>At least one special character</li>
                </ul>
            </div>
            <?= $this->Form->button(__('Submit'), ['class' => 'dash__btn']) ?>
        <?= $this->Form->end() ?>
    </div>
</div>
