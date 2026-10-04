<?php

declare(strict_types=1);

namespace Tests\Feature\Central;

use App\Models\Central\CentralPermission;
use App\Models\Central\CentralRole;
use App\Models\Central\PlatformProfile;
use App\Models\User;
use App\Services\Central\PlatformProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\InteractsWithCentral;
use Tests\TestCase;

/**
 * Central platform profile, logo, regional settings, and the public branding endpoints.
 */
#[Group('central')]
class CentralPlatformSettingsApiTest extends TestCase
{
    use InteractsWithCentral;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(PlatformProfileService::LOGO_DISK);
        $this->setUpCentralAdmin();
    }

    public function test_branding_is_public_and_defaults_to_mena(): void
    {
        $this->asCentralRequest()
            ->getJson($this->centralUrl('branding'))
            ->assertOk()
            ->assertJsonPath('data.name', PlatformProfile::DEFAULT_NAME)
            ->assertJsonPath('data.has_logo', false)
            ->assertJsonPath('data.logo_version', null)
            ->assertJsonPath('data.regional.number_format', 'comma_dot');

        $this->asCentralRequest()
            ->getJson($this->centralUrl('branding/logo'))
            ->assertNotFound()
            ->assertJsonPath('code', 'PLATFORM_LOGO_NOT_FOUND');
    }

    public function test_profile_requires_authentication_and_permission(): void
    {
        $this->asCentralRequest()
            ->getJson($this->centralUrl('platform-profile'))
            ->assertStatus(401);

        $role = CentralRole::query()->create(['name' => 'Profile Viewer', 'is_active' => true, 'is_system' => false]);
        $permission = CentralPermission::query()->where('resource_key', 'platform_profile')->firstOrFail();
        $role->permissions()->attach($permission->id, ['can_view' => true]);
        $token = $this->centralToken(User::factory()->create(['central_role_id' => $role->id]));

        $this->asCentralRequest($token)
            ->getJson($this->centralUrl('platform-profile'))
            ->assertOk()
            ->assertJsonPath('data.name', PlatformProfile::DEFAULT_NAME);

        $this->asCentralRequest($token)
            ->putJson($this->centralUrl('platform-profile'), ['name' => 'Nope'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'FORBIDDEN');

        $this->asCentralRequest($token)
            ->getJson($this->centralUrl('platform-settings'))
            ->assertStatus(403);
    }

    public function test_profile_update_changes_public_branding(): void
    {
        $token = $this->centralToken();

        $this->asCentralRequest($token)
            ->putJson($this->centralUrl('platform-profile'), [
                'name' => '  Acme Cloud  ',
                'legal_name' => 'Acme Cloud LLC',
                'phone' => '+1 555 0100',
                'email' => 'hello@acme.test',
                'website' => 'https://acme.test',
                'tax_number' => 'TX-1',
                'registration_number' => 'REG-1',
                'address' => '1 Main St',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Acme Cloud')
            ->assertJsonPath('data.email', 'hello@acme.test')
            ->assertJsonPath('data.logo', null);

        $this->asCentralRequest()
            ->getJson($this->centralUrl('branding'))
            ->assertOk()
            ->assertJsonPath('data.name', 'Acme Cloud');

        $this->asCentralRequest($token)
            ->putJson($this->centralUrl('platform-profile'), ['name' => '', 'email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email']);
    }

    public function test_logo_upload_serve_and_delete(): void
    {
        $token = $this->centralToken();

        $this->asCentralRequest($token)
            ->post($this->centralUrl('platform-profile/logo'), [
                'file' => UploadedFile::fake()->create('logo.svg', 4, 'image/svg+xml'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file']);

        $version = $this->asCentralRequest($token)
            ->post($this->centralUrl('platform-profile/logo'), [
                'file' => UploadedFile::fake()->image('logo.png', 64, 64),
            ])
            ->assertOk()
            ->assertJsonPath('data.logo.mime_type', 'image/png')
            ->json('data.logo.version');

        $this->assertIsInt($version);
        $firstPath = (string) PlatformProfile::query()->value('logo_path');
        Storage::disk(PlatformProfileService::LOGO_DISK)->assertExists($firstPath);

        $this->asCentralRequest()
            ->getJson($this->centralUrl('branding'))
            ->assertJsonPath('data.has_logo', true)
            ->assertJsonPath('data.logo_version', $version);

        $logo = $this->asCentralRequest()->get($this->centralUrl('branding/logo?v='.$version));
        $logo->assertOk();
        $this->assertSame('image/png', $logo->headers->get('Content-Type'));
        $this->assertStringContainsString('immutable', (string) $logo->headers->get('Cache-Control'));

        $this->asCentralRequest($token)
            ->post($this->centralUrl('platform-profile/logo'), [
                'file' => UploadedFile::fake()->image('logo.webp', 32, 32),
            ])
            ->assertOk();
        Storage::disk(PlatformProfileService::LOGO_DISK)->assertMissing($firstPath);

        $secondPath = (string) PlatformProfile::query()->value('logo_path');

        $this->asCentralRequest($token)
            ->deleteJson($this->centralUrl('platform-profile/logo'))
            ->assertOk()
            ->assertJsonPath('data.logo', null);
        Storage::disk(PlatformProfileService::LOGO_DISK)->assertMissing($secondPath);

        $this->asCentralRequest()
            ->getJson($this->centralUrl('branding/logo'))
            ->assertNotFound()
            ->assertJsonPath('code', 'PLATFORM_LOGO_NOT_FOUND');
    }

    public function test_settings_show_and_update(): void
    {
        $token = $this->centralToken();

        $this->asCentralRequest($token)
            ->getJson($this->centralUrl('platform-settings'))
            ->assertOk()
            ->assertJsonPath('data.preferred_language', 'en')
            ->assertJsonPath('data.timezone', 'UTC')
            ->assertJsonPath('data.date_format', 'Y-m-d')
            ->assertJsonPath('data.number_format', 'comma_dot');

        $this->asCentralRequest($token)
            ->putJson($this->centralUrl('platform-settings'), [
                'preferred_language' => 'ar',
                'timezone' => 'Asia/Riyadh',
                'date_format' => 'd/m/Y',
                'number_format' => 'dot_comma',
            ])
            ->assertOk()
            ->assertJsonPath('data.preferred_language', 'ar')
            ->assertJsonPath('data.timezone', 'Asia/Riyadh')
            ->assertJsonPath('data.date_format', 'd/m/Y')
            ->assertJsonPath('data.number_format', 'dot_comma');

        $this->asCentralRequest()
            ->getJson($this->centralUrl('branding'))
            ->assertOk()
            ->assertJsonPath('data.regional.date_format', 'd/m/Y')
            ->assertJsonPath('data.regional.timezone', 'Asia/Riyadh');

        $this->asCentralRequest($token)
            ->putJson($this->centralUrl('platform-settings'), [
                'timezone' => 'Mars/Olympus',
                'date_format' => 'Y/m/d',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['timezone', 'date_format']);
    }
}
