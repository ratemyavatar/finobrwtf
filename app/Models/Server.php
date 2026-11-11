<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Server extends Model
{
    public $timestamps = false;

    protected $connection = 'finobe';
    protected $table = 'servers';

    protected $fillable = [
        'ip',
        'port',
        'placeid',
        'players',
        'jobId',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'players' => 'array'
        ];
    }
}
