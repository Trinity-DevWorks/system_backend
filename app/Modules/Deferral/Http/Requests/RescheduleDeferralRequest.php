<?php

declare(strict_types=1);

namespace App\Modules\Deferral\Http\Requests;

use App\Modules\Deferral\Enums\DeferralMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RescheduleDeferralRequest extends FormRequest
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
            'end_date' => ['required', 'date'],
            'method' => ['nullable', Rule::in(DeferralMethod::values())],
        ];
    }
}
