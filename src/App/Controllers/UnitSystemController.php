<?php

namespace Keel\App\Controllers;

use Keel\App\Models\User;
use Keel\App\Services\Units;
use Keel\Core\Auth;
use Keel\Core\Controller;
use Keel\Core\Request;
use Keel\Core\Response;

/** Imperial or metric, per user: how dimensions are shown and how a bare number is read. */
class UnitSystemController extends Controller
{
    private const RETURN_PATHS = ['/vehicles', '/dashboard'];

    public function update(Request $request): void
    {
        $unitSystem = strtolower(trim((string) $request->input('unit_system', '')));

        if (!in_array($unitSystem, Units::SYSTEMS, true)) {
            Response::abort(422, 'Choose imperial or metric.');
        }

        User::updateUnitSystem((int) Auth::id(), $unitSystem);

        $returnTo = (string) $request->input('return_to', '');
        $this->redirect(in_array($returnTo, self::RETURN_PATHS, true) ? $returnTo : '/vehicles');
    }
}
