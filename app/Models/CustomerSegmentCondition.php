<?php

namespace App\Models;

use Vayu\Database\Model;

class CustomerSegmentCondition extends Model
{
    protected string $table = 'customer_segment_conditions';
    public bool $timestamps = false;
    protected array $fillable = ['segment_id', 'field', 'operator', 'value', 'sort_order'];
}
