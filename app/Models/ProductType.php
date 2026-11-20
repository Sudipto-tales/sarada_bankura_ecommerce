<?php

namespace App\Models;

use Vayu\Database\Model;

class ProductType extends Model
{
    protected string $table = 'product_types';
    protected array $fillable = ['name', 'slug'];
}
