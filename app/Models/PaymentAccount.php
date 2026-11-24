<?php

namespace App\Models;

use Vayu\Database\Model;

/** Writes go through App\Services\Payment (single writers, see docs/roadmap.md), not this model. */
class PaymentAccount extends Model
{
    protected string $table = 'payment_accounts';
    protected array $fillable = [];
}
