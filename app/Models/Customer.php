<?php

namespace App\Models;

use Vayu\Database\Model;

/** Writes go through App\Services\Customer\CustomerService (phone normalising + online linking). */
class Customer extends Model
{
    protected string $table = 'customers';
    protected array $fillable = [
        'name', 'phone', 'email', 'company', 'gst_number', 'customer_type', 'status', 'lifecycle', 'source',
        'is_online', 'user_id', 'marketing_consent', 'total_orders', 'total_spent', 'last_order_at', 'notes',
        'merged_into_id', 'created_by',
    ];
}
