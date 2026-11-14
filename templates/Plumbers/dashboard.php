<?php
/**
 * The plumber's landing page: what needs doing, what is on today, and what is
 * coming up.
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Admin $plumber
 * @var int $pendingApproval
 * @var list<\App\Model\Entity\JobAssignment> $pending
 * @var list<\App\Model\Entity\JobAssignment> $today
 * @var list<\App\Model\Entity\JobAssignment> $comingUp
 * @var list<\App\Model\Entity\JobAssignment> $recentlyFinished
 * @var array{pending:int, today:int, nextSeven:int, inProgress:int, completed:int} $stats
 * @var \DateTimeImmutable $now
 * @var \DateTimeZone $timezone
 */
$this->assign('title', 'Plumber dashboard');

$hour = (int)$now->format('G');
$greeting = $hour < 12 ? __('Good morning') : ($hour < 17 ? __('Good afternoon') : __('Good evening'));
?>
<?= $this->Html->css('dashboard') ?>
<?= $this->element('admin_sidebar') ?>

<div class="dash portal-content">
    <div class="dash__header">
        <div>
            <h1 class="dash__title"><?= __('Plumber dashboard') ?></h1>
            <p class="dash__card-sub job-context">
                <?= h($greeting) ?>, <?= h($plumber->admin_username) ?> &middot;
                <?= h($now->format('l j F Y')) ?>
            </p>
        </div>
        <div class="dash__header-actions">
            <?= $this->Html->link(__('My timetable'), ['action' => 'availability'], [
                'class' => 'dash__btn dash__btn--ghost',
            ]) ?>
            <?= $this->Html->link(__('All my jobs'), ['action' => 'jobs'], ['class' => 'dash__btn']) ?>
        </div>
    </div>

    <?= $this->Flash->render() ?>

    <ul class="dash__stats">
        <li class="dash__stat<?= $stats['pending'] > 0 ? ' dash__stat--accent' : '' ?>">
            <span class="dash__stat-value"><?= $this->Number->format($stats['pending']) ?></span>
            <span class="dash__stat-label"><?= __('Awaiting approval') ?></span>
        </li>
        <li class="dash__stat">
            <span class="dash__stat-value"><?= $this->Number->format($stats['today']) ?></span>
            <span class="dash__stat-label"><?= __('Today') ?></span>
        </li>
        <li class="dash__stat">
            <span class="dash__stat-value"><?= $this->Number->format($stats['inProgress']) ?></span>
            <span class="dash__stat-label"><?= __('In progress') ?></span>
        </li>
        <li class="dash__stat">
            <span class="dash__stat-value"><?= $this->Number->format($stats['nextSeven']) ?></span>
            <span class="dash__stat-label"><?= __('Next 7 days') ?></span>
        </li>
        <li class="dash__stat">
            <span class="dash__stat-value"><?= $this->Number->format($stats['completed']) ?></span>
            <span class="dash__stat-label"><?= __('Completed, last 30 days') ?></span>
        </li>
    </ul>

    <?php if ($pending !== []) : ?>
        <div class="dash__card dash__card--attention">
            <div class="dash__card-head">
                <div>
                    <h2 class="dash__card-title"><?= __n(
                        '{0} job is waiting for your approval.',
                        '{0} jobs are waiting for your approval.',
                        $pendingApproval,
                        $pendingApproval,
                    ) ?></h2>
                    <p class="dash__card-sub">
                        <?= __('The office has assigned these to you. Approve each one to confirm you can do it.') ?>
                    </p>
                </div>
            </div>
            <ul class="job-list">
                <?php foreach ($pending as $assignment) : ?>
                    <?= $this->element('plumber_job_row', [
                        'assignment' => $assignment,
                        'timezone' => $timezone,
                        'highlight' => true,
                    ]) ?>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="dashboard-grid">
        <div class="dash__card">
            <div class="dash__card-head">
                <h2 class="dash__card-title"><?= __('Today') ?></h2>
                <span class="dash__card-sub"><?= h($now->format('D j M')) ?></span>
            </div>
            <?php if ($today === []) : ?>
                <p class="dash__empty"><?= __('Nothing scheduled for today.') ?></p>
            <?php else : ?>
                <ul class="job-list">
                    <?php foreach ($today as $assignment) : ?>
                        <?= $this->element('plumber_job_row', [
                            'assignment' => $assignment,
                            'timezone' => $timezone,
                        ]) ?>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <div class="dash__card">
            <div class="dash__card-head">
                <h2 class="dash__card-title"><?= __('Coming up') ?></h2>
                <span class="dash__card-sub"><?= __('Next scheduled jobs') ?></span>
            </div>
            <?php if ($comingUp === []) : ?>
                <p class="dash__empty"><?= __('No upcoming jobs yet.') ?></p>
            <?php else : ?>
                <ul class="job-list">
                    <?php foreach ($comingUp as $assignment) : ?>
                        <?= $this->element('plumber_job_row', [
                            'assignment' => $assignment,
                            'timezone' => $timezone,
                        ]) ?>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <div class="dash__card">
        <div class="dash__card-head">
            <h2 class="dash__card-title"><?= __('Recently finished') ?></h2>
            <?= $this->Html->link(__('See all jobs'), ['action' => 'jobs'], [
                'class' => 'dash__btn dash__btn--ghost dash__btn--small',
            ]) ?>
        </div>
        <?php if ($recentlyFinished === []) : ?>
            <p class="dash__empty"><?= __('Jobs you complete will appear here.') ?></p>
        <?php else : ?>
            <ul class="job-list">
                <?php foreach ($recentlyFinished as $assignment) : ?>
                    <?= $this->element('plumber_job_row', [
                        'assignment' => $assignment,
                        'timezone' => $timezone,
                    ]) ?>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <p class="dash__card-sub dashboard-footnote">
        <?= __('Your standard working hours are Monday to Friday, 08:00–17:00.') ?>
        <?= __('Your jobs and unavailable times appear together in My Timetable.') ?>
        <?= __('If you received a temporary password, change it using the link in the sidebar.') ?>
    </p>
</div>
