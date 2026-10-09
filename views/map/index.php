<?php
use EchoDial\Deck\Deck;
use Keel\App\Models\RoutePreference;
use Keel\App\Models\VehicleType;
use Keel\App\Services\Units;
use Keel\Core\Csrf;
use Keel\Core\Theme;

$vehicle = $vehicle ?? null;
$unitSystem = $unitSystem ?? Units::IMPERIAL;
$personality = $personality ?? RoutePreference::DEFAULT_PERSONALITY;
$tilesUrl = $tilesUrl ?? null;
$categories = $categories ?? [];
$initial = $initial ?? ['q' => '', 'from' => null, 'to' => null];
$isCustom = !in_array($personality, RoutePreference::PRIMARY_PERSONALITIES, true);
$customPersonalities = array_diff(RoutePreference::PERSONALITIES, RoutePreference::PRIMARY_PERSONALITIES);

$vehicleData = null;
$vehicleChip = 'Add a vehicle';
if ($vehicle !== null) {
    $type = (string) $vehicle['type'];
    $height = $vehicle['height_m'] !== null ? Units::formatLength($vehicle['height_m'], $unitSystem) : null;
    $vehicleChip = (string) $vehicle['name'] . ($height !== null ? ' · ' . $height : '');
    $vehicleData = [
        'id' => (int) $vehicle['id'],
        'name' => (string) $vehicle['name'],
        'type' => $type,
        'emoji' => VehicleType::emoji($type),
        'large' => VehicleType::isLarge($type),
        'edit_url' => '/vehicles/' . (int) $vehicle['id'] . '/edit',
    ];
}

$json = static fn (mixed $value): string => htmlspecialchars((string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html <?= Deck::htmlAttributes(lang: 'en') ?> <?= Deck::theme(mode: Theme::serverPreference()) ?>>
<head>
<?php require __DIR__ . '/../partials/head.php'; ?>
<?php require __DIR__ . '/../partials/map-assets.php'; ?>
<script type="module" src="<?= htmlspecialchars($publicAsset('/js/map.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
</head>
<body>
    <div
        class="map-screen"
        data-map-screen
        data-tiles-url="<?= htmlspecialchars((string) $tilesUrl, ENT_QUOTES, 'UTF-8') ?>"
        data-vehicle="<?= $json($vehicleData) ?>"
        data-personality="<?= htmlspecialchars($personality, ENT_QUOTES, 'UTF-8') ?>"
        data-categories="<?= $json($categories) ?>"
        data-initial="<?= $json($initial) ?>"
    >
        <div class="map-canvas" data-map role="region" aria-label="Map"></div>

        <div class="map-fallback" data-map-fallback hidden>
            <div class="alert alert-warn">
                <?= Deck::icon('alert-triangle') ?>
                <div class="alert-body">
                    <p class="alert-title" data-map-fallback-title>The map can't be shown</p>
                    <p data-map-fallback-text>Routes still work: search for a place below.</p>
                </div>
            </div>
        </div>

        <noscript>
            <div class="map-fallback">
                <div class="alert alert-warn">
                    <?= Deck::icon('alert-triangle') ?>
                    <div class="alert-body">
                        <p class="alert-title">The map needs JavaScript</p>
                        <p>Turn JavaScript on to search for places and plan routes.</p>
                    </div>
                </div>
            </div>
        </noscript>

        <header class="map-top" data-map-top>
            <div class="card card-raised map-panel">
                <div class="bar">
                    <a class="btn btn-icon btn-ghost" href="/dashboard" aria-label="Home"><?= Deck::icon('home') ?></a>
                    <div class="search grow" role="search">
                        <?= Deck::icon('search') ?>
                        <label class="sr-only" for="place-search" data-search-label>Search for a destination</label>
                        <input
                            type="search"
                            id="place-search"
                            class="input"
                            placeholder="Where to?"
                            autocomplete="off"
                            spellcheck="false"
                            enterkeyhint="search"
                            role="combobox"
                            aria-autocomplete="list"
                            aria-expanded="false"
                            aria-controls="place-results"
                            maxlength="200"
                            value="<?= htmlspecialchars((string) $initial['q'], ENT_QUOTES, 'UTF-8') ?>"
                            data-search
                        >
                    </div>
                    <?php $themeToggleClass = ''; require __DIR__ . '/../partials/theme-toggle.php'; ?>
                </div>

                <div class="bar text-sm" data-origin-mode hidden>
                    <p class="grow">Choose where to start from.</p>
                    <button type="button" class="btn btn-sm btn-ghost" data-origin-cancel>Cancel</button>
                </div>

                <div class="map-results" data-results hidden>
                    <p class="map-results-status text-sm text-muted" data-results-status role="status" aria-live="polite"></p>
                    <ul class="list" id="place-results" role="listbox" aria-label="Places" data-results-list></ul>
                </div>

                <ul class="scroller map-chips" role="list">
                    <li>
                        <button type="button" class="btn btn-soft btn-round" data-origin-chip>
                            <?= Deck::icon('map-pin-sm', 'icon icon-sm') ?>
                            <span>From: <span data-origin-label>Your location</span></span>
                        </button>
                    </li>
                    <li>
                        <a class="btn btn-soft btn-round" href="<?= $vehicle === null ? '/vehicles/create' : '/vehicles?return_to=%2Fmap' ?>" data-vehicle-chip>
                            <?php if ($vehicleData !== null): ?>
                            <span class="emoji" aria-hidden="true"><?= $vehicleData['emoji'] ?></span>
                            <?php else: ?>
                            <?= Deck::icon('plus-sm', 'icon icon-sm') ?>
                            <?php endif; ?>
                            <span><span class="sr-only">Vehicle: </span><?= htmlspecialchars($vehicleChip) ?></span>
                        </a>
                    </li>
                </ul>
            </div>
        </header>

        <button type="button" class="btn btn-icon btn-round map-locate" aria-label="Show my location" data-locate>
            <?= Deck::icon('send') ?>
        </button>

        <section class="map-sheet" id="route-sheet" data-sheet data-state="collapsed" aria-labelledby="sheet-title">
            <div class="map-sheet-handle" data-sheet-handle>
                <div class="sheet-grip" aria-hidden="true"></div>
                <div class="sheet-header">
                    <div class="stack stack-0 grow">
                        <h2 id="sheet-title" class="sheet-title" data-sheet-title>Plan a route</h2>
                        <p class="text-sm text-muted" data-sheet-subtitle>Search for a place to see up to three routes<?= $vehicle !== null ? ' for ' . htmlspecialchars((string) $vehicle['name']) : '' ?>.</p>
                    </div>
                    <a class="btn btn-icon btn-ghost" href="/routes" aria-label="Saved routes" title="Saved routes"><?= Deck::icon('bookmark') ?></a>
                    <button type="button" class="btn btn-icon btn-ghost map-sheet-toggle" aria-expanded="false" aria-controls="sheet-body" aria-label="Show more of the route panel" data-sheet-toggle>
                        <?= Deck::icon('chevron-up') ?>
                    </button>
                </div>
            </div>

            <div class="map-sheet-body stack stack-4" id="sheet-body">
                <div data-sheet-message></div>

                <div class="alert alert-warn" data-clearance hidden>
                    <?= Deck::icon('alert-triangle') ?>
                    <div class="alert-body">
                        <p class="alert-title">Clearance not guaranteed</p>
                        <p data-clearance-text></p>
                    </div>
                </div>

                <div class="stack stack-2" role="group" aria-label="Route options" data-route-options></div>

                <div class="stack stack-4" data-route-detail hidden>
                    <details class="disclosure card" data-why>
                        <summary>Why this route?</summary>
                        <div class="disclosure-body stack stack-2">
                            <p data-why-text></p>
                            <ul class="stack stack-1" data-why-warnings></ul>
                        </div>
                    </details>

                    <figure class="stack stack-2" data-elevation>
                        <figcaption class="bar text-sm">
                            <span class="fw-semi">Elevation</span>
                            <span class="push text-muted nums" data-elevation-range></span>
                        </figcaption>
                        <svg class="elevation-chart" viewBox="0 0 300 80" preserveAspectRatio="none" aria-hidden="true" focusable="false" data-elevation-chart>
                            <path class="elevation-area" data-elevation-area d=""></path>
                            <path class="elevation-line" data-elevation-line d="" vector-effect="non-scaling-stroke"></path>
                        </svg>
                        <p class="text-sm text-muted" data-elevation-summary></p>
                    </figure>

                    <div class="stack stack-2">
                        <button type="button" class="btn btn-primary btn-lg btn-block" data-save>
                            <?= Deck::icon('bookmark') ?>
                            <span data-save-label>Save route</span>
                        </button>
                        <button type="button" class="btn btn-lg btn-block" disabled aria-describedby="start-note">
                            <?= Deck::icon('arrow-right') ?>
                            Start
                        </button>
                        <p id="start-note" class="text-sm text-muted">Navigation coming next.</p>
                    </div>
                </div>

                <form method="POST" action="/preferences/travel-mode" class="stack stack-2" data-travel-mode>
                    <?= Csrf::field() ?>
                    <input type="hidden" name="return_to" value="/map">
                    <h3 id="travel-mode-title" class="h6">Travel mode</h3>
                    <div class="segmented" role="group" aria-labelledby="travel-mode-title">
                        <?php foreach (RoutePreference::PRIMARY_PERSONALITIES as $mode): ?>
                        <button type="submit" name="personality" value="<?= htmlspecialchars($mode) ?>" aria-pressed="<?= $mode === $personality ? 'true' : 'false' ?>">
                            <?= htmlspecialchars(RoutePreference::LABELS[$mode]) ?>
                        </button>
                        <?php endforeach; ?>
                        <button type="button" aria-pressed="<?= $isCustom ? 'true' : 'false' ?>" aria-expanded="<?= $isCustom ? 'true' : 'false' ?>" aria-controls="custom-modes" data-custom-toggle>Custom</button>
                    </div>
                    <div id="custom-modes" class="cluster cluster-tight" role="group" aria-label="Custom travel modes"<?= $isCustom ? '' : ' hidden' ?>>
                        <?php foreach ($customPersonalities as $mode): ?>
                        <button type="submit" name="personality" value="<?= htmlspecialchars($mode) ?>" class="chip" aria-pressed="<?= $mode === $personality ? 'true' : 'false' ?>">
                            <?= htmlspecialchars(RoutePreference::LABELS[$mode]) ?>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </form>
            </div>
        </section>
    </div>

    <template id="place-result-template">
        <li class="list-row" role="option" aria-selected="false" data-place>
            <span class="emoji-tile" aria-hidden="true"><span class="emoji" data-place-icon></span></span>
            <span class="list-main">
                <span class="list-title truncate" data-place-label></span>
                <span class="list-sub truncate" data-place-secondary></span>
            </span>
            <span class="sr-only" data-place-category></span>
        </li>
    </template>

    <template id="route-card-template">
        <button type="button" class="card route-card" aria-pressed="false" data-route-card>
            <span class="bar">
                <span class="emoji-tile" aria-hidden="true"><span class="emoji" data-route-icon></span></span>
                <span class="stack stack-0 grow">
                    <span class="fw-semi" data-route-title></span>
                    <span class="badge badge-solid" data-route-recommended hidden>Recommended</span>
                </span>
                <span class="badge" data-route-classification></span>
            </span>
            <span class="bar route-card-time">
                <span class="fw-semi nums" data-route-duration></span>
                <span class="text-sm text-muted nums" data-route-delta></span>
            </span>
            <span class="cluster cluster-tight text-sm text-muted nums" data-route-facts></span>
        </button>
    </template>

    <template id="sheet-message-template">
        <div class="alert" role="alert" data-message>
            <?= Deck::icon('alert-circle') ?>
            <div class="alert-body stack stack-2">
                <p class="alert-title" data-message-title></p>
                <p data-message-text></p>
                <div class="cluster cluster-tight" data-message-actions></div>
            </div>
        </div>
    </template>

    <template id="loading-template">
        <div class="stack stack-2" data-loading>
            <p class="bar text-sm text-muted" role="status"><span class="spinner"></span><span data-loading-text>Finding routes…</span></p>
            <div class="skeleton route-skeleton"></div>
            <div class="skeleton route-skeleton"></div>
        </div>
    </template>
</body>
</html>
