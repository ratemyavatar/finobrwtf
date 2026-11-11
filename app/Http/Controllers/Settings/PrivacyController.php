<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\Privacy;
use Illuminate\Http\Request;

class PrivacyController extends Controller
{
    public function show(Request $request)
    {
        $settings = [];
        foreach (Privacy::SETTINGS as $key => $setting) {
            $settings[$key] = $setting + ['value' => Privacy::level($request->user(), $key)];
        }

        return $this->page('Settings/Privacy', ['privacy' => ['settings' => $settings, 'levels' => Privacy::LEVELS]], 'Privacy');
    }

    public function update(Request $request)
    {
        $user = $request->user();

        foreach (Privacy::SETTINGS as $key => $setting) {
            $value = (string) $request->input($key, 'everyone');

            if (in_array($value, $setting['levels'], true)) {
                $user->{'privacy_' . $key} = $value;
            }
        }

        $user->save();
        $request->session()->put('success', 'Successfully updated.');

        return redirect('/app/privacy');
    }
}
