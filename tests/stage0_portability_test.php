<?php

use Vayu\Application;
use Vayu\Database\DB;
use Vayu\Database\Schema\Blueprint;
use Vayu\Database\Schema\Schema;
use Vayu\Validation\Validator;

/** Stage 0: MySQL-portability guards (blank → NULL, case-insensitive unique, business config). */
return function (Assert $assert): void {
    $app = new Application(dirname(__DIR__));
    $app->config()->set('database.default', 'sqlite');
    $app->config()->set('database.connections.sqlite.database', ':memory:');

    // num_or_null / blank_to_null
    $assert->equals('num_or_null: empty string', null, num_or_null(''));
    $assert->equals('num_or_null: whitespace', null, num_or_null('   '));
    $assert->equals('num_or_null: null', null, num_or_null(null));
    $assert->equals('num_or_null: non-numeric', null, num_or_null('abc'));
    $assert->equals('num_or_null: array', null, num_or_null(['1']));
    $assert->equals('num_or_null: trims numeric string', '450.50', num_or_null(' 450.50 '));
    $assert->equals('num_or_null: zero kept', '0', num_or_null('0'));
    $assert->equals('num_or_null: int passes', 5, num_or_null(5));
    $assert->equals('blank_to_null: blank', null, blank_to_null('  '));
    $assert->equals('blank_to_null: trims', 'box', blank_to_null(' box '));

    // Case-insensitive unique (MySQL collation behaviour on every engine)
    Schema::create('products', function (Blueprint $t): void {
        $t->id();
        $t->string('sku');
    });
    $id = (int) DB::table('products')->insertGetId(['sku' => 'MSM-a']);
    $v = Validator::make(['sku' => 'msm-A'], ['sku' => 'unique:products,sku']);
    $assert->that('unique: differing case is a duplicate', $v->fails());
    $v = Validator::make(['sku' => ' MSM-A '], ['sku' => 'unique:products,sku']);
    $assert->that('unique: surrounding spaces ignored', $v->fails());
    $v = Validator::make(['sku' => 'MSM-A'], ['sku' => 'unique:products,sku,' . $id]);
    $assert->that('unique: own row ignored on update', !$v->fails());
    $v = Validator::make(['sku' => 'MSM-B'], ['sku' => 'unique:products,sku']);
    $assert->that('unique: new value passes', !$v->fails());

    // Whitespace-only input is empty
    $v = Validator::make(['price' => '  '], ['price' => 'nullable|numeric']);
    $assert->that('nullable: whitespace-only skips numeric', !$v->fails());
    $v = Validator::make(['name' => '   '], ['name' => 'required']);
    $assert->that('required: whitespace-only fails', $v->fails());

    // business() config
    $assert->equals('business(): default currency', 'INR', business('currency'));
    $assert->equals('business(): nested key', 'Asia/Kolkata', business('timezone'));
    $assert->that('business(): whole map', is_array(business()) && isset(business()['tax']['gst_rate']));
};
