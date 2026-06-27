<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBranchRequest extends FormRequest
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
        $client = $this->route('client');

        return [
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('branches', 'code')->where('client_id', $client->id),
            ],
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
