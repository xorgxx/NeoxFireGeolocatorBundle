<?php
declare(strict_types=1);

// Simple Clover XML coverage threshold checker
// Usage: php tools/check_coverage.php path/to/clover.xml 80

if ($argc < 3) {
    fwrite(STDERR, "Usage: php tools/check_coverage.php <clover.xml> <minPercent>\n");
    exit(2);
}

[$script, $cloverPath, $minStr] = $argv;
$min = (float) $minStr;

if (!is_file($cloverPath)) {
    fwrite(STDERR, "Coverage file not found: {$cloverPath}\n");
    exit(2);
}

libxml_use_internal_errors(true);
$xml = simplexml_load_file($cloverPath);
if (false === $xml) {
    fwrite(STDERR, "Failed to parse Clover XML: {$cloverPath}\n");
    foreach (libxml_get_errors() as $error) {
        fwrite(STDERR, trim($error->message) . "\n");
    }
    exit(2);
}

// Clover schema: metrics attributes contain elements like statements and coveredstatements
$metrics = $xml->xpath('//metrics');
if (!$metrics || !isset($metrics[0])) {
    fwrite(STDERR, "Could not locate <metrics> in Clover XML.\n");
    exit(2);
}

$attrs = $metrics[0]->attributes();
$statements = (float) ($attrs['statements'] ?? 0);
$covered = (float) ($attrs['coveredstatements'] ?? 0);

$percent = $statements > 0 ? ($covered / $statements) * 100.0 : 0.0;
$percentRounded = round($percent, 2);

if ($percent + 1e-9 < $min) {
    fwrite(STDERR, sprintf(
        "Code coverage %.2f%% is below required threshold %.2f%%\n",
        $percentRounded,
        $min
    ));
    exit(1);
}

fwrite(STDOUT, sprintf("Code coverage check passed: %.2f%% >= %.2f%%\n", $percentRounded, $min));
