<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAttachmentRequest extends FormRequest
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
            'file' => ['required', 'file', 'max:15360'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Please choose a file to upload.',
            'file.file' => 'The upload must be a valid file.',
            'file.max' => 'The file may not be greater than 15 MB.',
            'file.uploaded' => 'The file may not be greater than 15 MB.',
        ];
    }
}
