<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Election extends Model
{
    public $timestamps = false;

    protected $connection = 'finobe';
    protected $table = 'elections';

    protected $fillable = [
        'title',
        'author',
        'css',
        'options',
        'votes',
        'date',
        'expire',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'votes' => 'array'
        ];
    }
}