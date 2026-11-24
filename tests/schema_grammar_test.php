<?php

use Vayu\Database\Schema\Grammar;

/**
 * Index DDL must be valid on MySQL 8 (no CREATE INDEX IF NOT EXISTS, 64-char names),
 * while SQLite keeps its IF NOT EXISTS guard.
 */
return function (Assert $assert): void {
    $long = 'whatsapp_message_template_components';
    $cols = ['template_id', 'component_type', 'position'];

    $mysql = (new Grammar('mysql'))->compileCreate($long, [], [['type' => 'index', 'columns' => $cols]]);
    $assert->that('mysql: no IF NOT EXISTS on CREATE INDEX', !str_contains($mysql[1], 'INDEX IF NOT EXISTS'));
    preg_match('/INDEX `([^`]+)`/', $mysql[1], $m);
    $assert->that('mysql: index name ≤ 64 chars', isset($m[1]) && strlen($m[1]) <= 64);

    $uniq = (new Grammar('mysql'))->compileCreate('users', [], [['type' => 'unique', 'columns' => ['email']]]);
    $assert->equals('mysql: short unique index unchanged', 'CREATE UNIQUE INDEX `users_email_unique` ON `users` (`email`)', $uniq[1]);

    $sqlite = (new Grammar('sqlite'))->compileCreate('users', [], [['type' => 'unique', 'columns' => ['email']]]);
    $assert->that('sqlite: keeps IF NOT EXISTS', str_contains($sqlite[1], 'UNIQUE INDEX IF NOT EXISTS "users_email_unique"'));
};
