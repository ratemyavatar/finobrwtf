<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ThemeController extends Controller
{
    private const TOGGLES = ['dark' => 'theme', 'gary' => 'gary'];

    public function show()
    {
        return $this->page('Settings/Theme', [], 'Theme');
    }

    public function update(Request $request)
    {
        $user = $request->user();

        if ($request->has('logo')) {
            if (!in_array($request->input('logo'), ['v1', 'v2', 'v3'], true)) {
                $request->session()->put('error', 'Pick a logo from the list.');

                return redirect('/app/theme');
            }

            $user->forceFill(['logo' => $request->input('logo')])->save();
        } else {
            foreach (self::TOGGLES as $input => $column) {
                if ($request->has($input)) {
                    $user->forceFill([$column => (int) !$user->$column])->save();
                    break;
                }
            }
        }

        $request->session()->put('successv2', 'Successfully updated.');

        return redirect('/app/theme');
    }
}
