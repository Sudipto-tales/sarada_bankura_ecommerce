<?php

namespace App\Models;

use Vayu\Database\Model;

/** Staff login (separate from customers' users). Writes go through App\Services\AdminAccess\Staff / Guard. */
class AdminUser extends Model
{
    protected string $table = 'admin_users';
    protected array $fillable = [];
}
