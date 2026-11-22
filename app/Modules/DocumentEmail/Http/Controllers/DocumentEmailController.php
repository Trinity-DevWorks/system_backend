<?php

declare(strict_types=1);

namespace App\Modules\DocumentEmail\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Modules\DocumentEmail\Services\DocumentEmailService;
use App\Modules\DocumentEmail\Services\EmailTemplateService;
use App\Modules\DocumentEmail\Support\DocumentEmailRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Send a document by email. One endpoint for every document type; the permission
 * is the document's own (see DocumentEmailRegistry).
 */
class DocumentEmailController extends Controller
{
    public function __construct(private readonly DocumentEmailService $service) {}

    public function defaults(Request $request): JsonResponse
    {
        $data = $request->validate([
            ...$this->documentRules(),
            'locale' => ['nullable', Rule::in(EmailTemplateService::LOCALES)],
            ...$this->statementRules(),
        ]);
        $this->service->authorize($this->user($request), (string) $data['type']);

        return ApiResponse::success($this->service->defaults(
            (string) $data['type'],
            (string) $data['id'],
            (string) ($data['locale'] ?? app()->getLocale()),
            [
                'date_from' => $data['date_from'] ?? null,
                'date_to' => $data['date_to'] ?? null,
                'currency_id' => isset($data['currency_id']) ? (int) $data['currency_id'] : null,
            ],
        ), 'Email defaults fetched successfully.');
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate($this->documentRules());
        $this->service->authorize($this->user($request), (string) $data['type']);

        return ApiResponse::success(
            ['emails' => $this->service->history((string) $data['type'], (string) $data['id'])],
            'Email history fetched successfully.'
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            ...$this->documentRules(),
            'to' => ['required', 'array', 'min:1', 'max:'.DocumentEmailService::MAX_RECIPIENTS],
            'to.*' => ['required', 'email', 'max:255'],
            'cc' => ['nullable', 'array', 'max:'.DocumentEmailService::MAX_RECIPIENTS],
            'cc.*' => ['required', 'email', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:10000'],
            'locale' => ['required', Rule::in(EmailTemplateService::LOCALES)],
            ...$this->statementRules(),
        ]);
        $user = $this->user($request);
        $this->service->authorize($user, (string) $data['type']);

        $email = $this->service->send($user, (string) $data['type'], (string) $data['id'], [
            'to' => array_values(array_map('strval', (array) $data['to'])),
            'cc' => array_values(array_map('strval', (array) ($data['cc'] ?? []))),
            'subject' => (string) $data['subject'],
            'body' => (string) $data['body'],
            'locale' => (string) $data['locale'],
            'date_from' => isset($data['date_from']) ? (string) $data['date_from'] : null,
            'date_to' => isset($data['date_to']) ? (string) $data['date_to'] : null,
            'currency_id' => isset($data['currency_id']) ? (int) $data['currency_id'] : null,
        ]);

        return ApiResponse::success($email->toResponse(), 'Email queued for sending.', 202);
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function documentRules(): array
    {
        return [
            'type' => ['required', Rule::in(DocumentEmailRegistry::types())],
            'id' => ['required', 'uuid'],
        ];
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function statementRules(): array
    {
        return [
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'currency_id' => ['nullable', 'integer', 'exists:currencies,id'],
        ];
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        return $user;
    }
}
