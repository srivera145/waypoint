<?php

namespace Keel\App\Controllers;

use Keel\App\Models\RoutePreference;
use Keel\App\Models\User;
use Keel\App\Models\Vehicle;
use Keel\App\Services\Geocoding\PlaceCategory;
use Keel\App\Services\Geocoding\PlaceLookup;
use Keel\App\Services\Maps\MapScreen;
use Keel\App\Services\Maps\MapTiles;
use Keel\App\Services\Units;
use Keel\Core\Auth;
use Keel\Core\Controller;
use Keel\Core\Request;

/** GET /map: search, see up to three routes, compare them and save one. */
class MapController extends Controller
{
    public function index(Request $request): void
    {
        $userId = (int) Auth::id();
        $user = User::find($userId);
        $query = is_string($request->input('q')) ? PlaceLookup::normalizeQuery($request->input('q')) : '';

        $this->view('map.index', [
            'title' => 'Map · Waypoint',
            'vehicle' => Vehicle::activeForUser($userId),
            'unitSystem' => Units::normalizeSystem($user['unit_system'] ?? null),
            'personality' => RoutePreference::personalityForUser($userId),
            'tilesUrl' => MapTiles::browserUrl(),
            'categories' => PlaceCategory::all(),
            'initial' => [
                'q' => mb_substr($query, 0, PlaceLookup::MAX_QUERY_LENGTH),
                'from' => MapScreen::place($request->input('from'), $request->input('from_label')),
                'to' => MapScreen::place($request->input('to'), $request->input('to_label')),
            ],
        ]);
    }
}
