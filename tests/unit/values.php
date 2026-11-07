<?php
declare(strict_types=1);
require __DIR__ . '/../../lib/Service/Problem.php';
require __DIR__ . '/../../lib/Service/Values.php';
use OCA\Stammbaum\Service\{Values, Problem};
function ok(bool $b): void
{
    if (!$b) {
        throw new RuntimeException('Assertion failed');
    }
}
function fails(callable $fn): void
{
    try {
        $fn();
    } catch (Problem) {
        return;
    }
    throw new RuntimeException('Expected validation rejection');
}
ok(
    Values::date(['kind' => 'exact', 'value' => '1890']) === [
        'kind' => 'exact',
        'value' => '1890',
        'end' => '',
    ],
);
ok(Values::date(['kind' => 'literal', 'value' => '03/04'])['value'] === '03/04');
Values::date(['kind' => 'exact', 'value' => '2000-02-29']);
fails(fn() => Values::date(['kind' => 'exact', 'value' => '1900-02-29']));
fails(fn() => Values::date(['kind' => 'exact', 'value' => '2020-13']));
fails(fn() => Values::date(['kind' => 'between', 'value' => '1900', 'end' => '1899']));
fails(fn() => Values::date(['kind' => 'between', 'value' => '1900']));
Values::date(['kind' => 'period', 'value' => '', 'end' => '1900']);
fails(fn() => Values::date(['kind' => 'between', 'value' => '1900', 'end' => '1899-12-31']));
fails(fn() => Values::date(['kind' => 'between', 'value' => '1900-02', 'end' => '1900-01-31']));
Values::date(['kind' => 'between', 'value' => '1900-06', 'end' => '1900']);
fails(fn() => Values::person([]));
$p = Values::person(['confirmUnnamed' => true]);
ok($p['livingStatus'] === 'unknown');
ok($p['birthDate']['value'] === '');
fails(fn() => Values::person(['givenName' => 'Ada', 'sex' => 'invented']));
echo "Historical date and person validation passed.\n";
