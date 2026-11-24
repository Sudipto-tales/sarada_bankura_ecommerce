<?php

namespace App\Models;

use Vayu\Database\Model;

/** Writes go through App\Services\Payment (single writers, see docs/roadmap.md), not this model. */
class PaymentAttempt extends Model
{
    protected string $table = 'payment_attempts';
    protected array $fillable = [];
}
