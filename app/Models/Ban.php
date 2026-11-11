<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Ban extends Model
{
    public $timestamps = false;

    protected $connection = 'finobe';
    protected $table = 'bans';

    protected $fillable = [
        'username',
        'reason',
        'expire',
        'moderator',
        'perm',
        'reactivated',
        'date',
        'undone',
    ];
}
