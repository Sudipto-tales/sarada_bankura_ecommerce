<?php

namespace App\Models;

use Vayu\Database\Model;

class Brand extends Model
{
    protected string $table = 'brands';
    protected array $fillable = [
        'name', 'slug', 'country', 'tier', 'brand_type', 'description', 'website',
        'contact_email', 'is_featured', 'status', // logo lives in media_links (role logo)
    ];
}
