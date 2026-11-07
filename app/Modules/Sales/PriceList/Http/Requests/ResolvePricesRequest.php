<?php

declare(strict_types=1);

namespace App\Modules\Sales\PriceList\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResolvePricesRequest extends FormRequest
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
            'customer_id' => ['nullable', 'uuid'],
            'currency_id' => ['required', 'integer', Rule::exists('currencies', 'id')],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'date' => ['nullable', 'date'],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.item_id' => ['required', 'uuid'],
            'lines.*.item_uom_id' => ['nullable', 'integer'],
            'lines.*.quantity' => ['nullable', 'numeric'],
        ];
    }
}
