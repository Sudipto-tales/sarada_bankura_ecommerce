<?php

namespace App\Models;

use Vayu\Database\Model;

/** Login history + lockout counter. Written by App\Services\AdminAccess\Guard. */
class AdminLoginAttempt extends Model
{
    protected string $table = 'admin_login_attempts';
    protected array $fillable = [];
}
