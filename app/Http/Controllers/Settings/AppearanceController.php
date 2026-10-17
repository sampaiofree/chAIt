<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AppearanceController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'appearance' => ['required', 'string', Rule::in(['light', 'dark', 'system'])],
        ]);

        $request->user()->forceFill($validated)->save();

        return to_route('appearance.edit')->withCookie(cookie()->forget('appearance'));
    }
}
