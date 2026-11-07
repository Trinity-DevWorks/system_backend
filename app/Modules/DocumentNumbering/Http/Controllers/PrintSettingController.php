<?php

declare(strict_types=1);

namespace App\Modules\DocumentNumbering\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\PrintSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Print settings live next to document numbering: both shape printed documents. */
class PrintSettingController extends Controller
{
    public function show(): JsonResponse
    {
        return ApiResponse::success($this->payload(PrintSetting::singleton()), 'Print settings fetched successfully.');
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'paper' => ['required', Rule::in(PrintSetting::PAPERS)],
            'show_logo' => ['required', 'boolean'],
            'show_tax_number' => ['required', 'boolean'],
            'show_address' => ['required', 'boolean'],
            'show_signatures' => ['required', 'boolean'],
            'footer_text' => ['nullable', 'string', 'max:500'],
            'terms_text' => ['nullable', 'string', 'max:5000'],
        ]);

        $settings = PrintSetting::singleton();
        $settings->update($data);
        PrintSetting::forgetCache();

        return ApiResponse::success($this->payload($settings->refresh()), 'Print settings updated successfully.');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(PrintSetting $s): array
    {
        return [
            'paper' => $s->paper,
            'show_logo' => $s->show_logo,
            'show_tax_number' => $s->show_tax_number,
            'show_address' => $s->show_address,
            'show_signatures' => $s->show_signatures,
            'footer_text' => $s->footer_text,
            'terms_text' => $s->terms_text,
        ];
    }
}
