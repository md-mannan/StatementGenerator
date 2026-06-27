<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreStatementInvoiceScanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'scan' => [
                'required',
                'file',
                'max:20480',
                'mimes:pdf,jpg,jpeg,png,webp',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'scan.required' => __('Please choose a scanned invoice file.'),
            'scan.mimes' => __('Only PDF, JPG, PNG, or WEBP files are allowed.'),
            'scan.max' => __('The scan may not be larger than 20 MB.'),
        ];
    }
}
