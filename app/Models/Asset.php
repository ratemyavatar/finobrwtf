<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Asset extends Model
{
    public $timestamps = false;

    protected $connection = 'finobe';
    protected $table = 'assets';
    const CREATED_AT = 'created';
    const UPDATED_AT = 'updated';

    protected $fillable = [
        'asset_type',
        'title',
        'author',
        'file',
        'description',
        'visibility',
        'additional',
    ];

    protected function casts(): array
    {
        return [
            'additional' => 'array'
        ];
    }
}
