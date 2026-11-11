<?php

namespace App\Http\Controllers\Web;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Session;
use App\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;

class FriendsController extends Controller
{

    public function add($id) {
        if(!Auth::check()) {
            return redirect('/');
        }

        if(!User::find($id)) {
            Session::put('error', 'User does not exist');
            return redirect('/');
        }

        $user = User::find($id);
        
        if($user->id == Auth::user()->id) {
            return redirect('/user/' . $id);
        }

        foreach($user->friends as $friend) {
            if($friend['userid'] == Auth::user()->id) {
                return redirect('/user/' . $id);
            }
        }

        if(!\App\Services\Privacy::allows($user, 'friend_requests', \Illuminate\Support\Facades\Auth::user())) {
            Session::put('error', $user->username . " isn't accepting friend requests.");
            return redirect('/user/' . $id);
        }

        $friends = $user->friends;
        $friends[] = [
            'userid' => Auth::user()->id,
            'status' => 'pending'
        ];

        $user->friends = $friends;
        $user->save();

        return redirect('/user/' . $id);
    }

    public function accept(Request $request, $id) {
        $data = $request->all();

        if(!Auth::check()) {
            return redirect('/');
        }

        if(!User::find($id)) {
            Session::put('error', 'User does not exist');
            return redirect('/');
        }

        if(empty(array_filter(Auth::user()->friends, fn($entry) => $entry['userid'] == $id))) {
            if(isset($data['feature'])) {
                return redirect('/friends/incoming');
            }
            
            return redirect('/user/' . $id);
        }

        $user = User::find($id);
        
        if($user->id == Auth::user()->id) {
            if(isset($data['feature'])) {
                return redirect('/friends/incoming');
            }
            
            return redirect('/user/' . $id);
        }

        foreach($user->friends as $friend) {
            if($friend['userid'] == Auth::user()->id) {
                Session::put('error', 'You already added this user');
                if(isset($data['feature'])) {
                    return redirect('/friends/incoming');
                }
                
                return redirect('/user/' . $id);
            }
        }

        $friends = $user->friends;
        $friends[] = [
            'userid' => Auth::user()->id,
            'status' => 'friends'
        ];

        $user->friends = $friends;
        $user->save();

        $mine = Auth::user()->friends ?? [];

        foreach($mine as $key => $friend) {
            if($friend['userid'] == $user->id) {
                $mine[$key]['status'] = 'friends';
                break;
            }
        }

        User::where('id', Auth::user()->id)->update([
            'friends' => json_encode($mine)
        ]);

        if(isset($data['feature'])) {
            return redirect('/friends/incoming');
        }
        
        return redirect('/user/' . $id);
    }

    public function remove(Request $request, $id) {
        $data = $request->all();

        if(!Auth::check()) {
            return redirect('/');
        }

        if(!User::find($id)) {
            Session::put('error', 'User does not exist');
            return redirect('/');
        }

        if(empty(array_filter(Auth::user()->friends, fn($entry) => $entry['userid'] == $id))) {
            if(isset($data['feature'])) {
                return redirect('/friends/incoming');
            }
            
            return redirect('/user/' . $id);
        }

        $user = User::find($id);
        
        if($user->id == Auth::user()->id) {
            if(isset($data['feature'])) {
                return redirect('/friends/incoming');
            }
            
            return redirect('/user/' . $id);
        }

        $friends = $user->friends;

        foreach($friends as $key => $friend) {
            if($friend['userid'] == Auth::user()->id) {
                unset($friends[$key]);
                break;
            }
        }

        $user->friends = $friends;
        $user->save();

        $mine = Auth::user()->friends ?? [];

        foreach($mine as $key => $friend) {
            if($friend['userid'] == $user->id) {
                unset($mine[$key]);
                break;
            }
        }

        User::where('id', Auth::user()->id)->update([
            'friends' => json_encode($mine)
        ]);

        if(isset($data['feature'])) {
            return redirect('/friends/incoming');
        }
        
        return redirect('/user/' . $id);
    }

    public function list(Request $request, $id) {
        $data = $request->all();

        if(!Auth::check()) {
            return redirect('/');
        }

        if(!User::find($id)) {
            abort(404);
        }

        $user = User::find($id)->toArray();
        $user['friends'] = array_reverse(array_filter($user['friends'], fn($friend) => $friend['status'] == 'friends'));

        foreach($user['friends'] as $key => $friend) {
            $user['friends'][$key]['username'] = Cache::remember('username_' . $friend['userid'], 60 * 60, fn() => User::where('id', $friend['userid'])->value('username'));
            $user['friends'][$key]['pfp'] = Cache::remember('pfp_' . $friend['userid'], 60 * 60, fn() => User::where('id', $friend['userid'])->value('pfp'));
        }

        $pages_to_show = 10;
        $results_per_page = 12;
        $number_of_pages = ceil(count($user['friends']) / $results_per_page);
        $currentPage = isset($data['page']) ? max(1, intval($data['page'])) : 1;
        $offset = ($currentPage - 1) * $results_per_page;
        $start_page = max(1, min($currentPage - floor($pages_to_show / 2), $number_of_pages - $pages_to_show + 1));
        $end_page = min($number_of_pages, $start_page + $pages_to_show - 1);
        $user['friends'] = array_slice($user['friends'], $offset, $results_per_page);
        
        $pageData['pagination'] = [
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
            $pageData['pagination']['pages']['data'][] = ['page' => $page];
        }

        if(!count($user['friends'])) {
            $pageData['pagination']['pages']['data'][] = [
                'page' => 1
            ];
        }

        $pageData['profile'] = $user;
        $title = htmlspecialchars($user['username']) . '\'s Friends';

        return $this->page('User_friends', $pageData ?? [], $title ?? null);
    }

    public function incoming() {
        $title = 'Friends Incoming';

        if(!Auth::check()) {
            return redirect('/');
        }

        $incoming = array_reverse(array_filter(Auth::user()->friends ?? [], fn($friend) => $friend['status'] == 'pending'));

        foreach($incoming as $key => $friend) {
            $incoming[$key]['username'] = Cache::remember('username_' . $friend['userid'], 60 * 60, fn() => User::where('id', $friend['userid'])->value('username'));
            $incoming[$key]['pfp'] = Cache::remember('pfp_' . $friend['userid'], 60 * 60, fn() => User::where('id', $friend['userid'])->value('pfp'));
        }

        $pageData['incoming'] = $incoming;

        return $this->page('User_friends_incoming', $pageData ?? [], $title ?? null);
    }
}