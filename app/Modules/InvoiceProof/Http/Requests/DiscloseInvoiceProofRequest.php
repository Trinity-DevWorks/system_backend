<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DiscloseInvoiceProofRequest extends FormRequest
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
            'fields' => ['required', 'array', 'min:1', 'max:1000'],
            'fields.*' => ['required', 'string', 'distinct', 'max:255', 'regex:/^[a-z0-9_]+(\.[a-z0-9_]+)*$/'],
        ];
    }
}
