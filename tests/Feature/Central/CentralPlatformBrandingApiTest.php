<?php

declare(strict_types=1);

namespace Tests\Feature\Central;

use App\Models\Central\PlatformBranding;
use App\Services\Central\PlatformBrandingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\InteractsWithCentral;
use Tests\TestCase;

/**
 * Platform branding: public name + logo, edited from the central Platform Settings page.
 */
#[Group('central')]
class CentralPlatformBrandingApiTest extends TestCase
{
    use InteractsWithCentral;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(PlatformBrandingService::LOGO_DISK);
        app(PlatformBrandingService::class)->forgetCache();
        $this->setUpCentralAdmin();
    }

    public function test_branding_is_public_with_a_default_name(): void
    {
        $this->asCentralRequest()
            ->getJson($this->centralUrl('branding'))
            ->assertOk()
            ->assertJsonPath('data.name', PlatformBranding::DEFAULT_NAME)
            ->assertJsonPath('data.has_logo', false)
            ->assertJsonPath('data.logo_version', null);

        $this->asCentralRequest()
            ->getJson($this->centralUrl('branding/logo'))
            ->assertNotFound()
            ->assertJsonPath('code', 'PLATFORM_LOGO_NOT_FOUND');
    }

    public function test_settings_require_authentication(): void
    {
        $this->asCentralRequest()
            ->putJson($this->centralUrl('platform-settings'), ['name' => 'Acme'])
            ->assertStatus(401);
    }

    public function test_admin_renames_the_platform(): void
    {
        $token = $this->centralToken();

        $this->asCentralRequest($token)
            ->putJson($this->centralUrl('platform-settings'), ['name' => '  Acme ERP  '])
            ->assertOk()
            ->assertJsonPath('data.name', 'Acme ERP');

        $this->asCentralRequest()
            ->getJson($this->centralUrl('branding'))
            ->assertOk()
            ->assertJsonPath('data.name', 'Acme ERP');

        $this->asCentralRequest($token)
            ->putJson($this->centralUrl('platform-settings'), ['name' => ''])
            ->assertStatus(422);
    }

    public function test_logo_upload_serve_and_remove(): void
    {
        $token = $this->centralToken();

        $version = $this->asCentralRequest($token)
            ->post($this->centralUrl('platform-settings/logo'), [
                'file' => UploadedFile::fake()->image('logo.png', 64, 64),
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.has_logo', true)
            ->json('data.logo_version');

        $this->assertIsInt($version);
        $path = (string) PlatformBranding::singleton()->logo_path;
        Storage::disk(PlatformBrandingService::LOGO_DISK)->assertExists($path);

        $this->asCentralRequest()
            ->get($this->centralUrl('branding/logo?v='.$version))
            ->assertOk()
            ->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');

        $this->asCentralRequest($token)
            ->deleteJson($this->centralUrl('platform-settings/logo'))
            ->assertOk()
            ->assertJsonPath('data.has_logo', false);

        Storage::disk(PlatformBrandingService::LOGO_DISK)->assertMissing($path);
    }

    public function test_svg_logo_is_rejected(): void
    {
        $this->asCentralRequest($this->centralToken())
            ->post($this->centralUrl('platform-settings/logo'), [
                'file' => UploadedFile::fake()->create('logo.svg', 4, 'image/svg+xml'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422);
    }
}
