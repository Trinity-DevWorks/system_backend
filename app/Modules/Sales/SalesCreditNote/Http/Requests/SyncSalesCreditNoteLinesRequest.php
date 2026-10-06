<?php

declare(strict_types=1);

namespace App\Modules\Sales\SalesCreditNote\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SyncSalesCreditNoteLinesRequest extends FormRequest
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
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sales_invoice_line_id' => ['required', 'integer', 'distinct', 'exists:sales_invoice_lines,id'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
