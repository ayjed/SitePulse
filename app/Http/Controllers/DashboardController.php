<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * Render the minimal authenticated owner dashboard placeholder.
     */
    public function __invoke(Request $request): View
    {
        return view('dashboard', [
            'owner' => $request->user(),
        ]);
    }
}
