<?php

namespace Keel\App\Controllers;

use Keel\App\Models\User;
use Keel\App\Models\Vehicle;
use Keel\App\Models\VehicleType;
use Keel\App\Services\Units;
use Keel\App\Services\VehicleService;
use Keel\Core\Auth;
use Keel\Core\Controller;
use Keel\Core\ErrorHandler;
use Keel\Core\Request;

class VehicleController extends Controller
{
    /** ?status= and ?error= are looked up here, never echoed, so a link cannot put words on the page. */
    private const STATUS_MESSAGES = [
        'created' => 'Vehicle added.',
        'updated' => 'Vehicle saved.',
        'deleted' => 'Vehicle deleted.',
        'activated' => 'Active vehicle changed.',
    ];

    private const ERROR_MESSAGES = [
        VehicleService::LAST_VEHICLE => 'You need at least one vehicle. Add another before deleting this one.',
        VehicleService::IN_USE => "A trip or saved route uses this vehicle, so it can't be deleted yet.",
    ];

    public function index(Request $request): void
    {
        $user = $this->currentUser();

        $this->view('vehicles.index', [
            'title' => 'Your vehicles',
            'vehicles' => Vehicle::forUser((int) $user['id']),
            'unitSystem' => Units::normalizeSystem($user['unit_system'] ?? null),
            'status' => self::STATUS_MESSAGES[(string) $request->input('status', '')] ?? null,
            'error' => self::ERROR_MESSAGES[(string) $request->input('error', '')] ?? null,
        ]);
    }

    /** Without ?type= this asks what they drive; with one, it shows the form filled with that type's defaults. */
    public function create(Request $request): void
    {
        $user = $this->currentUser();
        $unitSystem = Units::normalizeSystem($user['unit_system'] ?? null);
        $type = (string) $request->input('type', '');

        if (!VehicleType::exists($type)) {
            $this->view('vehicles.choose-type', [
                'title' => 'Add a vehicle',
                'types' => VehicleType::all(),
                'unitSystem' => $unitSystem,
            ]);
            return;
        }

        $values = (new VehicleService())->defaultFormValues($type, $unitSystem);
        $values['name'] = VehicleType::label($type);

        $this->renderForm($user, null, $values, [], true);
    }

    public function store(Request $request): void
    {
        $user = $this->currentUser();
        $service = new VehicleService();
        $result = $service->validate($request->all(), Units::normalizeSystem($user['unit_system'] ?? null));
        $makeActive = (string) $request->input('make_active', '') === '1';

        if ($result['errors'] !== []) {
            http_response_code(422);
            $this->renderForm($user, null, $result['old'], $result['errors'], $makeActive);
            return;
        }

        $service->create((int) $user['id'], $result['attributes'], $makeActive);
        $this->redirect('/vehicles?status=created');
    }

    public function edit(Request $request, string $id): void
    {
        $user = $this->currentUser();
        $vehicle = $this->findOwnedOr404($id, (int) $user['id']);
        $values = (new VehicleService())->formValues($vehicle, Units::normalizeSystem($user['unit_system'] ?? null));

        $this->renderForm($user, $vehicle, $values, []);
    }

    public function update(Request $request, string $id): void
    {
        $user = $this->currentUser();
        $vehicle = $this->findOwnedOr404($id, (int) $user['id']);
        $service = new VehicleService();
        $result = $service->validate($request->all(), Units::normalizeSystem($user['unit_system'] ?? null));

        if ($result['errors'] !== []) {
            http_response_code(422);
            $this->renderForm($user, $vehicle, $result['old'], $result['errors']);
            return;
        }

        $service->update((int) $user['id'], (int) $vehicle['id'], $result['attributes']);
        $this->redirect('/vehicles?status=updated');
    }

    public function destroy(Request $request, string $id): void
    {
        $user = $this->currentUser();
        $vehicle = $this->findOwnedOr404($id, (int) $user['id']);
        $result = (new VehicleService())->delete((int) $user['id'], (int) $vehicle['id']);

        match ($result) {
            VehicleService::DELETED => $this->redirect('/vehicles?status=deleted'),
            VehicleService::NOT_FOUND => ErrorHandler::render(404),
            default => $this->redirect('/vehicles?error=' . $result),
        };
    }

    public function activate(Request $request, string $id): void
    {
        $user = $this->currentUser();
        $vehicle = $this->findOwnedOr404($id, (int) $user['id']);

        if (!(new VehicleService())->activate((int) $user['id'], (int) $vehicle['id'])) {
            ErrorHandler::render(404);
        }

        // The home screen's vehicle card can switch and land back home; anywhere else returns to the list.
        $this->redirect((string) $request->input('return_to', '') === '/dashboard' ? '/dashboard' : '/vehicles?status=activated');
    }

    private function renderForm(array $user, ?array $vehicle, array $values, array $errors, bool $makeActive = false): void
    {
        $userId = (int) $user['id'];
        $unitSystem = Units::normalizeSystem($user['unit_system'] ?? null);

        $this->view('vehicles.form', [
            'title' => $vehicle === null ? 'Add a vehicle' : 'Edit ' . $vehicle['name'],
            'vehicle' => $vehicle,
            'values' => $values,
            'errors' => $errors,
            'makeActive' => $makeActive,
            'unitSystem' => $unitSystem,
            'types' => VehicleType::all(),
            'typesForForm' => (new VehicleService())->typesForForm($unitSystem),
            'canDelete' => $vehicle !== null && Vehicle::countForUser($userId) > 1,
        ]);
    }

    /** Another user's vehicle is indistinguishable from one that does not exist. */
    private function findOwnedOr404(string $id, int $userId): array
    {
        $vehicle = ctype_digit($id) ? Vehicle::findForUser((int) $id, $userId) : null;

        if ($vehicle === null) {
            ErrorHandler::render(404);
        }

        return $vehicle;
    }

    private function currentUser(): array
    {
        $user = User::find((int) Auth::id());

        if ($user === null) {
            throw new \RuntimeException('Authenticated user could not be resolved.');
        }

        return $user;
    }
}
