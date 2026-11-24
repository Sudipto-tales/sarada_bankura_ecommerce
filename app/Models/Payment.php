<?php

namespace App\Models;

use Vayu\Database\Model;

/** Writes go through App\Services\Payment (single writers, see docs/roadmap.md), not this model. */
class Payment extends Model
{
    protected string $table = 'payments';
    protected array $fillable = [];
}
