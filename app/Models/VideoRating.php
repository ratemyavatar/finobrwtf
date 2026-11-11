<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VideoRating extends Model
{
    public $timestamps = false;

    protected $connection = 'finobe';
    protected $table = 'video_ratings';

    protected $fillable = [
        'sender',
        'toid',
        'rate_type',
    ];
}
