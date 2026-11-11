<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Purchases extends Model
{
    public $timestamps = false;

    protected $connection = 'finobe';
    protected $table = 'purchases';
    const CREATED_AT = 'date';
    const UPDATED_AT = 'lastchanged';

    protected $fillable = [
        'username',
        'assetid',
        'serial',
        'date',
        'lastchanged',
        'author',
        'amount',
        'type',
    ];

    protected function casts(): array
    {
        return [
            'additional' => 'array'
        ];
    }
}
