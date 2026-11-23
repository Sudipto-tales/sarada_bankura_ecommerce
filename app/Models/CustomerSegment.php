<?php

namespace App\Models;

use Vayu\Database\Model;

/** Rules are compiled by App\Services\Customer\SegmentMatcher. */
class CustomerSegment extends Model
{
    protected string $table = 'customer_segments';
    protected array $fillable = [
        'name', 'slug', 'description', 'type', 'match_type', 'is_active', 'member_count', 'refreshed_at', 'created_by',
    ];
}
