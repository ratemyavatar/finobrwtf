<?php

namespace App\Http\Controllers\Web;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;
use App\Models\User;
use App\Models\Message;
use App\Http\Controllers\frontEnd;
use App\Http\Controllers\Controller;

class MessagesController extends Controller
{
    protected $request;

    public function __construct(Request $request) {
        $frontend = new frontEnd($request);
        $this->request = $frontend->getData();
    }

    public function inbox(Request $request) {
        $this->request['data']['embeds']['title'] = 'Inbox' . $this->request['data']['embeds']['title'];
        $this->request['data']['section'] = 'inbox';
        $data = $request->all();

        if(!$this->request['data']['siteusername']) {
            return redirect('/');
        }

        $this->request['data']['messages'] = [
            'data' => [],
            'page' => 1,
            'number_of_pages' => 1
        ];

        $messages = Message::where('touser', $this->request['data']['user']['id'])
            ->where('archived', false)
            ->count();

        $results_per_page = 12;
        $number_of_pages = ceil($messages / $results_per_page);
        $currentPage = isset($data['page']) ? max(1, intval($data['page'])) : 1;
        $offset = ($currentPage - 1) * $results_per_page;

        $messages = Message::where('touser', $this->request['data']['user']['id'])
            ->where('archived', false)
            ->orderBy('date', 'DESC')
            ->offset($offset)
            ->limit($results_per_page)
            ->get()
            ->map(fn($item) => $item->toArray());
        
        foreach($messages as $message) {
            $message['message'] = strip_tags(htmlspecialchars(\App\Services\CurseFilter::forViewer($message['message'])));
            $message['subject'] = strip_tags(htmlspecialchars($message['subject']));
            $message['author'] = htmlspecialchars(User::where('id', $message['author'])->value('username'));
            $message['date'] = date('M j, Y | g:i A', strtotime($message['date']));
            $this->request['data']['messages']['data'][] = $message;
        }

        $this->request['data']['messages']['page'] = $currentPage;
        $this->request['data']['messages']['number_of_pages'] = $number_of_pages;

        return view($this->request['data']['user']['version'] . '/Inbox/Index', $this->request);
    }

    public function sent(Request $request) {
        $this->request['data']['embeds']['title'] = 'Sent Messages' . $this->request['data']['embeds']['title'];
        $this->request['data']['section'] = 'sent';
        $data = $request->all();

        if(!$this->request['data']['siteusername']) {
            return redirect('/');
        }

        $this->request['data']['messages'] = [
            'data' => [],
            'page' => 1,
            'number_of_pages' => 1
        ];

        $messages = Message::where('author', $this->request['data']['user']['id'])
            ->where('archived', false)
            ->count();

        $results_per_page = 12;
        $number_of_pages = ceil($messages / $results_per_page);
        $currentPage = isset($data['page']) ? max(1, intval($data['page'])) : 1;
        $offset = ($currentPage - 1) * $results_per_page;

        $messages = Message::where('author', $this->request['data']['user']['id'])
            ->where('archived', false)
            ->orderBy('date', 'DESC')
            ->offset($offset)
            ->limit($results_per_page)
            ->get()
            ->map(fn($item) => $item->toArray());
        
        foreach($messages as $message) {
            $message['message'] = strip_tags(htmlspecialchars(\App\Services\CurseFilter::forViewer($message['message'])));
            $message['subject'] = strip_tags(htmlspecialchars($message['subject']));
            $message['author'] = htmlspecialchars(User::where('id', $message['author'])->value('username'));
            $message['date'] = date('M j, Y | g:i A', strtotime($message['date']));
            $this->request['data']['messages']['data'][] = $message;
        }
        
        $this->request['data']['messages']['page'] = $currentPage;
        $this->request['data']['messages']['number_of_pages'] = $number_of_pages;

        return view($this->request['data']['user']['version'] . '/Inbox/Index', $this->request);
    }

    public function archive(Request $request) {
        $this->request['data']['embeds']['title'] = 'Archived Messages' . $this->request['data']['embeds']['title'];
        $this->request['data']['section'] = 'archive';
        $data = $request->all();

        if(!$this->request['data']['siteusername']) {
            return redirect('/');
        }

        $this->request['data']['messages'] = [
            'data' => [],
            'page' => 1,
            'number_of_pages' => 1
        ];

        $messages = Message::where('touser', $this->request['data']['user']['id'])
            ->where('archived', true)
            ->count();

        $results_per_page = 12;
        $number_of_pages = ceil($messages / $results_per_page);
        $currentPage = isset($data['page']) ? max(1, intval($data['page'])) : 1;
        $offset = ($currentPage - 1) * $results_per_page;

        $messages = Message::where('touser', $this->request['data']['user']['id'])
            ->where('archived', true)
            ->orderBy('date', 'DESC')
            ->offset($offset)
            ->limit($results_per_page)
            ->get()
            ->map(fn($item) => $item->toArray());
        
        foreach($messages as $message) {
            $message['message'] = strip_tags(htmlspecialchars(\App\Services\CurseFilter::forViewer($message['message'])));
            $message['subject'] = strip_tags(htmlspecialchars($message['subject']));
            $message['author'] = htmlspecialchars(User::where('id', $message['author'])->value('username'));
            $message['date'] = date('M j, Y | g:i A', strtotime($message['date']));
            $this->request['data']['messages']['data'][] = $message;
        }
        
        $this->request['data']['messages']['page'] = $currentPage;
        $this->request['data']['messages']['number_of_pages'] = $number_of_pages;

        return view($this->request['data']['user']['version'] . '/Inbox/Index', $this->request);
    }

    public function message(Request $request) {
        $data = $request->all();

        if(!$this->request['data']['siteusername']) {
            return redirect('/');
        }

        if(!isset($data['id'])) {
            return redirect('/app/inbox');
        }

        if(!Message::find($data['id'])) {
            Session::put('error', 'Message not found');
            return redirect('/app/inbox');
        }

        $message = Message::find($data['id'])->toArray();

        if($this->request['data']['user']['id'] != $message['author'] && $this->request['data']['user']['id'] != $message['touser']) {
            Session::put('error', 'You are not mentioned in this message');
            return redirect('/app/inbox');
        }

        if($request->isMethod('post')) {
            if($this->request['data']['user']['id'] != $message['touser']) {
                return redirect('/app/inbox/message?id=' . $message['id']);
            }

            if(!$message['archived']) {
                $message2 = Message::find($data['id']);
                $message2->archived = true;
                $message2->save();
                
                Session::put('success', 'Successfully archived');
            } else {
                $message2 = Message::find($data['id']);
                $message2->archived = false;
                $message2->save();
                
                Session::put('success', 'Successfully unarchived');
            }

            return redirect('/app/inbox/message?id=' . $data['id']);
        }

        $message['uid'] = $message['author'];
        $message['message'] = nl2br(preg_replace('/\b((?:https?|ftp):\/\/\S+)/i', '<a href="$1" target="_blank">$1</a>', strip_tags(htmlspecialchars(\App\Services\CurseFilter::forViewer($message['message'])))));
        $message['subject'] = strip_tags(htmlspecialchars($message['subject']));
        $message['author'] = htmlspecialchars(User::where('id', $message['author'])->value('username'));
        $message['date'] = date('M j, g:ia', strtotime($message['date']));

        if(!$message['readed'] && $this->request['data']['user']['id'] != $message['uid']) {
            $message2 = Message::find($message['id']);
            $message2->readed = true;
            $message2->save();
            // the count was worked out before this, so the badge on this page goes down too
            if(isset($this->request['data']['notifications']['info']['inbox'])) {
                $this->request['data']['notifications']['info']['inbox'] = max(0, $this->request['data']['notifications']['info']['inbox'] - 1);
            }
        }

        $this->request['data']['embeds']['title'] = $message['subject'] . $this->request['data']['embeds']['title'];
        $this->request['data']['message'] = $message;

        return view($this->request['data']['user']['version'] . '/Inbox/Message', $this->request);
    }

    public function compose(Request $request) {
        $this->request['data']['embeds']['title'] = 'Compose Messages' . $this->request['data']['embeds']['title'];
        $data = $request->all();

        if(!$this->request['data']['siteusername']) {
            return redirect('/');
        }

        if($request->isMethod('post')) {
            $validator = Validator::make($data, [
                'username' => 'required|string|exists:finobe.users,username',
                'subject' => 'required|string|min:3|max:255',
                'message' => 'required|string|min:3|max:50000'
            ]);

            if($validator->fails()) {
                Session::put('error', $validator->errors()->first());
                return redirect('/app/inbox/compose');
            }

            if(Message::where('author', $this->request['data']['user']['id'])->where('date', '>=', DB::raw('NOW() - INTERVAL 5 MINUTE'))->exists()) {
                Session::put('error', 'Wait 5 minutes before sending another message');
                return redirect('/app/inbox/compose');
            }

            if($this->request['data']['user']['username'] == $data['username']) {
                Session::put('error', 'You cannot send a message to yourself');
                return redirect('/app/inbox/compose');
            }

            if($restricted = \App\Services\Restrictions::message((int) $this->request['data']['user']['id'], 'chat')) {
                Session::put('error', $restricted);
                return redirect('/app/inbox/compose');
            }

            $recipient = User::where('username', $data['username'])->first();
            if(!\App\Services\Privacy::allows($recipient, 'messages', \Illuminate\Support\Facades\Auth::user())) {
                Session::put('error', $recipient->username . " isn't accepting messages from you.");
                return redirect('/app/inbox/compose');
            }

            Message::create([
                'author' => $this->request['data']['user']['id'],
                'touser' => User::where('username', $data['username'])->value('id'),
                'subject' => $data['subject'],
                'message' => $data['message']
            ]);

            Session::put('success', 'Successfully send');
            return redirect('/app/inbox');
        }

        if(isset($data['user'])) {
            if(!User::where('username', $data['user'])->exists()) {
                Session::put('error', 'User not found');
                return redirect('/app/inbox/compose');
            }

            $this->request['data']['sendto'] = [
                'id' => User::where('username', $data['user'])->value('id'),
                'username' => $data['user']
            ];
        }

        if(isset($data['subject'])) {
            $this->request['data']['subject'] = $data['subject'];
        }

        return view($this->request['data']['user']['version'] . '/Inbox/Compose', $this->request);
    }
}