<?php

declare(strict_types=1);

namespace App\Modules\Sales\SalesOrder\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SyncSalesOrderLinesRequest extends FormRequest
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
            ...StoreSalesOrderRequest::lineRules('lines.*'),
        ];
    }
}
