<?php

namespace App\Models;

use Vayu\Database\Model;

/** Remember this device tokens (stored hashed). Written by App\Services\AdminAccess\Guard. */
class AdminRememberToken extends Model
{
    protected string $table = 'admin_remember_tokens';
    protected array $fillable = [];
}
