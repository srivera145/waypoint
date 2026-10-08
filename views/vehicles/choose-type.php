<?php
use EchoDial\Deck\Deck;
use Keel\Core\Theme;

$types = $types ?? [];
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
                        <li><a href="/vehicles">Vehicles</a></li>
                        <li><span aria-current="page">Add</span></li>
                    </ol>
                </nav>
                <h1 class="h2">What are you driving?</h1>
            </div>
            <?php $themeToggleClass = 'push'; require __DIR__ . '/../partials/theme-toggle.php'; ?>
        </header>

        <p class="text-muted">Pick the closest match. Its usual size is filled in next, and you can change any of it.</p>

        <nav class="list" aria-label="Vehicle types">
            <?php foreach ($types as $type => $definition): ?>
            <a class="list-row" href="/vehicles/create?type=<?= urlencode((string) $type) ?>">
                <span class="emoji-tile" aria-hidden="true"><span class="emoji"><?= $definition['emoji'] ?></span></span>
                <span class="list-main">
                    <span class="list-title"><?= htmlspecialchars($definition['label']) ?></span>
                    <span class="list-sub"><?= htmlspecialchars($definition['description']) ?></span>
                </span>
                <span class="list-trail"><?= Deck::icon('chevron-right') ?></span>
            </a>
            <?php endforeach; ?>
        </nav>
    </main>

    <?php require __DIR__ . '/../partials/back-to-top.php'; ?>
</body>
</html>
