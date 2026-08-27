<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SyncBundleExplosionLinesRequest extends FormRequest
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
            ...StoreBundleExplosionRequest::lineRules(),
        ];
    }
}
