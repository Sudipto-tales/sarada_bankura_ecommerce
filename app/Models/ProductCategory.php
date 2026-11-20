<?php

namespace App\Models;

use Vayu\Database\Model;

class ProductCategory extends Model
{
    protected string $table = 'product_categories';
    protected array $fillable = ['parent_id', 'name', 'slug', 'description', 'is_visible', 'is_featured', 'sort'];
}
