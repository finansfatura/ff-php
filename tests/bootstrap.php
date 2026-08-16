<?php

declare(strict_types=1);

// Tiny zero-framework test harness (mirrors the node client's `node --test`).
// Runnable directly: `php tests/PayloadTest.php` — no composer install needed.

require __DIR__ . '/../src/exceptions.php';
require __DIR__ . '/../src/Payload.php';
require __DIR__ . '/../src/Client.php';
require __DIR__ . '/../src/OAuth.php';

$GLOBALS['__ff_fail'] = 0;

function eq($actual, $expected, string $msg): void
{
    if ($actual !== $expected) {
        $GLOBALS['__ff_fail']++;
        fwrite(STDERR, sprintf(
            "  ✗ %s\n    expected %s, got %s\n",
            $msg,
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function ok(bool $cond, string $msg): void
{
    if (!$cond) {
        $GLOBALS['__ff_fail']++;
        fwrite(STDERR, "  ✗ $msg\n");
    }
}

/** @param callable $fn */
function throwsMatching(callable $fn, string $exceptionClass, string $msg): void
{
    try {
        $fn();
    } catch (\Throwable $e) {
        if ($e instanceof $exceptionClass) {
            return;
        }
        $GLOBALS['__ff_fail']++;
        fwrite(STDERR, "  ✗ $msg — got " . get_class($e) . "\n");
        return;
    }
    $GLOBALS['__ff_fail']++;
    fwrite(STDERR, "  ✗ $msg — no exception thrown\n");
}

function done(string $file): void
{
    if ($GLOBALS['__ff_fail'] > 0) {
        fwrite(STDERR, "\n$file: {$GLOBALS['__ff_fail']} assertion(s) FAILED\n");
        exit(1);
    }
    fwrite(STDOUT, "$file: OK\n");
}
