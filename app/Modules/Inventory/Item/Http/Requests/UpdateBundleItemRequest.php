<?php

namespace App\Modules\Inventory\Item\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBundleItemRequest extends FormRequest
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
            'quantity' => ['required', 'numeric', 'min:0.000001', 'max:999999.999999'],
        ];
    }
}
