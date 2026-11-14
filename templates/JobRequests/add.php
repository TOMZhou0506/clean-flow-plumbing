<?php
use App\Model\Table\JobRequestsTable;
use App\Service\Address\PhotonClient;

/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\JobRequest $jobRequest
 * @var array<string, string> $states
 * @var string $preferredDate
 * @var string $preferredTime
 * @var array<string, string> $preferredTimeOptions
 */
$this->assign('title', 'Request plumbing work');
$this->Html->css('dashboard', ['block' => true]);
$this->Html->css('job-requests', ['block' => true]);
$this->Html->script('form-enhancements', ['block' => true]);
$this->Html->script('address-lookup', ['block' => true]);
?>
<?= $this->element('customer_sidebar') ?>

<div class="dash portal-content">
    <div class="job-request form content">
        <p class="eyebrow">Online job request</p>
        <h1>Tell us what you need</h1>
        <p>
            Submit the details below and our team will assess your request.
            Your preferred time is not confirmed until we contact you.
        </p>

        <?php
        // novalidate + data-validate-all hands validation to form-enhancements.js,
        // which lists every problem at once instead of letting the browser stop at
        // the first one. JobRequestsTable still re-checks everything on save.
        ?>
        <?= $this->Form->create($jobRequest, [
            'novalidate' => true,
            'data-validate-all' => true,
        ]) ?>
        <fieldset>
            <legend>Service address</legend>
            <?php
            // data-address-lookup is what address-lookup.js attaches to, and
            // the two URLs are handed over rather than written into the script
            // so routes.php stays the one place the paths are decided.
            //
            // The field is an ordinary required text input underneath all of
            // that. If the script does not load or the lookup is switched off,
            // this is simply a text box and the form works as it did before.
            // With no getAddress() key, the free Photon search answers instead.
            ?>
            <?= $this->Form->control('street_address', [
                'label' => 'Street address',
                'required' => true,
                'maxlength' => 255,
                'autocomplete' => 'street-address',
                'data-address-lookup' => true,
                'data-suggest-url' => $this->Url->build('/job-requests/address/suggest'),
                'data-resolve-url' => $this->Url->build('/job-requests/address/resolve'),
                // With Photon as the provider the browser asks it directly, which
                // is what makes the suggestions keep up with typing. The two URLs
                // above stay as the route it falls back to.
                'data-photon-url' => PhotonClient::isActive() ? PhotonClient::searchUrl() : null,
                'data-photon-bbox' => PhotonClient::isActive() ? PhotonClient::bounds() : null,
                'data-photon-state' => PhotonClient::isActive() ? PhotonClient::state() : null,
            ]) ?>
            <small>Start typing and pick your address, or type it in yourself.</small>
            <div class="address-grid">
                <?= $this->Form->control('suburb', [
                    'required' => true,
                    'maxlength' => 100,
                    'autocomplete' => 'address-level2',
                ]) ?>
                <?= $this->Form->control('state', [
                    'type' => 'select',
                    'options' => $states,
                    'empty' => 'Select',
                    'required' => true,
                    'autocomplete' => 'address-level1',
                ]) ?>
                <?= $this->Form->control('postcode', [
                    'required' => true,
                    'maxlength' => 4,
                    'pattern' => '\\d{4}',
                    'inputmode' => 'numeric',
                    'autocomplete' => 'postal-code',
                    'data-digits-only' => true,
                    'data-format-message' => 'Please enter a four-digit Australian postcode.',
                ]) ?>
            </div>
        </fieldset>

        <fieldset>
            <legend>Job details</legend>
            <?= $this->Form->control('job_description', [
                'label' => 'What plumbing work is required?',
                'type' => 'textarea',
                'rows' => 7,
                'required' => true,
                'minlength' => 5,
                'maxlength' => 500,
                'data-char-counter' => true,
                'data-meaningful-text' => true,
                // The same wording as the server's rules, one message for each problem.
                'data-validity-message' => JobRequestsTable::DESCRIPTION_EMPTY,
                'data-short-message' => JobRequestsTable::DESCRIPTION_TOO_SHORT,
                'data-meaningful-message' => JobRequestsTable::DESCRIPTION_NOT_MEANINGFUL,
            ]) ?>
            <?= $this->Form->control('preferred_date', [
                'label' => 'Preferred service date',
                'type' => 'date',
                'required' => true,
                'min' => substr($preferredMin, 0, 10),
                'value' => $preferredDate,
                'data-preferred-date' => true,
                'data-business-time-zone' => 'Australia/Melbourne',
                'data-format-message' => 'Choose a future weekday between 08:00 and 16:00 (Melbourne time).',
            ]) ?>
            <?= $this->Form->control('preferred_time', [
                'label' => 'Preferred start time (Melbourne time)',
                'type' => 'select',
                'options' => $preferredTimeOptions,
                'empty' => 'Choose a time',
                'required' => true,
                'value' => $preferredTime,
                'data-preferred-time' => true,
                'data-format-message' => 'Choose a future weekday between 08:00 and 16:00 (Melbourne time).',
            ]) ?>
            <small>
                Choose a future weekday and a start time from 08:00–16:00 (Melbourne time).
                The working day ends at 17:00.
                This is a preference only; we will confirm availability.
            </small>
            <?php if ($jobRequest->getError('preferred_datetime')) : ?>
                <div class="input error">
                    <div class="error-message">
                        <?= h(implode(' ', $jobRequest->getError('preferred_datetime'))) ?>
                    </div>
                </div>
            <?php endif; ?>
            <?= $this->Form->control('contact_phone', [
                'label' => 'Contact phone for this job',
                'type' => 'tel',
                'required' => true,
                'maxlength' => 12,
                'autocomplete' => 'tel',
                'inputmode' => 'numeric',
                'placeholder' => '0412 345 678 or 03 9123 4567',
                'pattern' => '(?:04[0-9]{2} [0-9]{3} [0-9]{3}|03 [0-9]{4} [0-9]{4})',
                'data-au-phone' => true,
                'data-format-message' => 'Use a 03 landline (03 9123 4567) or 04 mobile (0412 345 678).',
            ]) ?>
            <small>Enter a 03 landline (03 9123 4567) or 04 mobile (0412 345 678).</small>
            <?= $this->Form->control('access_instructions', [
                'label' => 'Site access instructions (optional)',
                'type' => 'textarea',
                'rows' => 4,
                'maxlength' => 2000,
                'placeholder' => 'Parking, gate codes, pets, or how to enter the property',
            ]) ?>
        </fieldset>

        <?= $this->Form->button('Submit a job request', ['class' => 'button']) ?>
        <?= $this->Form->end() ?>
    </div>
</div>
