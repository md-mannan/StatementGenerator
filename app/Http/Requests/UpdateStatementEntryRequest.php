<?php

namespace App\Http\Requests;

use App\Support\StatementAmount;
use App\Support\StatementDate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateStatementEntryRequest extends FormRequest
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
            'transaction_date' => ['required', 'string', 'max:20'],
            'invoice_no' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric'],
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'month' => ['nullable', 'integer', 'between:1,12'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'branch_ids' => ['nullable', 'array'],
            'branch_ids.*' => ['integer', 'exists:branches,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if (StatementDate::parse($this->input('transaction_date')) === null) {
                $validator->errors()->add(
                    'transaction_date',
                    __('The date must be in dd/mm/yyyy format.'),
                );
            }

            if (StatementAmount::parse($this->input('amount')) === null) {
                $validator->errors()->add(
                    'amount',
                    __('The amount must be a valid number.'),
                );
            }
        });
    }
}
