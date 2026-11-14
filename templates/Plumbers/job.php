<?php
/** @var \App\View\AppView $this */
use App\Model\Entity\JobRequest;
use App\Model\Table\ContactSubmissionsTable;

$this->assign('title', 'Assigned job');
$tz = ContactSubmissionsTable::businessTimezone();
$job = $assignment->job_request;
?>
<?= $this->Html->css(['dashboard', 'plumber-job']) ?>
<?= $this->element('admin_sidebar') ?>
<div class="dash portal-content">
    <div class="dash__header">
        <h1 class="dash__title"><?= __('Job #{0}', $job->id) ?></h1>
        <?= $this->Html->link(__('My jobs'), ['action' => 'jobs'], ['class' => 'dash__btn dash__btn--ghost']) ?>
    </div>
    <?= $this->Flash->render() ?>
    <?php if ($job->status === JobRequest::STATUS_ASSIGNED) : ?>
        <div class="callout callout--action" role="status">
            <strong><?= __('This job is waiting for your approval.') ?></strong>
            <?= __('Check the visit time and address below, then approve it. You can mark it completed once the work is done.') ?>
        </div>
    <?php endif; ?>
    <div class="job-layout">
    <div class="dash__card">
        <div class="dash__card-head">
            <h2 class="dash__card-title"><?= __('Visit details') ?></h2>
        </div>
        <dl class="dash__meta">
            <dt><?= __('Status') ?></dt><dd><?= h($job->plumberStatusLabel()) ?></dd>
            <dt><?= __('Visit') ?></dt>
            <dd>
                <?= h($assignment->scheduled_start->setTimezone($tz)->format('d M Y, g:ia')) ?>–<?=
                    h($assignment->scheduled_end->setTimezone($tz)->format('g:ia'))
                ?>
            </dd>
            <dt><?= __('Vehicle') ?></dt>
            <dd><?= h($assignment->vehicle
                ? $assignment->vehicle->registration_number . ' — ' . $assignment->vehicle->label
                : __('Not recorded for this earlier assignment')) ?></dd>
            <dt><?= __('Address') ?></dt><dd><?= h($job->addressLine()) ?></dd>
            <dt><?= __('Customer phone') ?></dt><dd><?= h($job->contact_phone) ?></dd>
            <dt><?= __('Job description') ?></dt><dd><?= nl2br(h($job->job_description)) ?></dd>
            <dt><?= __('Access instructions') ?></dt>
            <dd><?= nl2br(h($job->access_instructions ?: __('None supplied'))) ?></dd>
            <?php if ($assignment->unable_reason) : ?>
                <dt><?= __('Unable to complete reason') ?></dt><dd><?= h($assignment->unable_reason) ?></dd>
            <?php endif; ?>
        </dl>
        <?php if ($job->status === JobRequest::STATUS_ASSIGNED) : ?>
            <?= $this->Form->postLink(__('Approve job'), ['action' => 'startJob', $job->id], ['class' => 'dash__btn dash__btn--proceed']) ?>
        <?php elseif ($job->status === JobRequest::STATUS_IN_PROGRESS) : ?>
            <?= $this->Form->create(null, ['url' => ['action' => 'finishJob', $job->id]]) ?>
            <?= $this->Form->hidden('outcome', ['value' => JobRequest::STATUS_COMPLETED]) ?>
            <?= $this->Form->button(__('Mark completed'), ['class' => 'dash__btn']) ?>
            <?= $this->Form->end() ?>
            <?= $this->Form->create(null, ['url' => ['action' => 'finishJob', $job->id]]) ?>
            <?= $this->Form->hidden('outcome', ['value' => JobRequest::STATUS_UNABLE_TO_COMPLETE]) ?>
            <?= $this->Form->control('unable_reason', [
                'label' => __('Why could the job not be completed?'), 'type' => 'textarea',
                'required' => true, 'minlength' => 10, 'maxlength' => 500,
            ]) ?>
            <?= $this->Form->button(__('Mark unable to complete'), ['class' => 'dash__btn']) ?>
            <?= $this->Form->end() ?>
        <?php endif; ?>
    </div>
    <?= $this->element('job_map', ['address' => $job->addressLine()]) ?>
    </div>
</div>
