<?php
/**
 * One job in a plumber's dashboard list: a date block, the address and visit
 * time, and where it stands.
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\JobAssignment $assignment
 * @var \DateTimeZone $timezone
 * @var bool $highlight Draw attention to it (a job waiting for approval).
 */

$job = $assignment->job_request;
$start = $assignment->scheduled_start->setTimezone($timezone);
$end = $assignment->scheduled_end->setTimezone($timezone);
$highlight = $highlight ?? false;
?>
<li class="job-row<?= $highlight ? ' job-row--attention' : '' ?>">
    <div class="job-row__date" aria-hidden="true">
        <span class="job-row__day"><?= h($start->format('j')) ?></span>
        <span class="job-row__month"><?= h($start->format('M')) ?></span>
    </div>
    <div class="job-row__main">
        <p class="job-row__address"><?= h($job->addressLine()) ?></p>
        <p class="job-row__meta">
            <?= h($start->format('D')) ?>,
            <?= h($start->format('g:ia')) ?>–<?= h($end->format('g:ia')) ?>
            <?php if ($assignment->vehicle) : ?>
                &middot; <?= h($assignment->vehicle->registration_number) ?>
            <?php endif; ?>
        </p>
        <span class="dash__badge job-row__status dash__badge--<?= h($job->status) ?>">
            <?= h($job->plumberStatusLabel()) ?>
        </span>
    </div>
    <?= $this->Html->link(
        $highlight ? __('Review') : __('Open'),
        ['controller' => 'Plumbers', 'action' => 'job', $job->id],
        ['class' => 'dash__btn dash__btn--small' . ($highlight ? ' dash__btn--proceed-small' : '')],
    ) ?>
</li>
