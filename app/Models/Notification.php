<?php

namespace App\Models;

use Vayu\Database\Model;

/** Writes go through App\Services\Inventory / App\Services\NotificationService, not this model. */
class Notification extends Model
{
    protected string $table = 'notifications';
    protected array $fillable = [];
}
