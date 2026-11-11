<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GameNode extends Model
{
    protected $connection = 'finobe';
    protected $table = 'game_nodes';

    protected $fillable = [
        'name',
        'version',
        'public_ip',
        'control_url',
        'port_min',
        'port_max',
        'enabled',
        'weight',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'port_min' => 'integer',
            'port_max' => 'integer',
            'weight' => 'integer',
        ];
    }
}
