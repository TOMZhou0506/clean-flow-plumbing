<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\JobRequest $job
 * @var list<array{status:string, when:mixed, actor:string}> $history
 */
use App\Model\Table\ContactSubmissionsTable;

$this->assign('title', 'Job #' . $job->id . ' overview');
$tz = ContactSubmissionsTable::businessTimezone();
$assignment = $job->job_assignment;
$displayTime = static fn($value): string => $value === null
    ? 'Not recorded'
    : $value->setTimezone($tz)->format('d M Y, g:ia');
?>
<?= $this->Html->css('dashboard') ?>
<?= $this->element('admin_sidebar') ?>

<div class="dash portal-content">
    <div class="dash__header">
        <h1 class="dash__title"><?= __('Job #{0}', h((string)$job->id)) ?></h1>
        <?= $this->Html->link(__('Back to overview'), ['action' => 'index'], [
            'class' => 'dash__btn dash__btn--ghost',
        ]) ?>
    </div>
    <p><?= __('Read only. An admin manages approvals and assignments; the plumber records the outcome.') ?></p>

    <section class="dash__card" aria-labelledby="manager-job-summary">
        <h2 id="manager-job-summary"><?= __('Job summary') ?></h2>
        <dl class="dash__meta">
            <dt><?= __('Current status') ?></dt>
            <dd><span class="dash__badge dash__badge--<?= h($job->status) ?>">
                <?= h(ucwords(str_replace('_', ' ', $job->status))) ?>
            </span></dd>
            <dt><?= __('Received') ?></dt><dd><?= h($displayTime($job->created)) ?></dd>
            <dt><?= __('Address') ?></dt><dd><?= h($job->addressLine()) ?></dd>
            <dt><?= __('Work requested') ?></dt><dd><?= nl2br(h($job->job_description)) ?></dd>
            <dt><?= __('Customer preferred time') ?></dt>
            <dd><?= h($displayTime($job->preferred_datetime)) ?></dd>
        </dl>
    </section>

    <section class="dash__card" aria-labelledby="manager-assignment">
        <h2 id="manager-assignment"><?= __('Assignment and outcome') ?></h2>
        <?php if ($assignment === null) : ?>
            <p><?= __('This request has not been assigned to a plumber.') ?></p>
        <?php else : ?>
            <dl class="dash__meta">
                <dt><?= __('Assigned by admin') ?></dt>
                <dd><?= h($assignment->assigning_admin?->admin_username
                    ?? __('Account #{0}', $assignment->assigned_by)) ?></dd>
                <dt><?= __('Assigned at') ?></dt><dd><?= h($displayTime($assignment->created)) ?></dd>
                <dt><?= __('Plumber') ?></dt>
                <dd><?= h($assignment->plumber?->admin_username
                    ?? __('Account #{0}', $assignment->plumber_admin_id)) ?></dd>
                <dt><?= __('Vehicle') ?></dt>
                <dd><?= h($assignment->vehicle
                    ? $assignment->vehicle->registration_number . ' — ' . $assignment->vehicle->label
                    : __('Not recorded for this earlier assignment')) ?></dd>
                <dt><?= __('Planned visit') ?></dt>
                <dd><?= h($displayTime($assignment->scheduled_start)) ?> –
                    <?= h($displayTime($assignment->scheduled_end)) ?></dd>
                <dt><?= __('Actually started') ?></dt><dd><?= h($displayTime($assignment->started_at)) ?></dd>
                <dt><?= __('Finished / marked unable') ?></dt><dd><?= h($displayTime($assignment->finished_at)) ?></dd>
                <?php if ($assignment->unable_reason) : ?>
                    <dt><?= __('Unable to complete reason') ?></dt>
                    <dd><?= h($assignment->unable_reason) ?></dd>
                <?php endif; ?>
            </dl>
        <?php endif; ?>
    </section>

    <section class="dash__card" aria-labelledby="manager-job-history">
        <h2 id="manager-job-history"><?= __('Status history') ?></h2>
        <ol>
            <li><?= h($displayTime($job->created)) ?> — <?= __('Submitted by customer') ?></li>
            <?php foreach ($history as $event) : ?>
                <li>
                    <?= h($displayTime($event['when'])) ?> —
                    <?= h(ucwords(str_replace('_', ' ', $event['status']))) ?>
                    (<?= h($event['actor']) ?>)
                </li>
            <?php endforeach; ?>
        </ol>
    </section>
</div>
