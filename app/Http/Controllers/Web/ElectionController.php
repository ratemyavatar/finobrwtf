<?php

namespace App\Http\Controllers\Web;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;
use App\Models\User;
use App\Models\Election;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;

class ElectionController extends Controller
{

    public function election(Request $request) {
        $title = 'Invites';
        $data = $request->all();

        if(!Auth::check()) {
            return redirect('/');
        }

        if(!Election::where('expire', '>', now())->exists()) {
            Session::put('error', 'There is currently no active elections');
            return redirect('/');
        }

        $election = Election::where('expire', '>', now())->first()->toArray();
        
        $election['title'] = strip_tags(htmlspecialchars($election['title']));

        if($request->isMethod('post')) {
            if(!isset($data['index'])) {
                return redirect('/election');
            }

            if(!is_scalar($data['index']) || !in_array((int)$data['index'], array_map(fn($o) => (int)($o['id'] ?? 0), $election['options'] ?? []), true)) {
                return redirect('/election');
            }

            $data['index'] = (int)$data['index'];

            foreach($election['votes'] as $key => $vote) {
                if($vote['id'] != $data['index'] && $vote['username'] == Auth::user()->username) {
                    Session::put('error', 'You can only vote on one option');
                    return redirect('/election');
                }
            }

            foreach($election['votes'] as $key => $vote) {
                if($vote['id'] == $data['index'] && $vote['username'] == Auth::user()->username) {
                    unset($election['votes'][$key]);

                    $election2 = Election::find($election['id']);
                    $election2->votes = $election['votes'];
                    $election2->save();
                    
                    return redirect('/election');
                }
            }

            $election['votes'][] = [
                'id' => $data['index'],
                'username' => Auth::user()->username
            ];

            $election2 = Election::find($election['id']);
            $election2->votes = $election['votes'];
            $election2->save();
                    
            return redirect('/election');
        }

        $pageData['election'] = $election;

        return $this->page('Election', $pageData ?? [], $title ?? null);
    }
}