<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Announcement extends Model
{
    public $timestamps = false;

    protected $connection = 'finobe';
    protected $table = 'announcements';

    protected $fillable = [
        'author',
        'message',
        'date',
        'expire',
        'color',
    ];
}
