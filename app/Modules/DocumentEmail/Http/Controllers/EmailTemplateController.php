<?php

declare(strict_types=1);

namespace App\Modules\DocumentEmail\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\DocumentEmail\Services\EmailTemplateService;
use App\Modules\DocumentEmail\Support\DocumentEmailRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Settings → Email → Templates: the subject / body per document type and language. */
class EmailTemplateController extends Controller
{
    public function __construct(private readonly EmailTemplateService $service) {}

    public function index(): JsonResponse
    {
        return ApiResponse::success([
            'templates' => $this->service->all(),
            'placeholders' => EmailTemplateService::PLACEHOLDERS,
        ], 'Email templates fetched successfully.');
    }

    public function update(Request $request, string $type, string $locale): JsonResponse
    {
        $this->assertKey($type, $locale);
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:10000'],
        ]);
        $this->service->save($type, $locale, (string) $data['subject'], (string) $data['body']);

        return ApiResponse::success($this->service->get($type, $locale), 'Email template saved.');
    }

    public function destroy(string $type, string $locale): JsonResponse
    {
        $this->assertKey($type, $locale);
        $this->service->reset($type, $locale);

        return ApiResponse::success($this->service->get($type, $locale), 'Email template reset to default.');
    }

    private function assertKey(string $type, string $locale): void
    {
        validator(
            ['type' => $type, 'locale' => $locale],
            ['type' => [Rule::in(DocumentEmailRegistry::types())], 'locale' => [Rule::in(EmailTemplateService::LOCALES)]],
        )->validate();
    }
}
