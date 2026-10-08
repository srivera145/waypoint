<?php

namespace Keel\App\Services;

use Keel\App\Models\Vehicle;
use Keel\App\Models\VehicleType;
use Keel\Core\Activity;
use Keel\Core\Database;

/**
 * Validation and the rules that span rows: a user always has exactly one active vehicle, and
 * never has none. Every write takes a lock on the user's row first, so two requests from the
 * same user (a double-tapped "Use this vehicle") run one after the other.
 */
class VehicleService
{
    public const DELETED = 'deleted';
    public const NOT_FOUND = 'not_found';
    public const LAST_VEHICLE = 'last_vehicle';
    public const IN_USE = 'in_use';

    public const DEFAULT_NAME = 'My Car';
    private const NAME_MAX = 100;

    /**
     * Accepted ranges, metric. A minimum of 0 is exclusive: a weight has to be something.
     * Length is the vehicle alone; a trailer is checked on its own.
     */
    private const RANGES = [
        'length_m' => [1.0, 25.0],
        'width_m' => [0.5, 3.0],
        'height_m' => [1.0, 4.5],
        'weight_kg' => [0, 40000],
        'axle_count' => [2, 10],
        'axle_load_kg' => [500, 15000],
        'trailer_length_m' => [1.0, 20.0],
        'trailer_weight_kg' => [0, 40000],
    ];

    /**
     * Reads the vehicle form. Returns metric attributes ready for Vehicle::create/update, the
     * errors keyed by input name, and the raw input to put back in the form if there are any.
     *
     * @return array{attributes: array, errors: array<string, string>, old: array<string, string>}
     */
    public function validate(array $input, string $unitSystem): array
    {
        $old = $this->rawInput($input);
        $errors = [];

        $name = $old['name'];
        if ($name === '') {
            $errors['name'] = 'Give this vehicle a name.';
        } elseif (mb_strlen($name) > self::NAME_MAX) {
            $errors['name'] = 'Keep the name to ' . self::NAME_MAX . ' characters or fewer.';
        }

        $type = $old['type'];
        if (!VehicleType::exists($type)) {
            $errors['type'] = 'Choose a vehicle type.';
            $type = VehicleType::DEFAULT_TYPE;
        }

        $hasTrailer = match (VehicleType::trailerMode($type)) {
            VehicleType::TRAILER_REQUIRED => true,
            VehicleType::TRAILER_OPTIONAL => $old['has_trailer'] === '1',
            default => false,
        };

        $attributes = ['name' => $name, 'type' => $type, 'has_trailer' => $hasTrailer ? 1 : 0];

        foreach (VehicleType::FIELDS as $column => $field) {
            $attributes[$column] = null;

            if (!VehicleType::applies($type, $column, $hasTrailer)) {
                continue;
            }

            [$value, $error] = $this->readField($column, $old[$field['input']], $unitSystem);

            if ($error !== null) {
                $errors[$field['input']] = $error;
            }

            $attributes[$column] = $value;
        }

        return ['attributes' => $attributes, 'errors' => $errors, 'old' => $old];
    }

    public function create(int $userId, array $attributes, bool $makeActive = false): int
    {
        return $this->withUserLock($userId, function () use ($userId, $attributes, $makeActive): int {
            // A user's first vehicle is always active, whatever the form said.
            $active = $makeActive || Vehicle::countForUser($userId) === 0;

            if ($active) {
                Vehicle::deactivateAllForUser($userId);
            }

            $id = Vehicle::create($userId, $attributes, $active);
            Activity::log('vehicle.created', 'Vehicle', $id, ['type' => $attributes['type'], 'active' => $active]);

            return $id;
        });
    }

    public function update(int $userId, int $vehicleId, array $attributes): void
    {
        $this->withUserLock($userId, function () use ($userId, $vehicleId, $attributes): void {
            Vehicle::update($vehicleId, $userId, $attributes);
            Activity::log('vehicle.updated', 'Vehicle', $vehicleId, ['type' => $attributes['type']]);
        });
    }

    /** Makes one vehicle active and every other one inactive. False if it is not the user's. */
    public function activate(int $userId, int $vehicleId): bool
    {
        return $this->withUserLock($userId, function () use ($userId, $vehicleId): bool {
            if (Vehicle::findForUser($vehicleId, $userId) === null) {
                return false;
            }

            Vehicle::deactivateAllForUser($userId);
            Vehicle::markActive($vehicleId, $userId);
            Activity::log('vehicle.activated', 'Vehicle', $vehicleId);

            return true;
        });
    }

    /**
     * Deletes a vehicle unless it is the user's only one or a trip or saved route uses it.
     * Deleting the active vehicle hands "active" to the most recently changed one left.
     *
     * @return string one of the DELETED, NOT_FOUND, LAST_VEHICLE, IN_USE constants
     */
    public function delete(int $userId, int $vehicleId): string
    {
        return $this->withUserLock($userId, function () use ($userId, $vehicleId): string {
            $vehicle = Vehicle::findForUser($vehicleId, $userId);

            if ($vehicle === null) {
                return self::NOT_FOUND;
            }

            if (Vehicle::countForUser($userId) <= 1) {
                return self::LAST_VEHICLE;
            }

            if (Vehicle::isInUse($vehicleId, $userId)) {
                return self::IN_USE;
            }

            try {
                Vehicle::delete($vehicleId, $userId);
            } catch (\PDOException $exception) {
                // 1451: a trip or saved route arrived after the check above; RESTRICT refused.
                if ((int) ($exception->errorInfo[1] ?? 0) === 1451) {
                    return self::IN_USE;
                }

                throw $exception;
            }

            if ((int) $vehicle['is_active'] === 1) {
                $next = Vehicle::mostRecentForUser($userId, $vehicleId);

                if ($next !== null) {
                    Vehicle::markActive((int) $next['id'], $userId);
                }
            }

            Activity::log('vehicle.deleted', 'Vehicle', $vehicleId, ['name' => (string) $vehicle['name']]);

            return self::DELETED;
        });
    }

    /** Gives a user with no vehicles an active "My Car". Returns its id, or null if they had one. */
    public function ensureDefaultVehicle(int $userId): ?int
    {
        return $this->withUserLock($userId, function () use ($userId): ?int {
            if (Vehicle::countForUser($userId) > 0) {
                return null;
            }

            $type = VehicleType::DEFAULT_TYPE;
            $attributes = ['name' => self::DEFAULT_NAME, 'type' => $type, 'has_trailer' => 0];
            $defaults = VehicleType::defaults($type);

            foreach (VehicleType::fields($type) as $column) {
                $attributes[$column] = $defaults[$column] ?? null;
            }

            $id = Vehicle::create($userId, $attributes, true);
            Activity::log('vehicle.created', 'Vehicle', $id, ['type' => $type, 'active' => true, 'default' => true]);

            return $id;
        });
    }

    /** A stored vehicle as the strings its edit form shows, in the user's units. */
    public function formValues(array $vehicle, string $unitSystem): array
    {
        $values = [
            'name' => (string) $vehicle['name'],
            'type' => (string) $vehicle['type'],
            'has_trailer' => (int) $vehicle['has_trailer'] === 1 ? '1' : '',
        ];

        foreach (VehicleType::FIELDS as $column => $field) {
            $values[$field['input']] = $this->formatField($column, $vehicle[$column] ?? null, $unitSystem);
        }

        return $values;
    }

    /** The form for a new vehicle of $type, filled with that type's defaults. */
    public function defaultFormValues(string $type, string $unitSystem): array
    {
        $defaults = VehicleType::defaults($type);
        $values = [
            'name' => '',
            'type' => $type,
            'has_trailer' => VehicleType::trailerMode($type) === VehicleType::TRAILER_REQUIRED ? '1' : '',
        ];

        foreach (VehicleType::FIELDS as $column => $field) {
            $values[$field['input']] = $this->formatField($column, $defaults[$column] ?? null, $unitSystem);
        }

        return $values;
    }

    /**
     * What the form's script needs to show the right fields and fill defaults when the type
     * changes: per type, its label, the inputs that apply without a trailer, its trailer mode,
     * and its defaults as the strings the inputs take.
     */
    public function typesForForm(string $unitSystem): array
    {
        $types = [];

        foreach (VehicleType::all() as $type => $definition) {
            $types[$type] = [
                'label' => $definition['label'],
                'fields' => array_map(static fn (string $column): string => VehicleType::FIELDS[$column]['input'], VehicleType::fields($type)),
                'trailer' => VehicleType::trailerMode($type),
                'defaults' => array_diff_key($this->defaultFormValues($type, $unitSystem), ['name' => true, 'type' => true, 'has_trailer' => true]),
            ];
        }

        return $types;
    }

    public function formatField(string $column, mixed $value, string $unitSystem): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return match (VehicleType::FIELDS[$column]['kind']) {
            'length' => Units::formatLength((float) $value, $unitSystem),
            'weight' => Units::formatWeight((float) $value, $unitSystem),
            default => (string) (int) $value,
        };
    }

    /** @return array{0: int|float|null, 1: ?string} the metric value and an error message */
    private function readField(string $column, string $raw, string $unitSystem): array
    {
        $field = VehicleType::FIELDS[$column];
        $label = $field['label'];
        [$min, $max] = self::RANGES[$column];

        if ($raw === '') {
            return [null, 'Enter the ' . strtolower($label) . '.'];
        }

        if ($field['kind'] === 'count') {
            $count = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);

            return $count === false
                ? [null, "Enter a whole number of axles from {$min} to {$max}."]
                : [$count, null];
        }

        $isLength = $field['kind'] === 'length';
        $value = $isLength ? Units::parseLength($raw, $unitSystem) : Units::parseWeight($raw, $unitSystem);

        if ($value === null) {
            $examples = $isLength ? Units::lengthExamples($unitSystem) : Units::weightExamples($unitSystem);

            return [null, "We couldn't read that " . strtolower($label) . ". Try something like {$examples}."];
        }

        $tooSmall = $min == 0 ? $value <= 0 : $value < $min;

        if ($tooSmall || $value > $max) {
            $upper = $this->bound($column, $max, $unitSystem, false);
            $message = $min == 0
                ? "{$label} must be more than 0 and at most {$upper}."
                : "{$label} must be between " . $this->bound($column, $min, $unitSystem, true) . " and {$upper}.";

            return [null, $message];
        }

        // Stored to the millimetre and the hundredth of a kilogram: finer than any display unit.
        return [round($value, $isLength ? 3 : 2), null];
    }

    /**
     * A range limit in the user's units, rounded inward to the display unit so the message
     * never names a value the check would refuse: the 1 m minimum height reads 3'4", because
     * 3'3" is 0.99 m.
     */
    private function bound(string $column, float $metric, string $unitSystem, bool $isMinimum): string
    {
        $metricUnits = Units::normalizeSystem($unitSystem) === Units::METRIC;
        $isLength = VehicleType::FIELDS[$column]['kind'] === 'length';

        $step = match (true) {
            $isLength && $metricUnits => 0.01,
            $isLength => Units::METRES_PER_INCH,
            $metricUnits => 1.0,
            default => Units::KG_PER_POUND,
        };

        $steps = $isMinimum ? ceil($metric / $step - 1e-9) : floor($metric / $step + 1e-9);

        return $this->formatField($column, $steps * $step, $unitSystem);
    }

    /** Every input the form posts, as trimmed strings, so the form can show them back. */
    private function rawInput(array $input): array
    {
        $old = [];

        foreach (['name', 'type', 'has_trailer'] as $key) {
            $old[$key] = trim((string) ($input[$key] ?? ''));
        }

        foreach (VehicleType::FIELDS as $field) {
            $old[$field['input']] = trim((string) ($input[$field['input']] ?? ''));
        }

        return $old;
    }

    /**
     * Runs $work in a transaction holding a lock on the user's row. Every vehicle write goes
     * through here, which is what makes "exactly one active vehicle" hold under concurrency:
     * the row lock exists even when the user has no vehicles yet to lock.
     */
    private function withUserLock(int $userId, \Closure $work): mixed
    {
        $connection = Database::connection();
        $connection->beginTransaction();

        try {
            $lock = $connection->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
            $lock->execute([$userId]);

            if (!$lock->fetch()) {
                throw new \RuntimeException('User not found.');
            }

            $result = $work();
            $connection->commit();

            return $result;
        } catch (\Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            throw $exception;
        }
    }
}
