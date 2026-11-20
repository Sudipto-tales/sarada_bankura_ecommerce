<?php

namespace App\Models;

use Vayu\Database\Model;

class Product extends Model
{
    protected string $table = 'products';

    protected array $fillable = [
        'external_product_id', 'status', 'sku', 'name', 'slug',
        'short_description', 'description', 'product_type_id', 'brand_id',
        'is_enquiry_only', 'show_price', 'is_featured', 'is_bestseller',
        'meta_title', 'meta_description',
    ];
}
