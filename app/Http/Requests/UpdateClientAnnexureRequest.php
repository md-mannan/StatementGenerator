<?php

namespace App\Http\Requests;

use App\Support\StatementAmount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateClientAnnexureRequest extends FormRequest
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
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'between:1,12'],
            'rebate' => ['required', 'numeric', 'min:0'],
            'payment_checks' => ['required', 'array', 'min:1'],
            'payment_checks.*.check_number' => ['nullable', 'string', 'max:20'],
            'payment_checks.*.amount' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if (StatementAmount::parse($this->input('rebate')) === null) {
                $validator->errors()->add(
                    'rebate',
                    __('The rebate must be a valid number.'),
                );
            }

            foreach ($this->input('payment_checks', []) as $index => $check) {
                if (
                    isset($check['amount'])
                    && $check['amount'] !== ''
                    && StatementAmount::parse($check['amount']) === null
                ) {
                    $validator->errors()->add(
                        "payment_checks.{$index}.amount",
                        __('The check amount must be a valid number.'),
                    );
                }
            }
        });
    }
}
