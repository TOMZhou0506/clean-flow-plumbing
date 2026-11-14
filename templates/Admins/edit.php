<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Admin $admin
 */
$this->assign('title', 'Change password');
$this->Html->script('password-validation', ['block' => true]);
?>
<?= $this->Html->css('dashboard') ?>

<?= $this->element('admin_sidebar') ?>

<div class="dash portal-content">

    <div class="dash__header">
        <h1 class="dash__title"><?= __('Change password') ?></h1>
    </div>

    <?= $this->Flash->render() ?>

    <div class="dash__card">
        <?= $this->Form->create($admin, ['data-password-form' => true]) ?>
            <?php
                echo $this->Form->control('current_password', [
                    'type' => 'password',
                    'required' => true,
                    'label' => __('Current password'),
                    'value' => '',
                    'autocomplete' => 'current-password',
                ]);
                echo $this->Form->control('admin_password', [
                    'type' => 'password',
                    'required' => true,
                    'label' => __('New password'),
                    'value' => '',
                    'autocomplete' => 'new-password',
                    'data-password-field' => true,
                ]);
                echo $this->Form->control('confirm_password', [
                    'type' => 'password',
                    'required' => true,
                    'label' => __('Confirm new password'),
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
