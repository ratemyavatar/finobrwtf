<?php

namespace App\Http\Controllers\Web;

use Illuminate\Http\Request;
use App\Models\Ban;
use App\Models\User;
use App\Models\Warning;
use App\Http\Controllers\frontEnd;
use App\Http\Controllers\Controller;

class LegalController extends Controller
{
    protected $request;

    public function __construct(Request $request) {
        $frontend = new frontEnd($request);
        $this->request = $frontend->getData();
    }

    public function about_us(Request $request) {
        $this->request['data']['embeds']['title'] = 'About us' . $this->request['data']['embeds']['title'];
        $this->request['data']['about'] = ['team' => config('finobe.about_team', [])];
        $this->request['data']['invitekeys'] = \App\Admin\Settings::invitesRequired();

        return view($this->request['data']['user']['version'] . '/About-us', $this->request);
    }

    public function welcome(Request $request) {
        $this->request['data']['embeds']['title'] = 'Welcome' . $this->request['data']['embeds']['title'];

        if(!$this->request['data']['siteusername']) {
            return redirect('/');
        }

        return view($this->request['data']['user']['version'] . '/Welcome', $this->request);
    }

    public function rules(Request $request) {
        $this->request['data']['embeds']['title'] = 'Rules' . $this->request['data']['embeds']['title'];

        return view($this->request['data']['user']['version'] . '/Rules', $this->request);
    }

    public function terms(Request $request) {
        $this->request['data']['embeds']['title'] = 'Terms of Service' . $this->request['data']['embeds']['title'];

        return view($this->request['data']['user']['version'] . '/Terms', $this->request);
    }

    public function privacy(Request $request) {
        $this->request['data']['embeds']['title'] = 'Privacy Policy' . $this->request['data']['embeds']['title'];

        return view('v2/PrivacyPolicy', $this->request);
    }

    public function transparency_bans(Request $request) {
        $this->request['data']['embeds']['title'] = 'Public Ban List' . $this->request['data']['embeds']['title'];
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
        $this->request['data']['bans'] = $bans;

        return view($this->request['data']['user']['version'] . '/Bans', $this->request);
    }
}