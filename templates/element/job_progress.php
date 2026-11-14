<?php
/**
 * Progress bar for one job request, shown on the office job page.
 *
 * A thin bar fills as stages are completed, with a labelled marker for each
 * stage below it. The stages and their states come from
 * JobRequest::progressSteps(), so the wording and the order live in one place.
 *
 * Colour is never the only signal: each marker carries a tick, a number or an
 * exclamation mark, and the current stage is announced with aria-current.
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\JobRequest $job
 * @var bool $forCustomer Use the customer-facing step wording.
 */

$steps = $job->progressSteps(!empty($forCustomer));
$total = count($steps);
$done = 0;
$currentLabel = '';
$stopped = false;
foreach ($steps as $index => $step) {
    if ($step['state'] === 'done') {
        $done++;
    } elseif ($step['state'] === 'current' || $step['state'] === 'stopped') {
        $currentLabel = $step['label'];
        $stopped = $step['state'] === 'stopped';
    }
}
$percent = (int)round($done / $total * 100);
if ($done === $total) {
    $currentLabel = __('All stages complete');
}
?>
<section class="job-progress<?= $stopped ? ' job-progress--stopped' : '' ?>" aria-label="<?= __('Job progress') ?>">
    <div class="job-progress__summary">
        <span class="job-progress__now"><?= h($currentLabel) ?></span>
        <span class="job-progress__percent"><?= $percent ?>% <?= __('complete') ?></span>
    </div>
    <div
        class="job-progress__bar"
        role="progressbar"
        aria-valuemin="0"
        aria-valuemax="100"
        aria-valuenow="<?= $percent ?>"
        aria-label="<?= __('Job progress') ?>"
    >
        <span class="job-progress__fill" style="width: <?= $percent ?>%"></span>
    </div>
    <ol class="job-progress__steps">
        <?php foreach ($steps as $index => $step) : ?>
            <li
                class="job-progress__step is-<?= h($step['state']) ?>"
                <?= in_array($step['state'], ['current', 'stopped'], true) ? 'aria-current="step"' : '' ?>
            >
                <span class="job-progress__marker" aria-hidden="true">
                    <?php if ($step['state'] === 'done') : ?>
                        <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                    <?php elseif ($step['state'] === 'stopped') : ?>
                        !
                    <?php else : ?>
                        <?= $index + 1 ?>
                    <?php endif; ?>
                </span>
                <span class="job-progress__label"><?= h($step['label']) ?></span>
            </li>
        <?php endforeach; ?>
    </ol>
</section>
