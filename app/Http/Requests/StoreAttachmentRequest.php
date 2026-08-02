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
        $maxKb = max(1, (int) config('attachments.max_file_kilobytes', 15360));

        return [
            'file' => ['required', 'file', 'max:'.$maxKb],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $maxMb = max(1, (int) ceil(((int) config('attachments.max_file_kilobytes', 15360)) / 1024));

        return [
            'file.required' => 'Please choose a file to upload.',
            'file.file' => 'The upload must be a valid file.',
            'file.max' => "The file may not be greater than {$maxMb} MB.",
            'file.uploaded' => "The file may not be greater than {$maxMb} MB.",
        ];
    }
}
