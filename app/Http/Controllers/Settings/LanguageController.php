<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class LanguageController extends Controller
{
    public const LOCALES = [
        'en' => 'English (US)', 'zh-hk' => '中文(繁體)', 'pt-br' => 'Português (Brasil)',
        'es-us' => 'Español (Estados Unidos)', 'de-de' => 'Deutsch', 'pl-pl' => 'Polski (Polska)', 'tl' => 'Tagalog', 'th-th' => 'ไทย',
    ];

    public function show(Request $request)
    {
        return $this->page('Settings/Language', [
            'languages' => self::LOCALES,
            'locale' => $request->user()->locale ?: $request->session()->get('locale', 'en'),
        ], 'Language');
    }

    public function update(Request $request)
    {
        $locale = (string) $request->input('locale', $request->query('lang', ''));

        if (isset(self::LOCALES[$locale])) {
            $request->session()->put('locale', $locale);
            $request->user()?->forceFill(['locale' => $locale])->save();
        }

        return $request->expectsJson() || $request->ajax()
            ? response()->json(['code' => 200])
            : redirect($request->user() ? '/app/language' : '/');
    }
}
