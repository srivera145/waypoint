<?php

namespace Keel\App\Controllers;

use Keel\App\Models\RoutePreference;
use Keel\App\Models\User;
use Keel\App\Models\Vehicle;
use Keel\App\Services\Units;
use Keel\Core\Auth;
use Keel\Core\Controller;
use Keel\Core\Request;

class DashboardController extends Controller
{
    public function index(Request $request): void
    {
        $userId = (int) Auth::id();
        $user = User::find($userId);
        $personality = RoutePreference::personalityForUser($userId);
        $isCustom = !in_array($personality, RoutePreference::PRIMARY_PERSONALITIES, true);

        $this->view('dashboard.index', [
            'title' => 'Waypoint',
            'user' => $user,
            'vehicle' => Vehicle::activeForUser($userId),
            'unitSystem' => Units::normalizeSystem($user['unit_system'] ?? null),
            'personality' => $personality,
            'isCustom' => $isCustom,
            // Custom's options show while one of them is the saved mode, or after choosing Custom.
            'showCustom' => $isCustom || $request->input('mode') === 'custom',
        ]);
    }
}
