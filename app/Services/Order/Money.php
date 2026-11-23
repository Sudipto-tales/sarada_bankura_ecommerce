<?php

namespace App\Services\Order;

/** Indian money formatting: ₹1,23,456.50 and "Rupees One Lakh … Only". */
final class Money
{
    /** 123456.5 → "1,23,456.50" (Indian digit grouping). */
    public static function format(float|int|string|null $amount, int $decimals = 2): string
    {
        $v = round((float) $amount, $decimals);
        $neg = $v < 0;
        $parts = explode('.', number_format(abs($v), $decimals, '.', ''));
        $int = $parts[0];
        if (strlen($int) > 3) {
            $last3 = substr($int, -3);
            $rest = substr($int, 0, -3);
            $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
            $int = $rest . ',' . $last3;
        }

        return ($neg ? '-' : '') . $int . ($decimals > 0 ? '.' . $parts[1] : '');
    }

    public static function inr(float|int|string|null $amount, int $decimals = 2): string
    {
        return '₹' . self::format($amount, $decimals);
    }

    /** 38700.5 → "Rupees Thirty Eight Thousand Seven Hundred and Fifty Paise Only". */
    public static function words(float|int|string $amount): string
    {
        $v = round(abs((float) $amount), 2);
        $rupees = (int) floor($v);
        $paise = (int) round(($v - $rupees) * 100);
        $out = 'Rupees ' . ($rupees === 0 ? 'Zero' : self::intWords($rupees));
        if ($paise > 0) {
            $out .= ' and ' . self::intWords($paise) . ' Paise';
        }

        return $out . ' Only';
    }

    private static function intWords(int $n): string
    {
        $parts = [];
        foreach ([[10000000, 'Crore'], [100000, 'Lakh'], [1000, 'Thousand'], [100, 'Hundred']] as [$size, $name]) {
            if ($n >= $size) {
                $parts[] = self::intWords(intdiv($n, $size)) . ' ' . $name;
                $n %= $size;
            }
        }
        if ($n > 0) {
            $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen',
                'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
            $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
            $parts[] = $n < 20 ? $ones[$n] : trim($tens[intdiv($n, 10)] . ' ' . $ones[$n % 10]);
        }

        return implode(' ', $parts);
    }
}
