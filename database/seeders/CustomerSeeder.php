<?php

use App\Services\Customer\SegmentMatcher;
use Vayu\Database\DB;
use Vayu\Database\Seeder;

/**
 * Stage 3 defaults (idempotent): customer tags + the six default segments from docs/customers.md.
 * Creates no customers. Run: php vayu db:seed CustomerSeeder
 */
return new class extends Seeder {
    public function run(): void
    {
        $now = date('Y-m-d H:i:s');
        $tags = [
            ['VIP', 'gold', 'High-value customers who get priority service'],
            ['Builder', 'blue', 'Builders and contractors buying for projects'],
            ['Dealer', 'violet', 'Resellers and retail dealers'],
            ['Wholesale', 'green', 'Bulk / truck-load buyers'],
            ['Architect', 'rose', 'Architects and interior designers who specify stone'],
        ];
        foreach ($tags as [$name, $color, $desc]) {
            $slug = strtolower($name);
            if (!DB::table('customer_tags')->where('slug', $slug)->exists()) {
                DB::table('customer_tags')->insert(['name' => $name, 'slug' => $slug, 'color' => $color, 'description' => $desc, 'created_at' => $now, 'updated_at' => $now]);
            }
        }

        $segments = [
            ['VIP Customers', 'Spent ₹2,00,000 or more across 3+ orders', 'all', [['total_spent', 'gte', '200000'], ['total_orders', 'gte', '3']]],
            ['Builders & Contractors', 'Project buyers — follow up on site progress', 'all', [['customer_type', 'is', 'builder']]],
            ['Dealers', 'Resellers buying for their own shops', 'all', [['customer_type', 'is', 'dealer']]],
            ['Inactive Customers', 'No order in the last 90 days', 'all', [['last_order_at', 'older_than_days', '90']]],
            ['New Customers', 'At most one order so far', 'all', [['total_orders', 'lte', '1']]],
            ['Online Customers', 'Have an online store account', 'all', [['is_online', 'is_true', null]]],
        ];
        foreach ($segments as [$name, $desc, $match, $rules]) {
            $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
            if (DB::table('customer_segments')->where('slug', $slug)->exists()) {
                continue;
            }
            $sid = (int) DB::table('customer_segments')->insertGetId([
                'name' => $name, 'slug' => $slug, 'description' => $desc, 'type' => 'dynamic', 'match_type' => $match,
                'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach ($rules as $i => [$field, $op, $value]) {
                DB::table('customer_segment_conditions')->insert(['segment_id' => $sid, 'field' => $field, 'operator' => $op, 'value' => $value, 'sort_order' => $i]);
            }
            SegmentMatcher::refresh($sid);
        }
    }
};
