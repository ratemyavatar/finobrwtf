<?php

namespace App\Http\Controllers\Web;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use App\Models\Keys;
use App\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;

class InvitesController extends Controller
{

    public function index(Request $request) {
        $title = 'Invites';

        if(!Auth::check()) {
            return redirect('/');
        }

        $keys = [
            'data' => [],
            'info' => [
                'created' => Keys::where('author', Auth::user()->username)->where(DB::raw('MONTH(creation)'), DB::raw('MONTH(NOW())'))->where(DB::raw('YEAR(creation)'), DB::raw('YEAR(NOW())'))->count()
            ]
        ];

        $inviteKeys = Keys::where('author', Auth::user()->username)
            ->orderBy('creation', 'DESC')
            ->get()
            ->map(fn($item) => $item->toArray());
        
        foreach($inviteKeys as $key) {
            $key['creation'] = date('m/d/Y', strtotime($key['creation']));

            if($key['used'] && $key['used'] !== 'n') {   // 'y' or 1 depending on the column type
                $key['uuid'] = User::where('username', $key['usedBy'])->value('id');
                $key['dateUsed'] = date('m/d/Y', strtotime($key['dateUsed']));
            }

            $keys['data'][] = $key;
        }

        $pageData['keys'] = $keys;
        $pageData['tree'] = self::inviteTree(Auth::user()->username);

        return $this->page('Invitations/Invites', $pageData ?? [], $title ?? null);
    }

    /*
        inviteTree
         - who invited this user, and everyone they invited (and who those people invited, ...)
         - built from invite keys: author = who made the key, usedBy = who signed up with it
    */
    private static function inviteTree(string $username): array
    {
        $maxDepth = 5;
        $budget = 500;   // never walk more than this many people

        $inviter = Keys::where('usedBy', $username)->where('used', '!=', 'n')->value('author');

        $build = function (string $name, int $depth) use (&$build, &$budget, $maxDepth) {
            $children = [];

            if ($depth >= $maxDepth || $budget <= 0) {
                return $children;
            }

            $keys = Keys::where('author', $name)
                ->where('used', '!=', 'n')
                ->whereNotNull('usedBy')
                ->orderBy('dateUsed')
                ->get(['usedBy', 'dateUsed']);

            foreach ($keys as $key) {
                if ($budget-- <= 0) {
                    break;
                }

                $children[] = [
                    'username' => $key->usedBy,
                    'id' => User::where('username', $key->usedBy)->value('id'),
                    'joined' => $key->dateUsed ? date('m/d/Y', strtotime($key->dateUsed)) : null,
                    'children' => $build((string) $key->usedBy, $depth + 1),
                ];
            }

            return $children;
        };

        $children = $build($username, 0);

        $count = function (array $nodes) use (&$count) {
            return array_sum(array_map(fn($n) => 1 + $count($n['children']), $nodes));
        };

        return [
            'inviter' => $inviter ? ['username' => $inviter, 'id' => User::where('username', $inviter)->value('id')] : null,
            'children' => $children,
            'total' => $count($children),
            'truncated' => $budget <= 0,
        ];
    }

    public function new(Request $request) {
        $title = 'Invites';
        $data = $request->all();

        if(!Auth::check()) {
            return redirect('/');
        }

        if($request->isMethod('post')) {
            if(!(bool)env('FINOBE_INVITE_KEYS')) {
                Session::put('error', 'Invite keys are disabled');
                return redirect('/invites');
            }

            if(!(bool)env('FINOBE_CREATE_INVITE_KEYS')) {
                Session::put('error', 'Invite key creation is disabled');
                return redirect('/invites');
            }

            if(Keys::where('author', Auth::user()->username)->where(DB::raw('MONTH(creation)'), DB::raw('MONTH(NOW())'))->where(DB::raw('YEAR(creation)'), DB::raw('YEAR(NOW())'))->count() - 2 >= 0) {
                Session::put('error', 'You cannot create any more invites this month');
                return redirect('/invites');
            }

            if(Keys::where('author', Auth::user()->username)->where('used', 'n')->count() >= 2) {
                Session::put('error', 'You have too many unused keys');
                return redirect('/invites');
            }

            function inviteKey($length) {
                $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
                $randomString = '';
            
                $maxIndex = strlen($characters) - 1;
            
                for($i = 0; $i < $length; $i++) {
                    $randomString .= $characters[random_int(0, $maxIndex)];
                }
            
                return $randomString;
            }

            $key = inviteKey(32);

            Keys::create([
                'author' => Auth::user()->username,
                'IID' => $key
            ]);

            Session::put('successv2', 'Invite key created: ' . $key);
            return redirect('/invites');
        }

        return $this->page('Invitations/Confirmation', $pageData ?? [], $title ?? null);
    }
}