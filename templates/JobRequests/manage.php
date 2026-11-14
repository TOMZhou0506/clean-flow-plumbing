<?php
/**
 * Job requests, for the office.
 *
 * Deliberately the same shape as the enquiry dashboard: same filter bar, same
 * summary cards, same sortable headers, same unread highlighting. The office
 * asked for the two screens to work alike, and two lists that behave
 * differently would be worse than either on its own.
 *
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\JobRequest> $jobRequests
 * @var array{total:int, unread:int, submitted:int, approved:int, archived:int, today:int} $stats
 * @var list<string> $statuses
 * @var array<string, string|null> $filters
 */

use App\Model\Entity\JobRequest;
use App\Model\Table\ContactSubmissionsTable;

$this->assign('title', 'Job requests');

$tz = ContactSubmissionsTable::businessTimezone();

// Carried onto the export link so the download matches what is on screen.
$activeFilters = array_filter($filters, fn($v) => $v !== null && $v !== '');
?>
<?= $this->Html->css('dashboard') ?>

<?= $this->element('admin_sidebar') ?>

<div class="dash portal-content">
    <div class="dash__header">
        <h1 class="dash__title"><?= __('Job requests') ?></h1>
        <div class="dash__header-actions">
            <?= $this->Html->link(
                __('Export CSV'),
                ['action' => 'exportCsv'] + $activeFilters,
                ['class' => 'dash__btn dash__btn--ghost'],
            ) ?>
        </div>
    </div>

    <?= $this->Flash->render() ?>

    <?php if ($availabilityAlerts !== []) : ?>
        <div class="dash__card" role="status">
            <p><?= $this->Html->link(
                __('{0} new plumber availability update(s). View all notifications', count($availabilityAlerts)),
                ['controller' => 'PlumberAvailability', 'action' => 'index', '#' => 'alerts'],
               ) ?></p>
            <ul>
                <?php foreach (array_slice($availabilityAlerts, 0, 5) as $alert) : ?>
                    <?php
                    $period = $alert['period'];
                    $event = $alert['event'];
                    $anchor = $event->action === 'plumber.unavailable_cancelled'
                        ? 'alert-' . (int)$event->id : 'period-' . (int)$period->id;
                    ?>
                    <li><?= $this->Html->link(
                        $alert['plumber']->admin_username . ' — ' .
                            $period->unavailable_date->format('d M Y') . ' (' .
                            ($event->action === 'plumber.unavailable_cancelled'
                                ? __('withdrawn') : __('unavailable')) . ')',
                        ['controller' => 'PlumberAvailability', 'action' => 'index', '#' => $anchor],
                        ) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <ul class="dash__stats">
        <li class="dash__stat">
            <span class="dash__stat-value"><?= $this->Number->format($stats['total']) ?></span>
            <span class="dash__stat-label"><?= __('Total') ?></span>
        </li>
        <li class="dash__stat dash__stat--accent">
            <span class="dash__stat-value"><?= $this->Number->format($stats['unread']) ?></span>
            <span class="dash__stat-label"><?= __('Unread') ?></span>
        </li>
        <li class="dash__stat">
            <span class="dash__stat-value"><?= $this->Number->format($stats['submitted']) ?></span>
            <span class="dash__stat-label"><?= __('Submitted') ?></span>
        </li>
        <li class="dash__stat">
            <span class="dash__stat-value"><?= $this->Number->format($stats['approved']) ?></span>
            <span class="dash__stat-label"><?= __('Approved') ?></span>
        </li>
        <li class="dash__stat">
            <a href="<?= $this->Url->build(['action' => 'manage', '?' => ['status' => JobRequest::STATUS_UNABLE_TO_COMPLETE]]) ?>">
                <span class="dash__stat-value"><?= $this->Number->format($stats['unable_to_complete']) ?></span>
                <span class="dash__stat-label"><?= __('Unable to complete') ?></span>
            </a>
        </li>
        <li class="dash__stat">
            <span class="dash__stat-value"><?= $this->Number->format($stats['archived']) ?></span>
            <span class="dash__stat-label"><?= __('Archived') ?></span>
        </li>
        <li class="dash__stat">
            <span class="dash__stat-value"><?= $this->Number->format($stats['today']) ?></span>
            <span class="dash__stat-label"><?= __('Today') ?></span>
        </li>
    </ul>

    <?= $this->Form->create(null, [
        'type' => 'get',
        'url' => ['action' => 'manage'],
        'class' => 'dash__filters',
    ]) ?>
        <?= $this->Form->control('q', [
            'type' => 'text',
            'label' => __('Search'),
            'value' => $filters['q'] ?? null,
            'placeholder' => __('Address, suburb, postcode, phone or email'),
            'maxlength' => 100,
        ]) ?>
        <?= $this->Form->control('status', [
            'type' => 'select',
            'label' => __('Status'),
            'options' => array_combine($statuses, array_map(fn($status) => ucwords(str_replace('_', ' ', $status)), $statuses)),
            'empty' => __('All'),
            'value' => $filters['status'] ?? null,
        ]) ?>
        <?php
        // max on From and min on To, each pointing at the other's current
        // value, so the date picker greys out anything that would make the
        // range run backwards and the browser refuses to submit it.
        //
        // Only set when the other end actually holds a date, otherwise an
        // empty max="" makes the whole control invalid in some browsers and
        // nothing can be submitted at all.
        $fromValue = $filters['from'] ?? null;
        $toValue = $filters['to'] ?? null;

        $fromOptions = [
            'type' => 'date',
            'label' => __('From'),
            'value' => $fromValue,
        ];
        if (is_string($toValue) && $toValue !== '') {
            $fromOptions['max'] = $toValue;
        }

        $toOptions = [
            'type' => 'date',
            'label' => __('To'),
            'value' => $toValue,
        ];
        if (is_string($fromValue) && $fromValue !== '') {
            $toOptions['min'] = $fromValue;
        }
        ?>
        <?= $this->Form->control('from', $fromOptions) ?>
        <?= $this->Form->control('to', $toOptions) ?>
        <div class="dash__filter-actions">
            <?= $this->Form->button(__('Apply'), ['class' => 'dash__btn']) ?>
            <?= $this->Html->link(__('Clear'), ['action' => 'manage'], [
                'class' => 'dash__btn dash__btn--ghost',
            ]) ?>
        </div>
    <?= $this->Form->end() ?>

    <div class="dash__table-wrap">
        <table class="dash__table dash__table--job-requests">
            <thead>
                <tr>
                    <th><?= $this->Paginator->sort('created', __('Received')) ?></th>
                    <th><?= __('Customer') ?></th>
                    <th><?= $this->Paginator->sort('suburb', __('Address')) ?></th>
                    <th><?= $this->Paginator->sort('preferred_datetime', __('Preferred')) ?></th>
                    <th><?= $this->Paginator->sort('status', __('Status')) ?></th>
                    <th class="dash__actions-col"><?= __('Actions') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php $rendered = 0; ?>
                <?php foreach ($jobRequests as $jobRequest): ?>
                <?php $rendered++; ?>
                <?php
                // Unread rows carry a yellow edge and bolder text. The
                // status column still spells the state out, so this does
                // not rely on colour alone.
                ?>
                <tr class="<?= $jobRequest->isUnread() ? 'is-unread' : '' ?>">
                    <td class="dash__nowrap">
                        <?= h($jobRequest->created?->setTimezone($tz)->format('d M Y, g:ia')) ?>
                    </td>
                    <td>
                        <?php if ($jobRequest->customer !== null): ?>
                            <span class="dash__clamp" title="<?= h($jobRequest->customer->cust_email) ?>">
                                <?= h($jobRequest->customer->cust_email) ?>
                            </span>
                        <?php else: ?>
                            <?= __('Account removed') ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="dash__clamp" title="<?= h($jobRequest->street_address) ?>">
                            <?= h($jobRequest->street_address) ?>
                        </span>
                        <span class="dash__preview dash__clamp" title="<?= h($jobRequest->preview) ?>">
                            <?= h($jobRequest->preview) ?>
                        </span>
                    </td>
                    <td class="dash__nowrap">
                        <?= h($jobRequest->preferred_datetime
                            ?->setTimezone($tz)
                            ->format('d M Y, g:ia') ?: __('No preference')) ?>
                    </td>
                    <td>
                        <span class="dash__badge dash__badge--<?= h($jobRequest->status) ?>">
                            <?= h($jobRequest->officeStatusLabel()) ?>
                        </span>
                    </td>
                    <td class="dash__actions-col">
                        <?= $this->Html->link(__('Open'), [
                            'action' => 'manageView',
                            $jobRequest->id,
                        ], ['class' => 'dash__btn dash__btn--small']) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php
                // Counted as rows are rendered rather than by inspecting
                // the paginated result. Casting it with (array) returns the
                // object's own properties, not the rows, so the count is
                // non-zero even on an empty page.
                ?>
                <?php if ($rendered === 0): ?>
                <tr>
                    <td colspan="6" class="dash__empty">
                        <?= __('No job requests match these filters.') ?>
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
            __('Page {{page}} of {{pages}}, showing {{current}} of {{count}} job requests'),
        ) ?></p>
    </div>
</div>
