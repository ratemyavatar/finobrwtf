<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class PasswordController extends Controller
{
    public function show()
    {
        return $this->page('Settings/Password', [], 'Password');
    }

    public function update(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'password' => 'required|string|confirmed|alpha_dash|min:8|max:255',
        ]);

        if ($validator->fails()) {
            $request->session()->put('error', $validator->errors()->first());

            return redirect('/app/password');
        }

        $user = $request->user();

        if (!Hash::check((string) $request->input('current_password'), $user->password)) {
            $request->session()->put('error', 'Your current password is wrong.');

            return redirect('/app/password');
        }

        $user->forceFill(['password' => Hash::make((string) $request->input('password'))])->save();
        Auth::logoutOtherDevices((string) $request->input('password'));
        $request->session()->put('success', 'Password changed.');

        return redirect('/app/password');
    }
}
