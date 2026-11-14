<?php
/**
 * Assign a plumber and a vehicle to an approved job request.
 *
 * Shared by the job page (where it appears as the next stage once compliance
 * is cleared) and the standalone assign page, so there is one form to keep in
 * step with what assign() validates.
 *
 * Three steps: when, which plumber, which vehicle. Plumbers and vehicles are
 * radio cards rather than drop-downs so their availability sits right on the
 * choice. As soon as a start time and duration are set, job-availability.js
 * asks JobRequests::availability() who is free for exactly that visit, badges
 * every card, and disables anyone who is not free. assign() checks all of it
 * again on submit, so the cards are guidance and the server remains the
 * safeguard. Without JavaScript the cards still work as plain radio buttons.
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\JobRequest $job
 * @var array<int, string> $plumbers
 * @var array<int, string> $vehicleOptions
 * @var \Cake\Datasource\EntityInterface|null $assignment The current allocation, when it is being changed.
 */

use App\Validation\BusinessVisitTime;

$zone = BusinessVisitTime::timezone();

// When an existing allocation is being changed, the form starts on what it
// is now. These are defaults only: anything the office has just typed (for
// instance after a clash was reported) takes priority.
$assignment ??= null;
$changing = $assignment !== null;
$defaults = ['start' => null, 'hours' => 1, 'plumber' => null, 'vehicle' => null];
if ($changing) {
    $defaults = [
        'start' => $assignment->scheduled_start->setTimezone($zone)->format('Y-m-d\TH:i'),
        'hours' => max(1, min(8, (int)round(
            ($assignment->scheduled_end->getTimestamp() - $assignment->scheduled_start->getTimestamp()) / 3600,
        ))),
        'plumber' => $assignment->plumber_admin_id,
        'vehicle' => $assignment->vehicle_id,
    ];
}
$request = $this->getRequest();
$value = static fn(string $field, mixed $default): string => (string)($request->getData($field) ?? $default);
$startValue = $value('scheduled_start', $defaults['start']);
$hoursValue = max(1, min(8, (int)$value('duration_hours', $defaults['hours'])));
$plumberValue = $value('plumber_admin_id', $defaults['plumber']);
$vehicleValue = $value('vehicle_id', $defaults['vehicle']);

// The customer's preference, offered as a one-click start time when it is a
// usable weekday slot that is still in the future.
$preferred = $job->preferred_datetime?->setTimezone($zone);
$preferredValue = null;
if ($preferred !== null) {
    $candidate = $preferred->format('Y-m-d\TH:i');
    if (BusinessVisitTime::endForDuration(BusinessVisitTime::parseForm($candidate), '1') !== null) {
        $preferredValue = $candidate;
    }
}
$now = new DateTimeImmutable('now', $zone);

$initials = static function (string $name): string {
    $parts = preg_split('/[^A-Za-z0-9]+|(?<=[a-z])(?=[A-Z])/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [$name];

    return strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : substr($parts[0], 1, 1)));
};

$this->Html->css('assign-form', ['block' => true]);
$this->Html->script('job-availability', ['block' => true]);
?>
<?= $this->Form->create(null, [
    'url' => ['controller' => 'JobRequests', 'action' => 'assign', $job->id],
    'class' => 'assign-form',
    'data-availability-url' => $this->Url->build([
        'controller' => 'JobRequests', 'action' => 'availability',
    ]),
    // Tells the availability check to ignore this job's own current booking.
    'data-job-id' => $changing ? $job->id : null,
]) ?>

<section class="assign-step" aria-labelledby="assign-step-when">
    <h3 class="assign-step__title" id="assign-step-when">
        <span class="assign-step__number" aria-hidden="true">1</span><?= __('Visit time') ?>
    </h3>
    <div class="assign-when">
        <div class="assign-field">
            <label for="scheduled-start"><?= __('Start (Melbourne time)') ?></label>
            <input type="datetime-local" name="scheduled_start" id="scheduled-start" required
                step="60" value="<?= h($startValue) ?>"
                min="<?= h($now->format('Y-m-d\TH:i')) ?>">
            <?php if ($preferred !== null) : ?>
                <p class="assign-preferred">
                    <?= __('Customer prefers {0}', h($preferred->format('D j M, g:ia'))) ?>
                    <?php if ($preferredValue !== null) : ?>
                        <button type="button" class="assign-preferred__use"
                            data-use-start="<?= h($preferredValue) ?>"><?= __('Use this time') ?></button>
                    <?php else : ?>
                        <span class="assign-preferred__note"><?= __('(outside visit hours or already past)') ?></span>
                    <?php endif; ?>
                </p>
            <?php else : ?>
                <p class="assign-preferred"><?= __('The customer did not give a preferred time.') ?></p>
            <?php endif; ?>
        </div>
        <div class="assign-field assign-duration">
            <label for="duration-hours">
                <?= __('Duration') ?>
                <output class="assign-duration__value" for="duration-hours" id="duration-label">
                    <?= h(__n('{0} hour', '{0} hours', $hoursValue, $hoursValue)) ?>
                </output>
            </label>
            <input type="range" name="duration_hours" id="duration-hours" min="1" max="8" step="1"
                value="<?= $hoursValue ?>" list="duration-marks" required>
            <datalist id="duration-marks">
                <?php foreach (range(1, 8) as $hours) : ?>
                    <option value="<?= $hours ?>"></option>
                <?php endforeach; ?>
            </datalist>
            <div class="assign-duration__scale" aria-hidden="true">
                <?php foreach (range(1, 8) as $hours) : ?>
                    <span><?= h($hours . 'h') ?></span>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <p class="assign-hint">
        <?= __('A weekday visit starting 08:00–16:00 that ends by 17:00.') ?>
    </p>
</section>

<p class="assign-status" id="availability-note" aria-live="polite">
    <?= __('Pick a start time to see who is free.') ?>
</p>

<section class="assign-step" aria-labelledby="assign-step-plumber">
    <h3 class="assign-step__title" id="assign-step-plumber">
        <span class="assign-step__number" aria-hidden="true">2</span><?= __('Assign plumber') ?>
    </h3>
    <div class="assign-cards" role="radiogroup" aria-labelledby="assign-step-plumber"
        data-availability-group="plumbers">
        <?php if ($plumbers === []) : ?>
            <p class="assign-hint"><?= __('There are no active plumbers.') ?></p>
        <?php endif; ?>
        <?php foreach ($plumbers as $id => $name) : ?>
            <label class="assign-card" data-option-id="<?= (int)$id ?>">
                <input type="radio" name="plumber_admin_id" value="<?= (int)$id ?>"
                    <?= (string)$id === $plumberValue ? 'checked' : '' ?> required>
                <span class="assign-card__icon" aria-hidden="true"><?= h($initials((string)$name)) ?></span>
                <span class="assign-card__body">
                    <span class="assign-card__name"><?= h($name) ?></span>
                    <span class="assign-card__status">
                        <span class="assign-card__badge" data-badge></span>
                        <span class="assign-card__detail" data-detail></span>
                    </span>
                </span>
            </label>
        <?php endforeach; ?>
    </div>
</section>

<section class="assign-step" aria-labelledby="assign-step-vehicle">
    <h3 class="assign-step__title" id="assign-step-vehicle">
        <span class="assign-step__number" aria-hidden="true">3</span><?= __('Assign vehicle') ?>
    </h3>
    <div class="assign-cards" role="radiogroup" aria-labelledby="assign-step-vehicle"
        data-availability-group="vehicles">
        <?php if ($vehicleOptions === []) : ?>
            <p class="assign-hint"><?= __('There are no vehicles in service.') ?></p>
        <?php endif; ?>
        <?php foreach ($vehicleOptions as $id => $label) : ?>
            <?php [$registration, $vehicleName] = array_pad(explode(' — ', (string)$label, 2), 2, ''); ?>
            <label class="assign-card" data-option-id="<?= (int)$id ?>">
                <input type="radio" name="vehicle_id" value="<?= (int)$id ?>"
                    <?= (string)$id === $vehicleValue ? 'checked' : '' ?> required>
                <span class="assign-card__icon assign-card__icon--vehicle" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="M3 7h11v9H3zM14 10h4l3 3v3h-7z"/>
                        <circle cx="7" cy="17.5" r="1.8"/><circle cx="17" cy="17.5" r="1.8"/></svg>
                </span>
                <span class="assign-card__body">
                    <span class="assign-card__name"><?= h($vehicleName !== '' ? $vehicleName : $registration) ?></span>
                    <span class="assign-card__status">
                        <span class="assign-card__badge" data-badge></span>
                        <span class="assign-card__detail" data-detail>
                            <?= h($vehicleName !== '' ? $registration : '') ?>
                        </span>
                    </span>
                </span>
            </label>
        <?php endforeach; ?>
    </div>
</section>

<div class="assign-summary">
    <p class="assign-summary__text" id="assign-summary" aria-live="polite">
        <?= __('Choose a time, a plumber and a vehicle.') ?>
    </p>
    <?= $this->Form->button($changing ? __('Save changes') : __('Assign job'), [
        'class' => 'dash__btn dash__btn--proceed',
    ]) ?>
</div>
<?= $this->Form->end() ?>
