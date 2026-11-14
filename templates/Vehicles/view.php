<?php
/**
 * One vehicle: what it is doing now, what is booked for it, and what it has
 * done. Only job references and plumbers are shown, never customer details.
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Vehicle $vehicle
 * @var \Cake\Datasource\EntityInterface|null $currentUse
 * @var list<\Cake\Datasource\EntityInterface> $upcoming
 * @var list<\Cake\Datasource\EntityInterface> $history
 * @var \DateTimeZone $timezone
 */

$this->assign('title', 'Vehicle');
?>
<?= $this->Html->css('dashboard') ?>
<?= $this->element('admin_sidebar') ?>
<div class="dash portal-content">
    <div class="dash__header">
        <h1 class="dash__title"><?= h($vehicle->label) ?></h1>
        <div class="dash__header-actions">
            <?= $this->Html->link(__('All vehicles'), ['action' => 'index'], [
                'class' => 'dash__btn dash__btn--ghost',
            ]) ?>
            <?= $this->Html->link(__('Edit'), ['action' => 'edit', $vehicle->id], [
                'class' => 'dash__btn',
            ]) ?>
        </div>
    </div>

    <?= $this->Flash->render() ?>
    <div class="dash__card">
        <dl class="dash__meta">
            <dt><?= __('Registration') ?></dt><dd><?= h($vehicle->registration_number) ?></dd>
            <dt><?= __('Status now') ?></dt>
            <dd>
                <?php if (!$vehicle->active) : ?>
                    <span class="dash__badge dash__badge--archived"><?= __('Unavailable') ?></span>
                <?php elseif ($currentUse !== null) : ?>
                    <span class="dash__badge dash__badge--reviewing"><?= __('In Use') ?></span>
                    <?= $this->element('vehicle_booking', ['assignment' => $currentUse, 'timezone' => $timezone]) ?>
                <?php else : ?>
                    <span class="dash__badge dash__badge--read"><?= __('Available') ?></span>
                    <span class="dash__card-sub"><?= __('Free until its next booking.') ?></span>
                <?php endif; ?>
            </dd>
            <dt><?= __('Notes') ?></dt><dd><?= nl2br(h($vehicle->notes ?: __('None'))) ?></dd>
        </dl>
        <?php if ($vehicle->active) : ?>
            <?= $this->Form->postLink(__('Deactivate vehicle'), [
                'action' => 'deactivate', $vehicle->id,
            ], [
                'confirm' => __('Deactivate {0}? Its record will be kept.', $vehicle->label),
                'class' => 'dash__btn dash__btn--ghost',
            ]) ?>
        <?php else : ?>
            <?= $this->Form->postLink(__('Reactivate vehicle'), [
                'action' => 'reactivate', $vehicle->id,
            ], ['class' => 'dash__btn']) ?>
        <?php endif; ?>
    </div>

    <div class="dash__card">
        <div class="dash__card-head">
            <div>
                <h2 class="dash__card-title"><?= __('Booked jobs') ?></h2>
                <p class="dash__card-sub"><?= __('Jobs still to come, with who has the vehicle and when.') ?></p>
            </div>
        </div>
        <?php if ($upcoming === []) : ?>
            <p class="dash__card-sub"><?= __('Nothing is booked for this vehicle.') ?></p>
        <?php else : ?>
            <div class="dash__table-wrap">
                <table class="dash__table">
                    <thead>
                        <tr>
                            <th><?= __('Job') ?></th>
                            <th><?= __('Plumber') ?></th>
                            <th><?= __('Between') ?></th>
                            <th><?= __('Status') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($upcoming as $booking) : ?>
                            <?php
                            $from = $booking->scheduled_start->setTimezone($timezone);
                            $to = $booking->scheduled_end->setTimezone($timezone);
                            ?>
                            <tr>
                                <td>
                                    <?= $this->Html->link($booking->job_request->reference(), [
                                        'controller' => 'JobRequests',
                                        'action' => 'manageView',
                                        $booking->job_request->id,
                                    ]) ?>
                                </td>
                                <td><?= h($booking->plumber->admin_username ?? __('Unknown plumber')) ?></td>
                                <td class="dash__nowrap">
                                    <?= h($from->format('D j M, g:ia')) ?>–<?= h($to->format('g:ia')) ?>
                                </td>
                                <td>
                                    <span class="dash__badge dash__badge--<?= h($booking->job_request->status) ?>">
                                        <?= h($booking->job_request->officeStatusLabel()) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="dash__card">
        <div class="dash__card-head">
            <div>
                <h2 class="dash__card-title"><?= __('Recent use') ?></h2>
                <p class="dash__card-sub">
                    <?= __('Finished jobs. The vehicle was free again once each one ended.') ?>
                </p>
            </div>
        </div>
        <?php if ($history === []) : ?>
            <p class="dash__card-sub"><?= __('This vehicle has not finished any jobs yet.') ?></p>
        <?php else : ?>
            <div class="dash__table-wrap">
                <table class="dash__table">
                    <thead>
                        <tr>
                            <th><?= __('Job') ?></th>
                            <th><?= __('Plumber') ?></th>
                            <th><?= __('Scheduled') ?></th>
                            <th><?= __('Actually used') ?></th>
                            <th><?= __('Outcome') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $past) : ?>
                            <?php
                            $from = $past->scheduled_start->setTimezone($timezone);
                            $to = $past->scheduled_end->setTimezone($timezone);
                            ?>
                            <tr>
                                <td>
                                    <?= $this->Html->link($past->job_request->reference(), [
                                        'controller' => 'JobRequests',
                                        'action' => 'manageView',
                                        $past->job_request->id,
                                    ]) ?>
                                </td>
                                <td><?= h($past->plumber->admin_username ?? __('Unknown plumber')) ?></td>
                                <td class="dash__nowrap">
                                    <?= h($from->format('D j M, g:ia')) ?>–<?= h($to->format('g:ia')) ?>
                                </td>
                                <td class="dash__nowrap">
                                    <?php if ($past->started_at !== null && $past->finished_at !== null) : ?>
                                        <?= h($past->started_at->setTimezone($timezone)->format('D j M, g:ia')) ?>–<?=
                                            h($past->finished_at->setTimezone($timezone)->format('g:ia'))
                                        ?>
                                    <?php else : ?>
                                        <span class="dash__card-sub">&mdash;</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="dash__badge dash__badge--<?= h($past->job_request->status) ?>">
                                        <?= h($past->job_request->officeStatusLabel()) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
