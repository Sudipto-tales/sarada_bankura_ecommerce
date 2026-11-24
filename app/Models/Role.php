<?php

namespace App\Models;

use Vayu\Database\Model;

/** Admin role; permissions in role_permissions, the list itself in config/permissions.php. Seeded by Roles::sync(). */
class Role extends Model
{
    protected string $table = 'roles';
    protected array $fillable = [];
}
