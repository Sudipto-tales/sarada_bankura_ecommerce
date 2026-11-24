<?php

namespace App\Models;

use Vayu\Database\Model;

/** 6-digit password codes (stored hashed). Written by App\Services\AdminAccess\Otp. */
class AdminOtp extends Model
{
    protected string $table = 'admin_otps';
    protected array $fillable = [];
}
