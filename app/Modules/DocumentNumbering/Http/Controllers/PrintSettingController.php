<?php

declare(strict_types=1);

namespace App\Modules\DocumentNumbering\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\PrintSetting;
use App\Support\DocumentType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Print settings live next to document numbering: both shape printed documents.
 * Company-wide: one default, plus optional settings per document type.
 */
class PrintSettingController extends Controller
{
    public function show(): JsonResponse
    {
        return ApiResponse::success($this->overview(), 'Print settings fetched successfully.');
    }

    /** The company default. */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(PrintSetting::PAGE_PAPERS));

        PrintSetting::singleton()->update($data);
        PrintSetting::forgetCache();

        return ApiResponse::success($this->overview(), 'Print settings updated successfully.');
    }

    /** Create or replace one document type's own settings. */
    public function updateType(Request $request, string $type): JsonResponse
    {
        $case = $this->printableOrFail($type);
        $papers = PrintSetting::hasReceipt($case) ? PrintSetting::PAPERS : PrintSetting::PAGE_PAPERS;
        $data = $request->validate($this->rules($papers));

        PrintSetting::query()->updateOrCreate(['document_type' => $case->value], $data);
        PrintSetting::forgetCache();

        return ApiResponse::success($this->overview(), 'Print settings updated successfully.');
    }

    /** Drop a document type's own settings: it goes back to the company default. */
    public function destroyType(string $type): JsonResponse
    {
        $case = $this->printableOrFail($type);

        PrintSetting::query()->where('document_type', $case->value)->first()?->delete();
        PrintSetting::forgetCache();

        return ApiResponse::success($this->overview(), 'Print settings updated successfully.');
    }

    private function printableOrFail(string $type): DocumentType
    {
        $case = PrintSetting::printable($type);
        if ($case === null) {
            abort(404, 'This document type has no print settings.', ['X-Error-Code' => 'PRINT_SETTINGS_TYPE_UNKNOWN']);
        }

        return $case;
    }

    /**
     * @param  list<string>  $papers
     * @return array<string, mixed>
     */
    private function rules(array $papers): array
    {
        return [
            'paper' => ['required', Rule::in($papers)],
            'show_logo' => ['required', 'boolean'],
            'show_tax_number' => ['required', 'boolean'],
            'show_address' => ['required', 'boolean'],
            'show_signatures' => ['required', 'boolean'],
            'footer_text' => ['nullable', 'string', 'max:500'],
            'terms_text' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * The company default (top level, as before), the per-type overrides, and which types can have them.
     *
     * @return array<string, mixed>
     */
    private function overview(): array
    {
        $default = PrintSetting::singleton();
        $overrides = PrintSetting::query()->whereNotNull('document_type')->orderBy('document_type')->get();

        return [
            ...$this->payload($default),
            'overrides' => $overrides->map(fn (PrintSetting $s): array => [
                'document_type' => $s->document_type,
                ...$this->payload($s),
            ])->values()->all(),
            'document_types' => array_map(fn (DocumentType $t): array => [
                'value' => $t->value,
                'receipt' => PrintSetting::hasReceipt($t),
            ], PrintSetting::PRINTABLE_TYPES),
        ];
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
