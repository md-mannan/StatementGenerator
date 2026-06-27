<?php

namespace App\Services;

use Carbon\Carbon;

class AnnexureSelectionResolver
{
    /**
     * @param  list<array{
     *     id: int,
     *     amount: float,
     *     cheque_number: string,
     *     cheque_year: int,
     *     cheque_month: int,
     *     payment_saved: bool,
     * }>  $candidates
     * @return array{
     *     amount: float|null,
     *     cheque_number: string|null,
     *     cheque_period: string|null,
     *     cheque_issued: bool,
     * }
     */
    public static function resolve(array $candidates): array
    {
        if ($candidates === []) {
            return [
                'amount' => null,
                'cheque_number' => null,
                'cheque_period' => null,
                'cheque_issued' => false,
            ];
        }

        usort(
            $candidates,
            function (array $left, array $right): int {
                $leftIssued = trim($left['cheque_number']) !== '' || $left['payment_saved'];
                $rightIssued = trim($right['cheque_number']) !== '' || $right['payment_saved'];

                if ($leftIssued !== $rightIssued) {
                    return $rightIssued <=> $leftIssued;
                }

                if ($left['cheque_year'] !== $right['cheque_year']) {
                    return $right['cheque_year'] <=> $left['cheque_year'];
                }

                if ($left['cheque_month'] !== $right['cheque_month']) {
                    return $right['cheque_month'] <=> $left['cheque_month'];
                }

                return $right['id'] <=> $left['id'];
            },
        );

        $selected = $candidates[0];
        $chequeNumber = trim($selected['cheque_number']);
        $chequeIssued = $chequeNumber !== '' || $selected['payment_saved'];

        return [
            'amount' => $selected['amount'],
            'cheque_number' => $chequeNumber !== '' ? $chequeNumber : null,
            'cheque_period' => $selected['cheque_year'] > 0 && $selected['cheque_month'] > 0
                ? Carbon::create($selected['cheque_year'], $selected['cheque_month'], 1)->format('M Y')
                : null,
            'cheque_issued' => $chequeIssued,
        ];
    }
}
