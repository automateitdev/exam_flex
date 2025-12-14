<?php

if (!function_exists('roundMark')) {
    function roundMark($mark, $method)
    {
        $method = $method ?? 'At Actual';

        return match ($method) {
            'At Actual' => (float) $mark,
            'Always Down' => floor($mark),
            'Always Up' => ceil($mark),
            'Without Fraction' => ($mark - floor($mark) >= 0.50) ? floor($mark) + 1 : floor($mark),
            default => (float) $mark,
        };
    }
}


if (!function_exists('round2')) {
    function round2($value)
    {
        return (float) number_format((float) $value, 2, '.', '');
    }
}

if (!function_exists('format2')) {
    function format2($value)
    {
        return number_format((float)$value, 2, '.', '');
    }
}

