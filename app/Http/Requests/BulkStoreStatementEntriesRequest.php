<?php

namespace App\Http\Requests;

use App\Support\StatementAmount;
use App\Support\StatementDate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class BulkStoreStatementEntriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'entries' => ['required', 'array', 'min:1'],
            'entries.*.transaction_date' => ['required', 'string', 'max:20'],
            'entries.*.invoice_no' => ['required', 'string', 'max:255'],
            'entries.*.amount' => ['required'],
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'month' => ['nullable', 'integer', 'between:1,12'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            foreach ($this->input('entries', []) as $index => $entry) {
                if (StatementDate::parse($entry['transaction_date'] ?? null) === null) {
                    $validator->errors()->add(
                        "entries.{$index}.transaction_date",
                        __('The date must be in dd/mm/yyyy format.'),
                    );
                }

                if (StatementAmount::parse($entry['amount'] ?? null) === null) {
                    $validator->errors()->add(
                        "entries.{$index}.amount",
                        __('The amount must be a valid number.'),
                    );
                }
            }
        });
    }

    /**
     * @return array{year: int, month: int}
     */
    public function resolvedPeriod(): array
    {
        $year = $this->integer('year');
        $month = $this->integer('month');

        if ($year > 0 && $month > 0) {
            return ['year' => $year, 'month' => $month];
        }

        $firstDate = StatementDate::parse($this->input('entries.0.transaction_date'));

        return [
            'year' => $firstDate?->year ?? now()->year,
            'month' => $firstDate?->month ?? now()->month,
        ];
    }
}
