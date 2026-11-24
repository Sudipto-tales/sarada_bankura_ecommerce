<?php

namespace App\Models;

use Vayu\Database\Model;

/** Writes go through App\Services\Payment (single writers, see docs/roadmap.md), not this model. */
class PaymentRefund extends Model
{
    protected string $table = 'payment_refunds';
    protected array $fillable = [];
}
