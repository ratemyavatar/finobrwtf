<?php

namespace App\Http\Middleware;

use Closure;
use App\Models\User;
use App\Http\Controllers\dataController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Session;
use Symfony\Component\HttpFoundation\Response;

class ModerationMiddleware
{
    protected $db;
    protected $request;
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $this->db = DB::connection('finobe');
        $this->dataService = new dataController();
        $this->request = [
            'data' => [
                'embeds' => [
                    'title' => ' - ',
                    'description' => 'Finobe, it is website. He is for old brick-builder. Good for use.',
                    'url' => env('APP_URL'),
                    'image' => env('APP_URL') . '/s/img/'
                ],
                'csrf_token' => View::share('csrf_token', csrf_token()),
                'siteusername' => true,
                'user' => [
                    'version' => 'v2'
                ],
                'page' => strtok(request()->getRequestUri(), '?'),
                'dir' => str_replace('\\', '', '/' . explode('/', trim(request()->getRequestUri(), '/'))[0] . '/'),
                'alerts' => [
                    'successv2' => false,
                    'success' => false,
                    'error' => false,
                    'announcements' => []
                ],
                'lucky_number' => rand(0, Cache::remember('user_count', 3600, fn() => User::count())) . '/' . Cache::remember('user_count', 3600, fn() => User::count()),
                'server_name' => config('finobe.server_name'), // shown after the lucky number, e.g. "(bumpy)"; blank hides it
            ]
        ];

        if(Auth::check()) {
            $this->request['data']['user'] = Auth::user()->toArray();
            // the real site had no version picker: everyone gets the v2 pages
            $this->request['data']['user']['version'] = 'v2';
            $this->request['data']['user']['formattedDius'] = $this->dataService->formatNumber($this->request['data']['user']['Dius']);
            $this->request['data']['embeds']['title'] .= 'Finobe';
                
            if($this->request['data']['user']['logo'] == 'v1') {
                $this->request['data']['embeds']['image'] .= 'BUSY.png';
            } elseif($this->request['data']['user']['logo'] == 'v2') {
                $this->request['data']['embeds']['image'] .= 'finnobe3.png';
            } else {
                $this->request['data']['embeds']['image'] .= 'finnobe3logo.png';
            }

            $user = Auth::user();
            $user->ip = hash_hmac('sha256', request()->header('CF-Connecting-IP'), 'ip');
            $user->lastlogin = now();
            $user->save();

            // no rendered avatar yet (or it went missing): have the arbiter render one
            \App\Services\AvatarRenderer::renderIfMissing($user->id, $user->pfp);
        }

        if(!($request->isMethod('post') && ($this->request['data']['page'] == '/' || $this->request['data']['page'] == '/logout'))) {
            if(Session::has('siteipban')) {
                if(!$this->db->table('bans')->where('username', hash_hmac('sha256', request()->header('CF-Connecting-IP'), 'ip'))->exists()) {
                    $this->db->table('bans')->insert([
                        'username' => hash_hmac('sha256', request()->header('CF-Connecting-IP'), 'ip'),
                        'reason' => 'tried to switch to different IP',
                        'moderator' => 'Auto'
                    ]);
                }
            }

            if($this->db->table('bans')->where('username', hash_hmac('sha256', request()->header('CF-Connecting-IP'), 'ip'))->exists()) {
                $this->request['data']['embeds']['title'] = 'IP Ban' . $this->request['data']['embeds']['title'];

                if(!Session::has('siteipban')) {
                    Session::put('siteipban', 'true');
                }
                
                if(parse_url(env('APP_URL'), PHP_URL_HOST) != $request->getHost()) {
                    return response()->json(['code' => 403, 'message' => 'Access denied'], 403);
                }

                return response()->view('v2/403', $this->request);
            }

            if(Auth::check()) {
                if($this->db->table('warning')->where('username', $this->request['data']['user']['username'])->where('reactivated', \App\Admin\Workbench::flag('warning', 'reactivated', false))->exists()) {
                    if(parse_url(env('APP_URL'), PHP_URL_HOST) != $request->getHost()) {
                        return response()->json(['code' => 403, 'message' => 'Access denied'], 403);
                    }
                    $this->request['data']['embeds']['title'] = 'Moderation' . $this->request['data']['embeds']['title'];
                    $this->request['data']['isCurrentlyBanned'] = true;
                    $this->request['data']['moderationType'] = 1;
                    $this->request['data']['ban_info'] = (array) $this->db->table('warning')
                        ->where('username', $this->request['data']['user']['username'])
                        ->where('reactivated', \App\Admin\Workbench::flag('warning', 'reactivated', false))
                        ->first();
                    
                    return response()->view('v2/Moderation', $this->request);
                }

                if($this->db->table('bans')->where('username', $this->request['data']['user']['username'])->where('reactivated', \App\Admin\Workbench::flag('bans', 'reactivated', false))->exists()) {
                    if(parse_url(env('APP_URL'), PHP_URL_HOST) != $request->getHost()) {
                        return response()->json(['code' => 403, 'message' => 'Access denied'], 403);
                    }
                    $this->request['data']['embeds']['title'] = 'Moderation' . $this->request['data']['embeds']['title'];
                    $this->request['data']['isCurrentlyBanned'] = $this->db->table('bans')->where('username', $this->request['data']['user']['username'])->where('reactivated', \App\Admin\Workbench::flag('bans', 'reactivated', false))->where('expire', '>', now())->exists();
                    $this->request['data']['moderationType'] = 2;
                    $this->request['data']['ban_info'] = (array) $this->db->table('bans')
                        ->where('username', $this->request['data']['user']['username'])
                        ->where('reactivated', \App\Admin\Workbench::flag('bans', 'reactivated', false))
                        ->first();
                        
                    $currentDateTime = new \DateTime('now', new \DateTimeZone('America/Los_Angeles'));
                    $futureDateTime = new \DateTime($this->request['data']['ban_info']['expire'], new \DateTimeZone('America/Los_Angeles'));
                        
                    $timeDifference = $currentDateTime->diff($futureDateTime);
                            
                    $days = $timeDifference->d;
                    $hours = $timeDifference->h;
                    $minutes = $timeDifference->i;
                    $seconds = $timeDifference->s;

                    if (in_array((string) $activeBan->perm, ['1', 'y'], true)) {
                        $this->request['data']['expiration'] = 'Permanent';
                        $this->request['data']['expiration_formatted'] = 'Never';
                    } else {
                        $expires = \Carbon\Carbon::parse($activeBan->expire);
                        $seconds = max(0, (int) now()->diffInSeconds($expires, false));
                        $days = intdiv($seconds, 86400);
                        $hours = intdiv($seconds % 86400, 3600);
                        $minutes = intdiv($seconds % 3600, 60);
                        $this->request['data']['expiration'] = trim(
                            ($days ? $days . ' day(s) ' : '') .
                            ($hours ? $hours . ' hour(s) ' : '') .
                            ($minutes ? $minutes . ' minute(s)' : '')
                        ) ?: 'Less than a minute';
                        $this->request['data']['expiration_formatted'] = $expires->format('Y-m-d');
                    }
                    return response()->view('v2/Moderation', $this->request);
                }

                if($this->request['data']['page'] != '/logout' && !$request->isMethod('post') && $this->request['data']['dir'] != '/email/') {
                    if($this->request['data']['user']['verified'] == 'n' && $this->request['data']['page'] != '/legal/welcome') {
                        if(parse_url(env('APP_URL'), PHP_URL_HOST) != $request->getHost()) {
                            return response()->json(['code' => 403, 'message' => 'Access denied'], 403);
                        }

                        $this->request['data']['embeds']['title'] = 'Verify Email' . $this->request['data']['embeds']['title'];
                        $this->request['data']['alerts']['successv2'] = Session::get('successv2', false);
                        $this->request['data']['alerts']['success'] = Session::get('success', false);
                        $this->request['data']['alerts']['error'] = Session::get('error', false);
                        if($this->request['data']['alerts']['successv2']) {
                            Session::forget('successv2');
                        }

                        if($this->request['data']['alerts']['success']) {
                            Session::forget('success');
                        }
                        
                        if($this->request['data']['alerts']['error']) {
                            Session::forget('error');
                        }

                        return response()->view('v2/Verify', $this->request);
                    }
                }
            }
        }

        return $next($request);
    }
}
