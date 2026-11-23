<?php

namespace App\Models;

use Vayu\Database\Model;

/** Customer vocabulary (VIP, Builder…). Separate from the product `tags` table on purpose. */
class CustomerTag extends Model
{
    protected string $table = 'customer_tags';
    protected array $fillable = ['name', 'slug', 'color', 'description'];
}
