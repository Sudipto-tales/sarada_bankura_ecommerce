<?php

namespace App\Models;

use Vayu\Database\Model;

class User extends Model
{
    /**
     * Mass-assignable attributes.
     *
     * @var list<string>
     */
    protected array $fillable = [
        'name',
        'email',
        'password',
        'role',
        'phone',
        'status',
        'email_verified_at',
        'phone_verified_at',
        'last_login_at',
    ];
}
