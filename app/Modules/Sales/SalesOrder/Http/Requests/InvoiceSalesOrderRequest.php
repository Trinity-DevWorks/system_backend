<?php

declare(strict_types=1);

namespace App\Modules\Sales\SalesOrder\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InvoiceSalesOrderRequest extends FormRequest
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
            'lines' => ['sometimes', 'array'],
            'lines.*.sales_order_line_id' => ['required', 'integer', 'exists:sales_order_lines,id'],
            'lines.*.quantity' => ['nullable', 'numeric', 'min:0.000001', 'max:999999.999999'],
        ];
    }
}
