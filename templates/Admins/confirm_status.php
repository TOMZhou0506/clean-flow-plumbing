<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Admin $admin
 * @var string $actionLabel
 * @var bool $active
 * @var string|null $passwordError
 */
$this->assign('title', $actionLabel . ' admin');
?>
<?= $this->Html->css('dashboard') ?>

<?= $this->element('admin_sidebar') ?>

<div class="dash portal-content">
    <div class="dash__header">
        <h1 class="dash__title"><?= h($actionLabel) ?> <?= h($admin->admin_username) ?></h1>
        <div class="dash__header-actions">
            <?= $this->Html->link(__('Cancel'), ['action' => 'index'], ['class' => 'dash__btn dash__btn--ghost']) ?>
        </div>
    </div>

    <div class="dash__card">
        <p>
            <?= $active
                ? __('This will restore access for admin #{0}.', $admin->admin_id)
                : __('This will immediately remove access for admin #{0}.', $admin->admin_id) ?>
        </p>
        <p><?= __('Enter your own manager password to confirm this change.') ?></p>

        <?= $this->Form->create(null, [
            'type' => 'post',
            'url' => ['action' => $active ? 'reactivate' : 'deactivate', $admin->admin_id],
            'autocomplete' => 'off',
        ]) ?>
            <?= $this->Form->control('manager_password', [
                'type' => 'password',
                'label' => __('Your password'),
                'required' => true,
                'autocomplete' => 'current-password',
                'value' => '',
                'aria-invalid' => $passwordError === null ? 'false' : 'true',
                'aria-describedby' => $passwordError === null ? null : 'manager-password-error',
            ]) ?>
            <?php if ($passwordError !== null) : ?>
                <p id="manager-password-error" class="dash__form-error" role="alert">
                    <?= h($passwordError) ?>
                </p>
            <?php endif; ?>
            <?= $this->Form->button($actionLabel . ' admin', ['class' => 'dash__btn']) ?>
        <?= $this->Form->end() ?>
    </div>
</div>
