<?php
use EchoDial\Deck\Deck;
use Keel\App\Models\VehicleType;
use Keel\App\Services\Routing\RouteExplainer;
use Keel\App\Services\Units;
use Keel\Core\Csrf;
use Keel\Core\Theme;

$route = $route ?? [];
$request = $request ?? [];
$summary = $summary ?? [];
$unitSystem = $unitSystem ?? Units::IMPERIAL;
$tilesUrl = $tilesUrl ?? null;
$nameValue = $nameValue ?? '';
$nameError = $nameError ?? null;
$status = $status ?? null;

$routeId = (int) $route['id'];
$option = is_array($summary['option'] ?? null) ? $summary['option'] : [];
$elevation = is_array($option['elevation'] ?? null) ? $option['elevation'] : [];
$hasElevation = (bool) ($elevation['available'] ?? false);
$notices = is_array($summary['notices'] ?? null) ? $summary['notices'] : [];
$vehicle = is_array($summary['vehicle'] ?? null) ? $summary['vehicle'] : null;
$origin = is_array($request['origin'] ?? null) ? $request['origin'] : null;
$destination = is_array($request['destination'] ?? null) ? $request['destination'] : null;

// The same words and tones as the map screen's cards (public_html/js/map.js).
$classifications = ['OK' => ['OK', 'badge-good'], 'SUBOPTIMAL' => ['Suboptimal', 'badge-warn'], 'RESTRICTED' => ['Restricted', 'badge-bad']];
[$classification, $classificationTone] = $classifications[$option['classification'] ?? ''] ?? [(string) ($option['classification'] ?? ''), ''];
$routeIcons = ['fastest' => '⚡', 'vehicle_friendly' => $vehicle !== null ? VehicleType::emoji((string) $vehicle['type']) : '🚗', 'scenic' => '🏞️', 'flat' => '〰️', 'alternate' => '🔀'];

$facts = [Units::formatDistance((float) $route['distance_m'], $unitSystem)];
if ($hasElevation) {
    $facts[] = 'max grade ' . RouteExplainer::percent((float) $elevation['max_grade_pct']);
    $facts[] = Units::formatElevation((float) $elevation['total_climb_m'], $unitSystem) . ' climb';
} else {
    $facts[] = 'grades not measured';
}

$vehicleLine = null;
if ($vehicle !== null) {
    $vehicleLine = (string) $vehicle['name'] . (isset($vehicle['height_m']) ? ' (' . Units::formatLength($vehicle['height_m'], $unitSystem) . ' high)' : '');
}

$point = static fn (array $place): string => sprintf('%.6f,%.6f', (float) $place['lat'], (float) $place['lng']);
$planAgain = $origin !== null && $destination !== null
    ? '/map?' . http_build_query(['from' => $point($origin), 'from_label' => $origin['label'] ?? '', 'to' => $point($destination), 'to_label' => $destination['label'] ?? ''])
    : '/map';

$routeData = [
    'polyline' => (string) $route['polyline'],
    'precision' => (int) ($option['polyline_precision'] ?? 6),
    'origin' => $origin !== null ? ['lat' => (float) $origin['lat'], 'lng' => (float) $origin['lng']] : null,
    'destination' => $destination !== null ? ['lat' => (float) $destination['lat'], 'lng' => (float) $destination['lng']] : null,
    'profile' => $hasElevation ? ($elevation['profile'] ?? []) : [],
];
?>
<!DOCTYPE html>
<html <?= Deck::htmlAttributes(lang: 'en') ?> <?= Deck::theme(mode: Theme::serverPreference()) ?>>
<head>
<?php require __DIR__ . '/../partials/head.php'; ?>
<?php require __DIR__ . '/../partials/map-assets.php'; ?>
<script type="module" src="<?= htmlspecialchars($publicAsset('/js/saved-route.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
</head>
<body>
    <div
        class="map-screen"
        data-saved-route
        data-tiles-url="<?= htmlspecialchars((string) $tilesUrl, ENT_QUOTES, 'UTF-8') ?>"
        data-route="<?= htmlspecialchars((string) json_encode($routeData, JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?>"
    >
        <div class="map-canvas" data-map role="region" aria-label="Map of this route"></div>

        <div class="map-fallback" data-map-fallback hidden>
            <div class="alert alert-warn">
                <?= Deck::icon('alert-triangle') ?>
                <div class="alert-body">
                    <p class="alert-title" data-map-fallback-title>The map can't be shown</p>
                    <p data-map-fallback-text></p>
                </div>
            </div>
        </div>

        <header class="map-top" data-map-top>
            <div class="card card-raised map-panel">
                <div class="bar">
                    <a class="btn btn-icon btn-ghost" href="/routes" aria-label="Back to saved routes"><?= Deck::icon('arrow-left') ?></a>
                    <nav class="grow" aria-label="Breadcrumb">
                        <ol class="breadcrumb">
                            <li><a href="/dashboard">Home</a></li>
                            <li><a href="/routes">Saved routes</a></li>
                        </ol>
                    </nav>
                    <?php $themeToggleClass = ''; require __DIR__ . '/../partials/theme-toggle.php'; ?>
                </div>
            </div>
        </header>

        <section class="map-sheet" data-sheet data-state="collapsed" aria-labelledby="route-name">
            <div class="map-sheet-handle" data-sheet-handle>
                <div class="sheet-grip" aria-hidden="true"></div>
                <div class="sheet-header">
                    <div class="stack stack-0 grow">
                        <h1 id="route-name" class="sheet-title"><?= htmlspecialchars((string) $route['name']) ?></h1>
                        <?php if ($origin !== null && $destination !== null): ?>
                        <p class="text-sm text-muted">From <?= htmlspecialchars((string) $origin['label']) ?> to <?= htmlspecialchars((string) $destination['label']) ?></p>
                        <?php endif; ?>
                    </div>
                    <button type="button" class="btn btn-icon btn-ghost map-sheet-toggle" aria-expanded="false" aria-controls="saved-route-body" aria-label="Show more of the route panel" data-sheet-toggle>
                        <?= Deck::icon('chevron-up') ?>
                    </button>
                </div>
            </div>

            <div class="map-sheet-body stack stack-4" id="saved-route-body">
                <?php if (is_string($status)): ?>
                <div class="alert alert-good" role="status">
                    <?= Deck::icon('check-circle') ?>
                    <p><?= htmlspecialchars($status) ?></p>
                </div>
                <?php endif; ?>

                <?php foreach ($notices as $notice): ?>
                <div class="alert alert-warn">
                    <?= Deck::icon('alert-triangle') ?>
                    <div class="alert-body">
                        <p class="alert-title">Clearance not guaranteed</p>
                        <p><?= htmlspecialchars((string) $notice) ?></p>
                    </div>
                </div>
                <?php endforeach; ?>

                <div class="card route-card" role="group" aria-label="Route summary">
                    <span class="bar">
                        <span class="emoji-tile" aria-hidden="true"><span class="emoji"><?= $routeIcons[$option['label'] ?? ''] ?? '🔀' ?></span></span>
                        <span class="fw-semi grow"><?= htmlspecialchars((string) ($option['title'] ?? 'Route')) ?></span>
                        <?php if ($classification !== ''): ?>
                        <span class="badge <?= $classificationTone ?>"><?= htmlspecialchars($classification) ?></span>
                        <?php endif; ?>
                    </span>
                    <span class="bar route-card-time">
                        <span class="fw-semi nums"><?= htmlspecialchars(Units::formatDuration((float) $route['duration_s'])) ?></span>
                    </span>
                    <span class="cluster cluster-tight text-sm text-muted nums">
                        <?php foreach ($facts as $fact): ?>
                        <span><?= htmlspecialchars($fact) ?></span>
                        <?php endforeach; ?>
                    </span>
                </div>

                <p class="text-sm text-muted">
                    Saved <?= htmlspecialchars(date('M j, Y', strtotime((string) $route['created_at']))) ?><?= $vehicleLine !== null ? ' for ' . htmlspecialchars($vehicleLine) : '' ?>.
                    Roads and closures may have changed since, so plan it again before you drive.
                </p>

                <?php if (($option['explanation'] ?? '') !== ''): ?>
                <details class="disclosure card">
                    <summary>Why this route?</summary>
                    <div class="disclosure-body stack stack-2">
                        <p><?= htmlspecialchars((string) $option['explanation']) ?></p>
                        <?php if (($option['warnings'] ?? []) !== []): ?>
                        <ul class="stack stack-1">
                            <?php foreach ($option['warnings'] as $warning): ?>
                            <li><?= htmlspecialchars((string) $warning) ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <?php endif; ?>
                    </div>
                </details>
                <?php endif; ?>

                <figure class="stack stack-2">
                    <figcaption class="bar text-sm">
                        <span class="fw-semi">Elevation</span>
                        <?php if ($hasElevation): ?>
                        <span class="push text-muted nums"><?= htmlspecialchars(Units::formatElevation((float) $elevation['min_elevation_m'], $unitSystem) . ' – ' . Units::formatElevation((float) $elevation['max_elevation_m'], $unitSystem)) ?></span>
                        <?php endif; ?>
                    </figcaption>
                    <?php if ($hasElevation): ?>
                    <svg class="elevation-chart" viewBox="0 0 300 80" preserveAspectRatio="none" aria-hidden="true" focusable="false" data-elevation-chart>
                        <path class="elevation-area" data-elevation-area d=""></path>
                        <path class="elevation-line" data-elevation-line d="" vector-effect="non-scaling-stroke"></path>
                    </svg>
                    <p class="text-sm text-muted">
                        Climbs <?= htmlspecialchars(Units::formatElevation((float) $elevation['total_climb_m'], $unitSystem)) ?> in all.
                        Highest point <?= htmlspecialchars(Units::formatElevation((float) $elevation['max_elevation_m'], $unitSystem)) ?>, lowest <?= htmlspecialchars(Units::formatElevation((float) $elevation['min_elevation_m'], $unitSystem)) ?>.
                    </p>
                    <?php else: ?>
                    <p class="text-sm text-muted">Elevation wasn't available for this route, so its grades aren't known.</p>
                    <?php endif; ?>
                </figure>

                <a class="btn btn-primary btn-lg btn-block" href="<?= htmlspecialchars($planAgain, ENT_QUOTES, 'UTF-8') ?>"><?= Deck::icon('refresh') ?> Plan this trip again</a>

                <form method="POST" action="/routes/<?= $routeId ?>/rename" class="stack stack-2" id="rename">
                    <?= Csrf::field() ?>
                    <div class="field<?= $nameError !== null ? ' is-invalid' : '' ?>">
                        <label class="label" for="route-name-input">Name</label>
                        <input type="text" id="route-name-input" name="name" class="input" maxlength="100" value="<?= htmlspecialchars($nameValue) ?>" required<?= $nameError !== null ? ' aria-invalid="true" aria-describedby="route-name-error"' : '' ?>>
                        <?php if ($nameError !== null): ?>
                        <p class="error" id="route-name-error"><?= Deck::icon('alert-circle-sm', 'icon icon-sm') ?> <?= htmlspecialchars($nameError) ?></p>
                        <?php endif; ?>
                    </div>
                    <button type="submit" class="btn btn-block">Rename</button>
                </form>

                <form id="delete-route-form" method="POST" action="/routes/<?= $routeId ?>/delete" data-confirm="delete-route-dialog">
                    <?= Csrf::field() ?>
                    <button type="submit" class="btn btn-danger btn-block">Delete route</button>
                </form>
            </div>
        </section>
    </div>

    <dialog class="modal" id="delete-route-dialog" aria-labelledby="delete-dialog-title">
        <div class="modal-header">
            <h2 class="modal-title" id="delete-dialog-title">Delete this route?</h2>
        </div>
        <div class="modal-body">
            <p><strong><?= htmlspecialchars((string) $route['name']) ?></strong> will be removed from your saved routes.</p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn" data-modal-close>Keep it</button>
            <button type="button" class="btn btn-danger" data-confirm-submit="delete-route-form">Delete route</button>
        </div>
    </dialog>
</body>
</html>
