<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\Admin> $plumbers
 * @var array<int, array<string, mixed>>|null $schedule Each plumber's current job, next job, open job count and
 *     whether they have marked this moment unavailable, or null for the manager, who only oversees accounts.
 * @var \DateTimeZone $timezone
 * @var array<int, array{average: float|null, count: int}>|null $ratings Each plumber's average customer rating, or
 *     null when the viewer is not the manager or an admin.
 */
$this->assign('title', 'Plumber accounts');

// The job columns are the office's; the manager's list is about accounts only.
$showSchedule = $schedule !== null;
$jobUrl = static fn(int $id): array => ['controller' => 'JobRequests', 'action' => 'manageView', $id];
?>
<?= $this->Html->css('dashboard') ?>
<?= $this->element('admin_sidebar') ?>

<div class="dash portal-content">
    <div class="dash__header">
        <h1 class="dash__title"><?= __('Plumber accounts') ?></h1>
        <div class="dash__header-actions">
            <?php if (($officeRole ?? null) === 'admin') : ?>
                <?= $this->Html->link(
                    __('Team schedule'),
                    ['controller' => 'PlumberAvailability', 'action' => 'index'],
                    ['class' => 'dash__btn dash__btn--ghost'],
                ) ?>
            <?php endif; ?>
            <?= $this->Html->link(__('New plumber'), ['action' => 'add'], ['class' => 'dash__btn']) ?>
        </div>
    </div>

    <?= $this->Flash->render() ?>

    <div class="dash__table-wrap">
        <table class="dash__table">
            <thead>
                <tr>
                    <th><?= $this->Paginator->sort('admin_id', __('ID')) ?></th>
                    <th><?= $this->Paginator->sort('admin_username', __('Username')) ?></th>
                    <th><?= $this->Paginator->sort('admin_email', __('Email')) ?></th>
                    <th><?= __('Account') ?></th>
                    <?php if ($showSchedule) : ?>
                        <th><?= __('Right now') ?></th>
                        <th><?= __('Next job') ?></th>
                        <th><?= __('Open jobs') ?></th>
                    <?php endif; ?>
                    <?php if ($ratings !== null) : ?>
                        <th><?= __('Customer rating') ?></th>
                    <?php endif; ?>
                    <th class="dash__actions-col"><?= __('Actions') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($plumbers as $plumber) : ?>
                    <tr>
                        <td><?= $this->Number->format($plumber->admin_id) ?></td>
                        <td><?= h($plumber->admin_username) ?></td>
                        <td><?= h($plumber->admin_email) ?></td>
                        <td><?= $plumber->active ? __('Active') : __('Deactivated') ?></td>
                        <?php if ($showSchedule) : ?>
                            <?php
                            $row = $schedule[$plumber->admin_id]
                                ?? ['current' => null, 'next' => null, 'open' => 0, 'unavailable' => false];
                            $current = $row['current'];
                            $next = $row['next'];
                            ?>
                            <td>
                                <?php if (!$plumber->active) : ?>
                                    <span class="dash__card-sub">&mdash;</span>
                                <?php elseif ($current !== null) : ?>
                                    <span class="dash__badge dash__badge--in_progress"><?= __('On a job') ?></span>
                                    <span class="dash__preview">
                                        <?= $this->Html->link(
                                            $current->job_request->reference(),
                                            $jobUrl($current->job_request->id),
                                        ) ?>
                                        <?= __('until {0}', h(
                                            $current->scheduled_end->setTimezone($timezone)->format('g:ia'),
                                        )) ?>
                                    </span>
                                <?php elseif ($row['unavailable']) : ?>
                                    <span class="dash__badge dash__badge--reviewing"><?= __('Unavailable') ?></span>
                                <?php else : ?>
                                    <span class="dash__badge dash__badge--completed"><?= __('Available') ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($next !== null) : ?>
                                    <?= $this->Html->link(
                                        $next->job_request->reference(),
                                        $jobUrl($next->job_request->id),
                                    ) ?>
                                    <span class="dash__preview">
                                        <?= h($next->scheduled_start->setTimezone($timezone)->format('D j M, g:ia')) ?>
                                    </span>
                                <?php else : ?>
                                    <span class="dash__card-sub"><?= __('Nothing booked') ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= $this->Number->format($row['open']) ?></td>
                        <?php endif; ?>
                        <?php if ($ratings !== null) : ?>
                            <?php $rating = $ratings[$plumber->admin_id] ?? ['average' => null, 'count' => 0]; ?>
                            <td>
                                <?php if ($rating['count'] === 0) : ?>
                                    <span class="dash__card-sub"><?= __('No ratings yet') ?></span>
                                <?php else : ?>
                                    <?= h(number_format((float)$rating['average'], 1)) ?> / 5
                                    <span class="dash__preview">
                                        (<?= $rating['count'] === 1
                                            ? __('1 rating')
                                            : h(__('{0} ratings', $rating['count'])) ?>)
                                    </span>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                        <td class="dash__actions-col">
                            <?php if (($officeRole ?? null) === 'manager') : ?>
                                <?= $this->Html->link(
                                    __('Open'),
                                    ['action' => 'timetable', $plumber->admin_id],
                                    ['class' => 'dash__btn dash__btn--small'],
                                ) ?>
                            <?php endif; ?>
                            <?php if ($plumber->active) : ?>
                                <?= $this->Form->postLink(
                                    __('Deactivate'),
                                    ['action' => 'deactivate', $plumber->admin_id],
                                    [
                                        'confirm' => __(
                                            'Deactivate plumber {0}? They will immediately lose access.',
                                            $plumber->admin_username,
                                        ),
                                        'class' => 'dash__btn dash__btn--small dash__btn--ghost',
                                    ],
                                ) ?>
                            <?php else : ?>
                                <?= $this->Form->postLink(
                                    __('Reactivate'),
                                    ['action' => 'reactivate', $plumber->admin_id],
                                    ['class' => 'dash__btn dash__btn--small'],
                                ) ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="paginator dash__paginator">
        <ul class="pagination">
            <?= $this->Paginator->first('<< ' . __('first')) ?>
            <?= $this->Paginator->prev('< ' . __('previous')) ?>
            <?= $this->Paginator->numbers() ?>
            <?= $this->Paginator->next(__('next') . ' >') ?>
            <?= $this->Paginator->last(__('last') . ' >>') ?>
        </ul>
        <p><?= $this->Paginator->counter(
            __('Page {{page}} of {{pages}}, showing {{current}} record(s) out of {{count}} total'),
        ) ?></p>
    </div>
</div>
