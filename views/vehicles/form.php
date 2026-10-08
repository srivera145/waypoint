<?php
use EchoDial\Deck\Deck;
use Keel\App\Models\VehicleType;
use Keel\App\Services\Units;
use Keel\Core\Csrf;
use Keel\Core\Theme;

$vehicle = $vehicle ?? null;
$values = $values ?? [];
$errors = $errors ?? [];
$types = $types ?? VehicleType::all();
$typesForForm = $typesForForm ?? [];
$unitSystem = $unitSystem ?? Units::IMPERIAL;
$makeActive = $makeActive ?? false;
$canDelete = $canDelete ?? false;

$isEdit = $vehicle !== null;
$vehicleId = $isEdit ? (int) $vehicle['id'] : 0;
$action = $isEdit ? "/vehicles/{$vehicleId}/update" : '/vehicles';

// The server draws the form for the type it was given; vehicle-form.js redraws it on change.
$selectedType = VehicleType::exists($values['type'] ?? null) ? (string) $values['type'] : VehicleType::DEFAULT_TYPE;
$trailerMode = VehicleType::trailerMode($selectedType);
$hasTrailer = $trailerMode === VehicleType::TRAILER_REQUIRED
    || ($trailerMode === VehicleType::TRAILER_OPTIONAL && ($values['has_trailer'] ?? '') === '1');
$applies = VehicleType::fields($selectedType, $hasTrailer);
$hasDimensions = VehicleType::fields($selectedType) !== [];
$nameError = $errors['name'] ?? null;
$typeError = $errors['type'] ?? null;
?>
<!DOCTYPE html>
<html <?= Deck::htmlAttributes(lang: 'en') ?> <?= Deck::theme(mode: Theme::serverPreference()) ?>>
<head>
<?php require __DIR__ . '/../partials/head.php'; ?>
<script src="<?= htmlspecialchars($publicAsset('/js/vehicle-form.js'), ENT_QUOTES, 'UTF-8') ?>" defer></script>
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
                        <li><span aria-current="page"><?= $isEdit ? 'Edit' : 'Add' ?></span></li>
                    </ol>
                </nav>
                <h1 class="h2"><?= $isEdit ? 'Edit vehicle' : 'Add a vehicle' ?></h1>
            </div>
            <?php $themeToggleClass = 'push'; require __DIR__ . '/../partials/theme-toggle.php'; ?>
        </header>

        <?php if ($errors !== []): ?>
        <div class="alert alert-bad" role="alert">
            <?= Deck::icon('alert-circle') ?>
            <div>
                <p class="alert-title">Some details need another look.</p>
                <p class="alert-body">Nothing was saved. Check the fields marked below.</p>
            </div>
        </div>
        <?php endif; ?>

        <form method="POST" action="<?= $action ?>" class="card" novalidate data-vehicle-form data-vehicle-types="<?= htmlspecialchars((string) json_encode($typesForForm), ENT_QUOTES, 'UTF-8') ?>">
            <?= Csrf::field() ?>
            <div class="card-body">
                <div class="stack stack-4">
                    <div class="field<?= $nameError !== null ? ' is-invalid' : '' ?>">
                        <label class="label" for="vehicle-name">Name</label>
                        <input type="text" id="vehicle-name" name="name" class="input" maxlength="100" placeholder="The camper" value="<?= htmlspecialchars((string) ($values['name'] ?? '')) ?>" required<?= $nameError !== null ? ' aria-invalid="true" aria-describedby="vehicle-name-error"' : '' ?>>
                        <?php if ($nameError !== null): ?>
                        <p class="error" id="vehicle-name-error"><?= Deck::icon('alert-circle-sm', 'icon icon-sm') ?> <?= htmlspecialchars($nameError) ?></p>
                        <?php endif; ?>
                    </div>

                    <div class="field<?= $typeError !== null ? ' is-invalid' : '' ?>">
                        <label class="label" for="vehicle-type">Type</label>
                        <select id="vehicle-type" name="type" class="select" aria-describedby="vehicle-type-help<?= $typeError !== null ? ' vehicle-type-error' : '' ?>">
                            <?php foreach ($types as $type => $definition): ?>
                            <option value="<?= htmlspecialchars((string) $type) ?>"<?= $type === $selectedType ? ' selected' : '' ?>><?= htmlspecialchars($definition['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="help" id="vehicle-type-help">Changing the type fills in its usual size.</p>
                        <?php if ($typeError !== null): ?>
                        <p class="error" id="vehicle-type-error"><?= Deck::icon('alert-circle-sm', 'icon icon-sm') ?> <?= htmlspecialchars($typeError) ?></p>
                        <?php endif; ?>
                    </div>

                    <p class="text-sm text-muted" data-no-dimensions<?= $hasDimensions ? ' hidden' : '' ?>>Bike and walking routes don't need any dimensions.</p>

                    <fieldset class="fieldset" data-size-section<?= $hasDimensions ? '' : ' hidden' ?>>
                        <legend>Size and weight</legend>
                        <p class="help" id="vehicle-units-help">Type lengths like <?= htmlspecialchars(Units::lengthExamples($unitSystem)) ?>, and weights like <?= htmlspecialchars(Units::weightExamples($unitSystem)) ?>.</p>
                        <div class="field-row">
                            <?php foreach (['length_m', 'width_m', 'height_m', 'weight_kg', 'axle_count', 'axle_load_kg'] as $column): ?>
                            <?php require __DIR__ . '/_dimension-field.php'; ?>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>

                    <div class="stack stack-4" data-trailer-section<?= $trailerMode === VehicleType::TRAILER_NONE ? ' hidden' : '' ?>>
                        <label class="check" data-trailer-toggle<?= $trailerMode === VehicleType::TRAILER_OPTIONAL ? '' : ' hidden' ?>>
                            <input type="checkbox" name="has_trailer" value="1"<?= $hasTrailer ? ' checked' : '' ?><?= $trailerMode === VehicleType::TRAILER_OPTIONAL ? '' : ' disabled' ?>>
                            <span class="check-text">
                                <span>Towing a trailer</span>
                                <span class="check-note">A camper, boat or cargo trailer behind it.</span>
                            </span>
                        </label>

                        <fieldset class="fieldset" data-trailer-fields<?= $hasTrailer ? '' : ' hidden' ?>>
                            <legend>Trailer</legend>
                            <div class="field-row">
                                <?php foreach (VehicleType::TRAILER_FIELDS as $column): ?>
                                <?php require __DIR__ . '/_dimension-field.php'; ?>
                                <?php endforeach; ?>
                            </div>
                        </fieldset>
                    </div>

                    <?php if (!$isEdit): ?>
                    <label class="check">
                        <input type="checkbox" name="make_active" value="1"<?= $makeActive ? ' checked' : '' ?>>
                        <span class="check-text">
                            <span>Make this my active vehicle</span>
                            <span class="check-note">Routes are planned for your active vehicle.</span>
                        </span>
                    </label>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-footer">
                <a class="btn" href="/vehicles">Cancel</a>
                <button type="submit" class="btn btn-primary push"><?= $isEdit ? 'Save vehicle' : 'Add vehicle' ?></button>
            </div>
        </form>

        <?php if ($isEdit): ?>
        <section class="card" aria-labelledby="delete-title">
            <div class="card-body">
                <h2 id="delete-title" class="card-title">Delete vehicle</h2>
                <?php if ($canDelete): ?>
                <p class="text-sm text-muted">Removes <?= htmlspecialchars((string) $vehicle['name']) ?> and its route preferences. This can't be undone.</p>
                <form id="delete-vehicle-form" method="POST" action="/vehicles/<?= $vehicleId ?>/delete" data-confirm="delete-vehicle-dialog">
                    <?= Csrf::field() ?>
                    <button type="submit" class="btn btn-danger btn-sm">Delete vehicle</button>
                </form>
                <?php else: ?>
                <p class="text-sm text-muted">This is your only vehicle. Add another before deleting it.</p>
                <?php endif; ?>
            </div>
        </section>
        <?php endif; ?>
    </main>

    <?php if ($isEdit && $canDelete): ?>
    <dialog class="modal" id="delete-vehicle-dialog" aria-labelledby="delete-dialog-title">
        <div class="modal-header">
            <h2 class="modal-title" id="delete-dialog-title">Delete this vehicle?</h2>
        </div>
        <div class="modal-body">
            <p><strong><?= htmlspecialchars((string) $vehicle['name']) ?></strong> and its route preferences will be removed.</p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn" data-modal-close>Keep it</button>
            <button type="button" class="btn btn-danger" data-confirm-submit="delete-vehicle-form">Delete vehicle</button>
        </div>
    </dialog>
    <?php endif; ?>

    <?php require __DIR__ . '/../partials/back-to-top.php'; ?>
</body>
</html>
