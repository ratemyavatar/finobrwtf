<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Configuration extends Model
{
    public $timestamps = false;

    protected $connection = 'finobe';
    protected $table = 'web_configuration';

    protected $fillable = [
        'type',
        'data'
    ];
}
