<?php

namespace App\Http\Controllers\Web;

use Illuminate\Http\Request;
use App\Models\Ban;
use App\Models\User;
use App\Models\Warning;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;

class LegalController extends Controller
{

    public function about_us(Request $request) {
        $title = 'About us';
        $pageData['about'] = ['team' => config('finobe.about_team', [])];
        $pageData['invitekeys'] = \App\Admin\Settings::invitesRequired();

        return $this->page('About-us', $pageData ?? [], $title ?? null);
    }

    public function welcome(Request $request) {
        $title = 'Welcome';

        if(!Auth::check()) {
            return redirect('/');
        }

        return $this->page('Welcome', $pageData ?? [], $title ?? null);
    }

    public function rules(Request $request) {
        $title = 'Rules';

        return $this->page('Rules', $pageData ?? [], $title ?? null);
    }

    public function terms(Request $request) {
        $title = 'Terms of Service';

        return $this->page('Terms', $pageData ?? [], $title ?? null);
    }

    public function privacy(Request $request) {
        $title = 'Privacy Policy';

        return $this->page('PrivacyPolicy', $pageData ?? [], $title ?? null);
    }

    public function transparency_bans(Request $request) {
        $title = 'Public Ban List';
        $data = $request->all();

        $bans = [];
        $results = Ban::whereIn('username', function ($subquery) {
                $subquery->select('username')->from('users');
            })
            ->orderByDesc('id')
            ->limit(50);

        if(isset($data['q'])) {
            $search = '%' . $data['q'] . '%';
            $results->where('username', 'like', $search);
        }

        $results = $results->get()
            ->map(fn($item) => $item->toArray());
        
        foreach($results as $result) {
            if(!User::where('username', $result['username'])->exists()) {
                continue;
            }

            $result['username'] = htmlspecialchars($result['username']);
            $result['date'] = date('Y-m-d', strtotime($result['date']));
            $result['expire'] = date('Y-m-d', strtotime($result['expire']));
            $bans[] = $result;
        }

        $results = Warning::whereIn('username', function ($subquery) {
                $subquery->select('username')->from('users');
            })
            ->orderByDesc('id')
            ->limit(50);

        if(isset($data['q'])) {
            $search = '%' . $data['q'] . '%';
            $results->where('username', 'like', $search);
        }

        $results = $results->get()
            ->map(fn($item) => $item->toArray());

        foreach($results as $result) {
            if(!User::where('username', $result['username'])->exists()) {
                continue;
            }

            $result['username'] = htmlspecialchars($result['username']);
            $result['date'] = date('Y-m-d', strtotime($result['date']));
            $bans[] = $result;
        }

        usort($bans, fn($a, $b) => strtotime($b['date']) - strtotime($a['date']));
        $pageData['bans'] = $bans;

        return $this->page('Bans', $pageData ?? [], $title ?? null);
    }
}