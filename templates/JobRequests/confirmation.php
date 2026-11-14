<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\JobRequest $jobRequest
 */
use App\Model\Entity\JobRequest;
use App\Model\Table\ContactSubmissionsTable;

$this->assign('title', 'Job request details');
$this->Html->css('dashboard', ['block' => true]);
$this->Html->css('job-requests', ['block' => true]);
$this->Html->script('live-status', ['block' => true]);
$reference = sprintf('REQ-%06d', $jobRequest->id);
$tz = ContactSubmissionsTable::businessTimezone();
$hasVisit = $jobRequest->customerHasConfirmedVisit();
$assignment = $jobRequest->job_assignment;
$hasFinalOutcome = in_array($jobRequest->status, [
    JobRequest::STATUS_COMPLETED,
    JobRequest::STATUS_UNABLE_TO_COMPLETE,
], true);
?>
<?= $this->element('customer_sidebar') ?>

<div class="dash portal-content">
    <div class="job-request confirmation content">
        <?php if ($jobRequest->status === JobRequest::STATUS_SUBMITTED) : ?>
            <p class="success-mark" aria-hidden="true">&#10003;</p>
            <h1>Your job request has been received</h1>
        <?php else : ?>
            <h1>Job request details</h1>
        <?php endif; ?>
        <p>Track this request here as its status changes.</p>

        <?php // Anything that can change while the page is open sits in a live region, kept fresh by live-status.js. ?>
        <div data-live-region="status" aria-live="polite">
        <?= $this->element('job_progress', ['job' => $jobRequest, 'forCustomer' => true]) ?>
        </div>

        <?php
        // Straight under the progress bar, so a finished job's rating is not
        // missed below the details. Between the two live regions on purpose:
        // they are swapped every half minute, which would clear a star the
        // customer had just chosen. The page reloads once by itself if the job
        // completes while it is open.
        ?>
        <?php if ($jobRequest->canBeRated()) : ?>
            <section class="rating" data-rating-section aria-labelledby="rating-heading">
                <h2 id="rating-heading">Rate this job</h2>
                <p>How happy were you with the work? Choose from 1 to 5 stars.</p>
                <?= $this->Form->create(null, ['url' => ['action' => 'rate', $jobRequest->id]]) ?>
                <fieldset class="rating__stars">
                    <legend class="rating__sr">Your rating, from 1 to 5 stars</legend>
                    <?php for ($stars = JobRequest::RATING_MAX; $stars >= JobRequest::RATING_MIN; $stars--) : ?>
                        <input type="radio" name="rating" id="rating-<?= $stars ?>" value="<?= $stars ?>" required>
                        <label for="rating-<?= $stars ?>">
                            <span class="rating__sr"><?= $stars ?> <?= $stars === 1 ? 'star' : 'stars' ?></span>
                        </label>
                    <?php endfor; ?>
                </fieldset>
                <?= $this->Form->button('Submit rating', ['class' => 'button']) ?>
                <?= $this->Form->end() ?>
            </section>
        <?php elseif ($jobRequest->isRated()) : ?>
            <section class="rating rating--done" data-rating-section aria-labelledby="rating-heading">
                <h2 id="rating-heading">Your rating</h2>
                <p>
                    You rated this job
                    <span class="rating__result" aria-hidden="true"><?= h($jobRequest->ratingStars()) ?></span>
                    (<?= h((string)(int)$jobRequest->customer_rating) ?> out of <?= JobRequest::RATING_MAX ?> stars)
                </p>
            </section>
        <?php endif; ?>

        <div data-live-region="summary" aria-live="polite">
        <dl class="request-summary">
            <dt>Reference</dt>
            <dd><?= h($reference) ?></dd>
            <dt>Status</dt>
            <dd>
                <span class="request-status request-status--<?= h($jobRequest->status) ?>">
                    <?= h($jobRequest->customerStatusLabel()) ?>
                </span>
                <span class="request-status-message"><?= h($jobRequest->customerStatusMessage()) ?></span>
            </dd>
            <dt>Preferred service time (not confirmed)</dt>
            <dd><?= h($jobRequest->preferred_datetime->setTimezone($tz)->format('d M Y, g:ia')) ?></dd>
            <?php if ($hasVisit) : ?>
                <dt>Confirmed visit</dt>
                <dd>
                    <?= h($assignment->scheduled_start->setTimezone($tz)->format('d M Y, g:ia')) ?> –
                    <?= h($assignment->scheduled_end->setTimezone($tz)->format('d M Y, g:ia')) ?>
                    (Melbourne time)
                </dd>
                <?php // The plumber's login name is internal, so the customer only hears that one is assigned. ?>
                <dt>Your plumber</dt>
                <dd>Assigned to your visit</dd>
            <?php endif; ?>
            <dt>Service address</dt>
            <dd>
                <?= h($jobRequest->street_address) ?>,
                <?= h($jobRequest->suburb) ?> <?= h($jobRequest->state) ?> <?= h($jobRequest->postcode) ?>
            </dd>
            <dt>Job details</dt>
            <dd><?= nl2br(h($jobRequest->job_description)) ?></dd>
            <?php if ($hasFinalOutcome) : ?>
                <dt>Outcome</dt>
                <dd><?= h($jobRequest->customerStatusLabel()) ?></dd>
            <?php endif; ?>
        </dl>
        </div>

        <?= $this->Html->link('My job requests', '/job-requests', ['class' => 'button']) ?>
        <?= $this->Html->link('Return to home', '/', ['class' => 'button button-outline']) ?>
    </div>
</div>
