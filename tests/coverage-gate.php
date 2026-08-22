#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Fail the build when line coverage drops below a floor.
 *
 * PHPUnit measures coverage but has no notion of a minimum, so the number it
 * prints is easy to ignore — a change that deletes tests, or adds a large
 * untested class, looks exactly like a green build. This reads the Clover
 * report and turns that number into a pass/fail.
 *
 * The floor is a *ratchet*, not a target: it should only ever move up. When
 * coverage climbs comfortably above it, raise the constant below in the same
 * commit, so the suite can never quietly regress to where it was.
 *
 * Usage: php tests/coverage-gate.php [clover.xml] [floor]
 *   composer test:coverage && composer coverage:gate
 */

const DEFAULT_REPORT = __DIR__ . '/../build/coverage/clover.xml';

/**
 * Percent of executable lines that must be covered. Raise, never lower.
 *
 * Set deliberately low to start: no coverage driver was available when the gate
 * was written, so this is a floor that cannot fail a currently-green build
 * rather than a measurement. The first CI run prints the real figure — ratchet
 * this up to just under it then, and the gate starts earning its keep.
 */
const DEFAULT_FLOOR = 40.0;

$report = $argv[1] ?? DEFAULT_REPORT;
$floor = isset($argv[2]) ? (float) $argv[2] : (float) (getenv('LOG_LENS_COVERAGE_FLOOR') ?: DEFAULT_FLOOR);

if (!is_file($report)) {
    fwrite(STDERR, "No coverage report at {$report}.\n"
        . "Run `composer test:coverage` first (it needs the pcov or xdebug extension).\n");
    exit(1);
}

$xml = @simplexml_load_file($report);
if ($xml === false || !isset($xml->project->metrics)) {
    fwrite(STDERR, "Could not read Clover metrics from {$report}.\n");
    exit(1);
}

$metrics = $xml->project->metrics;
$statements = (int) $metrics['statements'];
$covered = (int) $metrics['coveredstatements'];
if ($statements === 0) {
    fwrite(STDERR, "The coverage report contains no executable statements — is <source> configured?\n");
    exit(1);
}

$percent = $covered / $statements * 100;
printf(
    "Line coverage: %.2f%% (%d/%d statements), floor %.2f%%\n",
    $percent,
    $covered,
    $statements,
    $floor,
);

if ($percent + 0.005 < $floor) {
    fwrite(STDERR, sprintf(
        "Coverage %.2f%% is below the %.2f%% floor. Add tests, or state why the floor should change.\n",
        $percent,
        $floor,
    ));
    exit(1);
}

// Worth knowing: a floor left far below reality stops protecting anything.
if ($percent - $floor >= 5.0) {
    printf("Coverage is %.2f points above the floor — consider raising DEFAULT_FLOOR in %s.\n", $percent - $floor, basename(__FILE__));
}

exit(0);
