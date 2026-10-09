<?php
use EchoDial\Deck\Deck;
use Keel\App\Models\Vehicle;
use Keel\App\Models\VehicleType;
use Keel\App\Services\Units;
use Keel\Core\Csrf;
use Keel\Core\Theme;

$vehicles = $vehicles ?? [];
$unitSystem = $unitSystem ?? Units::IMPERIAL;
$status = $status ?? null;
$error = $error ?? null;
$returnTo = $returnTo ?? null;
$unitChoices = [Units::IMPERIAL => 'Imperial (ft, lbs)', Units::METRIC => 'Metric (m, kg)'];
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
            <div class="stack stack-2">
                <nav aria-label="Breadcrumb">
                    <ol class="breadcrumb">
                        <li><a href="/dashboard">Home</a></li>
                        <?php if ($returnTo !== null): ?>
                        <li><a href="<?= htmlspecialchars($returnTo) ?>">Map</a></li>
                        <?php endif; ?>
                        <li><span aria-current="page">Vehicles</span></li>
                    </ol>
                </nav>
                <h1 class="h2">Your vehicles</h1>
            </div>
            <?php $themeToggleClass = 'push'; require __DIR__ . '/../partials/theme-toggle.php'; ?>
        </header>

        <?php if ($returnTo !== null): ?>
        <div class="alert alert-info">
            <?= Deck::icon('info') ?>
            <div class="alert-body">
                <p>Choose the vehicle for this trip. You'll go back to the map and the route will be planned again for it.</p>
                <a class="btn btn-sm" href="<?= htmlspecialchars($returnTo) ?>"><?= Deck::icon('arrow-left-sm', 'icon icon-sm') ?> Back to the map</a>
            </div>
        </div>
        <?php endif; ?>

        <?php if (is_string($status)): ?>
        <div class="alert alert-good" role="status">
            <?= Deck::icon('check-circle') ?>
            <p><?= htmlspecialchars($status) ?></p>
        </div>
        <?php endif; ?>

        <?php if (is_string($error)): ?>
        <div class="alert alert-bad" role="alert">
            <?= Deck::icon('alert-circle') ?>
            <p><?= htmlspecialchars($error) ?></p>
        </div>
        <?php endif; ?>

        <section class="stack stack-3" aria-labelledby="vehicles-title">
            <div class="bar">
                <h2 id="vehicles-title" class="h5">Saved vehicles</h2>
                <a class="btn btn-primary btn-sm push" href="/vehicles/create"><?= Deck::icon('plus-sm', 'icon icon-sm') ?> Add vehicle</a>
            </div>

            <?php if ($vehicles === []): ?>
            <div class="card">
                <div class="empty">
                    <span class="empty-art"><?= Deck::icon('car') ?></span>
                    <p class="empty-title">No vehicles yet</p>
                    <p>Add what you drive so routes avoid low bridges and weight limits it can't take.</p>
                </div>
            </div>
            <?php else: ?>
            <ul class="list" role="list">
                <?php foreach ($vehicles as $vehicle): ?>
                <?php
                $type = (string) $vehicle['type'];
                $isActive = (int) $vehicle['is_active'] === 1;
                $overallLength = Vehicle::overallLength($vehicle);
                $details = [VehicleType::label($type)];
                if ($overallLength !== null) {
                    $details[] = Units::formatLength($overallLength, $unitSystem) . ' long';
                }
                if ($vehicle['height_m'] !== null) {
                    $details[] = Units::formatLength($vehicle['height_m'], $unitSystem) . ' high';
                }
                ?>
                <li class="list-row">
                    <span class="emoji-tile" aria-hidden="true"><span class="emoji"><?= VehicleType::emoji($type) ?></span></span>
                    <div class="list-main">
                        <a class="list-title link-quiet truncate" href="/vehicles/<?= (int) $vehicle['id'] ?>/edit"><?= htmlspecialchars((string) $vehicle['name']) ?></a>
                        <span class="list-sub nums"><?= htmlspecialchars(implode(' · ', $details)) ?></span>
                    </div>
                    <?php if ($isActive): ?>
                    <span class="badge badge-good badge-dot">Active</span>
                    <?php else: ?>
                    <form method="POST" action="/vehicles/<?= (int) $vehicle['id'] ?>/activate">
                        <?= Csrf::field() ?>
                        <?php if ($returnTo !== null): ?>
                        <input type="hidden" name="return_to" value="<?= htmlspecialchars($returnTo) ?>">
                        <?php endif; ?>
                        <button type="submit" class="btn btn-sm btn-soft">Use<span class="sr-only"> <?= htmlspecialchars((string) $vehicle['name']) ?></span></button>
                    </form>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </section>

        <section class="card" aria-labelledby="units-title">
            <div class="card-body">
                <h2 id="units-title" class="card-title">Units</h2>
                <p class="text-sm text-muted">How lengths and weights are shown. You can type either kind in any field.</p>
                <form method="POST" action="/settings/units">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="return_to" value="/vehicles">
                    <div class="segmented" role="group" aria-labelledby="units-title">
                        <?php foreach ($unitChoices as $system => $label): ?>
                        <button type="submit" name="unit_system" value="<?= $system ?>" aria-pressed="<?= $system === $unitSystem ? 'true' : 'false' ?>"><?= htmlspecialchars($label) ?></button>
                        <?php endforeach; ?>
                    </div>
                </form>
            </div>
        </section>
    </main>

    <?php require __DIR__ . '/../partials/back-to-top.php'; ?>
</body>
</html>
