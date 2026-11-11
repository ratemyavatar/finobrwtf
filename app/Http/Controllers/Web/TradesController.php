<?php

namespace App\Http\Controllers\Web;

use App\Models\User;
use App\Services\Trading;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use App\Http\Controllers\Controller;

class TradesController extends Controller
{

    /* /trades: every trade you sent or got, newest first (15 a page, like the real site) */
    public function trades(Request $request) {
        $title = 'Trades';

        if(!Auth::check()) {
            return redirect('/');
        }

        $me = (int) Auth::id();
        $rows = [];
        $pages = 1;
        $page = max(1, (int) $request->query('page', 1));
        if(Trading::available()) {
            $query = DB::connection('finobe')->table('trades')
                ->where(fn($q) => $q->where('sender_id', $me)->orWhere('receiver_id', $me))
                ->orderByDesc('id');
            $pages = max(1, (int) ceil($query->count() / 15));
            $page = min($page, $pages);
            $trades = $query->forPage($page, 15)->get();
            $partners = User::whereIn('id', $trades->map(fn($t) => (int) $t->sender_id === $me ? $t->receiver_id : $t->sender_id)->unique()->all())->get()->keyBy('id');
            foreach($trades as $t) {
                $partner = $partners[(int) $t->sender_id === $me ? $t->receiver_id : $t->sender_id] ?? null;
                $rows[] = [
                    'id' => $t->id,
                    'date' => date('m/d/Y', strtotime($t->created_at)),
                    'datetime' => date('m/d/Y g:i:sa', strtotime($t->created_at)),
                    'partner' => $partner ? ['id' => $partner->id, 'username' => $partner->username, 'pfp' => $partner->pfp] : null,
                    'status' => $t->status,
                    'incoming' => (int) $t->receiver_id === $me,
                ];
            }
        }

        $pageData['trades'] = $rows;
        $pageData['pagination'] = ['page' => $page, 'pages' => $pages];
        return $this->page('Trades', $pageData ?? [], $title ?? null);
    }

    /* /trade/info/{id}: Trade Details, with accept / decline (incoming) or revoke (outgoing) */
    public function info(Request $request, $id) {
        if(!Auth::check()) {
            return redirect('/');
        }
        $user = Auth::user();
        $trade = Trading::available() ? Trading::find((int) $id) : null;
        if(!$trade || !in_array((int) $user->id, [(int) $trade->sender_id, (int) $trade->receiver_id], true)) {
            abort(404);
        }

        if($request->isMethod('post')) {
            try {
                switch((string) $request->input('action')) {
                    case 'accept': Trading::accept($trade->id, $user); Session::put('success', 'Trade accepted.'); break;
                    case 'decline': Trading::decline($trade->id, $user); Session::put('success', 'Trade declined.'); break;
                    case 'revoke': Trading::revoke($trade->id, $user); Session::put('success', 'Trade revoked.'); break;
                }
            } catch(\RuntimeException $e) {
                Session::put('error', $e->getMessage());
            }
            return redirect('/trade/info/' . $trade->id);
        }

        $title = 'Trade Details';
        $pageData['trade'] = Trading::view($trade, $user);
        return $this->page('Trade/Info', $pageData ?? [], $title ?? null);
    }

    /* /trade/new/{userId}: pick what to give and what you want back */
    public function create(Request $request, $userId) {
        if(!Auth::check()) {
            return redirect('/');
        }
        $user = Auth::user();
        $partner = User::find((int) $userId);
        if(!$partner || !Trading::available()) {
            abort(404);
        }

        if($request->isMethod('post')) {
            try {
                $id = Trading::send($user, $partner, (array) $request->input('give', []), (array) $request->input('receive', []));
            } catch(\RuntimeException $e) {
                Session::put('error', $e->getMessage());
                return redirect('/trade/new/' . $partner->id);
            }
            Session::put('success', 'Trade sent.');
            return redirect('/trade/info/' . $id);
        }

        if($problem = Trading::cantTrade($user, $partner)) {
            Session::put('error', $problem);
            return redirect('/user/' . $partner->id);
        }

        $title = 'Trade';
        $pageData['partner'] = ['id' => $partner->id, 'username' => $partner->username, 'pfp' => $partner->pfp];
        $pageData['mine'] = Trading::tradable($user);
        $pageData['theirs'] = Trading::tradable($partner);
        $pageData['maxItems'] = Trading::MAX_ITEMS;
        return $this->page('Trade/New', $pageData ?? [], $title ?? null);
    }
}
