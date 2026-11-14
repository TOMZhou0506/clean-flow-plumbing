<?php
/**
 * The fleet, with what each vehicle is doing now and what is booked next.
 *
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\Vehicle> $vehicles
 * @var array<int, array{current: ?\Cake\Datasource\EntityInterface, upcoming: list<\Cake\Datasource\EntityInterface>}> $schedule
 * @var \DateTimeZone $timezone
 */

$this->assign('title', 'Vehicles');
?>
<?= $this->Html->css('dashboard') ?>
<?= $this->element('admin_sidebar') ?>
<div class="dash portal-content">
    <div class="dash__header">
        <h1 class="dash__title"><?= __('Vehicles') ?></h1>
        <div class="dash__header-actions">
            <?= $this->Html->link(__('Add vehicle'), ['action' => 'add'], ['class' => 'dash__btn']) ?>
        </div>
    </div>

    <?= $this->Flash->render() ?>
    <p><?= __('Inactive vehicles remain on this list so their records can be retained for job history.') ?></p>
    <p class="dash__card-sub">
        <?= __('A vehicle is free again as soon as its plumber marks the job completed.') ?>
    </p>
    <div class="dash__table-wrap">
        <table class="dash__table">
            <thead>
                <tr>
                    <th><?= __('Vehicle') ?></th>
                    <th><?= __('Registration') ?></th>
                    <th><?= __('Status') ?></th>
                    <th><?= __('Next booking') ?></th>
                    <th class="dash__actions-col"><?= __('Actions') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($vehicles as $vehicle) : ?>
                    <?php
                    $row = $schedule[$vehicle->id] ?? ['current' => null, 'upcoming' => []];
                    $use = $row['current'];
                    $next = $row['upcoming'][0] ?? null;
                    ?>
                    <tr>
                        <td><?= h($vehicle->label) ?></td>
                        <td><?= h($vehicle->registration_number) ?></td>
                        <td>
                            <?php if (!$vehicle->active) : ?>
                                <span class="dash__badge dash__badge--archived"><?= __('Unavailable') ?></span>
                            <?php elseif ($use !== null) : ?>
                                <span class="dash__badge dash__badge--reviewing"><?= __('In Use') ?></span>
                                <?= $this->element('vehicle_booking', [
                                    'assignment' => $use,
                                    'timezone' => $timezone,
                                ]) ?>
                            <?php else : ?>
                                <span class="dash__badge dash__badge--read"><?= __('Available') ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!$vehicle->active) : ?>
                                <span class="dash__card-sub">&mdash;</span>
                            <?php elseif ($next !== null) : ?>
                                <?= $this->element('vehicle_booking', [
                                    'assignment' => $next,
                                    'timezone' => $timezone,
                                ]) ?>
                            <?php else : ?>
                                <span class="dash__card-sub"><?= __('Nothing booked') ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="dash__actions-col">
                            <?= $this->Html->link(__('View'), ['action' => 'view', $vehicle->id], [
                                'class' => 'dash__btn dash__btn--small',
                            ]) ?>
                            <?= $this->Html->link(__('Edit'), ['action' => 'edit', $vehicle->id], [
                                'class' => 'dash__btn dash__btn--small dash__btn--ghost',
                            ]) ?>
                            <?php if ($vehicle->active) : ?>
                                <?= $this->Form->postLink(__('Deactivate'), [
                                    'action' => 'deactivate', $vehicle->id,
                                ], [
                                    'confirm' => __('Deactivate {0}? Its record will be kept.', $vehicle->label),
                                    'class' => 'dash__btn dash__btn--small dash__btn--ghost',
                                ]) ?>
                            <?php else : ?>
                                <?= $this->Form->postLink(__('Reactivate'), [
                                    'action' => 'reactivate', $vehicle->id,
                                ], ['class' => 'dash__btn dash__btn--small']) ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="paginator dash__paginator">
        <ul class="pagination">
            <?= $this->Paginator->prev(__('Previous')) ?>
            <?= $this->Paginator->numbers() ?>
            <?= $this->Paginator->next(__('Next')) ?>
        </ul>
        <p><?= $this->Paginator->counter(__('Page {{page}} of {{pages}}')) ?></p>
    </div>
</div>
