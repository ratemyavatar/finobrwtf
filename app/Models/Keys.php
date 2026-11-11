<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Keys extends Model
{
    public $timestamps = false;

    protected $connection = 'finobe';
    protected $table = 'invitekeys';

    protected $fillable = [
        'author',
        'IID',
        'creation',
        'dateUsed',
        'used',
        'usedBy',
    ];
}