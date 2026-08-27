<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SyncGoodsReceiptLinesRequest extends FormRequest
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
            'lines' => ['required', 'array'],
            ...StoreGoodsReceiptRequest::lineRules(),
        ];
    }
}
