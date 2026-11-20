<?php

namespace App\Models;

use Vayu\Database\Model;

class Brand extends Model
{
    protected string $table = 'brands';
    protected array $fillable = ['name', 'slug'];
}
