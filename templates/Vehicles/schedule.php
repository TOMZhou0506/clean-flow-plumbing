<?php
/**
 * Vehicle availability: which vehicle is out on which job, by day or by week.
 *
 * Shares its look with the plumber team schedule (team-schedule.css).
 *
 * @var \App\View\AppView $this
 * @var list<\App\Model\Entity\Vehicle> $vehicles Active vehicles only.
 * @var int $inactiveCount
 * @var string $view day or week
 * @var \DateTimeImmutable $date
 * @var list<\DateTimeImmutable> $days
 * @var \DateTimeImmutable $today
 * @var \DateTimeImmutable $now
 * @var \DateTimeZone $timezone
 * @var array<int, array<string, list<array{assignment: object, start: int, end: int}>>> $bookings
 * @var array<int, array{current: ?object, upcoming: list<object>, state: string}> $status
 * @var array{in_use: int, available: int, off_hours: int, bookings: int} $summary
 * @var bool $jumpedFromToday No date was chosen and today had nothing booked.
 */
use App\Model\Table\PlumberUnavailabilitiesTable;

$this->assign('title', 'Vehicle availability');
$this->Html->script('team-schedule', ['block' => true]);

$clock = static fn(int $minute): string => sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60);
$dayStart = PlumberUnavailabilitiesTable::WORK_START_MINUTE;
$dayLength = PlumberUnavailabilitiesTable::WORK_END_MINUTE - $dayStart;
// Position on the 08:00–17:00 axis as a percentage, clamped to the axis.
$percent = static fn(int $minute): float => round(
    max(0, min($dayLength, $minute - $dayStart)) / $dayLength * 100,
    3,
);
$statusClass = static fn(string $status): string => match ($status) {
    'in_progress' => 'in-progress',
    'completed' => 'completed',
    'unable_to_complete' => 'unable',
    default => 'assigned',
};
$statusLabel = static fn(string $status): string => match ($status) {
    'in_progress' => __('In progress'),
    'completed' => __('Completed'),
    'unable_to_complete' => __('Unable to complete'),
    default => __('Assigned'),
};
$stateLabels = [
    'in_use' => __('Out on a job'),
    'available' => __('Available'),
    'off_hours' => __('Off hours'),
];
$plumberName = static fn(object $assignment): string => (string)($assignment->plumber->admin_username
    ?? __('Unknown plumber'));
$jobUrl = static fn(int $id): array => ['controller' => 'JobRequests', 'action' => 'manageView', $id];

$step = $view === 'week' ? '7 days' : '1 day';
$prev = $date->modify('-' . $step);
$next = $date->modify('+' . $step);
// Skip weekends when stepping day by day; no visits are booked then.
if ($view === 'day') {
    while ((int)$prev->format('N') > 5) {
        $prev = $prev->modify('-1 day');
    }
    while ((int)$next->format('N') > 5) {
        $next = $next->modify('+1 day');
    }
}
$link = static fn(string $view, DateTimeImmutable $date): array => [
    'action' => 'schedule',
    '?' => ['view' => $view, 'date' => $date->format('Y-m-d')],
];
$isToday = static fn(DateTimeImmutable $day): bool => $day->format('Y-m-d') === $today->format('Y-m-d');
$minuteNow = (int)$now->format('G') * 60 + (int)$now->format('i');
$showNowLine = $view === 'day' && $isToday($date)
    && $minuteNow >= $dayStart && $minuteNow <= $dayStart + $dayLength;
// From the week view, "Day" opens today if it is in that week, else its Monday.
$dayTarget = $date;
if ($view === 'week') {
    $dayTarget = $days[0];
    foreach ($days as $day) {
        if ($isToday($day)) {
            $dayTarget = $day;
        }
    }
}
$rangeLabel = $view === 'week'
    ? $days[0]->format('j M') . ' – ' . end($days)->format('j M Y')
    : $date->format('l j F Y');
$van = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 7h11v9H3zM14 10h4l3 3v3h-7z"/>'
    . '<circle cx="7" cy="17.5" r="1.8"/><circle cx="17" cy="17.5" r="1.8"/></svg>';
$rowHeading = static fn($vehicle, array $row): array => [
    'vehicle' => $vehicle,
    'row' => $row,
    'stateLabels' => $stateLabels,
    'van' => $van,
    'timezone' => $timezone,
];
?>
<?= $this->Html->css(['dashboard', 'team-schedule']) ?>
<?= $this->element('admin_sidebar') ?>

<div class="dash portal-content team">
    <div class="dash__header">
        <div>
            <h1 class="dash__title"><?= __('Vehicle availability') ?></h1>
            <p class="team__subtitle">
                <?= __('A vehicle is free again as soon as its plumber marks the job completed.') ?>
                <span class="team__clock" data-team-clock><?= __('Updated {0}', h($now->format('g:ia'))) ?></span>
            </p>
        </div>
        <div class="dash__header-actions">
            <?= $this->Html->link(__('All vehicles'), ['action' => 'index'], [
                'class' => 'dash__btn dash__btn--ghost',
            ]) ?>
        </div>
    </div>
    <?= $this->Flash->render() ?>

    <ul class="dash__stats team__stats" aria-label="<?= h(__('Fleet right now')) ?>">
        <li class="dash__stat team__stat team__stat--on_job">
            <span class="dash__stat-value"><?= $summary['in_use'] ?></span>
            <span class="dash__stat-label"><?= __('Out on a job now') ?></span>
        </li>
        <li class="dash__stat team__stat team__stat--available">
            <span class="dash__stat-value"><?= $summary['available'] ?></span>
            <span class="dash__stat-label"><?= __('Available now') ?></span>
        </li>
        <li class="dash__stat team__stat team__stat--unavailable">
            <span class="dash__stat-value"><?= $inactiveCount ?></span>
            <span class="dash__stat-label"><?= __('Out of service') ?></span>
        </li>
        <li class="dash__stat">
            <span class="dash__stat-value"><?= $summary['bookings'] ?></span>
            <span class="dash__stat-label">
                <?= $view === 'week' ? __('Bookings this week') : __('Bookings on this day') ?>
            </span>
        </li>
    </ul>

    <section class="dash__card team-board" aria-labelledby="fleet-board-title">
        <div class="team-board__toolbar">
            <h2 id="fleet-board-title" class="team-board__range"><?= h($rangeLabel) ?></h2>
            <div class="team-board__controls">
                <div class="team-toggle" role="group" aria-label="<?= h(__('Calendar view')) ?>">
                    <?= $this->Html->link(__('Day'), $link('day', $dayTarget), [
                        'class' => 'team-toggle__btn' . ($view === 'day' ? ' is-active' : ''),
                        'aria-current' => $view === 'day' ? 'true' : null,
                    ]) ?>
                    <?= $this->Html->link(__('Week'), $link('week', $date), [
                        'class' => 'team-toggle__btn' . ($view === 'week' ? ' is-active' : ''),
                        'aria-current' => $view === 'week' ? 'true' : null,
                    ]) ?>
                </div>
                <div class="team-board__nav">
                    <?= $this->Html->link('‹', $link($view, $prev), [
                        'class' => 'dash__btn dash__btn--small dash__btn--ghost team-board__arrow',
                        'aria-label' => $view === 'week' ? __('Previous week') : __('Previous day'),
                    ]) ?>
                    <?= $this->Html->link(__('Today'), $link($view, $today), [
                        'class' => 'dash__btn dash__btn--small dash__btn--ghost',
                    ]) ?>
                    <?= $this->Html->link('›', $link($view, $next), [
                        'class' => 'dash__btn dash__btn--small dash__btn--ghost team-board__arrow',
                        'aria-label' => $view === 'week' ? __('Next week') : __('Next day'),
                    ]) ?>
                </div>
            </div>
        </div>

        <div class="team-legend" aria-label="<?= h(__('Legend')) ?>">
            <span><i class="team-swatch team-swatch--assigned"></i><?= __('Booked') ?></span>
            <span><i class="team-swatch team-swatch--in-progress"></i><?= __('Job in progress') ?></span>
            <span><i class="team-swatch team-swatch--completed"></i><?= __('Completed') ?></span>
            <span><i class="team-swatch team-swatch--unable"></i><?= __('Unable to complete') ?></span>
        </div>

        <?php if ($jumpedFromToday) : ?>
            <p class="team-board__jump" role="status">
                <?= __('Nothing is booked today, so this opens on the nearest day with bookings.') ?>
                <?= $this->Html->link(__('Show today'), $link($view, $today)) ?>
            </p>
        <?php endif; ?>

        <?php if ($vehicles === []) : ?>
            <p class="team-board__empty"><?= __('No vehicles in service.') ?></p>
        <?php elseif ($view === 'day') : ?>
            <?php $dayKey = $date->format('Y-m-d'); ?>
            <div class="team-scroll" role="region" tabindex="0" aria-label="<?= h(__('Day schedule')) ?>">
                <div class="team-day"
                    <?php if ($showNowLine) : ?>
                        data-now-line data-day-start="<?= $dayStart ?>" data-day-length="<?= $dayLength ?>"
                        data-now-minute="<?= $minuteNow ?>"
                    <?php endif; ?>>
                    <div class="team-day__head">
                        <div class="team-day__corner"><?= __('Vehicle · right now') ?></div>
                        <div class="team-day__hours" aria-hidden="true">
                            <?php for ($hour = 8; $hour <= 17; $hour++) : ?>
                                <span style="left: <?= $percent($hour * 60) ?>%"><?= sprintf('%02d:00', $hour) ?></span>
                            <?php endfor; ?>
                        </div>
                    </div>
                    <?php foreach ($vehicles as $vehicle) : ?>
                        <?php
                        $id = (int)$vehicle->id;
                        $row = $status[$id];
                        $dayBookings = $bookings[$id][$dayKey] ?? [];
                        ?>
                        <div class="team-day__row" id="vehicle-<?= $id ?>">
                            <?= $this->element('fleet_vehicle', $rowHeading($vehicle, $row)) ?>
                            <div class="team-day__track">
                                <?php if ($dayBookings === []) : ?>
                                    <span class="team-day__free"><?= __('Free all day') ?></span>
                                <?php endif; ?>
                                <?php foreach ($dayBookings as $item) : ?>
                                    <?php
                                    $assignment = $item['assignment'];
                                    $job = $assignment->job_request;
                                    $times = $clock($item['start']) . '–' . $clock(min($item['end'], 24 * 60 - 1));
                                    ?>
                                    <?= $this->Html->link(
                                        '<strong>' . h($plumberName($assignment)) . '</strong>'
                                        . '<span>' . h($times) . ' · ' . h(__('Job #{0}', $job->id)) . '</span>',
                                        $jobUrl((int)$job->id),
                                        [
                                            'escape' => false,
                                            'class' => 'team-block team-block--' . $statusClass((string)$job->status),
                                            'style' => sprintf(
                                                'left: %s%%; width: %s%%',
                                                $percent($item['start']),
                                                max(0.5, $percent($item['end']) - $percent($item['start'])),
                                            ),
                                            'title' => __(
                                                '{0}, job #{1}, {2}, {3}, {4}',
                                                $plumberName($assignment),
                                                $job->id,
                                                $statusLabel((string)$job->status),
                                                $times,
                                                $job->suburb,
                                            ),
                                        ],
                                    ) ?>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($showNowLine) : ?>
                        <div class="team-day__now" aria-hidden="true"
                            style="--now: <?= round($percent($minuteNow) / 100, 5) ?>">
                            <span><?= h($now->format('g:i')) ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php else : ?>
            <div class="team-scroll" role="region" tabindex="0" aria-label="<?= h(__('Week schedule')) ?>">
                <table class="team-week">
                    <thead>
                        <tr>
                            <th scope="col" class="team-week__corner"><?= __('Vehicle · right now') ?></th>
                            <?php foreach ($days as $day) : ?>
                                <th scope="col" class="<?= $isToday($day) ? 'is-today' : '' ?>">
                                    <?= $this->Html->link(
                                        '<span class="team-week__weekday">' . h($day->format('D')) . '</span>'
                                        . '<span class="team-week__date">' . h($day->format('j M')) . '</span>',
                                        $link('day', $day),
                                        ['escape' => false, 'title' => __('Open {0} as a day', $day->format('l j F'))],
                                    ) ?>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($vehicles as $vehicle) : ?>
                            <?php
                            $id = (int)$vehicle->id;
                            $row = $status[$id];
                            ?>
                            <tr id="vehicle-<?= $id ?>">
                                <th scope="row">
                                    <?= $this->element('fleet_vehicle', $rowHeading($vehicle, $row)) ?>
                                </th>
                                <?php foreach ($days as $day) : ?>
                                    <?php $dayBookings = $bookings[$id][$day->format('Y-m-d')] ?? []; ?>
                                    <td class="<?= $isToday($day) ? 'is-today' : '' ?>">
                                        <?php foreach ($dayBookings as $item) : ?>
                                            <?php
                                            $assignment = $item['assignment'];
                                            $job = $assignment->job_request;
                                            ?>
                                            <?= $this->Html->link(
                                                h($clock($item['start'])) . ' · ' . h($plumberName($assignment)),
                                                $jobUrl((int)$job->id),
                                                [
                                                    'escape' => false,
                                                    'class' => 'team-chip team-chip--'
                                                        . $statusClass((string)$job->status),
                                                    'title' => __(
                                                        'Job #{0}, {1}, {2}–{3}',
                                                        $job->id,
                                                        $statusLabel((string)$job->status),
                                                        $clock($item['start']),
                                                        $clock(min($item['end'], 24 * 60 - 1)),
                                                    ),
                                                ],
                                            ) ?>
                                        <?php endforeach; ?>
                                        <?php if ($dayBookings === []) : ?>
                                            <span class="team-week__free"><?= __('Free') ?></span>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <?php if ($inactiveCount > 0) : ?>
            <p class="team-board__note">
                <?= h(__n(
                    '{0} vehicle is out of service and not shown.',
                    '{0} vehicles are out of service and not shown.',
                    $inactiveCount,
                    $inactiveCount,
                )) ?>
                <?= $this->Html->link(__('See all vehicles'), ['action' => 'index']) ?>
            </p>
        <?php endif; ?>
    </section>
</div>
