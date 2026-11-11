<?php

namespace App\Http\Controllers\Web;

use App\Models\VideoRating;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;
use App\Models\User;
use App\Models\Video;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;

class VideoController extends Controller
{

    public function index(Request $request) {
        $title = 'Videos';
        $data = $request->all();

        $videos = Video::count();
        
        $pages_to_show = 10;
        $results_per_page = 20;
        $number_of_pages = ceil($videos / $results_per_page);
        $currentPage = isset($data['page']) ? max(1, intval($data['page'])) : 1;
        $offset = ($currentPage - 1) * $results_per_page;
        $start_page = max(1, min($currentPage - floor($pages_to_show / 2), $number_of_pages - $pages_to_show + 1));
        $end_page = min($number_of_pages, $start_page + $pages_to_show - 1);
        $videos = Video::orderBy('id', 'DESC')
            ->offset($offset)
            ->limit($results_per_page)
            ->get()
            ->map(fn($item) => $item->toArray());
        
        foreach($videos as $key => $video) {
            if(Redis::exists("video_processing:{$video['filename']}")) {
                unset($videos[$key]);
            }
        }
        
        $pageData['videos'] = [
            'data' => $videos,
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
            $pageData['videos']['pages']['data'][] = ['page' => $page];
        }

        if(!$videos) {
            $pageData['videos']['pages']['data'][] = [
                'page' => 1
            ];
        }

        return $this->page('Videos/Index', $pageData ?? [], $title ?? null);
    }

    public function video(Request $request, $id) {
        if(!Video::find($id)) {
            Session::put('error', 'Video not found');
            return redirect('/videos');
        }

        $video = Video::find($id)->toArray();
        
        $video['title'] = strip_tags(htmlspecialchars($video['title']));
        $video['description'] = nl2br(strip_tags(htmlspecialchars($video['description'])));
        $video['uuid'] = User::where('username', $video['author'])->value('id');
        $video['author'] = htmlspecialchars($video['author']);
        $video['rating'] = VideoRating::where('toid', $video['id'])->where('rate_type', 'l')->count();
        $video['upvotes'] = $video['rating'];
        $video['rating'] = $video['rating'] - VideoRating::where('toid', $video['id'])->where('rate_type', 'd')->count();
        $video['downvotes'] = VideoRating::where('toid', $video['id'])->where('rate_type', 'd')->count();

        if(Auth::check()) {
            if(VideoRating::where('toid', $id)->where('sender', Auth::user()->username)->count()) {
                $video['userRating'] = VideoRating::where('toid', $id)
                    ->where('sender', Auth::user()->username)
                    ->value('rate_type');
            }
        }

        $title = $video['title'];
        $pageData['video'] = $video;

        return $this->page('Videos/Video', $pageData ?? [], $title ?? null);
    }

    public function video_thumb(Request $request, $id) {
        if(!Video::find($id)) {
            abort(404);
        }

        return redirect('https://cdn.finobe.wtf/videos/thumbs/' . Video::find($id)->thumbnail);
    }

    public function video_data(Request $request, $id) {
        if(!Video::find($id)) {
            return $this->page('404', $pageData ?? [], $title ?? null, 404);
        }

        //return redirect('https://cdn.finobe.wtf/videos/data/' . $this->db->table('videos')->select('filename')->where('id', $id)->value('filename'));
        $video = Video::find($id)->toArray();
        
        if(!file_exists(config('finobe.cdn_path') . '/videos/data/' . $video['filename'])) {
            abort(404, 'Video not found');
        }

        $filePath = config('finobe.cdn_path') . '/videos/data/' . $video['filename'];
        $size = filesize($filePath);
        $start = 0;
        $end = $size - 1;

        $headers = [
            'Content-Type' => mime_content_type($filePath),
            'Accept-Ranges' => 'bytes',
        ];

        if($request->headers->has('Range')) {
            preg_match('/bytes=(\d+)-(\d*)/', $request->header('Range'), $matches);

            $start = intval($matches[1]);
            $end = isset($matches[2]) && is_numeric($matches[2]) ? intval($matches[2]) : $end;

            $length = $end - $start + 1;

            $headers['Content-Range'] = "bytes $start-$end/$size";
            $headers['Content-Length'] = $length;

            return response()->stream(function () use ($filePath, $start, $length) {
                $handle = fopen($filePath, 'rb');
                fseek($handle, $start);

                $buffer = 1024 * 8;
                $bytesSent = 0;

                while(!feof($handle) && $bytesSent < $length) {
                    $readLength = min($buffer, $length - $bytesSent);
                    echo fread($handle, $readLength);
                    $bytesSent += $readLength;
                    ob_flush();
                    flush();
                }

                fclose($handle);
            }, 206, $headers);
        }

        $headers['Content-Length'] = $size;

        return response()->stream(fn() => readfile($filePath), 200, $headers);
    }

    public function rate(Request $request) {
        $data = $request->all();
        $response = [
            'code' => 200,
            'message' => 'success'
        ];

        if(!Auth::check()) {
            $response['code'] = 400;
            $response['message'] = 'Bad request';

            return response()->json($response, 400);
        }

        $validator = Validator::make($data, [
            'videoId' => 'required|integer',
            'rating' => 'required|string|in:l,d|size:1',
        ]);

        if($validator->fails()) {
            $response['code'] = 400;
            $response['message'] = 'Bad request';
            $response['errors'] = $validator->errors();

            return response()->json($response, 400);
        }

        $data['videoId'] = intval($data['videoId']);

        if(VideoRating::where('sender', Auth::user()->username)->where('toid', $data['videoId'])->exists()) {
            $ratingData = VideoRating::where('sender', Auth::user()->username)
                //->where('rate_type', $data['rating'])
                ->where('toid', $data['videoId'])
                ->first()
                ->toArray();
            
            if($ratingData['rate_type'] != $data['rating']) {
                VideoRating::where('id', $ratingData['id'])
                    ->update([
                        'rate_type' => $data['rating']
                    ]);
            } else {
                VideoRating::where('sender', Auth::user()->username)
                    ->where('toid', $data['videoId'])
                    ->delete();
            }
        } else {
            VideoRating::create([
                'sender' => Auth::user()->username,
                'toid' => $data['videoId'],
                'rate_type' => $data['rating']
            ]);
        }

        return response()->json($response, 200);
    }

    public function rating_number(Request $request) {
        $data = $request->all();
        $response = [
            'code' => 200,
            'message' => 'success'
        ];

        if(!Auth::check()) {
            $response['code'] = 400;
            $response['message'] = 'Bad request';

            return response()->json($response, 400);
        }

        $validator = Validator::make($data, [
            'videoId' => 'required|integer'
        ]);

        if($validator->fails()) {
            $response['code'] = 400;
            $response['message'] = 'Bad request';
            $response['errors'] = $validator->errors();

            return response()->json($response, 400);
        }

        $data['videoId'] = intval($data['videoId']);

        $rating = VideoRating::where('toid', $data['videoId'])
            ->where('rate_type', 'l')
            ->count();
        
        $rating -= VideoRating::where('toid', $data['videoId'])
            ->where('rate_type', 'd')
            ->count();
        
        $response['rating'] = $rating;
        return response()->json($response, 200);
    }
}