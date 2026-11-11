<?php

namespace App\Http\Controllers\Web;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use App\Models\Ban;
use App\Models\User;
use App\Models\Warning;
use App\Models\Forum\Reply;
use App\Models\Notification;
use App\Models\Forum\Rating;
use App\Models\Forum\Thread;
use App\Models\Forum\Subscription;
use App\Http\Controllers\frontEnd;
use App\Http\Controllers\Controller;
use App\Http\Controllers\dataController as DataController;

class ForumController extends Controller
{
    protected $request;

    public function __construct(Request $request) {
        $frontend = new frontEnd($request);
        $this->request = $frontend->getData();
    }

    public function index(Request $request) {
        $data = $request->all();
        $this->request['data']['embeds']['title'] = 'Forum' . $this->request['data']['embeds']['title'];
        $this->request['data']['section'] = false;

        $posts = Thread::count();
        
        $pages_to_show = 10;
        $results_per_page = 10;
        $number_of_pages = ceil($posts / $results_per_page);
        $currentPage = isset($data['page']) ? max(1, intval($data['page'])) : 1;
        $offset = ($currentPage - 1) * $results_per_page;
        $start_page = max(1, min($currentPage - floor($pages_to_show / 2), $number_of_pages - $pages_to_show + 1));
        $end_page = min($number_of_pages, $start_page + $pages_to_show - 1);
        $phrasesToReplace = $this->request['data']['string_replacements']['phrasesToReplace'];
        $replacements = $this->request['data']['string_replacements']['replacements'];

        $posts = Thread::orderBy('pinned', 'DESC')
            ->orderBy('lastreplied', 'DESC')
            ->offset($offset)
            ->limit($results_per_page)
            ->get()
            ->map(fn($item) => $item->toArray());
        
        $this->request['data']['threads'] = [
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

        foreach($posts as $post) {
            $post['status'] = User::where('username', $post)->value('status');
            $post['replies'] = Reply::where('toid', $post['id'])->count();
            $post['title'] = htmlspecialchars($post['title']);
            $post['title'] = preg_replace_callback('/\b(' . implode('|', array_map('preg_quote', $phrasesToReplace)) . ')\b/i', fn() => $replacements[array_rand($replacements)], $post['title']);
            $post['author'] = htmlspecialchars($post['author']);
            $post['ago'] = DataController::time_elapsed_string($post['date']);
            $post['date'] = date('F d, Y g:i a', strtotime($post['date']));
            $post['rating'] = Rating::where('type', '1')->where('toid', $post['id'])->where('rate_type', 'l')->count();
            $post['rating'] = $post['rating'] - Rating::where('type', '1')->where('toid', $post['id'])->where('rate_type', 'd')->count();
            $this->request['data']['threads']['data'][] = $post;
        }

        for ($page = $start_page; $page <= $end_page; $page++) {
            $this->request['data']['threads']['pages']['data'][] = ['page' => $page];
        }

        if(!count($posts)) {
            $this->request['data']['threads']['pages']['data'][] = [
                'page' => 1
            ];
        }

        return view($this->request['data']['user']['version'] . '/Forum/Index', $this->request);
    }

    public function section(Request $request, $section) {
        $data = $request->all();
        $this->request['data']['embeds']['title'] = 'Forum' . $this->request['data']['embeds']['title'];
        $this->request['data']['section'] = $section;

        $posts = Thread::where('category', $section)->count();
        
        $pages_to_show = 10;
        $results_per_page = 10;
        $number_of_pages = ceil($posts / $results_per_page);
        $currentPage = isset($data['page']) ? max(1, intval($data['page'])) : 1;
        $offset = ($currentPage - 1) * $results_per_page;
        $start_page = max(1, min($currentPage - floor($pages_to_show / 2), $number_of_pages - $pages_to_show + 1));
        $end_page = min($number_of_pages, $start_page + $pages_to_show - 1);
        $phrasesToReplace = $this->request['data']['string_replacements']['phrasesToReplace'];
        $replacements = $this->request['data']['string_replacements']['replacements'];

        $posts = Thread::where('category', $section)
            ->orderBy('pinned', 'DESC')
            ->orderBy('lastreplied', 'DESC')
            ->offset($offset)
            ->limit($results_per_page)
            ->get()
            ->map(fn($item) => $item->toArray());
        
        $this->request['data']['threads'] = [
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

        foreach($posts as $post) {
            $post['status'] = User::where('username', $post)->value('status');
            $post['replies'] = Reply::where('toid', $post['id'])->count();
            $post['title'] = htmlspecialchars($post['title']);
            $post['title'] = preg_replace_callback('/\b(' . implode('|', array_map('preg_quote', $phrasesToReplace)) . ')\b/i', fn() => $replacements[array_rand($replacements)], $post['title']);
            $post['author'] = htmlspecialchars($post['author']);
            $post['ago'] = DataController::time_elapsed_string($post['date']);
            $post['date'] = date('F d, Y g:i a', strtotime($post['date']));
            $post['rating'] = Rating::where('type', '1')->where('toid', $post['id'])->where('rate_type', 'l')->count();
            $post['rating'] = $post['rating'] - Rating::where('type', '1')->where('toid', $post['id'])->where('rate_type', 'd')->count();
            $this->request['data']['threads']['data'][] = $post;
        }

        for ($page = $start_page; $page <= $end_page; $page++) {
            $this->request['data']['threads']['pages']['data'][] = ['page' => $page];
        }

        if(!count($posts)) {
            $this->request['data']['threads']['pages']['data'][] = [
                'page' => 1
            ];
        }

        return view($this->request['data']['user']['version'] . '/Forum/Index', $this->request);
    }

    public function search(Request $request) {
        $data = $request->all();
        $this->request['data']['embeds']['title'] = 'Forum' . $this->request['data']['embeds']['title'];

        if(!isset($data['q']) || empty($data['q'])) {
            return redirect('/forum/home');
        }

        $this->request['data']['search'] = htmlspecialchars($data['q']);
        $search = '%' . htmlspecialchars($data['q']) . '%';

        $posts = Thread::whereRaw('LOWER(title) LIKE ?', [$search])->count();
        
        $pages_to_show = 10;
        $results_per_page = 10;
        $number_of_pages = ceil($posts / $results_per_page);
        $currentPage = isset($data['page']) ? max(1, intval($data['page'])) : 1;
        $offset = ($currentPage - 1) * $results_per_page;
        $start_page = max(1, min($currentPage - floor($pages_to_show / 2), $number_of_pages - $pages_to_show + 1));
        $end_page = min($number_of_pages, $start_page + $pages_to_show - 1);
        $phrasesToReplace = $this->request['data']['string_replacements']['phrasesToReplace'];
        $replacements = $this->request['data']['string_replacements']['replacements'];

        $posts = Thread::whereRaw('LOWER(title) LIKE ?', [$search])
            ->orderBy('pinned', 'DESC')
            ->orderBy('lastreplied', 'DESC')
            ->offset($offset)
            ->limit($results_per_page)
            ->get()
            ->map(fn($item) => $item->toArray());
        
        $this->request['data']['threads'] = [
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

        foreach($posts as $post) {
            $post['status'] = User::where('username', $post)->value('status');
            $post['replies'] = Reply::where('toid', $post['id'])->count();
            $post['title'] = htmlspecialchars($post['title']);
            $post['title'] = preg_replace_callback('/\b(' . implode('|', array_map('preg_quote', $phrasesToReplace)) . ')\b/i', fn() => $replacements[array_rand($replacements)], $post['title']);
            $post['author'] = htmlspecialchars($post['author']);
            $post['ago'] = DataController::time_elapsed_string($post['date']);
            $post['date'] = date('F d, Y g:i a', strtotime($post['date']));
            $post['rating'] = Rating::where('type', '1')->where('toid', $post['id'])->where('rate_type', 'l')->count();
            $post['rating'] = $post['rating'] - Rating::where('type', '1')->where('toid', $post['id'])->where('rate_type', 'd')->count();
            $this->request['data']['threads']['data'][] = $post;
        }

        for ($page = $start_page; $page <= $end_page; $page++) {
            $this->request['data']['threads']['pages']['data'][] = ['page' => $page];
        }

        if(!count($posts)) {
            $this->request['data']['threads']['pages']['data'][] = [
                'page' => 1
            ];
        }

        return view($this->request['data']['user']['version'] . '/Forum/Index', $this->request);
    }

    public function post(Request $request) {
        $data = $request->all();

        if($request->isMethod('post')) {
            if(!$this->request['data']['siteusername']) {
                return redirect('/');
            }

            $validator = Validator::make($data, [
                'id' => 'required|integer|exists:finobe.forum_threads,id',
                'content' => 'required|string|min:3|max:16384'
            ]);

            if(!(bool)env('FINOBE_FORUM_POST')) {
                Session::put('error', 'Posting on the forums have been disabled');
                return redirect('/forum/post?id=' . $data['id']);
            }

            if($validator->fails()) {
                Session::put('error', $validator->errors()->first());
                return redirect('/forum/home');
            }

            $post = Thread::find($data['id']);
            
            if($post->author != $this->request['data']['user']['username']) {
                Session::put('error', 'You do not own this post');
                return redirect('/forum/post?id=' . $data['id']);
            }

            if($post->locked == 'y') {
                Session::put('error', 'This post is locked');
                return redirect('/forum/post?id=' . $data['id']);
            }

            $post->comment = trim($data['content']);
            $post->save();
            
            /*
            $this->db->table('users')
                ->where('username', $this->request['data']['user']['username'])
                ->update([
                    'post_cooldown' => DB::raw('CURRENT_TIMESTAMP()')
                ]);

            */
            $user = User::find($this->request['data']['user']['id']);
            $user->post_cooldown = now();
            $user->save();
            
            Session::put('success', 'Successfully edited.');
            return redirect('/forum/post?id=' . $data['id']);
        }

        if(!isset($data['id']) || empty($data['id'])) {
            return redirect('/forum/home');
        }

        if(!Thread::find($data['id'])) {
            Session::put('error', 'This thread doesn\'t exist or was deleted.');
            return redirect('/forum/home');
        }

        $post = Thread::find($data['id'])->toArray();

        if(isset($data['edit']) && (!$this->request['data']['siteusername'] || $post['author'] != $this->request['data']['user']['username'])) {
            return redirect('/forum/post?id=' . $data['id']);
        }
        
        $this->request['data']['embeds']['title'] = htmlspecialchars($post['title']) . $this->request['data']['embeds']['title'];
        $this->request['data']['editing'] = isset($data['edit']);
        $post['title'] = htmlspecialchars($post['title']);
        $post['author'] = htmlspecialchars($post['author']);
        $post['format_date'] = date('M d Y h:i:s A', strtotime($post['date']));
        $post['date'] = date('m/d/Y h:i A', strtotime($post['date']));
        $post['edited_date'] = date('m/d/Y h:i A', strtotime($post['edited_date']));

        $user = User::where('username', $post['author'])->select(array_merge(['id', 'status', 'pfp', 'lastlogin', 'badges'], User::hasGender() ? ['gender'] : []))->first()?->toArray();
        $post['uuid'] = $user['id'];
        $post['status'] = $user['status'];
        $post['gender'] = $user['gender'] ?? null;
        $post['pfp'] = $user['pfp'];
        $post['posts'] = Thread::where('author', $post['author'])->count() + Thread::where('author', $post['author'])->count();
        $post['badges'] = $user['badges']['data']['custom_badges'] ?? [];
        $phrasesToReplace = $this->request['data']['string_replacements']['phrasesToReplace'];
        $replacements = $this->request['data']['string_replacements']['replacements'];

        if(!isset($data['edit'])) {
            $environment = new Environment([
                'html_input' => ($post['status'] == 'admin' ? 'allow' : 'strip'),
                'allow_unsafe_links' => false,
            ]);

            $environment->addExtension(new CommonMarkCoreExtension());
            $converter = new MarkdownConverter($environment);

            $post['title'] = preg_replace_callback('/\b(' . implode('|', array_map('preg_quote', $phrasesToReplace)) . ')\b/i', fn() => $replacements[array_rand($replacements)], $post['title']);
            $post['comment'] = \App\Services\CurseFilter::forViewer($post['comment']);
            $post['comment'] = preg_replace_callback('/\b(' . implode('|', array_map('preg_quote', $phrasesToReplace)) . ')\b/i', fn() => $replacements[array_rand($replacements)], $post['comment']);

            if($post['status'] != "admin") {
                $post['comment'] = strip_tags(htmlspecialchars($post['comment']));
            }

            $post['comment'] = $converter->convert($post['comment'])->getContent();
            $post['comment'] = preg_replace_callback('/(<img[^>]*>|\b(?:https?|ftp):\/\/\S+)/i', function ($matches) use ($post) {
                if (strpos($matches[0], '<img') === 0) {
                    if($post['status'] != 'admin') {
                        return htmlspecialchars($matches[0]);
                    } else {
                        return $matches[0];
                    }
                } else {
                    return '<a href="' . strip_tags($matches[0]) . '" target="_blank">' . $matches[0] . '</a>';
                }
            }, $post['comment']);
        } else {
            $post['title'] = preg_replace_callback('/\b(' . implode('|', array_map('preg_quote', $phrasesToReplace)) . ')\b/i', fn() => $replacements[array_rand($replacements)], $post['title']);

            $post['comment'] = \App\Services\CurseFilter::forViewer($post['comment']);

            $post['comment'] = preg_replace_callback('/\b(' . implode('|', array_map('preg_quote', $phrasesToReplace)) . ')\b/i', fn() => $replacements[array_rand($replacements)], $post['comment']);

            if($post['status'] != "admin") {
                $post['comment'] = strip_tags(htmlspecialchars($post['comment'],));
            }
        }

        $post['rating'] = Rating::where('type', '1')->where('toid', $post['id'])->where('rate_type', 'l')->count();
        $post['upvotes'] = $post['rating'];
        $post['rating'] -= Rating::where('type', '1')->where('toid', $post['id'])->where('rate_type', 'd')->count();
        $post['downvotes'] = Rating::where('type', '1')->where('toid', $post['id'])->where('rate_type', 'd')->count();

        if($this->request['data']['siteusername']) {
            if(Rating::where('type', '1')->where('toid', $post['id'])->where('sender', $this->request['data']['user']['username'])->exists()) {
                $post['userRating'] = Rating::select('rate_type')->where('type', '1')->where('toid', $post['id'])->where('sender', $this->request['data']['user']['username'])->value('rate_type');
            }

            $post['subscription'] = Subscription::where('username', $this->request['data']['user']['username'])->where('forumId', $post['id'])->exists();
        }

        $post['online'] = Carbon::parse($user['lastlogin'])->gt(Carbon::now()->subMinutes(2));
        $this->request['data']['post'] = $post;

        if(Reply::where('toid', $post['id'])->where('sticked', 'y')->exists()) {
            $sticked = Reply::where('toid', $post['id'])
                ->where('sticked', 'y')
                ->first()
                ->toArray();
            
            $sticked['author'] = htmlspecialchars($sticked['author']);
            $sticked['date'] = date('m/d/Y h:i A', strtotime($sticked['date']));
            $sticked['edited_date'] = date('m/d/Y h:i A', strtotime($sticked['edited_date']));

            $user = User::where('username', $sticked['author'])->select(array_merge(['id', 'status', 'pfp', 'lastlogin', 'badges'], User::hasGender() ? ['gender'] : []))->first()?->toArray();
            $sticked['uuid'] = $user['id'];
            $sticked['status'] = $user['status'];
            $sticked['gender'] = $user['gender'] ?? null;
            $sticked['pfp'] = $user['pfp'];
            $sticked['posts'] = Thread::where('author', $sticked['author'])->count() + Reply::where('author', $sticked['author'])->count();
            $sticked['badges'] = $user['badges']['data']['custom_badges'] ?? [];
            $sticked['comment'] = \App\Services\CurseFilter::forViewer($sticked['comment']);
            $sticked['comment'] = preg_replace_callback('/\b(' . implode('|', array_map('preg_quote', $phrasesToReplace)) . ')\b/i', fn() => $replacements[array_rand($replacements)], $sticked['comment']);

            $environment = new Environment([
                'html_input' => ($sticked['status'] == 'admin' ? 'allow' : 'strip'),
                'allow_unsafe_links' => false,
            ]);

            $environment->addExtension(new CommonMarkCoreExtension());
            $converter = new MarkdownConverter($environment);

            if($sticked['status'] != "admin") {
                $sticked['comment'] = strip_tags(htmlspecialchars($sticked['comment']));
            }

            $sticked['comment'] = $converter->convert($sticked['comment'])->getContent();
            $sticked['comment'] = preg_replace_callback('/(<img[^>]*>|\b(?:https?|ftp):\/\/\S+)/i', function ($matches) use ($sticked) {
                if (strpos($matches[0], '<img') === 0) {
                    if($sticked['status'] != 'admin') {
                        return htmlspecialchars($matches[0]);
                    } else {
                        return $matches[0];
                    }
                } else {
                    return '<a href="' . strip_tags($matches[0]) . '" target="_blank">' . $matches[0] . '</a>';
                }
            }, $sticked['comment']);

            $sticked['rating'] = Rating::where('type', '2')->where('toid', $sticked['id'])->where('rate_type', 'l')->count();
            $sticked['upvotes'] = $sticked['rating'];
            $sticked['rating'] -= Rating::where('type', '2')->where('toid', $sticked['id'])->where('rate_type', 'd')->count();
            $sticked['downvotes'] = Rating::where('type', '2')->where('toid', $sticked['id'])->where('rate_type', 'd')->count();

            if($sticked['replyTo']) {
                $sticked['replyComment'] = Rating::select('comment')->where('id', $sticked['replyTo'])->value('comment');
                $sticked['replyComment'] = preg_replace_callback('/\b(' . implode('|', array_map('preg_quote', $phrasesToReplace)) . ')\b/i', fn() => $replacements[array_rand($replacements)], $sticked['replyComment']);
            }

            if($this->request['data']['siteusername']) {
                if(Rating::where('type', '2')->where('toid', $sticked['id'])->where('sender', $this->request['data']['user']['username'])->exists()) {
                    $sticked['userRating'] = Rating::select('rate_type')->where('type', '2')->where('toid', $sticked['id'])->where('sender', $this->request['data']['user']['username'])->value('rate_type');
                }

                $sticked['subscription'] = Subscription::where('username', $this->request['data']['user']['username'])->where('forumId', $sticked['id'])->exists();
            }

            $sticked['online'] = Carbon::parse($user['lastlogin'])->gt(Carbon::now()->subMinutes(2));
            $this->request['data']['sticked'] = $sticked;
        }

        $replies = Reply::where('toid', $post['id'])->count();
        
        $pages_to_show = 10;
        $results_per_page = 10;
        $number_of_pages = ceil($replies / $results_per_page);
        $currentPage = isset($data['page']) ? max(1, intval($data['page'])) : 1;
        $offset = ($currentPage - 1) * $results_per_page;
        $start_page = max(1, min($currentPage - floor($pages_to_show / 2), $number_of_pages - $pages_to_show + 1));
        $end_page = min($number_of_pages, $start_page + $pages_to_show - 1);

        $replies = Reply::where('toid', $post['id'])
            ->offset($offset)
            ->limit($results_per_page)
            ->get()
            ->map(fn($item) => $item->toArray());
        
        $this->request['data']['replies'] = [
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

        foreach($replies as $reply) {
            $reply['author'] = htmlspecialchars($reply['author']);
		    $reply['format_date'] = date('M d Y h:i:s A', strtotime($reply['date']));
            $reply['date'] = date('m/d/Y h:i A', strtotime($reply['date']));
            $reply['edited_date'] = date('m/d/Y h:i A', strtotime($reply['edited_date']));

            $user = User::where('username', $reply['author'])->select(array_merge(['id', 'status', 'pfp', 'lastlogin', 'badges'], User::hasGender() ? ['gender'] : []))->first()?->toArray();
            $reply['uuid'] = $user['id'];
            $reply['status'] = $user['status'];
            $reply['gender'] = $user['gender'] ?? null;
            $reply['pfp'] = $user['pfp'];
            $reply['posts'] = Thread::where('author', $reply['author'])->count() + Reply::where('author', $reply['author'])->count();
            $reply['badges'] = $user['badges']['data']['custom_badges'] ?? [];
            $reply['comment'] = \App\Services\CurseFilter::forViewer($reply['comment']);
            $reply['comment'] = preg_replace_callback('/\b(' . implode('|', array_map('preg_quote', $phrasesToReplace)) . ')\b/i', fn() => $replacements[array_rand($replacements)], $reply['comment']);

            $environment = new Environment([
                'html_input' => ($reply['status'] == 'admin' ? 'allow' : 'strip'),
                'allow_unsafe_links' => false,
            ]);

            $environment->addExtension(new CommonMarkCoreExtension());
            $converter = new MarkdownConverter($environment);

            if($reply['status'] != "admin") {
                $reply['comment'] = strip_tags(htmlspecialchars($reply['comment']));
            }

            $reply['comment'] = $converter->convert($reply['comment'])->getContent();
            $reply['comment'] = preg_replace_callback('/(<img[^>]*>|\b(?:https?|ftp):\/\/\S+)/i', function ($matches) use ($reply) {
                if (strpos($matches[0], '<img') === 0) {
                    if($reply['status'] != 'admin') {
                        return htmlspecialchars($matches[0]);
                    } else {
                        return $matches[0];
                    }
                } else {
                    return '<a href="' . strip_tags($matches[0]) . '" target="_blank">' . $matches[0] . '</a>';
                }
            }, $reply['comment']);

            $reply['rating'] = Rating::where('type', '2')->where('toid', $reply['id'])->where('rate_type', 'l')->count();
            $reply['upvotes'] = $reply['rating'];
            $reply['rating'] -= Rating::where('type', '2')->where('toid', $reply['id'])->where('rate_type', 'd')->count();
            $reply['downvotes'] = Rating::where('type', '2')->where('toid', $reply['id'])->where('rate_type', 'd')->count();

            if($reply['replyTo']) {
                $reply['replyComment'] = Reply::select('comment')->where('id', $reply['replyTo'])->value('comment');
                $reply['replyComment'] = preg_replace_callback('/\b(' . implode('|', array_map('preg_quote', $phrasesToReplace)) . ')\b/i', fn() => $replacements[array_rand($replacements)], $reply['replyComment']);
            }

            if($this->request['data']['siteusername']) {
                if(Rating::where('type', '2')->where('toid', $reply['id'])->where('sender', $this->request['data']['user']['username'])->exists()) {
                    $reply['userRating'] = Rating::select('rate_type')->where('type', '2')->where('toid', $reply['id'])->where('sender', $this->request['data']['user']['username'])->value('rate_type');
                }

                $reply['subscription'] = Subscription::where('username', $this->request['data']['user']['username'])->where('forumId', $reply['id'])->exists();
            }

            $reply['online'] = Carbon::parse($user['lastlogin'])->gt(Carbon::now()->subMinutes(2));
            $this->request['data']['replies']['data'][] = $reply;
        }

        for ($page = $start_page; $page <= $end_page; $page++) {
            $this->request['data']['replies']['pages']['data'][] = ['page' => $page];
        }

        if(!count($replies)) {
            $this->request['data']['replies']['pages']['data'][] = [
                'page' => 1
            ];
        }

        return view($this->request['data']['user']['version'] . '/Forum/Post', $this->request);
    }

    public function reply(Request $request) {
        $data = $request->all();

        if(!$this->request['data']['siteusername']) {
            return redirect('/');
        }

        if(!isset($data['id'])) {
            return redirect('/forum/home');
        }

        if($restricted = \App\Services\Restrictions::message((int) $this->request['data']['user']['id'], 'forum')) {
            Session::put('error', $restricted);
            return redirect('/forum/home');
        }

        if(!Thread::find($data['id'])) {
            Session::put('error', 'This post does not exist');
            return redirect('/forum/home');
        }

        if($request->isMethod('post')) {
            $validator = Validator::make($data, [
                'content' => 'required|string|min:3|max:16384'
            ]);

            if(!(bool)env('FINOBE_FORUM_POST')) {
                Session::put('error', 'Posting on the forums have been disabled');
                return redirect('/forum/post?id=' . $data['id']);
            }

            if($validator->fails()) {
                Session::put('error', $validator->errors()->first());
                return redirect('/forum/home');
            }

            if(($cooldown = $this->request['data']['user']['post_cooldown'] ?? null) && Carbon::parse($cooldown)->gt(Carbon::now()->subMinutes(5))) {
                Session::put('error', 'You are posting too often');
                return redirect('/forum/post?id=' . $data['id']);
            }

            $post = Thread::find($data['id']);
            
            if($post->locked == 'y') {
                Session::put('error', 'This post is locked');
                return redirect('/forum/home');
            }
            
            if(Carbon::parse($post->lastreplied)->lt(now()->subWeeks(3)) && $this->request['data']['user']['status'] == 'normal') {
                if(Warning::where('username', $this->request['data']['user']['username'])->where('date', '>=', DB::raw('DATE_SUB(NOW(), INTERVAL 1 MONTH)'))->count() >= 2) {
                    Ban::create([
                        'username' => $this->request['data']['user']['username'],
                        'reason' => 'You are not allowed to necrobump threads that have been inactive for 3 weeks.',
                        'expire' => date('Y-m-d H:i:s', strtotime('+1 week')),
                        'moderator' => 'Auto'
                    ]);
                } else {
                    Warning::create([
                        'username' => $this->request['data']['user']['username'],
                        'reason' => 'You are not allowed to necrobump threads that have been inactive for 3 weeks.',
                        'moderator' => 'Auto'
                    ]);
                }

                return redirect('/');
            }

            $reply = Reply::create([
                'toid' => $data['id'],
                'author' => $this->request['data']['user']['username'],
                'comment' => $data['content']
            ]);

            if($post->author != $this->request['data']['user']['username']) {
                Notification::create([
                    'owner' => $this->request['data']['user']['id'],
                    'touser' => User::where('username', $post->author)->value('id'),
                    'message' => $this->request['data']['user']['username'] . ' replied to ' . $post->title,
                    'forum_id' => $data['id'],
                    'reply_id' => $reply->id
                ]);
            }

            $subscriptions = Subscription::where('forumId', $data['id'])->get()->map(fn($item) => $item->toArray());
            
            foreach($subscriptions as $subscription) {
                Notification::create([
                    'owner' => $this->request['data']['user']['id'],
                    'touser' => User::where('username', $subscription['username'])->value('id'),
                    'message' => $this->request['data']['user']['username'] . ' replied to ' . $post->title,
                    'forum_id' => $data['id'],
                    'reply_id' => $reply->id
                ]);
            }

            if(isset($data['reply']) && Reply::where('id', $data['reply'])->exists()) {
                Notification::create([
                    'owner' => $this->request['data']['user']['id'],
                    'touser' => User::where('username', Reply::select('author')->where('id', $data['reply'])->value('author'))->value('id'), // place with inner query grabs id of user instead of this when forum userid rewrite
                    'message' => $this->request['data']['user']['username'] . ' replied to your reply on ' . $post->title,
                    'forum_id' => $data['id']
                ]);

                $reply->replyTo = $data['reply'];
                $reply->save();
            }
            
            $user = User::find($this->request['data']['user']['id']);
            $user->post_cooldown = now();
            $user->save();
            
            $post = Thread::find($data['id']);
            $post->lastreplied = now();
            $post->save();
            
            $results_per_page = 10;
            $position_in_list = Reply::where('id', '<=', $reply->id)->where('toid', $data['id'])->count();
            $page_of_reply = ceil($position_in_list / $results_per_page);

            Session::put('success', 'Successfully created.');
            return redirect('/forum/post?id=' . $data['id'] . '&page=' . $page_of_reply);
        }

        $post = Thread::find($data['id'])->toArray();
        
        if($post['locked'] == 'y') {
            Session::put('error', 'This post is locked');
            return redirect('/forum/post?id=' . $data['id']);
        }

        $this->request['data']['embeds']['title'] = htmlspecialchars($post['title']) . $this->request['data']['embeds']['title'];
        $this->request['data']['replying'] = isset($data['reply']) ? $data['reply'] : false;
        $post['title'] = htmlspecialchars($post['title']);
        $this->request['data']['post'] = $post;

        return view($this->request['data']['user']['version'] . '/Forum/Reply', $this->request);
    }

    public function new_reply(Request $request) {
        $this->request['data']['embeds']['title'] = 'Edit Reply' . $this->request['data']['embeds']['title'];
        $data = $request->all();

        if(!$this->request['data']['siteusername']) {
            return redirect('/');
        }

        if($request->isMethod('post')) {
            $validator = Validator::make($data, [
                'id' => 'required|integer|exists:finobe.forum_replies,id',
                'postId' => 'required|integer|exists:finobe.forum_threads',
                'content' => 'required|string|min:3|max:8192'
            ]);

            if($validator->fails()) {
                Session::put('error', $validator->errors()->first());
                return redirect('/forum/edit?id=' . $data['id']);
            }

            $reply = Reply::find($data['id']);
        
            if($reply->author != $this->request['data']['user']['username']) {
                Session::put('error', 'You do not own this reply');
                return redirect('/forum/home');
            }

            $post = Thread::find($reply->toid);
            
            if($post->locked == 'y') {
                Session::put('error', 'This post is locked');
                return redirect('/forum/edit?id=' . $data['id']);
            }

            $reply->comment = $data['content'];
            $reply->edited = 'y';
            $reply->edited_date = now();
            $reply->save();
            
            $results_per_page = 10;
            $position_in_list = Reply::where('id', '<=', $data['id'])->where('toid', $data['postId'])->count();
            $page_of_reply = ceil($position_in_list / $results_per_page);

            Session::put('success', 'Successfully edited.');
            return redirect('/forum/post?id=' . $data['postId'] . '&page=' . $page_of_reply);
        }

        if(!isset($data['id'])) {
            return redirect('/forum/home');
        }

        if(!Reply::find($data['id'])) {
            Session::put('error', 'This reply doesn\'t exist or was deleted');
            return redirect('/forum/home');
        }

        $reply = Reply::find($data['id']);
        
        if($reply['author'] != $this->request['data']['user']['username']) {
            Session::put('error', 'You do not own this reply');
            return redirect('/forum/home');
        }

        $post = Thread::find($reply['toid']);

        $post['title'] = htmlspecialchars($post['title']);
        $this->request['data']['post'] = $post;
        $this->request['data']['reply'] = $reply;

        return view($this->request['data']['user']['version'] . '/Forum/Edit', $this->request);
    }

    public function edit_reply(Request $request) {
        $this->request['data']['embeds']['title'] = 'Edit Reply' . $this->request['data']['embeds']['title'];
        $data = $request->all();

        if(!$this->request['data']['siteusername']) {
            return redirect('/');
        }

        if($request->isMethod('post')) {
            $validator = Validator::make($data, [
                'id' => 'required|integer|exists:finobe.forum_replies,id',
                'postId' => 'required|integer|exists:finobe.forum_threads,id',
                'content' => 'required|string|min:3|max:8192'
            ]);

            if($validator->fails()) {
                Session::put('error', $validator->errors()->first());
                return redirect('/forum/edit?id=' . $data['id']);
            }

            $reply = Reply::find($data['id']);
        
            if($reply->author != $this->request['data']['user']['username']) {
                Session::put('error', 'You do not own this reply');
                return redirect('/forum/home');
            }

            $post = Thread::find($reply['toid']);
            
            if($post->locked == 'y') {
                Session::put('error', 'This post is locked');
                return redirect('/forum/edit?id=' . $data['id']);
            }

            $reply->comment = $data['content'];
            $reply->edited = 'y';
            $reply->edited_date = now();
            $reply->save();

            $data['postId'] = $reply->toid;

            $results_per_page = 10;
            $position_in_list = Reply::where('id', '<=', $data['id'])->where('toid', $data['postId'])->count();
            $page_of_reply = ceil($position_in_list / $results_per_page);

            Session::put('success', 'Successfully edited.');
            return redirect('/forum/post?id=' . $data['postId'] . '&page=' . $page_of_reply);
        }

        if(!isset($data['id'])) {
            return redirect('/forum/home');
        }

        if(!Reply::find($data['id'])) {
            Session::put('error', 'This reply doesn\'t exist or was deleted');
            return redirect('/forum/home');
        }

        $reply = Reply::find($data['id'])->toArray();
        
        if($reply['author'] != $this->request['data']['user']['username']) {
            Session::put('error', 'You do not own this reply');
            return redirect('/forum/home');
        }

        $post = Thread::find($reply['toid'])->toArray();

        $post['title'] = htmlspecialchars($post['title']);
        $this->request['data']['post'] = $post;
        $this->request['data']['reply'] = $reply;

        return view($this->request['data']['user']['version'] . '/Forum/Edit', $this->request);
    }

    public function new_post(Request $request) {
        $this->request['data']['embeds']['title'] = 'New Post' . $this->request['data']['embeds']['title'];
        $data = $request->all();

        if(!$this->request['data']['siteusername']) {
            return redirect('/');
        }

        if($request->isMethod('post')) {
            if($restricted = \App\Services\Restrictions::message((int) $this->request['data']['user']['id'], 'forum')) {
                Session::put('error', $restricted);
                return redirect('/forum/home');
            }

            if($request->hasFile('file')) {
                if($this->request['data']['user']['status'] != 'admin') {
                    return redirect('/app/forum/new/post');
                }

                $validator = Validator::make($data, [
                    'file' => 'required|file|mimes:jpg,jpeg,png,pdf,doc,docx,mp4,mov,gif,exe,ttf,webm,webp'
                ]);

                if($validator->fails()) {
                    Session::put('error', $validator->errors()->first());
                    return redirect('/app/forum/new/post');
                }

                $file = $request->file('file');
                $fileUrl = 'https://cdn.finobe.wtf/forum/media/' . $file->getClientOriginalName();

                if(file_exists(config('finobe.cdn_path') . '/forum/media/' . $file->getClientOriginalName())) {
                    Session::put('error', 'File already exists. Here is the link: ' . $fileUrl);
                    return redirect('/app/forum/new/post');
                }

                try {
                    $file->move(config('finobe.cdn_path') . '/forum/media', $file->getClientOriginalName());
                    Session::put('success', 'File uploaded successfully. Here is the link: ' . $fileUrl);
                    return redirect('/app/forum/new/post');
                } catch(\Exception $e) {
                    Session::put('error', 'Error uploading file. Check your server configurations.');
                    return redirect('/app/forum/new/post');
                }
            } else {
                $validator = Validator::make($data, [
                    'title' => 'required|string|min:3',
                    'content' => 'required|string|min:3|max:8192',
                    'section' => 'required|integer|min:1|max:10'
                ]);

                if($validator->fails()) {
                    Session::put('error', $validator->errors()->first());
                    return redirect('/app/forum/new/post');
                }

                if(($cooldown = $this->request['data']['user']['post_cooldown'] ?? null) && Carbon::parse($cooldown)->gt(Carbon::now()->subMinutes(5))) {
                    Session::put('error', 'You are posting too often');
                    return redirect('/app/forum/new/post');
                }

                if($data['section'] == '1' && $this->request['data']['user']['status'] != 'admin') {
                    Session::put('error', 'Not enough permissions');
                    return redirect('/app/forum/new/post');
                }

                $post = Thread::create([
                    'category' => $data['section'],
                    'author' => $this->request['data']['user']['username'],
                    'title' => $data['title'],
                    'comment' => $data['content']
                ]);

                $user = User::find($this->request['data']['user']['id']);
                $user->post_cooldown = now();
                $user->save();

                Session::put('success', 'Successfully created.');
                return redirect('/forum/post?id=' . $post->id);
            }
        }

        if($this->request['data']['user']['status'] == 'admin') {
            $mediaDir = config('finobe.cdn_path') . '/forum/media';
            $this->request['data']['list'] = (@is_dir($mediaDir) && @is_readable($mediaDir)) ? self::displayDirectory($mediaDir, '') : '';
        }

        return view($this->request['data']['user']['version'] . '/Forum/New/Post', $this->request);
    }

    private static function displayDirectory($dir, $prefix) {
        $files = @scandir($dir) ?: [];
        $html = '<ul>';

        foreach($files as $file) {
            if($file == '.' || $file == '..') {
                continue;
            }

            $path = $dir . '/' . $file;
            $name = htmlspecialchars($file);
            $html .= '<li>';

            if(@is_dir($path) && @is_readable($path)) {
                $html .= '<strong>' . $name . '</strong>' . self::displayDirectory($path, $prefix . rawurlencode($file) . '/');
            } else {
                $html .= '<a href="https://cdn.' . env('FINOBE_DOMAIN', 'finobe.wtf') . '/forum/media/' . $prefix . rawurlencode($file) . '" target="_blank">' . $name . '</a>';
            }

            $html .= '</li>';
        }

        return $html . '</ul>';
    }

    public function subscribe(Request $request) {
        $data = $request->all();

        if(!$this->request['data']['siteusername']) {
            return redirect('/');
        }

        $validator = Validator::make($data, [
            'id' => 'required|integer|exists:finobe.forum_threads,id'
        ]);

        if($validator->fails()) {
            Session::put('error', $validator->errors()->first());
            return redirect('/forum/home');
        }

        if(Subscription::where('username', $this->request['data']['user']['username'])->where('forumId', $data['id'])->exists()) {
            Subscription::where('username', $this->request['data']['user']['username'])
                ->where('forumId', $data['id'])
                ->delete();
            
            Session::put('success', 'Successfully removed subscription.');
        } else {
            Subscription::create([
                'username' => $this->request['data']['user']['username'],
                'forumId' => $data['id']
            ]);

            Session::put('success', 'Successfully added subscription.');
        }

        return redirect('/forum/post?id=' . $data['id']);
    }
}