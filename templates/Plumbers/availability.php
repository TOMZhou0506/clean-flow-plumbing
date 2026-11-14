<?php
/**
 * @var \App\View\AppView $this
 * @var \DateTimeImmutable $today
 * @var \DateTimeImmutable $weekStart
 * @var \DateTimeImmutable $weekEnd
 * @var list<\DateTimeImmutable> $weekDays
 * @var list<\App\Model\Entity\PlumberUnavailability> $entries
 * @var array<string, list<\App\Model\Entity\PlumberUnavailability>> $byDate
 * @var array<string, list<object>> $jobsByDate
 * @var list<object> $outsideHours
 * @var string $formDate
 * @var string $defaultStartTime
 * @var string|null $error
 * @var bool $readOnly
 * @var \App\Model\Entity\Admin|null $plumber
 */
$readOnly = $readOnly ?? false;
$this->assign('title', $readOnly ? 'Plumber timetable' : 'My Timetable');
$clock = static fn(int $minute): string => sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60);
if (!$readOnly) {
    $this->Html->script('plumber-availability', ['block' => true]);
}
$baseRoute = ['action' => $readOnly ? 'timetable' : 'availability'];
if ($readOnly) {
    $baseRoute[] = $plumber->admin_id;
}
$weekRoute = static fn(?string $week): array => $week === null
    ? $baseRoute
    : $baseRoute + ['?' => ['week' => $week]];
?>
<?= $this->Html->css(['dashboard', 'plumber-availability']) ?>
<?= $this->element('admin_sidebar') ?>

<div class="dash portal-content">
    <div class="dash__header">
        <h1 class="dash__title"><?= $readOnly
            ? h(__('Timetable: {0}', $plumber->admin_username))
            : __('My Timetable') ?></h1>
        <div class="dash__header-actions">
            <?= $this->Html->link($readOnly ? __('Back to plumbers') : __('My dashboard'), [
                'action' => $readOnly ? 'index' : 'dashboard',
            ], [
                'class' => 'dash__btn dash__btn--ghost',
            ]) ?>
        </div>
    </div>

    <?= $this->Flash->render() ?>
    <?php if ($readOnly) : ?>
        <p><?= __('Read only. Only the plumber can change unavailable time.') ?></p>
    <?php endif; ?>

    <section class="dash__card availability-timetable" aria-labelledby="availability-week-title">
        <div class="availability-toolbar">
            <h2 id="availability-week-title">
                <?= h($weekStart->format('j M')) ?> – <?= h($weekEnd->format('j M Y')) ?>
            </h2>
            <div class="availability-toolbar__actions">
                <?= $this->Html->link(__('Previous week'), $weekRoute(
                    $weekStart->modify('-7 days')->format('Y-m-d'),
                ), ['class' => 'dash__btn dash__btn--small dash__btn--ghost']) ?>
                <?= $this->Html->link(__('This week'), $weekRoute(null), [
                    'class' => 'dash__btn dash__btn--small dash__btn--ghost',
                ]) ?>
                <?= $this->Html->link(__('Next week'), $weekRoute(
                    $weekStart->modify('+7 days')->format('Y-m-d'),
                ), ['class' => 'dash__btn dash__btn--small dash__btn--ghost']) ?>
            </div>
        </div>
        <div class="availability-legend" aria-label="Calendar legend">
            <span><i class="availability-legend__work" aria-hidden="true"></i><?= __('Working hours') ?></span>
            <span><i class="availability-legend__unavailable" aria-hidden="true"></i><?= __('Unavailable') ?></span>
            <span><i class="timetable-legend--assigned" aria-hidden="true"></i><?= __('Assigned') ?></span>
            <span><i class="timetable-legend--in-progress" aria-hidden="true"></i><?= __('In progress') ?></span>
            <span><i class="timetable-legend--completed" aria-hidden="true"></i><?= __('Completed') ?></span>
            <span><i class="timetable-legend--unable" aria-hidden="true"></i><?= __('Unable to complete') ?></span>
            <span><i class="availability-legend__weekend" aria-hidden="true"></i><?= __('Weekend') ?></span>
        </div>

        <div class="availability-scroll" role="region" aria-label="Weekly work timetable" tabindex="0">
            <div class="availability-grid availability-grid--header">
                <div class="availability-grid__corner"><?= __('Time') ?></div>
                <?php foreach ($weekDays as $day) : ?>
                    <?php $isToday = $day->format('Y-m-d') === $today->format('Y-m-d'); ?>
                    <div class="availability-grid__day-heading<?= $isToday ? ' is-today' : '' ?>">
                        <span class="availability-grid__weekday"><?= h($day->format('D')) ?></span>
                        <span class="availability-grid__date"><?= h($day->format('j M')) ?></span>
                        <?php if ($isToday) : ?>
                            <span class="availability-grid__today"><?= __('Today') ?></span>
                        <?php endif; ?>
                        <?php if (!$readOnly && (int)$day->format('N') <= 5 && $day >= $today) : ?>
                            <button type="button" class="availability-date-pick"
                                data-availability-date="<?= h($day->format('Y-m-d')) ?>"
                                aria-label="<?= h(__('Mark {0} unavailable', $day->format('l j F'))) ?>">
                                <?= __('+ Unavailable') ?>
                            </button>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="availability-grid availability-grid--body">
                <div class="availability-time-axis" aria-hidden="true">
                    <?php for ($hour = 8; $hour <= 17; $hour++) : ?>
                        <span style="--start: <?= ($hour - 8) * 60 ?>"><?= sprintf('%02d:00', $hour) ?></span>
                    <?php endfor; ?>
                </div>
                <?php foreach ($weekDays as $day) : ?>
                    <?php
                    $isWeekend = (int)$day->format('N') > 5;
                    $isToday = $day->format('Y-m-d') === $today->format('Y-m-d');
                    $trackClass = 'availability-day-track'
                        . ($isWeekend ? ' availability-day-track--weekend' : '')
                        . ($isToday ? ' is-today' : '');
                    ?>
                    <div class="<?= $trackClass ?>"
                        aria-label="<?= h($day->format('l j F Y')) ?>">
                        <?php foreach ($byDate[$day->format('Y-m-d')] ?? [] as $entry) : ?>
                            <?php
                            $start = (int)$entry->start_minute;
                            $end = (int)$entry->end_minute;
                            ?>
                            <div class="availability-block"
                                style="--start: <?= $start - 480 ?>; --length: <?= $end - $start ?>"
                                title="<?= h($clock($start) . '–' . $clock($end) . ' unavailable') ?>">
                                <strong><?= __('Unavailable') ?></strong>
                                <span><?= h($clock($start)) ?>–<?= h($clock($end)) ?></span>
                            </div>
                        <?php endforeach; ?>
                        <?php foreach ($jobsByDate[$day->format('Y-m-d')] ?? [] as $assignment) : ?>
                            <?php
                            $job = $assignment->job_request;
                            $startLocal = $assignment->scheduled_start->setTimezone($weekStart->getTimezone());
                            $endLocal = $assignment->scheduled_end->setTimezone($weekStart->getTimezone());
                            $start = (int)$startLocal->format('G') * 60 + (int)$startLocal->format('i');
                            $end = (int)$endLocal->format('G') * 60 + (int)$endLocal->format('i');
                            $statusClass = match ($job->status) {
                                'assigned' => 'assigned',
                                'in_progress' => 'in-progress',
                                'completed' => 'completed',
                                'unable_to_complete' => 'unable',
                            };
                            $statusLabel = ucwords(str_replace('_', ' ', $job->status));
                            $timeLabel = $clock($start) . '–' . $clock($end);
    ?>
                            <a class="timetable-job timetable-job--<?= h($statusClass) ?>"
                                href="<?= h($this->Url->build($readOnly
                                    ? ['controller' => 'ManagerJobs', 'action' => 'view', $job->id]
                                    : ['action' => 'job', $job->id])) ?>"
                                style="--start: <?= $start - 480 ?>; --length: <?= $end - $start ?>"
                                aria-label="<?= h(__(
                                    'Job #{0}, {1}, {2}, {3}',
                                    $job->id,
                                    $statusLabel,
                                    $timeLabel,
                                    $job->addressLine(),
                                )) ?>">
                                <strong><?= h(__('Job #{0}', $job->id)) ?></strong>
                                <span><?= h($timeLabel) ?> · <?= h($statusLabel) ?></span>
                                <span class="timetable-job__address"><?= h($job->addressLine()) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <?php if ($outsideHours !== []) : ?>
        <section class="dash__card" aria-labelledby="outside-hours-title">
            <h2 id="outside-hours-title"><?= __('Jobs outside displayed hours') ?></h2>
            <p><?= __('These earlier assignments are outside the standard 08:00–17:00 timetable.') ?></p>
            <ul>
                <?php foreach ($outsideHours as $assignment) : ?>
                    <?php $job = $assignment->job_request; ?>
                    <li>
                        <?= $this->Html->link(__('Job #{0}', $job->id), $readOnly
                            ? ['controller' => 'ManagerJobs', 'action' => 'view', $job->id]
                            : ['action' => 'job', $job->id]) ?> —
                        <?= h($assignment->scheduled_start->setTimezone($weekStart->getTimezone())
                            ->format('D j M, H:i')) ?>,
                        <?= h(ucwords(str_replace('_', ' ', $job->status))) ?>,
                        <?= h($job->addressLine()) ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <div class="availability-panels">
    <?php if (!$readOnly) : ?>
    <section class="dash__card" id="mark-unavailable" aria-labelledby="availability-form-title">
        <h2 id="availability-form-title"><?= __('Mark unavailable time') ?></h2>
        <?php if ($error !== null) : ?>
            <p class="dash__form-error" role="alert"><?= h($error) ?></p>
        <?php endif; ?>
        <?= $this->Form->create(null, ['id' => 'availability-form']) ?>
            <div class="availability-form-row">
            <?= $this->Form->control('unavailable_date', [
                'type' => 'date',
                'label' => __('Date'),
                'required' => true,
                'min' => $today->format('Y-m-d'),
                'value' => $formDate,
                'id' => 'unavailable-date',
            ]) ?>
            <div class="availability-time-fields" id="availability-time-fields">
                <?= $this->Form->control('start_time', [
                    'type' => 'time',
                    'label' => __('From'),
                    'step' => 60,
                    'required' => true,
                    'min' => '08:00',
                    'max' => '17:00',
                    'value' => $this->getRequest()->getData('start_time') ?: $defaultStartTime,
                ]) ?>
                <?= $this->Form->control('end_time', [
                    'type' => 'time',
                    'label' => __('Until'),
                    'step' => 60,
                    'required' => true,
                    'min' => '08:00',
                    'max' => '17:00',
                    'value' => $this->getRequest()->getData('end_time') ?: '17:00',
                ]) ?>
            </div>
            </div>
            <?= $this->Form->control('all_day', [
                'type' => 'checkbox',
                'label' => __('Entire working day (08:00–17:00)'),
                'value' => '1',
                'checked' => (string)$this->getRequest()->getData('all_day') === '1',
                'id' => 'unavailable-all-day',
            ]) ?>
            <?= $this->Form->button(__('Save unavailable time'), ['class' => 'dash__btn']) ?>
        <?= $this->Form->end() ?>
    </section>
    <?php endif; ?>

    <section class="dash__card" aria-labelledby="availability-list-title">
        <h2 id="availability-list-title"><?= __('Unavailable periods this week') ?></h2>
        <?php if ($entries === []) : ?>
            <p><?= __('No unavailable time marked for this week.') ?></p>
        <?php else : ?>
            <ul class="availability-period-list">
                <?php foreach ($entries as $entry) : ?>
                    <?php $canWithdraw = !$readOnly &&
                        $entry->unavailable_date->format('Y-m-d') >= $today->format('Y-m-d'); ?>
                    <li>
                        <span>
                            <?= h($entry->unavailable_date->format('D j M Y')) ?>,
                            <?= h($clock((int)$entry->start_minute)) ?>–<?= h($clock((int)$entry->end_minute)) ?>
                        </span>
                        <?php if ($canWithdraw) : ?>
                            <?= $this->Form->postLink(
                                __('Withdraw'),
                                ['action' => 'cancelUnavailable', $entry->id],
                                [
                                    'confirm' => __('Withdraw this unavailable period?'),
                                    'class' => 'dash__btn dash__btn--small dash__btn--ghost',
                                ],
                            ) ?>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
    </div>
</div>
