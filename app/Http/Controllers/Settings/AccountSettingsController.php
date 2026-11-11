<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\UsernameChange;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AccountSettingsController extends Controller
{
    public function show(Request $request)
    {
        $next = UsernameChange::nextAllowed($request->user());

        return $this->page('Settings/Index', [
            'nameChange' => ['cost' => UsernameChange::cost(), 'next' => $next?->format('m/d/Y h:i A')],
        ], 'Settings');
    }

    public function update(Request $request)
    {
        $user = $request->user();

        if ($request->hasFile('file')) {
            return $user->status === 'admin' ? $this->updatePicture($request) : back();
        }

        if ($request->has('new_username')) {
            $name = trim((string) $request->input('new_username'));

            if ($problem = UsernameChange::problem($user, $name)) {
                return $this->back($problem, false);
            }

            try {
                UsernameChange::apply($user, $name);
            } catch (\RuntimeException $e) {
                return $this->back($e->getMessage(), false);
            }

            return $this->back('Your username is now ' . $name . '.');
        }

        if ($request->has('gender')) {
            if (!in_array($request->input('gender'), ['m', 'f', 'n'], true)) {
                return $this->back('Pick a gender from the list.', false);
            }

            $user->forceFill(['gender' => $request->input('gender')])->save();

            return $this->back('Successfully updated.');
        }

        if ($request->has('blurb')) {
            $validator = Validator::make($request->all(), ['blurb' => 'required|string|max:8192']);

            if ($validator->fails()) {
                return $this->back($validator->errors()->first(), false);
            }

            $user->forceFill(['blurb' => $request->input('blurb')])->save();

            return $this->back('Successfully updated.');
        }

        if ($request->has('password')) {
            $validator = Validator::make($request->all(), [
                'email' => 'required|email|unique:finobe.users,email',
                'password' => 'required|string',
            ]);

            if ($validator->fails()) {
                return $this->back($validator->errors()->first(), false);
            }

            if (!Hash::check((string) $request->input('password'), $user->password)) {
                return $this->back('Your password is wrong.', false);
            }

            $user->forceFill(['email' => $request->input('email'), 'verified' => 'n'])->save();

            return $this->back('Your email has been changed.');
        }

        return redirect('/app/settings');
    }

    private function updatePicture(Request $request)
    {
        $validator = Validator::make($request->all(), ['file' => 'required|file|mimes:png,jpg,jpeg|max:10240']);

        if ($validator->fails()) {
            return $this->back($validator->errors()->first(), false);
        }

        $file = $request->file('file');
        $size = @getimagesize($file->getPathname());

        if (!$size || $size[0] !== $size[1]) {
            return $this->back('Image needs to be 1:1 ratio', false);
        }

        $filename = bin2hex(random_bytes(16)) . '.' . ($size[2] === IMAGETYPE_PNG ? 'png' : 'jpg');
        $file->move(config('finobe.cdn_path') . '/avatar/', $filename);
        $request->user()->forceFill(['pfp' => $filename])->save();

        return $this->back('Successfully updated.');
    }

    private function back(string $message, bool $success = true)
    {
        session()->put($success ? 'success' : 'error', $message);

        return redirect('/app/settings');
    }
}
