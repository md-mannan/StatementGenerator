<?php

use App\Support\StatementDate;

test('statement dates are formatted as dd/mm/yyyy', function () {
    expect(StatementDate::format('2026-06-14'))->toBe('14/06/2026');
});

test('statement dates parse dd/mm/yyyy strings', function () {
    $parsed = StatementDate::parse('05/06/2026');

    expect($parsed)->not->toBeNull()
        ->and($parsed->toDateString())->toBe('2026-06-05');
});

test('statement dates parse single digit day and month', function () {
    $parsed = StatementDate::parse('5/6/2026');

    expect($parsed)->not->toBeNull()
        ->and($parsed->toDateString())->toBe('2026-06-05');
});

test('statement dates reject invoice numbers and small numeric values', function () {
    expect(StatementDate::parse('27990'))->toBeNull()
        ->and(StatementDate::parse('20'))->toBeNull()
        ->and(StatementDate::parse('242.5'))->toBeNull();
});

test('statement dates parse datetime objects and iso strings from excel', function () {
    $parsed = StatementDate::parse(new DateTimeImmutable('2026-05-10'));

    expect($parsed)->not->toBeNull()
        ->and($parsed->toDateString())->toBe('2026-05-10');

    expect(StatementDate::parse('2026-05-10 00:00:00')?->toDateString())->toBe('2026-05-10');
});
