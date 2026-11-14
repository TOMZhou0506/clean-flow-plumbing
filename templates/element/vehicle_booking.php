<?php
/**
 * One booking of a vehicle: which job, which plumber, and between what times.
 *
 * Used on the fleet list and on a vehicle's own page, so the three facts always
 * read the same way. Times are shown in the business timezone. A visit that
 * runs past midnight shows both dates, and a job the plumber has started also
 * shows when they actually started.
 *
 * @var \App\View\AppView $this
 * @var \Cake\Datasource\EntityInterface $assignment The booking, with its job request and plumber.
 * @var \DateTimeZone $timezone The business timezone.
 */

$start = $assignment->scheduled_start->setTimezone($timezone);
$end = $assignment->scheduled_end->setTimezone($timezone);
$window = $start->format('Y-m-d') === $end->format('Y-m-d')
    ? $start->format('D j M, g:ia') . '–' . $end->format('g:ia')
    : $start->format('D j M, g:ia') . ' – ' . $end->format('D j M, g:ia');
$job = $assignment->job_request;
?>
<span class="vehicle-booking">
    <span class="vehicle-booking__who">
        <?php if ($job !== null) : ?>
            <?= $this->Html->link($job->reference(), [
                'controller' => 'JobRequests',
                'action' => 'manageView',
                $job->id,
            ]) ?> &middot;
        <?php endif; ?>
        <?= h($assignment->plumber->admin_username ?? __('Unknown plumber')) ?>
    </span>
    <span class="vehicle-booking__when"><?= h($window) ?></span>
    <?php if ($assignment->started_at !== null && $assignment->finished_at === null) : ?>
        <span class="vehicle-booking__when">
            <?= __('Started {0}', h($assignment->started_at->setTimezone($timezone)->format('D j M, g:ia'))) ?>
        </span>
    <?php endif; ?>
</span>
