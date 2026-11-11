<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailVerify extends Model
{
    public $timestamps = false;

    protected $connection = 'finobe';
    protected $table = 'verify_email';

    protected $fillable = [
        'username',
        'date',
        'expire',
        'used',
        'uid'
    ];
}