<?php
/**
 * The office's team schedule.
 *
 * @var \App\View\AppView $this
 * @var list<\App\Model\Entity\Admin> $plumbers Every plumber, active first.
 * @var list<\App\Model\Entity\Admin> $activePlumbers
 * @var array<int, list<\App\Model\Entity\PlumberUnavailability>> $byPlumber Upcoming time off.
 * @var list<array{event: \App\Model\Entity\AuditLog, period: \App\Model\Entity\PlumberUnavailability, plumber: \App\Model\Entity\Admin}> $alerts
 * @var string $view day or week
 * @var \DateTimeImmutable $date
 * @var list<\DateTimeImmutable> $days
 * @var \DateTimeImmutable $today
 * @var \DateTimeImmutable $now
 * @var \DateTimeZone $timezone
 * @var array<int, array<string, list<\App\Model\Entity\PlumberUnavailability>>> $timeOff
 * @var array<int, array<string, list<array{assignment: object, start: int, end: int}>>> $jobs
 * @var array<int, array{current: ?object, next: ?object, open: int, state: string}> $status
 * @var array{on_job: int, available: int, unavailable: int, off_hours: int, jobs: int} $summary
 * @var bool $jumpedFromToday No date was chosen and today had nothing booked.
 * @var array<int, array{average: float|null, count: int}> $ratings Each active plumber's customer rating.
 */
use App\Model\Table\PlumberUnavailabilitiesTable;

$this->assign('title', 'Plumber availability');
$this->Html->script('team-schedule', ['block' => true]);

$clock = static fn(int $minute): string => sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60);
$periodTimes = static fn($period): string => $clock((int)$period->start_minute) . '–'
    . $clock((int)$period->end_minute);
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
$initials = static function (?string $name): string {
    $name = (string)$name;
    // Split on separators and on camelCase, so "TomPlumbers" gives TP and "plumber_staff" gives PS.
    $parts = preg_split('/[^A-Za-z0-9]+|(?<=[a-z])(?=[A-Z])/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [$name];

    return strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : substr($parts[0], 1, 1)));
};
$stateLabels = [
    'on_job' => __('On a job'),
    'available' => __('Available'),
    'unavailable' => __('Unavailable'),
    'off_hours' => __('Off hours'),
];
$jobUrl = static fn(int $id): array => ['controller' => 'JobRequests', 'action' => 'manageView', $id];
// "★ 4.3 (3)" for a plumber with ratings, nothing for one without.
$ratingLine = static function (int $id) use ($ratings): string {
    $rating = $ratings[$id] ?? ['average' => null, 'count' => 0];
    if ($rating['count'] === 0) {
        return '';
    }

    return '<span class="team-rating" title="' . h(__n(
        'Average customer rating, from {0} rating',
        'Average customer rating, from {0} ratings',
        $rating['count'],
        $rating['count'],
    )) . '">★ ' . h(number_format((float)$rating['average'], 1))
        . ' <span class="team-rating__count">(' . (int)$rating['count'] . ')</span></span>';
};

$step = $view === 'week' ? '7 days' : '1 day';
$prev = $date->modify('-' . $step);
$next = $date->modify('+' . $step);
// Skip weekends when stepping day by day; nobody is rostered on.
if ($view === 'day') {
    while ((int)$prev->format('N') > 5) {
        $prev = $prev->modify('-1 day');
    }
    while ((int)$next->format('N') > 5) {
        $next = $next->modify('+1 day');
    }
}
$link = static fn(string $view, DateTimeImmutable $date): array => [
    'action' => 'index',
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
?>
<?= $this->Html->css(['dashboard', 'team-schedule']) ?>
<?= $this->element('admin_sidebar') ?>

<div class="dash portal-content team">
    <div class="dash__header">
        <div>
            <h1 class="dash__title"><?= __('Plumber availability') ?></h1>
            <p class="team__subtitle">
                <?= __('Standard working hours are Monday to Friday, 08:00–17:00.') ?>
                <span class="team__clock" data-team-clock><?= __('Updated {0}', h($now->format('g:ia'))) ?></span>
            </p>
        </div>
        <div class="dash__header-actions">
            <?= $this->Html->link(__('Plumber accounts'), ['controller' => 'Plumbers', 'action' => 'index'], [
                'class' => 'dash__btn dash__btn--ghost',
            ]) ?>
        </div>
    </div>
    <?= $this->Flash->render() ?>

    <ul class="dash__stats team__stats" aria-label="<?= h(__('Team right now')) ?>">
        <li class="dash__stat team__stat team__stat--on_job">
            <span class="dash__stat-value"><?= $summary['on_job'] ?></span>
            <span class="dash__stat-label"><?= __('On a job now') ?></span>
        </li>
        <li class="dash__stat team__stat team__stat--available">
            <span class="dash__stat-value"><?= $summary['available'] ?></span>
            <span class="dash__stat-label"><?= __('Available now') ?></span>
        </li>
        <li class="dash__stat team__stat team__stat--unavailable">
            <span class="dash__stat-value"><?= $summary['unavailable'] ?></span>
            <span class="dash__stat-label"><?= __('Unavailable') ?></span>
        </li>
        <li class="dash__stat">
            <span class="dash__stat-value"><?= $summary['jobs'] ?></span>
            <span class="dash__stat-label">
                <?= $view === 'week' ? __('Jobs this week') : __('Jobs on this day') ?>
            </span>
        </li>
    </ul>

    <section class="dash__card team-board" aria-labelledby="team-board-title">
        <div class="team-board__toolbar">
            <h2 id="team-board-title" class="team-board__range"><?= h($rangeLabel) ?></h2>
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
            <span><i class="team-swatch team-swatch--assigned"></i><?= __('Assigned') ?></span>
            <span><i class="team-swatch team-swatch--in-progress"></i><?= __('In progress') ?></span>
            <span><i class="team-swatch team-swatch--completed"></i><?= __('Completed') ?></span>
            <span><i class="team-swatch team-swatch--unable"></i><?= __('Unable to complete') ?></span>
            <span><i class="team-swatch team-swatch--off"></i><?= __('Unavailable') ?></span>
        </div>

        <?php if ($jumpedFromToday) : ?>
            <p class="team-board__jump" role="status">
                <?= __('Nothing is booked today, so this opens on the nearest day with jobs.') ?>
                <?= $this->Html->link(__('Show today'), $link($view, $today)) ?>
            </p>
        <?php endif; ?>

        <?php if ($activePlumbers === []) : ?>
            <p class="team-board__empty"><?= __('No active plumbers.') ?></p>
        <?php elseif ($view === 'day') : ?>
            <?php $dayKey = $date->format('Y-m-d'); ?>
            <div class="team-scroll" role="region" tabindex="0" aria-label="<?= h(__('Day schedule')) ?>">
                <div class="team-day"
                    <?php if ($showNowLine) : ?>
                        data-now-line data-day-start="<?= $dayStart ?>" data-day-length="<?= $dayLength ?>"
                        data-now-minute="<?= $minuteNow ?>"
                    <?php endif; ?>>
                    <div class="team-day__head">
                        <div class="team-day__corner"><?= __('Plumber · right now') ?></div>
                        <div class="team-day__hours" aria-hidden="true">
                            <?php for ($hour = 8; $hour <= 17; $hour++) : ?>
                                <span style="left: <?= $percent($hour * 60) ?>%"><?= sprintf('%02d:00', $hour) ?></span>
                            <?php endfor; ?>
                        </div>
                    </div>
                    <?php foreach ($activePlumbers as $plumber) : ?>
                        <?php
                        $id = (int)$plumber->admin_id;
                        $row = $status[$id];
                        $dayJobs = $jobs[$id][$dayKey] ?? [];
                        $dayOff = $timeOff[$id][$dayKey] ?? [];
                        ?>
                        <div class="team-day__row" id="plumber-<?= $id ?>">
                            <div class="team-person">
                                <span class="team-person__avatar team-person__avatar--<?= h($row['state']) ?>"
                                    aria-hidden="true"><?= h($initials($plumber->admin_username)) ?></span>
                                <span class="team-person__text">
                                    <strong><?= h($plumber->admin_username) ?> <?= $ratingLine($id) ?></strong>
                                    <span class="team-pill team-pill--<?= h($row['state']) ?>">
                                        <?= h($stateLabels[$row['state']]) ?>
                                        <?php if ($row['current'] !== null) : ?>
                                            · <?= h(__('until {0}', $row['current']->scheduled_end
                                                ->setTimezone($timezone)->format('g:ia'))) ?>
                                        <?php endif; ?>
                                    </span>
                                    <span class="team-person__meta">
                                        <?= h(__n('{0} job', '{0} jobs', count($dayJobs), count($dayJobs))) ?>
                                        <?php if ($row['next'] !== null && $row['current'] === null) : ?>
                                            · <?= h(__('next {0}', $row['next']->scheduled_start
                                                ->setTimezone($timezone)->format('D g:ia'))) ?>
                                        <?php endif; ?>
                                    </span>
                                </span>
                            </div>
                            <div class="team-day__track">
                                <?php if ($dayJobs === [] && $dayOff === []) : ?>
                                    <span class="team-day__free"><?= __('Free all day') ?></span>
                                <?php endif; ?>
                                <?php foreach ($dayOff as $period) : ?>
                                    <?php
                                    $start = (int)$period->start_minute;
                                    $end = (int)$period->end_minute;
                                    ?>
                                    <div class="team-block team-block--off"
                                        style="<?= h(sprintf(
                                            'left: %s%%; width: %s%%',
                                            $percent($start),
                                            $percent($end) - $percent($start),
                                        )) ?>"
                                        title="<?= h(__('Unavailable {0}–{1}', $clock($start), $clock($end))) ?>">
                                        <strong><?= __('Unavailable') ?></strong>
                                        <span><?= h($clock($start)) ?>–<?= h($clock($end)) ?></span>
                                    </div>
                                <?php endforeach; ?>
                                <?php foreach ($dayJobs as $item) : ?>
                                    <?php
                                    $job = $item['assignment']->job_request;
                                    $times = $clock($item['start']) . '–' . $clock(min($item['end'], 24 * 60 - 1));
                                    ?>
                                    <?= $this->Html->link(
                                        '<strong>' . h(__('Job #{0}', $job->id)) . '</strong>'
                                        . '<span>' . h($times) . ' · ' . h($job->suburb) . '</span>',
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
                                                'Job #{0}, {1}, {2}, {3}',
                                                $job->id,
                                                $statusLabel((string)$job->status),
                                                $times,
                                                $job->addressLine(),
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
                            <th scope="col" class="team-week__corner"><?= __('Plumber · right now') ?></th>
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
                        <?php foreach ($activePlumbers as $plumber) : ?>
                            <?php
                            $id = (int)$plumber->admin_id;
                            $row = $status[$id];
                            ?>
                            <tr id="plumber-<?= $id ?>">
                                <th scope="row">
                                    <div class="team-person">
                                        <span class="team-person__avatar team-person__avatar--<?= h($row['state']) ?>"
                                            aria-hidden="true"><?= h($initials($plumber->admin_username)) ?></span>
                                        <span class="team-person__text">
                                            <strong><?= h($plumber->admin_username) ?> <?= $ratingLine($id) ?></strong>
                                            <span class="team-pill team-pill--<?= h($row['state']) ?>">
                                                <?= h($stateLabels[$row['state']]) ?>
                                            </span>
                                        </span>
                                    </div>
                                </th>
                                <?php foreach ($days as $day) : ?>
                                    <?php
                                    $key = $day->format('Y-m-d');
                                    $dayJobs = $jobs[$id][$key] ?? [];
                                    $dayOff = $timeOff[$id][$key] ?? [];
                                    $offMinutes = array_sum(array_map(
                                        static fn($p): int => (int)$p->end_minute - (int)$p->start_minute,
                                        $dayOff,
                                    ));
                                    ?>
                                    <td class="<?= $isToday($day) ? 'is-today' : '' ?>">
                                        <?php if ($offMinutes >= $dayLength) : ?>
                                            <span class="team-chip team-chip--off">
                                                <?= __('Unavailable all day') ?>
                                            </span>
                                        <?php else : ?>
                                            <?php foreach ($dayOff as $period) : ?>
                                                <span class="team-chip team-chip--off">
                                                    <?= h($periodTimes($period)) ?>
                                                    <?= __('off') ?>
                                                </span>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                        <?php foreach ($dayJobs as $item) : ?>
                                            <?php $job = $item['assignment']->job_request; ?>
                                            <?= $this->Html->link(
                                                h($clock($item['start'])) . ' · #' . (int)$job->id,
                                                $jobUrl((int)$job->id),
                                                [
                                                    'escape' => false,
                                                    'class' => 'team-chip team-chip--'
                                                        . $statusClass((string)$job->status),
                                                    'title' => __(
                                                        'Job #{0}, {1}, {2}',
                                                        $job->id,
                                                        $statusLabel((string)$job->status),
                                                        $job->addressLine(),
                                                    ),
                                                ],
                                            ) ?>
                                        <?php endforeach; ?>
                                        <?php if ($dayJobs === [] && $dayOff === []) : ?>
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
    </section>

    <div class="team-panels">
        <section id="alerts" class="dash__card" aria-labelledby="availability-alerts-heading">
            <h2 id="availability-alerts-heading" class="dash__card-title">
                <?= __('Availability updates') ?>
                <span class="team-count"><?= count($alerts) ?></span>
            </h2>
            <?php if ($alerts === []) : ?>
                <p class="team-panels__empty"><?= __('No new availability updates.') ?></p>
            <?php else : ?>
                <ul class="team-list">
                    <?php foreach ($alerts as $alert) : ?>
                        <?php
                        $period = $alert['period'];
                        $event = $alert['event'];
                        $withdrawn = $event->action === 'plumber.unavailable_cancelled';
                        ?>
                        <li id="alert-<?= (int)$event->id ?>" class="team-list__item">
                            <span class="team-list__dot team-list__dot--<?= $withdrawn ? 'withdrawn' : 'new' ?>"
                                aria-hidden="true"></span>
                            <span class="team-list__text">
                                <strong><?= h($alert['plumber']->admin_username) ?></strong>
                                <?= $withdrawn ? __('unavailable period withdrawn') : __('now unavailable') ?>
                                <span class="team-list__meta">
                                    <?= h($period->unavailable_date->format('D j M Y')) ?>,
                                    <?= h($periodTimes($period)) ?>
                                </span>
                            </span>
                            <span class="team-list__actions">
                                <?php if (!$withdrawn) : ?>
                                    <?= $this->Html->link(__('View'), [
                                        'action' => 'index',
                                        '?' => ['view' => 'week', 'date' => $period->unavailable_date->format('Y-m-d')],
                                        '#' => 'plumber-' . (int)$period->admin_id,
                                    ], ['class' => 'dash__btn dash__btn--small dash__btn--ghost']) ?>
                                <?php endif; ?>
                                <?= $this->Form->postLink(__('Dismiss'), ['action' => 'dismiss', (int)$event->id], [
                                    'class' => 'dash__btn dash__btn--small dash__btn--ghost',
                                ]) ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="dash__card" aria-labelledby="upcoming-off-heading">
            <h2 id="upcoming-off-heading" class="dash__card-title"><?= __('Upcoming time off') ?></h2>
            <?php
            $anyOff = false;
            foreach ($plumbers as $plumber) {
                $anyOff = $anyOff || !empty($byPlumber[(int)$plumber->admin_id]);
            }
            ?>
            <?php if (!$anyOff) : ?>
                <p class="team-panels__empty"><?= __('No upcoming unavailable periods.') ?></p>
            <?php else : ?>
                <ul class="team-list">
                    <?php foreach ($plumbers as $plumber) : ?>
                        <?php foreach ($byPlumber[(int)$plumber->admin_id] ?? [] as $period) : ?>
                            <li id="period-<?= (int)$period->id ?>" class="team-list__item">
                                <span class="team-list__dot team-list__dot--off" aria-hidden="true"></span>
                                <span class="team-list__text">
                                    <strong><?= h($plumber->admin_username) ?></strong>
                                    <?php if (!$plumber->active) : ?>
                                        (<?= __('Deactivated') ?>)
                                    <?php endif; ?>
                                    <span class="team-list__meta">
                                        <?= h($period->unavailable_date->format('D j M Y')) ?>,
                                        <?= h($periodTimes($period)) ?>
                                    </span>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>
</div>
