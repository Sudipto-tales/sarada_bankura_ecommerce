<?php

namespace App\Models;

use Vayu\Database\Model;

/** Writes go through App\Services\Payment (single writers, see docs/README.md), not this model. */
class PaymentRequestLog extends Model
{
    protected string $table = 'payment_request_logs';
    protected array $fillable = [];
}
