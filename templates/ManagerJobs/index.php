<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\JobRequest> $jobs
 * @var string|null $status
 */
use App\Model\Entity\JobRequest;
use App\Model\Table\ContactSubmissionsTable;

$this->assign('title', 'Job overview');
$tz = ContactSubmissionsTable::businessTimezone();
$statuses = array_combine(
    JobRequest::STATUSES,
    array_map(static fn(string $item): string => ucwords(str_replace('_', ' ', $item)), JobRequest::STATUSES),
);
?>
<?= $this->Html->css('dashboard') ?>
<?= $this->element('admin_sidebar') ?>

<div class="dash portal-content">
    <div class="dash__header">
        <h1 class="dash__title"><?= __('Job overview') ?></h1>
    </div>
    <p><?= __('Read-only overview. Job decisions and assignments remain with admins.') ?></p>

    <?= $this->Form->create(null, [
        'type' => 'get',
        'url' => ['action' => 'index'],
        'class' => 'dash__filters',
    ]) ?>
        <?= $this->Form->control('status', [
            'type' => 'select',
            'label' => __('Status'),
            'options' => $statuses,
            'empty' => __('All statuses'),
            'value' => $status,
        ]) ?>
        <div class="dash__filter-actions">
            <?= $this->Form->button(__('Apply'), ['class' => 'dash__btn']) ?>
            <?= $this->Html->link(__('Clear'), ['action' => 'index'], [
                'class' => 'dash__btn dash__btn--ghost',
            ]) ?>
        </div>
    <?= $this->Form->end() ?>

    <div class="dash__table-wrap">
        <table class="dash__table dash__table--manager-jobs">
            <thead>
                <tr>
                    <th><?= __('Received') ?></th>
                    <th><?= __('Job') ?></th>
                    <th><?= __('Status') ?></th>
                    <th><?= __('Assigned by') ?></th>
                    <th><?= __('Plumber / vehicle') ?></th>
                    <th><?= __('Planned / finished') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php $count = 0; ?>
                <?php foreach ($jobs as $job) : ?>
                    <?php $count++; ?>
                    <?php $assignment = $job->job_assignment; ?>
                    <tr>
                        <td class="dash__nowrap">
                            <?= h($job->created?->setTimezone($tz)->format('d M Y, g:ia')) ?>
                        </td>
                        <td>
                            <strong><?= $this->Html->link(__('Job #{0}', $job->id), [
                                'action' => 'view', $job->id,
                            ]) ?></strong><br>
                            <?= h($job->addressLine()) ?>
                            <span class="dash__preview"><?= h($job->preview) ?></span>
                        </td>
                        <td>
                            <span class="dash__badge dash__badge--<?= h($job->status) ?>">
                                <?= h(ucwords(str_replace('_', ' ', $job->status))) ?>
                            </span>
                        </td>
                        <td><?= h($assignment?->assigning_admin?->admin_username ?? __('Not assigned')) ?></td>
                        <td>
                            <?= h($assignment?->plumber?->admin_username ?? __('Not assigned')) ?><br>
                            <?= h($assignment?->vehicle?->registration_number ?? __('No vehicle recorded')) ?>
                        </td>
                        <td>
                            <?= h($assignment?->scheduled_start?->setTimezone($tz)->format('d M Y, g:ia')
                                ?? __('Not scheduled')) ?><br>
                            <?= h($assignment?->finished_at?->setTimezone($tz)->format('d M Y, g:ia')
                                ?? __('Not finished')) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($count === 0) : ?>
                    <tr><td colspan="6" class="dash__empty"><?= __('No jobs match this status.') ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="paginator dash__paginator">
        <ul class="pagination">
            <?= $this->Paginator->prev('< ' . __('previous')) ?>
            <?= $this->Paginator->numbers() ?>
            <?= $this->Paginator->next(__('next') . ' >') ?>
        </ul>
        <p><?= $this->Paginator->counter(__('Page {{page}} of {{pages}}, showing {{current}} of {{count}} jobs')) ?></p>
    </div>
</div>
