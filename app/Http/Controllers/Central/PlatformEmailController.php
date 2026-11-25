<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Central\PlatformEmailService;
use App\Services\Central\PlatformEmailTemplateService;
use App\Support\Mail\SmtpMailbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Central Settings → Email: the platform mailbox and the texts of the account emails.
 */
class PlatformEmailController extends Controller
{
    public function __construct(
        private readonly PlatformEmailService $service,
        private readonly PlatformEmailTemplateService $templates,
    ) {}

    public function show(): JsonResponse
    {
        return ApiResponse::success($this->service->payload($this->service->settings()), 'Email settings fetched successfully.');
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'host' => ['nullable', 'string', 'max:255', Rule::requiredIf((bool) $request->boolean('enabled'))],
            // Mail ports only (checked in SmtpMailbox::assertHostAllowed; any port in local development).
            'port' => ['required', 'integer', 'between:1,65535'],
            'encryption' => ['required', Rule::in(SmtpMailbox::ENCRYPTIONS)],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:500'],
            'from_address' => ['nullable', 'email', 'max:255', Rule::requiredIf((bool) $request->boolean('enabled'))],
            'from_name' => ['nullable', 'string', 'max:255'],
            'reply_to' => ['nullable', 'email', 'max:255'],
        ]);

        return ApiResponse::success($this->service->payload($this->service->update($data)), 'Email settings updated successfully.');
    }

    /** Sends a test email to the given address (default: the signed-in admin). Uses the saved settings. */
    public function test(Request $request): JsonResponse
    {
        $data = $request->validate(['to' => ['nullable', 'email', 'max:255']]);
        $user = $request->user();
        $to = (string) ($data['to'] ?? ($user instanceof User ? $user->email : ''));
        if ($to === '') {
            abort(422, 'Enter an address for the test email.', ['X-Error-Code' => 'EMAIL_NO_RECIPIENT']);
        }

        $this->service->sendTest($to);

        return ApiResponse::success($this->service->payload($this->service->settings()), 'Test email sent.');
    }

    public function templates(): JsonResponse
    {
        return ApiResponse::success([
            'templates' => $this->templates->all(),
            'placeholders' => PlatformEmailTemplateService::PLACEHOLDERS,
        ], 'Email templates fetched successfully.');
    }

    public function updateTemplate(Request $request, string $type, string $locale): JsonResponse
    {
        $this->assertKey($type, $locale);
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:10000'],
        ]);
        $this->templates->save($type, $locale, (string) $data['subject'], (string) $data['body']);

        return ApiResponse::success($this->templates->get($type, $locale), 'Email template saved.');
    }

    public function resetTemplate(string $type, string $locale): JsonResponse
    {
        $this->assertKey($type, $locale);
        $this->templates->reset($type, $locale);

        return ApiResponse::success($this->templates->get($type, $locale), 'Email template reset to default.');
    }

    private function assertKey(string $type, string $locale): void
    {
        validator(
            ['type' => $type, 'locale' => $locale],
            [
                'type' => [Rule::in(PlatformEmailTemplateService::TYPES)],
                'locale' => [Rule::in(PlatformEmailTemplateService::LOCALES)],
            ],
        )->validate();
    }
}
