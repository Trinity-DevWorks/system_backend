<?php

declare(strict_types=1);

namespace App\Http\Requests\Central;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The logo is served publicly (login pages, favicon), so only raster images are accepted: SVG can carry scripts.
 */
class UploadPlatformLogoRequest extends FormRequest
{
    public const MAX_KILOBYTES = 2048;

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
            'file' => ['required', 'file', 'image', 'mimes:png,jpg,jpeg,webp', 'max:'.self::MAX_KILOBYTES],
        ];
    }
}
