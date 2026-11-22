<?php

declare(strict_types=1);

namespace App\Modules\RecurringEntry\Http\Requests;

class PreviewRecurringTemplateRequest extends SaveRecurringTemplateRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['lines'] = ['required', 'array', 'min:1', 'max:200'];

        return $rules;
    }
}
