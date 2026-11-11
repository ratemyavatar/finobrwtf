<?php
namespace App\Blog;
use Illuminate\Support\Facades\DB;
final class Settings {
    public static function all():array {
        return array_replace(collect(config('blog'))->only(['title','description','logo','cover','twitter_url'])->all(),DB::connection('finobe')->table('blog_settings')->pluck('value','key')->all());
    }
}
