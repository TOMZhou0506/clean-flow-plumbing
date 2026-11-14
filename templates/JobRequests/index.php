<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\JobRequest> $jobRequests
 */
use App\Model\Table\ContactSubmissionsTable;

$this->assign('title', 'My job requests');
$this->Html->css('dashboard', ['block' => true]);
$this->Html->css('job-requests', ['block' => true]);
$this->Html->script('live-status', ['block' => true]);
$tz = ContactSubmissionsTable::businessTimezone();
?>
<?= $this->element('customer_sidebar') ?>

<div class="dash portal-content">
    <div class="job-request job-request-list content">
        <div class="job-request-list__header">
            <div>
                <p class="eyebrow">Customer portal</p>
                <h1>My job requests</h1>
                <p>Review the plumbing requests you have submitted to Clean Flow.</p>
            </div>
            <?= $this->Html->link('Request service', '/job-requests/add', ['class' => 'button']) ?>
        </div>

        <?php if ($jobRequests->items()->isEmpty()) : ?>
            <div class="job-request-list__empty">
                <h2>No job requests yet</h2>
                <p>When you submit a request, its reference and status will appear here.</p>
                <?= $this->Html->link('Submit your first request', '/job-requests/add', ['class' => 'button']) ?>
            </div>
        <?php else : ?>
            <div class="table-responsive" data-live-region="requests" aria-live="polite">
                <table class="job-request-list__table">
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>Submitted</th>
                            <th>Service address</th>
                            <th>Preferred time (not confirmed)</th>
                            <th>Status</th>
                            <th><span class="show-for-sr">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($jobRequests as $jobRequest) : ?>
                            <tr>
                                <td><strong><?= h(sprintf('REQ-%06d', $jobRequest->id)) ?></strong></td>
                                <td><?= h($jobRequest->created->setTimezone($tz)->format('d M Y')) ?></td>
                                <td>
                                    <?= h($jobRequest->street_address) ?><br>
                                    <small>
                                        <?= h($jobRequest->suburb) ?>
                                        <?= h($jobRequest->state) ?>
                                        <?= h($jobRequest->postcode) ?>
                                    </small>
                                </td>
                                <td><?= h($jobRequest->preferred_datetime->setTimezone($tz)
                                    ->format('d M Y, g:ia')) ?></td>
                                <td>
                                    <span class="request-status request-status--<?= h($jobRequest->status) ?>">
                                        <?= h($jobRequest->customerStatusLabel()) ?>
                                    </span>
                                    <small class="job-request-list__status-help">
                                        <?= h($jobRequest->customerStatusMessage()) ?>
                                    </small>
                                    <?php if ($jobRequest->customerHasConfirmedVisit()) : ?>
                                        <small class="job-request-list__status-help">
                                            <?= __('Confirmed visit:') ?>
                                            <?= h($jobRequest->job_assignment->scheduled_start->setTimezone($tz)
                                                ->format('d M, g:ia')) ?>–<?=
                                                h($jobRequest->job_assignment->scheduled_end->setTimezone($tz)
                                                    ->format('g:ia'))
                                            ?>
                                        </small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= $this->Html->link(
                                        'View details',
                                        ['action' => 'confirmation', $jobRequest->id],
                                        ['class' => 'button button-outline'],
                                    ) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="paginator">
                <ul class="pagination">
                    <?= $this->Paginator->prev('Previous') ?>
                    <?= $this->Paginator->numbers() ?>
                    <?= $this->Paginator->next('Next') ?>
                </ul>
                <p><?= $this->Paginator->counter('Page {{page}} of {{pages}}') ?></p>
            </div>
        <?php endif; ?>
    </div>
</div>
