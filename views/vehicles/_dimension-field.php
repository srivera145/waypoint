<?php
/**
 * One dimension input on the vehicle form. Required from views/vehicles/form.php with
 * $column (a key of VehicleType::FIELDS), $values, $errors and $applies set. Lengths and
 * weights share the form's #vehicle-units-help line rather than repeating it per field.
 *
 * A field that does not apply to the current type is rendered hidden and disabled, so it
 * neither shows nor posts; vehicle-form.js flips both when the type changes.
 */
use EchoDial\Deck\Deck;
use Keel\App\Models\VehicleType;

$field = VehicleType::FIELDS[$column];
$input = $field['input'];
$fieldId = 'vehicle-' . str_replace('_', '-', $input);
$fieldError = $errors[$input] ?? null;
$visible = in_array($column, $applies, true);
$hint = match ($column) {
    'axle_count' => 'Every axle, a trailer’s included.',
    'axle_load_kg' => 'The most weight carried by any one axle.',
    default => null,
};
$describedBy = trim(
    ($hint !== null ? $fieldId . '-help ' : '')
    . ($field['kind'] !== 'count' ? 'vehicle-units-help ' : '')
    . ($fieldError !== null ? $fieldId . '-error' : '')
);
?>
<div class="field<?= $fieldError !== null ? ' is-invalid' : '' ?>" data-field="<?= $input ?>"<?= $visible ? '' : ' hidden' ?>>
    <label class="label" for="<?= $fieldId ?>"><?= htmlspecialchars($field['label']) ?></label>
    <input
        type="text"
        id="<?= $fieldId ?>"
        name="<?= $input ?>"
        class="input nums"
        value="<?= htmlspecialchars((string) ($values[$input] ?? '')) ?>"
        inputmode="<?= $field['kind'] === 'count' ? 'numeric' : 'text' ?>"
        autocomplete="off"
        autocapitalize="off"
        spellcheck="false"
        aria-describedby="<?= $describedBy ?>"
        <?= $fieldError !== null ? 'aria-invalid="true"' : '' ?>
        <?= $visible ? '' : 'disabled' ?>
    >
    <?php if ($hint !== null): ?>
    <p class="help" id="<?= $fieldId ?>-help"><?= htmlspecialchars($hint) ?></p>
    <?php endif; ?>
    <?php if ($fieldError !== null): ?>
    <p class="error" id="<?= $fieldId ?>-error"><?= Deck::icon('alert-circle-sm', 'icon icon-sm') ?> <?= htmlspecialchars($fieldError) ?></p>
    <?php endif; ?>
</div>
