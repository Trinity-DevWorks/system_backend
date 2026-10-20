<?php

declare(strict_types=1);

namespace App\Modules\Sales\SalesQuotation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SyncSalesQuotationLinesRequest extends FormRequest
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
            'lines' => ['present', 'array'],
            ...StoreSalesQuotationRequest::lineRules('lines.*'),
        ];
    }
}
