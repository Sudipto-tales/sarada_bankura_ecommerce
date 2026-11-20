<?php

namespace App\Models;

use Vayu\Database\Model;

class Tag extends Model
{
    protected string $table = 'tags';
    public bool $timestamps = false;
    protected array $fillable = ['name', 'slug'];
}
