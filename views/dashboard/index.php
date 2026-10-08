<?php
use EchoDial\Deck\Deck;
use Keel\App\Models\RoutePreference;
use Keel\App\Models\Vehicle;
use Keel\App\Models\VehicleType;
use Keel\App\Services\Units;
use Keel\Core\Csrf;
use Keel\Core\Theme;

$vehicle = $vehicle ?? null;
$unitSystem = $unitSystem ?? Units::IMPERIAL;
$personality = $personality ?? RoutePreference::DEFAULT_PERSONALITY;
$isCustom = $isCustom ?? false;
$showCustom = $showCustom ?? false;
$customPersonalities = array_diff(RoutePreference::PERSONALITIES, RoutePreference::PRIMARY_PERSONALITIES);

// Placeholders until search, saved places and trip planning arrive.
$quickActions = [
    'home' => 'Home',
    'map-pin' => 'Work',
    'star' => 'Favorites',
    'calendar' => 'Plan a Trip',
    'sparkle' => 'Ask Waypoint',
];

$overallLength = $vehicle !== null ? Vehicle::overallLength($vehicle) : null;
$includesTrailer = $overallLength !== null && (float) $overallLength !== (float) $vehicle['length_m'];
?>
<!DOCTYPE html>
<html <?= Deck::htmlAttributes(lang: 'en') ?> <?= Deck::theme(mode: Theme::serverPreference()) ?>>
<head>
<?php require __DIR__ . '/../partials/head.php'; ?>
</head>
<body>
    <span id="top" tabindex="-1"></span>

    <main class="container app-page stack stack-6">
        <header class="bar">
            <h1 class="h3">Waypoint</h1>
            <?php $themeToggleClass = 'push'; require __DIR__ . '/../partials/theme-toggle.php'; ?>
            <form method="POST" action="/logout">
                <?= Csrf::field() ?>
                <button type="submit" class="btn btn-sm">Sign out</button>
            </form>
        </header>

        <div class="search" role="search">
            <?= Deck::icon('search') ?>
            <label class="sr-only" for="place-search">Search for a place or address</label>
            <input type="search" id="place-search" class="input" placeholder="Where to?" autocomplete="off" aria-describedby="place-search-note">
            <p id="place-search-note" class="sr-only">Search is not available yet.</p>
        </div>

        <section aria-label="Quick actions">
            <ul class="scroller" role="list" style="--gap: var(--space-2)">
                <?php foreach ($quickActions as $icon => $label): ?>
                <li>
                    <button type="button" class="btn btn-sm btn-soft btn-round" aria-disabled="true" title="Coming soon">
                        <?= Deck::icon($icon . '-sm', 'icon icon-sm') ?>
                        <?= htmlspecialchars($label) ?>
                    </button>
                </li>
                <?php endforeach; ?>
            </ul>
        </section>

        <section class="card" aria-labelledby="vehicle-title">
            <div class="card-header">
                <h2 id="vehicle-title" class="card-title">Your vehicle</h2>
                <a class="push text-sm" href="/vehicles"><?= $vehicle === null ? 'Add vehicle' : 'Change vehicle' ?></a>
            </div>
            <div class="card-body">
                <?php if ($vehicle === null): ?>
                <p class="text-muted">Add what you drive so Waypoint can keep you off roads it won't fit.</p>
                <?php else: ?>
                <div class="bar">
                    <span class="emoji-tile" aria-hidden="true"><span class="emoji"><?= VehicleType::emoji((string) $vehicle['type']) ?></span></span>
                    <div class="stack stack-0 grow">
                        <p class="fw-semi truncate"><?= htmlspecialchars((string) $vehicle['name']) ?></p>
                        <p class="text-sm text-muted"><?= htmlspecialchars(VehicleType::label((string) $vehicle['type'])) ?></p>
                    </div>
                </div>

                <?php if ($overallLength === null): ?>
                <p class="text-sm text-muted">No size limits apply to <?= htmlspecialchars(strtolower(VehicleType::label((string) $vehicle['type']))) ?> routes.</p>
                <?php else: ?>
                <dl class="grid grid-fixed-2">
                    <div class="stat">
                        <dt class="stat-label"><?= $includesTrailer ? 'Length with trailer' : 'Length' ?></dt>
                        <dd class="fw-semi nums"><?= htmlspecialchars(Units::formatLength($overallLength, $unitSystem)) ?></dd>
                    </div>
                    <div class="stat">
                        <dt class="stat-label">Height</dt>
                        <dd class="fw-semi nums"><?= htmlspecialchars(Units::formatLength($vehicle['height_m'], $unitSystem)) ?></dd>
                    </div>
                </dl>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </section>

        <section id="travel-mode" class="stack stack-3" aria-labelledby="travel-mode-title">
            <h2 id="travel-mode-title" class="h5">Travel mode</h2>
            <form method="POST" action="/preferences/travel-mode" class="stack stack-3">
                <?= Csrf::field() ?>
                <div class="segmented" role="group" aria-labelledby="travel-mode-title">
                    <?php foreach (RoutePreference::PRIMARY_PERSONALITIES as $mode): ?>
                    <?php $selected = !$showCustom && $mode === $personality; ?>
                    <button type="submit" name="personality" value="<?= htmlspecialchars($mode) ?>" aria-pressed="<?= $selected ? 'true' : 'false' ?>">
                        <?= htmlspecialchars(RoutePreference::LABELS[$mode]) ?>
                    </button>
                    <?php endforeach; ?>
                    <a href="/dashboard?mode=custom#travel-mode"<?= $showCustom ? ' aria-current="true"' : '' ?>>Custom</a>
                </div>

                <?php if ($showCustom): ?>
                <div class="cluster cluster-tight" role="group" aria-label="Custom travel modes">
                    <?php foreach ($customPersonalities as $mode): ?>
                    <button type="submit" name="personality" value="<?= htmlspecialchars($mode) ?>" class="chip" aria-pressed="<?= $mode === $personality ? 'true' : 'false' ?>">
                        <?= htmlspecialchars(RoutePreference::LABELS[$mode]) ?>
                    </button>
                    <?php endforeach; ?>
                </div>
                <?php if (!$isCustom): ?>
                <p class="help">Pick one to use it. Until then, routes stay on <?= htmlspecialchars(RoutePreference::LABELS[$personality]) ?>.</p>
                <?php endif; ?>
                <?php endif; ?>
            </form>
        </section>
    </main>

    <?php require __DIR__ . '/../partials/back-to-top.php'; ?>
</body>
</html>
