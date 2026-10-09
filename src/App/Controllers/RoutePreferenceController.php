<?php

namespace Keel\App\Controllers;

use Keel\App\Models\RoutePreference;
use Keel\App\Services\Maps\MapScreen;
use Keel\Core\Auth;
use Keel\Core\Controller;
use Keel\Core\Request;
use Keel\Core\Response;

class RoutePreferenceController extends Controller
{
    /**
     * The travel mode, from the home screen or the map: saved as the personality on the user's
     * default preferences. Answers JSON to a request that asks for it (the map screen), and
     * otherwise redirects back.
     */
    public function updateTravelMode(Request $request): void
    {
        $personality = (string) $request->input('personality', '');

        if (!in_array($personality, RoutePreference::PERSONALITIES, true)) {
            if ($request->wantsJson()) {
                $this->json(['error' => 'Choose a travel mode.', 'errors' => ['personality' => 'Choose a travel mode.']], 422);
            }

            Response::abort(422, 'Choose a travel mode.');
        }

        RoutePreference::savePersonality((int) Auth::id(), $personality);

        if ($request->wantsJson()) {
            $this->json(['personality' => $personality, 'label' => RoutePreference::LABELS[$personality]]);
        }

        // Back to the selector itself, which sits below the fold on a phone.
        $this->redirect(MapScreen::returnPath($request->input('return_to')) ?? '/dashboard#travel-mode');
    }
}
