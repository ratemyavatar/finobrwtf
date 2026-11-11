<?php

namespace App\Http\Controllers\Web;

use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Asset;
use App\Models\Server;
use App\Http\Controllers\frontEnd;
use App\Http\Controllers\Controller;

class UsersController extends Controller
{
    protected $request;

    public function __construct(Request $request) {
        $frontend = new frontEnd($request);
        $this->request = $frontend->getData();
    }

    public function index(Request $request) {
        $this->request['data']['embeds']['title'] = 'Users' . $this->request['data']['embeds']['title'];
        $data = $request->all();

        if(!$this->request['data']['siteusername']) {
            return redirect('/');
        }

        $users = [];
        $pages_to_show = 10;
        $results_per_page = 16;

        if(isset($data['search'])) {
            $search = '%' . htmlspecialchars($data['search']) . '%';
            $results = User::whereNotIn('username', function ($query) {
                $query->select('username')
                    ->from('bans')
                    ->where('perm', 'y');
                })
                ->whereRaw('LOWER(username) LIKE LOWER(?)', ["%{$search}%"])
                ->orderBy('lastlogin', 'desc')
                ->count();
        } else {
            $results = User::whereNotIn('username', function ($query) {
                $query->select('username')
                    ->from('bans')
                    ->where('perm', 'y');
                })
                ->orderBy('lastlogin', 'desc')
                ->count();
        }

        $number_of_pages = ceil($results / $results_per_page);
        $currentPage = isset($data['page']) ? max(1, intval($data['page'])) : 1;
        $offset = ($currentPage - 1) * $results_per_page;
        $start_page = max(1, min($currentPage - floor($pages_to_show / 2), $number_of_pages - $pages_to_show + 1));
        $end_page = min($number_of_pages, $start_page + $pages_to_show - 1);

        if(isset($data['search'])) {
            $results = User::whereNotIn('username', function ($query) {
                $query->select('username')
                    ->from('bans')
                    ->where('perm', 'y');
                })
                ->whereRaw('LOWER(username) LIKE LOWER(?)', ["%{$search}%"])
                ->orderBy('lastlogin', 'desc')
                ->offset($offset)
                ->limit($results_per_page)
                ->get()
                ->toArray();
        } else {
            $results = User::whereNotIn('username', function ($query) {
                $query->select('username')
                    ->from('bans')
                    ->where('perm', 'y');
                })
                ->orderBy('lastlogin', 'desc')
                ->offset($offset)
                ->limit($results_per_page)
                ->get()
                ->toArray();
        }

        $servers = Server::get()->map(fn($item) => $item->toArray());

        foreach($results as $key => $result) {
            $user = [
                'id' => $result['id'],
                'username' => $result['username'],
                'pfp' => $result['pfp'],
                'lastlogin' => date('m/d/Y h:i A', strtotime($result['lastlogin'])),
                'IsOnline' => Carbon::parse($result['lastlogin'])->gt(Carbon::now()->subMinutes(2)),
                'InGame' => false
            ];

            foreach($servers as $server) {
                $placeid = 0;
                $found = false;
    
                foreach($server['players'] as $player) {
                    if($player == $result['id']) {
                        $found = true;
                        $placeid = $server['placeid'];
                        break;
                    }
                }
    
                if($found) {
                    $user['InGame'] = true;
                    $user['game'] = [
                        'title' => strip_tags(htmlspecialchars(Asset::where('id', $placeid)->value('title')))
                    ];
                }
            }

            $users[] = $user;
        }

        foreach($users as $key => $user) {
            if($user['InGame']) {
                unset($users[$key]);
                array_unshift($users, $user);
            }
        }

        $this->request['data']['pagination'] = [
            'data' => [],
            'pages' => [
                'info' => [
                    'current_page' => $currentPage,
                    'previous_page' => max(1, $currentPage - 1),
                    'next_page' => min($number_of_pages, $currentPage + 1),
                    'start_page' => $start_page,
                    'end_page' => $end_page,
                    'number_of_pages' => $number_of_pages
                ],
                'data' =>[]
            ]
        ];

        for ($page = $start_page; $page <= $end_page; $page++) {
            $this->request['data']['pagination']['pages']['data'][] = ['page' => $page];
        }

        if(!count($users)) {
            $this->request['data']['pagination']['pages']['data'][] = [
                'page' => 1
            ];
        }

        $this->request['data']['users'] = $users;
        $this->request['data']['search'] = isset($data['search']) ? $data['search'] : false;

        return view($this->request['data']['user']['version'] . '/Users', $this->request);
    }

    public function gettoken(Request $request) {
        $user = $request->user();

        return $user ? response($user->token, 200) : response('', 204);
    }
}