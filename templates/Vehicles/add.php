<?php
/** @var \App\View\AppView $this */
/** @var \App\Model\Entity\Vehicle $vehicle */

$this->assign('title', 'Add vehicle');
?>
<?= $this->Html->css('dashboard') ?>
<?= $this->element('admin_sidebar') ?>
<div class="dash portal-content">
    <div class="dash__header">
        <h1 class="dash__title"><?= __('Add vehicle') ?></h1>
        <?= $this->Html->link(__('All vehicles'), ['action' => 'index'], [
            'class' => 'dash__btn dash__btn--ghost',
        ]) ?>
    </div>
    <?= $this->Flash->render() ?>
    <div class="dash__card">
        <?= $this->Form->create($vehicle) ?>
        <?= $this->element('vehicle_fields') ?>
        <?= $this->Form->button(__('Save vehicle'), ['class' => 'dash__btn']) ?>
        <?= $this->Form->end() ?>
    </div>
</div>
