<?php
/** @var \App\View\AppView $this */
/** @var \App\Model\Entity\JobRequest $job */
/** @var array<int, string> $plumbers */
/** @var array<int, string> $vehicleOptions */
/** @var string|null $error */
/** @var \Cake\Datasource\EntityInterface|null $assignment */

$assignment ??= null;
$this->assign('title', $assignment ? 'Change allocation' : 'Assign job');
?>
<?= $this->Html->css('dashboard') ?>
<?= $this->element('admin_sidebar') ?>
<div class="dash portal-content">
    <div class="dash__header">
        <h1 class="dash__title"><?= $assignment
            ? __('Change allocation for job #{0}', $job->id)
            : __('Assign job #{0}', $job->id) ?></h1>
        <?= $this->Html->link(__('Back to job'), ['action' => 'manageView', $job->id], [
            'class' => 'dash__btn dash__btn--ghost',
        ]) ?>
    </div>
    <?= $this->Flash->render() ?>
    <?php if ($error) : ?>
        <p class="error-message" role="alert"><?= h($error) ?></p>
    <?php endif; ?>
    <div class="dash__card">
        <p><?= h($job->addressLine()) ?></p>
        <?= $this->element('job_assign_form', compact('job', 'plumbers', 'vehicleOptions', 'assignment')) ?>
    </div>
</div>
