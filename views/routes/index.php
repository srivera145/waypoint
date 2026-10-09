<?php
use EchoDial\Deck\Deck;
use Keel\App\Models\VehicleType;
use Keel\App\Services\Units;
use Keel\Core\Theme;

$routes = $routes ?? [];
$vehicles = $vehicles ?? [];
$unitSystem = $unitSystem ?? Units::IMPERIAL;
$status = $status ?? null;
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
                        <li><span aria-current="page">Saved routes</span></li>
                    </ol>
                </nav>
                <h1 class="h2">Saved routes</h1>
            </div>
            <?php $themeToggleClass = 'push'; require __DIR__ . '/../partials/theme-toggle.php'; ?>
        </header>

        <?php if (is_string($status)): ?>
        <div class="alert alert-good" role="status">
            <?= Deck::icon('check-circle') ?>
            <p><?= htmlspecialchars($status) ?></p>
        </div>
        <?php endif; ?>

        <section class="stack stack-3" aria-labelledby="routes-title">
            <div class="bar">
                <h2 id="routes-title" class="h5">Your routes</h2>
                <a class="btn btn-primary btn-sm push" href="/map"><?= Deck::icon('map-pin-sm', 'icon icon-sm') ?> Plan a route</a>
            </div>

            <?php if ($routes === []): ?>
            <div class="card">
                <div class="empty">
                    <span class="empty-art"><?= Deck::icon('bookmark') ?></span>
                    <p class="empty-title">No saved routes yet</p>
                    <p>Plan a route on the map and tap Save route to keep it here.</p>
                </div>
            </div>
            <?php else: ?>
            <ul class="list" role="list">
                <?php foreach ($routes as $route): ?>
                <?php
                $vehicle = $vehicles[(int) $route['vehicle_id']] ?? null;
                $details = [
                    Units::formatDistance((float) $route['distance_m'], $unitSystem),
                    Units::formatDuration((float) $route['duration_s']),
                ];
                if ($vehicle !== null) {
                    $details[] = (string) $vehicle['name'];
                }
                $details[] = 'saved ' . date('M j, Y', strtotime((string) $route['created_at']));
                ?>
                <li class="list-row">
                    <span class="emoji-tile" aria-hidden="true"><span class="emoji"><?= $vehicle !== null ? VehicleType::emoji((string) $vehicle['type']) : '🗺️' ?></span></span>
                    <div class="list-main">
                        <a class="list-title link-quiet truncate" href="/routes/<?= (int) $route['id'] ?>"><?= htmlspecialchars((string) $route['name']) ?></a>
                        <span class="list-sub nums"><?= htmlspecialchars(implode(' · ', $details)) ?></span>
                    </div>
                    <span class="list-trail"><?= Deck::icon('chevron-right') ?></span>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </section>
    </main>

    <?php require __DIR__ . '/../partials/back-to-top.php'; ?>
</body>
</html>
