<?php
/**
 * Audit trail.
 *
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\AuditLog> $entries
 * @var array{action:?string, user:?string} $filters
 * @var array<string, string> $actions
 */

use App\Model\Table\ContactSubmissionsTable;

$this->assign('title', 'Audit trail');

$tz = ContactSubmissionsTable::businessTimezone();
?>
<?= $this->Html->css('dashboard') ?>

<?= $this->element('admin_sidebar') ?>

<div class="dash portal-content">

    <div class="dash__header">
        <h1 class="dash__title"><?= __('Audit trail') ?></h1>
    </div>

    <?= $this->Flash->render() ?>

    <p class="dash__card-sub">
        <?= __('Every privileged action, with who did it and when.') ?>
        <?= __('This page is read only: entries cannot be edited or removed through the application.') ?>
    </p>

    <?= $this->Form->create(null, [
        'type' => 'get',
        'url' => ['action' => 'index'],
        'class' => 'dash__filters',
    ]) ?>
        <?= $this->Form->control('action', [
            'type' => 'select',
            'label' => __('Action'),
            'options' => $actions,
            'empty' => __('All'),
            'value' => $filters['action'],
        ]) ?>
        <?= $this->Form->control('user', [
            'type' => 'text',
            'label' => __('Admin id'),
            'value' => $filters['user'],
            'maxlength' => 10,
        ]) ?>
        <div class="dash__filter-actions">
            <?= $this->Form->button(__('Apply'), ['class' => 'dash__btn']) ?>
            <?= $this->Html->link(__('Clear'), ['action' => 'index'], ['class' => 'dash__btn dash__btn--ghost']) ?>
        </div>
    <?= $this->Form->end() ?>

    <div class="dash__table-wrap">
        <table class="dash__table">
            <thead>
                <tr>
                    <th><?= $this->Paginator->sort('created', __('When')) ?></th>
                    <th><?= __('Admin') ?></th>
                    <th><?= $this->Paginator->sort('action', __('Action')) ?></th>
                    <th><?= __('Record') ?></th>
                    <th><?= __('Detail') ?></th>
                    <th><?= __('IP') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php $rendered = 0; ?>
                <?php foreach ($entries as $entry): ?>
                <?php $rendered++; ?>
                <tr>
                    <td class="dash__nowrap">
                        <?= h($entry->created?->setTimezone($tz)->format('d M Y, g:ia')) ?>
                    </td>
                    <td>
                        <?php if ($entry->user_id !== null): ?>
                            <?= __('Admin #{0}', h((string)$entry->user_id)) ?>
                        <?php else: ?>
                            <?php
                            // A null user_id means the action ran without a
                            // signed-in identity. That is not a gap in the
                            // trail, it is a fact worth seeing plainly.
                            ?>
                            <?= __('Not signed in') ?>
                        <?php endif; ?>
                    </td>
                    <td><?= h($entry->action) ?></td>
                    <td>
                        <?php if ($entry->entity_type !== null): ?>
                            <?= h($entry->entity_type) ?>
                            <?php if ($entry->entity_id !== null): ?>
                                #<?= h($entry->entity_id) ?>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php
                        // The detail column holds JSON written by the app, but
                        // some of it originates in a query string the user
                        // controls, so it is escaped like anything else.
                        ?>
                        <span class="dash__preview"><?= h($entry->detail) ?></span>
                    </td>
                    <td class="dash__nowrap"><?= h($entry->ip_address) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if ($rendered === 0): ?>
                <tr>
                    <td colspan="6" class="dash__empty">
                        <?= __('No audit entries match these filters.') ?>
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
            __('Page {{page}} of {{pages}}, showing {{current}} of {{count}} entries'),
        ) ?></p>
    </div>
</div>
