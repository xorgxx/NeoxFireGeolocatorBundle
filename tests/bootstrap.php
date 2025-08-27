<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// Polyfills / helpers for tests.
if (!function_exists('mb_chr')) {
    function mb_chr($code, $encoding = 'UTF-8')
    {
        return iconv('UCS-4LE', $encoding, pack('V', $code));
    }
}
