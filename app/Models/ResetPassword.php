<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ResetPassword extends Model
{
    public $timestamps = false;

    protected $connection = 'finobe';
    protected $table = 'reset_password';

    protected $fillable = [
        'username',
        'date',
        'expire',
        'used',
        'uid'
    ];
}