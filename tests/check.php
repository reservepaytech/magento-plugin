<?php
// Shared by the test scripts: check() prints one line per assertion, done() prints the total and sets the exit code.

$failures = 0;

function check(string $label, mixed $actual, mixed $expected): void
{
    global $failures;
    $ok = $actual === $expected;
    $failures += $ok ? 0 : 1;
    printf("%s  %-62s %s\n", $ok ? 'ok  ' : 'FAIL', $label, json_encode($actual, JSON_UNESCAPED_UNICODE) . ($ok ? '' : ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)));
}

function done(): never
{
    global $failures;
    echo $failures ? "$failures FAILED\n" : "all passed\n";
    exit($failures ? 1 : 0);
}
