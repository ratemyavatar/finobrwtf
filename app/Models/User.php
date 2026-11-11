<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    public $timestamps = false;

    protected $connection = 'finobe';
    protected $table = 'users';

    /** whether the gender migration has run (forum author lookups select only some columns) */
    public static function hasGender(): bool
    {
        static $has = null;
        return $has ??= \Illuminate\Support\Facades\Schema::connection('finobe')->hasColumn('users', 'gender');
    }

    protected $fillable = [
        'username',
        'email',
        'password',
        'friends',
        'inventory',
        'badges',
        'avatar',
        'token',
        'created'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        //'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_backup_codes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected static function booted(): void
    {
        static::creating(function (self $user) {
            if (empty($user->created)) {
                $user->created = now()->toDateTimeString();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'friends' => 'array',
            'inventory' => 'array',
            'avatar' => 'array',
            'badges' => 'array'
        ];
    }
    
}
