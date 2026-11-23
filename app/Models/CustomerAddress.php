<?php

namespace App\Models;

use Vayu\Database\Model;

class CustomerAddress extends Model
{
    protected string $table = 'customer_addresses';
    protected array $fillable = [
        'customer_id', 'label', 'type', 'is_default', 'name', 'phone', 'address_line1', 'address_line2', 'area',
        'post_office', 'city', 'district', 'state', 'pincode', 'country',
    ];
}
