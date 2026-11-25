<?php

declare(strict_types=1);

namespace Tests\Feature\Central;

use App\Models\Central\PlatformEmailSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\InteractsWithCentral;
use Tests\TestCase;

/**
 * Central Settings → Email: the platform mailbox, its email texts, and account emails
 * (password resets) going through it.
 */
#[Group('central')]
class CentralPlatformEmailApiTest extends TestCase
{
    use InteractsWithCentral;
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCentralAdmin();
        $this->token = $this->centralToken();
    }

    public function test_settings_never_return_the_password_and_changes_clear_verified(): void
    {
        $this->asCentralRequest($this->token)
            ->putJson($this->centralUrl('platform-email'), [
                'enabled' => true,
                'host' => 'smtp.example.com',
                'port' => 587,
                'encryption' => 'tls',
                'username' => 'mailer',
                'password' => 'secret-pass',
                'from_address' => 'no-reply@example.com',
                'from_name' => 'MEASolutions',
            ])
            ->assertOk()
            ->assertJsonPath('data.has_password', true)
            ->assertJsonPath('data.ready', true)
            ->assertJsonMissingPath('data.password');

        $this->assertSame('secret-pass', PlatformEmailSetting::singleton()->password);
        PlatformEmailSetting::singleton()->forceFill(['verified_at' => now()])->save();

        // Blank password keeps the saved one; a new host clears "verified".
        $this->asCentralRequest($this->token)
            ->putJson($this->centralUrl('platform-email'), [
                'enabled' => true,
                'host' => 'smtp.other.com',
                'port' => 587,
                'encryption' => 'tls',
                'password' => '',
                'from_address' => 'no-reply@example.com',
            ])
            ->assertOk()
            ->assertJsonPath('data.verified_at', null);
        $this->assertSame('secret-pass', PlatformEmailSetting::singleton()->password);

        // Turning sending on requires the server and sender.
        $this->asCentralRequest($this->token)
            ->putJson($this->centralUrl('platform-email'), ['enabled' => true, 'port' => 587, 'encryption' => 'tls'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['host', 'from_address']);
    }

    public function test_test_email_goes_through_the_platform_mailbox(): void
    {
        $sent = $this->usePlatformMailbox();

        $this->asCentralRequest($this->token)
            ->postJson($this->centralUrl('platform-email/test'), ['to' => 'me@example.com'])
            ->assertOk()
            ->assertJsonPath('data.ready', true);

        $this->assertCount(1, $sent->messages());
        $this->assertNotNull(PlatformEmailSetting::singleton()->verified_at);
    }

    public function test_templates_can_be_customised_and_reset(): void
    {
        $rows = collect(
            $this->asCentralRequest($this->token)
                ->getJson($this->centralUrl('platform-email/templates'))
                ->assertOk()
                ->assertJsonPath('data.placeholders', ['name', 'platform', 'minutes'])
                ->json('data.templates')
        );
        $this->assertSame(['password_reset|en', 'password_reset|ar'], $rows->map(fn ($r) => $r['type'].'|'.$r['locale'])->all());
        $this->assertFalse($rows->first()['custom']);

        $this->asCentralRequest($this->token)
            ->putJson($this->centralUrl('platform-email/templates/password_reset/en'), [
                'subject' => 'New password for {platform}',
                'body' => 'Hi {name}, click below. Valid {minutes} minutes.',
            ])
            ->assertOk()
            ->assertJsonPath('data.custom', true);

        $this->asCentralRequest($this->token)
            ->putJson($this->centralUrl('platform-email/templates/unknown/en'), ['subject' => 'x', 'body' => 'y'])
            ->assertStatus(422);

        $this->asCentralRequest($this->token)
            ->deleteJson($this->centralUrl('platform-email/templates/password_reset/en'))
            ->assertOk()
            ->assertJsonPath('data.custom', false);
    }

    public function test_forgot_password_uses_the_platform_mailbox_and_the_custom_text(): void
    {
        $sent = $this->usePlatformMailbox();

        $this->asCentralRequest($this->token)
            ->putJson($this->centralUrl('platform-email/templates/password_reset/en'), [
                'subject' => 'Reset for {name}',
                'body' => 'Hello {name}, your link is valid for {minutes} minutes.',
            ])
            ->assertOk();

        $this->asCentralRequest()
            ->postJson($this->centralUrl('forgot-password'), ['email' => $this->centralAdmin->email])
            ->assertOk();

        $this->assertCount(1, $sent->messages());
        $message = $sent->messages()->first()->getOriginalMessage();
        $this->assertSame('Reset for Platform Admin', $message->getSubject());
        $this->assertStringContainsString('valid for 60 minutes', (string) $message->getTextBody());
        $this->assertStringContainsString('/reset-password?token=', (string) $message->getTextBody());
    }

    public function test_forgot_password_sends_nothing_without_a_platform_mailbox(): void
    {
        $sent = $this->usePlatformMailbox();
        PlatformEmailSetting::singleton()->forceFill(['enabled' => false])->save();

        // Same answer either way (does not reveal whether the account exists or mail works).
        $this->asCentralRequest()
            ->postJson($this->centralUrl('forgot-password'), ['email' => $this->centralAdmin->email])
            ->assertOk();

        $this->assertCount(0, $sent->messages());
    }

    public function test_settings_require_the_platform_email_permission(): void
    {
        $this->asCentralRequest()
            ->getJson($this->centralUrl('platform-email'))
            ->assertStatus(401);
    }
}
