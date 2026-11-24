<?php

namespace App\Models;

use Vayu\Database\Model;

/** Writes go through App\Services\Payment (single writers, see docs/roadmap.md), not this model. */
class AuditLog extends Model
{
    protected string $table = 'audit_logs';
    protected array $fillable = [];
}
