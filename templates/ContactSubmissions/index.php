<?php
/**
 * Enquiry dashboard.
 *
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\ContactSubmission> $submissions
 * @var array{total:int, new:int, read:int, archived:int, last_7_days:int, today:int} $stats
 * @var array{q:?string, status:?string, from:?string, to:?string} $filters
 * @var list<string> $statuses
 */

use App\Model\Entity\ContactSubmission;
use App\Model\Table\ContactSubmissionsTable;

$this->assign('title', 'Enquiries');
?>
<?= $this->Html->css('dashboard') ?>

<?= $this->element('admin_sidebar') ?>

<div class="dash portal-content">

    <div class="dash__header">
        <h1 class="dash__title"><?= __('Contact enquiries') ?></h1>
        <div class="dash__header-actions">
            <?= $this->Html->link(
                __('Export CSV'),
                ['action' => 'export'] + array_filter($filters, fn($v) => $v !== null && $v !== ''),
                ['class' => 'dash__btn dash__btn--ghost'],
            ) ?>
        </div>
    </div>

    <?= $this->Flash->render() ?>

    <ul class="dash__stats">
        <li class="dash__stat">
            <span class="dash__stat-value"><?= $this->Number->format($stats['total']) ?></span>
            <span class="dash__stat-label"><?= __('Total') ?></span>
        </li>
        <li class="dash__stat dash__stat--accent">
            <span class="dash__stat-value"><?= $this->Number->format($stats['new']) ?></span>
            <span class="dash__stat-label"><?= __('New') ?></span>
        </li>
        <li class="dash__stat">
            <span class="dash__stat-value"><?= $this->Number->format($stats['read']) ?></span>
            <span class="dash__stat-label"><?= __('Read') ?></span>
        </li>
        <li class="dash__stat">
            <span class="dash__stat-value"><?= $this->Number->format($stats['replied']) ?></span>
            <span class="dash__stat-label"><?= __('Replied') ?></span>
        </li>
        <li class="dash__stat">
            <span class="dash__stat-value"><?= $this->Number->format($stats['archived']) ?></span>
            <span class="dash__stat-label"><?= __('Archived') ?></span>
        </li>
        <li class="dash__stat">
            <span class="dash__stat-value"><?= $this->Number->format($stats['today']) ?></span>
            <span class="dash__stat-label"><?= __('Today') ?></span>
        </li>
        <li class="dash__stat">
            <span class="dash__stat-value"><?= $this->Number->format($stats['last_7_days']) ?></span>
            <span class="dash__stat-label"><?= __('Last 7 days') ?></span>
        </li>
    </ul>

    <?= $this->Form->create(null, [
        'type' => 'get',
        'url' => ['action' => 'index'],
        'class' => 'dash__filters',
    ]) ?>
        <?= $this->Form->control('q', [
            'type' => 'text',
            'label' => __('Search'),
            'value' => $filters['q'],
            'placeholder' => __('Name, email, subject or message'),
            'maxlength' => 100,
        ]) ?>
        <?= $this->Form->control('status', [
            'type' => 'select',
            'label' => __('Status'),
            'options' => ContactSubmission::STATUS_LABELS,
            'empty' => __('All'),
            'value' => $filters['status'],
        ]) ?>
        <?php
        // Each end caps the other, so the picker greys out a backwards range
        // and the browser will not submit one. Set only when the opposite end
        // holds a date: an empty max="" makes the control invalid in some
        // browsers and then nothing submits at all.
        $fromValue = $filters['from'] ?? null;
        $toValue = $filters['to'] ?? null;

        $fromOptions = ['type' => 'date', 'label' => __('From'), 'value' => $fromValue];
        if (is_string($toValue) && $toValue !== '') {
            $fromOptions['max'] = $toValue;
        }

        $toOptions = ['type' => 'date', 'label' => __('To'), 'value' => $toValue];
        if (is_string($fromValue) && $fromValue !== '') {
            $toOptions['min'] = $fromValue;
        }
        ?>
        <?= $this->Form->control('from', $fromOptions) ?>
        <?= $this->Form->control('to', $toOptions) ?>
        <div class="dash__filter-actions">
            <?= $this->Form->button(__('Apply'), ['class' => 'dash__btn']) ?>
            <?= $this->Html->link(__('Clear'), ['action' => 'index'], ['class' => 'dash__btn dash__btn--ghost']) ?>
        </div>
    <?= $this->Form->end() ?>

    <div class="dash__table-wrap">
        <table class="dash__table dash__table--enquiries">
            <thead>
                <tr>
                    <th><?= $this->Paginator->sort('submitted_at', __('Received')) ?></th>
                    <th><?= $this->Paginator->sort('name', __('Name')) ?></th>
                    <th><?= $this->Paginator->sort('email', __('Email')) ?></th>
                    <th><?= __('Subject') ?></th>
                    <th><?= $this->Paginator->sort('status', __('Status')) ?></th>
                    <th class="dash__actions-col"><?= __('Actions') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php $rendered = 0; ?>
                <?php foreach ($submissions as $submission): ?>
                <?php $rendered++; ?>
                <tr class="<?= $submission->isUnread() ? 'is-unread' : '' ?>">
                    <td class="dash__nowrap">
                        <?php
                        // Stored as UTC, shown in the business timezone. Without
                        // the conversion a 5:38pm enquiry printed as 7:38am.
                        echo h($submission->submitted_at
                            ?->setTimezone(ContactSubmissionsTable::businessTimezone())
                            ->format('d M Y, g:ia'));
                        ?>
                    </td>
                    <td><?= h($submission->name) ?></td>
                    <td><?= h($submission->email) ?></td>
                    <td>
                        <?= h($submission->subject ?: '') ?>
                        <span class="dash__preview"><?= h($submission->preview) ?></span>
                    </td>
                    <td>
                        <span class="dash__badge dash__badge--<?= h($submission->status) ?>">
                            <?= h($submission->statusLabel()) ?>
                        </span>
                    </td>
                    <td class="dash__actions-col">
                        <?= $this->Html->link(__('Open'), ['action' => 'view', $submission->id], [
                            'class' => 'dash__btn dash__btn--small',
                        ]) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php
                // I count rows as I render them rather than testing the
                // paginated result itself. Casting it with (array) returns the
                // object's own properties, not the rows, so the count is
                // non-zero even on an empty page and this message never shows.
                ?>
                <?php if ($rendered === 0): ?>
                <tr>
                    <td colspan="5" class="dash__empty">
                        <?= __('No enquiries match these filters.') ?>
                    </td>
                </tr>
                <?php endif; ?>
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
            __('Page {{page}} of {{pages}}, showing {{current}} of {{count}} enquiries'),
        ) ?></p>
    </div>
</div>
