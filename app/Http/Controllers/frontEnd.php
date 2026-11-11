<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Http\Controllers\dataController as DataController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Session;

class frontEnd extends Controller
{
    protected $db;
    protected $request;

    public function __construct(Request $request) {
        $this->db = DB::connection('finobe');
        $this->request = [
            'data' => [
                'embeds' => [
                    'title' => ' - ',
                    'description' => 'Finobe, it is website. He is for old brick-builder. Good for use.',
                    'url' => env('APP_URL'),
                    'image' => env('APP_URL') . '/s/img/'
                ],
                'csrf_token' => View::share('csrf_token', csrf_token()),
                'siteusername' => Auth::check(),
                'user' => [
                    'version' => 'v2'
                ],
                'page' => strtok($request->getRequestUri(), '?'),
				'dir' => str_replace('\\', '', '/' . explode('/', trim($request->getRequestUri(), '/'))[0] . '/'),
                'alerts' => [
                    'successv2' => Session::get('successv2', false),
					'success' => Session::get('success', false),
					'error' => Session::get('error', false) ?: (request()->query('upload') === 'too_big' ? "That file's too big (the server takes up to " . ini_get('post_max_size') . ' per upload).' : false),
					'announcements' => []
				],
                'lucky_number' => rand(0, Cache::remember('user_count', 3600, fn() => User::count())) . '/' . Cache::remember('user_count', 3600, fn() => User::count()),
                'social' => config('finobe.social'),
                'server_name' => config('finobe.server_name'), // shown after the lucky number, e.g. "(bumpy)"; blank hides it
                'string_replacements' => [
                    'phrasesToReplace' => [
                        'fuck',
                        'fucking',
                        'roblox',
                        'rob lox',
                        'robux',
                        'ass',
                        'asshole',
                        'shit',
                        'r*blox'
                    ],
                    'replacements' => [
                        'OBAMA BALL',
                        'sonic 06',
                        'blockland.us'
                    ]
                ]
            ]
        ];

        if($this->request['data']['alerts']['successv2']) {
			Session::forget('successv2');
		}

        if($this->request['data']['alerts']['success']) {
			Session::forget('success');
		}
		
		if($this->request['data']['alerts']['error']) {
			Session::forget('error');
		}

        if($this->request['data']['siteusername']) {
            $this->request['data']['user'] = Auth::user();

            if(!$this->request['data']['user']) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect('/');
            }

            $this->request['data']['user'] = $this->request['data']['user']->toArray();
            // the real site had no version picker: everyone gets the v2 pages
            $this->request['data']['user']['version'] = 'v2';
            $this->request['data']['user']['formattedDius'] = DataController::formatNumber($this->request['data']['user']['Dius']);
            $this->request['data']['embeds']['title'] .= 'Finobe';
            
            if($this->request['data']['user']['logo'] == 'v1') {
                $this->request['data']['embeds']['image'] .= 'BUSY.png';
            } elseif($this->request['data']['user']['logo'] == 'v2') {
                $this->request['data']['embeds']['image'] .= 'finnobe3.png';
            } else {
                $this->request['data']['embeds']['image'] .= 'finnobe3logo.png';
            }
            
            $this->request['data']['user']['places'] = $this->db->table('assets')
                ->where('author', $this->request['data']['user']['id'])
                ->where('asset_type', 9)
                ->count();
            
            $this->request['data']['notifications'] = [
                'data' => [],
                'ads' => (bool)env('FINOBE_ADS'),
                'info' => [
                    'number' => $this->db->table('pms')->where('touser', $this->request['data']['user']['id'])->where('readed', false)->count(),
                    'inbox' => $this->db->table('messages')->where('touser', $this->request['data']['user']['id'])->where('readed', false)->where('archived', false)->count(),
                    'trades' => \App\Services\Trading::pendingIncoming((int) $this->request['data']['user']['id']),
                    'incomingFriends' => 0
                ]
            ];

            $notifications = $this->db->table('pms')
                ->where('touser', $this->request['data']['user']['id'])
                ->orderBy('date', 'DESC')
                ->get()
                ->map(function ($item) {
                    return (array) $item;
                })->toArray();
            
            foreach($notifications as $notification) {
                $this->request['data']['notifications']['data'][] = [
                    'id' => $notification['id'],
                    'message' => $notification['message'],
                    'date' => date('M j Y g:i:s A', strtotime($notification['date']))
                ];
            }

            foreach($this->request['data']['user']['friends'] as $friend) {
                if($friend['status'] == 'pending') {
                    $this->request['data']['notifications']['info']['incomingFriends']++;
                }
            }
            
            if(strtotime($this->request['data']['user']['lastdiu'] ?? '') <= time() && ($this->request['data']['user']['diubanned'] ?? 'n') == 'n') {
                /*
                $this->db->table('users')
                    ->where('username', $this->request['data']['user']['username'])
                    ->update([
                        'Dius' => $this->request['data']['user']['Dius'] + 25,
                        'lastdiu' => DB::raw('DATE_ADD(CURRENT_TIMESTAMP(), INTERVAL 1 DAY)')
                    ]);
                */
                
                $user = Auth::user();
                $user->Dius = $this->request['data']['user']['Dius'] + 25;
                $user->lastdiu = now()->addDay();
                $user->save();
            }
        } else {
            $this->request['data']['embeds']['title'] .= 'Finobe';
            $this->request['data']['embeds']['image'] .= 'finnobe3logo.png';
        }

        $this->request['data']['alerts']['announcements'] = $this->db->table('announcements')
            ->select('message', 'expire', 'author', 'color')
            ->where('expire', '>', now())
            ->orderBy('id', 'DESC')
            ->get()
            ->map(function ($item) {
                return (array) $item;
            })->toArray();
    }

    public function getData() {
        return $this->request;
    }
}
