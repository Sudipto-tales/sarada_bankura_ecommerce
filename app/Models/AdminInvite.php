<?php

namespace App\Models;

use Vayu\Database\Model;

/** Invite / reset link (token stored hashed). Written by App\Services\AdminAccess\Invites. */
class AdminInvite extends Model
{
    protected string $table = 'admin_invites';
    protected array $fillable = [];
}
