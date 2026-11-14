<?php
/**
 * A map of a job's address with links out to Google Maps.
 *
 * The map is Google's keyless embed, so there is no API key to manage. The
 * iframe itself cannot be clicked through, so a transparent link sits over it
 * and opens the address in Google Maps (the Maps app on a phone).
 *
 * @var \App\View\AppView $this
 * @var string $address One-line street address, e.g. "22 Bellarine Highway, Geelong VIC 3220".
 */
$query = $address . ', Australia';
$embedUrl = 'https://maps.google.com/maps?' . http_build_query(['q' => $query, 'z' => 15, 'output' => 'embed']);
$openUrl = 'https://www.google.com/maps/search/?' . http_build_query(['api' => 1, 'query' => $query]);
$directionsUrl = 'https://www.google.com/maps/dir/?' . http_build_query(['api' => 1, 'destination' => $query]);
?>
<div class="dash__card job-map">
    <div class="dash__card-head">
        <h2 class="dash__card-title"><?= __('Location') ?></h2>
    </div>
    <div class="job-map__frame">
        <iframe
            src="<?= h($embedUrl) ?>"
            title="<?= h(__('Map of {0}', $address)) ?>"
            loading="lazy"
            referrerpolicy="no-referrer"
            tabindex="-1"></iframe>
        <a class="job-map__overlay" href="<?= h($openUrl) ?>" target="_blank" rel="noopener noreferrer">
            <span class="job-map__hint"><?= __('Open in Google Maps') ?></span>
        </a>
    </div>
    <p class="job-map__address"><?= h($address) ?></p>
    <div class="job-map__actions">
        <a class="dash__btn" href="<?= h($directionsUrl) ?>" target="_blank" rel="noopener noreferrer">
            <?= __('Get directions') ?>
        </a>
        <a class="dash__btn dash__btn--ghost" href="<?= h($openUrl) ?>" target="_blank" rel="noopener noreferrer">
            <?= __('Open in Google Maps') ?>
        </a>
    </div>
</div>
