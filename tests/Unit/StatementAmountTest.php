<?php

use App\Support\StatementAmount;

test('statement amounts are formatted with three decimal places', function () {
    expect(StatementAmount::format(150.5))->toBe('150.500')
        ->and(StatementAmount::format('2300.5'))->toBe('2300.500');
});

test('statement amounts parse to three decimal places', function () {
    expect(StatementAmount::parse('1500.1256'))->toBe(1500.126)
        ->and(StatementAmount::parse('1,500.500'))->toBe(1500.5);
});
