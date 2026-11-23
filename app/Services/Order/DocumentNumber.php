<?php

namespace App\Services\Order;

use Vayu\Database\DB;

/**
 * Gapless number series per Indian financial year (April–March, IST): MSO/26-27/0001.
 *
 * The only writer of document_counters. Call it INSIDE the transaction that creates the
 * document: the counter row is locked (SELECT … FOR UPDATE on MySQL; SQLite takes its write lock
 * with the UPDATE), so two requests can't get the same number, and a rolled-back document gives
 * its number back — no gaps.
 */
final class DocumentNumber
{
    public const SERIES = ['order', 'invoice', 'payment', 'refund', 'attempt', 'return'];

    /** "26-27" for a date in FY 2026-27 (1 Apr 2026 – 31 Mar 2027), IST. */
    public static function fy(?int $ts = null): string
    {
        $tz = new \DateTimeZone((string) (business('timezone') ?: 'Asia/Kolkata'));
        $d = (new \DateTimeImmutable('@' . ($ts ?? time())))->setTimezone($tz);
        $y = (int) $d->format('Y');
        $start = (int) $d->format('n') >= (int) (business('fy_start_month') ?: 4) ? $y : $y - 1;

        return sprintf('%02d-%02d', $start % 100, ($start + 1) % 100);
    }

    public static function prefix(string $series): string
    {
        return (string) (business('numbering.' . $series) ?: strtoupper(substr($series, 0, 3)));
    }

    /** Take the next number in a series. Must run inside a DB transaction. */
    public static function next(string $series, ?int $ts = null): string
    {
        if (!in_array($series, self::SERIES, true)) {
            throw new \InvalidArgumentException("Unknown number series: {$series}");
        }
        if (!DB::connection()->pdo()->inTransaction()) {
            throw new \LogicException('DocumentNumber::next() must be called inside the transaction that creates the document.');
        }
        $fy = self::fy($ts);
        $prefix = self::prefix($series);
        $now = date('Y-m-d H:i:s');
        $lock = DB::connection()->driver() === 'mysql' ? ' FOR UPDATE' : '';

        $row = DB::selectOne('SELECT id, last_number FROM document_counters WHERE series = ? AND fy = ?' . $lock, [$series, $fy]);
        if ($row === null) {
            try {
                DB::table('document_counters')->insert(['series' => $series, 'fy' => $fy, 'prefix' => $prefix, 'last_number' => 0, 'updated_at' => $now]);
            } catch (\Throwable) {
                // another request created the row first — fall through and lock it
            }
            $row = DB::selectOne('SELECT id, last_number FROM document_counters WHERE series = ? AND fy = ?' . $lock, [$series, $fy]);
        }
        $n = (int) $row['last_number'] + 1;
        DB::update('UPDATE document_counters SET last_number = ?, prefix = ?, updated_at = ? WHERE id = ? AND last_number = ?', [$n, $prefix, $now, (int) $row['id'], $n - 1]) === 1
            or throw new \RuntimeException('Number series "' . $series . '" changed while numbering — please retry.');

        return self::format($prefix, $fy, $n);
    }

    public static function format(string $prefix, string $fy, int $n): string
    {
        return $prefix . '/' . $fy . '/' . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }
}
