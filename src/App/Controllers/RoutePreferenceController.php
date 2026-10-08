<?php

namespace Keel\App\Controllers;

use Keel\App\Models\RoutePreference;
use Keel\Core\Auth;
use Keel\Core\Controller;
use Keel\Core\Request;
use Keel\Core\Response;

class RoutePreferenceController extends Controller
{
    /** The home screen's travel mode: saved as the personality on the user's default preferences. */
    public function updateTravelMode(Request $request): void
    {
        $personality = (string) $request->input('personality', '');

        if (!in_array($personality, RoutePreference::PERSONALITIES, true)) {
            Response::abort(422, 'Choose a travel mode.');
        }

        RoutePreference::savePersonality((int) Auth::id(), $personality);

        // Back to the selector itself, which sits below the fold on a phone.
        $this->redirect('/dashboard#travel-mode');
    }
}
