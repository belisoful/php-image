<?php

/**
 * Branch coverage gate.
 *
 * Line coverage says a line ran; it says nothing about a decision that only ever went
 * one way.  This gate reads the serialized coverage of a `--path-coverage` run and
 * requires every source file to take all of its branches, except the few whose remaining
 * edges cannot be reached from any input.  Those are counted, not listed by line number,
 * so ordinary edits above them do not break the gate.
 *
 * Most of the allowed entries are not code anyone wrote: PHP emits an implicit
 * `UnhandledMatchError` edge for a `match` whose subject is already range-checked, an
 * implicit `return null` after a `while (true)` that only exits by return or throw, an
 * implicit `default` for a `switch` over a validated private field, and an implicit
 * rethrow for a multi-catch whose `try` can only raise the listed types.  The rest are
 * guards made redundant by an identical earlier check, or failures that need resources a
 * unit test cannot afford (a >4 GiB payload, a ~537 M pixel image).  See AGENTS.md.
 *
 * Because those edges are emitted by the compiler rather than written, *which* site
 * reports one moves between PHP and Xdebug versions: two identical multi-catches can swap
 * which of them carries the dead rethrow.  The per-file figures are therefore maximums,
 * not exact counts, and a total cap keeps that tolerance from hiding real drift.  A file
 * that comes in under its maximum is reported, not failed.
 *
 * Usage: php tests/test_tools/branch-gate.php <coverage.php>
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

/** The most unreachable branch edges each file may carry. */
const ALLOWED_UNTAKEN = [
	'src/Compression/CCITTFaxCompressor.php' => 7,
	'src/Meta/JUMBF/JUMBFBox.php' => 1,
	'src/Meta/EXIF.php' => 1,
	'src/TIFF/TIFFDocument.php' => 1,
	'src/TIFF/TIFFDataType.php' => 2,
	'src/ImageGraphicsGD.php' => 2,
	'src/ImageGraphicsImagick.php' => 2,
	'src/JPEGImage.php' => 3,
	'src/PNGImage.php' => 2,
];

/** The most unreachable branches the library may carry in total, on any PHP version. */
const MAX_UNTAKEN = 20;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$path = $argv[1] ?? 'build/logs/coverage.php';
if (!is_file($path)) {
	fwrite(STDERR, "branch gate: no coverage report at {$path}\n");
	exit(2);
}

$coverage = include $path;
$root = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
$total = 0;
$untaken = [];

foreach ($coverage->getData()->functionCoverage() as $file => $functions) {
	$relative = str_replace($root, '', $file);
	foreach ($functions as $function) {
		foreach (($function['branches'] ?? []) as $branch) {
			$total++;
			if ((int) $branch['hit'] === 0) {
				$untaken[$relative][] = (int) $branch['line_start'];
			}
		}
	}
}

if ($total === 0) {
	fwrite(STDERR, "branch gate: the report contains no branches — was --path-coverage used?\n");
	exit(2);
}

$count = array_sum(array_map('count', $untaken));
printf("Branches: %.2f%% taken (%d of %d), %d untaken\n", ($total - $count) / $total * 100, $total - $count, $total, $count);

$failures = [];
foreach ($untaken as $file => $lines) {
	$allowed = ALLOWED_UNTAKEN[$file] ?? 0;
	if (count($lines) > $allowed) {
		sort($lines);
		$failures[] = sprintf('  %s has %d untaken branch(es) near line(s) %s, at most %d allowed', $file, count($lines), implode(', ', array_unique($lines)), $allowed);
	}
}
if ($count > MAX_UNTAKEN) {
	$failures[] = sprintf('  %d untaken branches in total, at most %d allowed', $count, MAX_UNTAKEN);
}
foreach (ALLOWED_UNTAKEN as $file => $allowed) {
	$has = count($untaken[$file] ?? []);
	if ($has < $allowed) {
		printf("  note: %s carries %d of its %d allowed unreachable edge(s) on this PHP version\n", $file, $has, $allowed);
	}
}

if ($failures !== []) {
	fwrite(STDERR, "Branch gate FAILED:\n" . implode("\n", $failures) . "\n");
	exit(1);
}

echo "Branch gate passed: every branch is taken except the documented unreachable edges.\n";
