<?php
/**
 * One vehicle's row heading on the fleet schedule: icon, name, registration
 * and what it is doing right now.
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Vehicle $vehicle
 * @var array{current: ?object, upcoming: list<object>, state: string} $row
 * @var array<string, string> $stateLabels
 * @var string $van Inline SVG for the icon.
 * @var \DateTimeZone $timezone
 */
$current = $row['current'];
$next = $row['upcoming'][0] ?? null;
?>
<div class="team-person">
    <span class="team-person__avatar team-person__avatar--vehicle team-person__avatar--<?= h($row['state']) ?>">
        <?= $van ?>
    </span>
    <span class="team-person__text">
        <?= $this->Html->link(h($vehicle->label), ['action' => 'view', $vehicle->id], [
            'escape' => false,
            'class' => 'team-person__name',
        ]) ?>
        <span class="team-person__meta"><?= h($vehicle->registration_number) ?></span>
        <span class="team-pill team-pill--<?= h($row['state']) ?>">
            <?= h($stateLabels[$row['state']]) ?>
            <?php if ($current !== null) : ?>
                · <?= h($current->plumber->admin_username ?? __('Unknown plumber')) ?>
            <?php endif; ?>
        </span>
        <?php if ($current === null && $next !== null) : ?>
            <span class="team-person__meta">
                <?= h(__('next {0}', $next->scheduled_start->setTimezone($timezone)->format('D g:ia'))) ?>
            </span>
        <?php endif; ?>
    </span>
</div>
