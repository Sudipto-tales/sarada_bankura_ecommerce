<?php

namespace App\Models;

use Vayu\Database\Model;

/** Writes go through App\Services\Inventory / App\Services\NotificationService, not this model. */
class InventoryMovement extends Model
{
    protected string $table = 'inventory_movements';
    protected array $fillable = [];
}
