<?php

declare(strict_types=1);

namespace App\Modules\Inventory\ScaleBarcode\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TestScaleBarcodeRequest extends FormRequest
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
            ...ScaleBarcodeFormatRequest::formatRules(),
            'name' => ['nullable', 'string', 'max:120'],
            'barcode' => ['required', 'string', 'max:200'],
        ];
    }
}
