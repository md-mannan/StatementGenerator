<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBranchRequest extends FormRequest
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
        $branch = $this->route('branch');

        return [
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('branches', 'code')
                    ->where('client_id', $branch->client_id)
                    ->ignore($branch->id),
            ],
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
