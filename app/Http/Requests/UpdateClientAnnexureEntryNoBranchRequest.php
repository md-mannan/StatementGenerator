<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateClientAnnexureEntryNoBranchRequest extends FormRequest
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
            'no_branch_expected' => ['required', 'boolean'],
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'month' => ['nullable', 'integer', 'between:1,12'],
            'periods' => ['nullable', 'array'],
            'periods.*' => ['string', 'max:20'],
            'cheque_ids' => ['nullable', 'array'],
            'cheque_ids.*' => ['integer', 'exists:client_annexure_cheques,id'],
        ];
    }
}
