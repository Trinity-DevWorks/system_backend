<?php

declare(strict_types=1);

namespace App\Modules\Deferral\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RecognizeRemainingRequest extends FormRequest
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
            'date' => ['required', 'date'],
        ];
    }
}
