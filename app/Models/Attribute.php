<?php

namespace App\Models;

use Vayu\Database\Model;

class Attribute extends Model
{
    protected string $table = 'attributes';
    protected array $fillable = ['code', 'name', 'type', 'unit', 'display', 'used_for', 'status', 'description', 'sort'];
}
