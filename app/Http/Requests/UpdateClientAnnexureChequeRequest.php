<?php

namespace App\Http\Requests;

use App\Support\StatementAmount;
use App\Support\StatementDate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateClientAnnexureChequeRequest extends FormRequest
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
            'check_number' => ['required', 'string', 'max:20'],
            'amount' => ['required', 'numeric', 'min:0'],
            'rebate' => ['required', 'numeric', 'min:0'],
            'cheque_date' => ['required', 'string'],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'between:1,12'],
            'stay_on_cheque' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if (StatementAmount::parse($this->input('amount')) === null) {
                $validator->errors()->add(
                    'amount',
                    __('The check amount must be a valid number.'),
                );
            }

            if (StatementAmount::parse($this->input('rebate')) === null) {
                $validator->errors()->add(
                    'rebate',
                    __('The rebate must be a valid number.'),
                );
            }

            if (StatementDate::parse($this->input('cheque_date')) === null) {
                $validator->errors()->add(
                    'cheque_date',
                    __('The cheque date must be a valid date (dd/mm/yyyy).'),
                );
            }
        });
    }
}
