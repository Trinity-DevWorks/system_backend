<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Jobs\SyncCentralRbac;
use App\Models\Central\CentralRole;
use App\Models\Central\PlatformEmailSetting;
use App\Models\User;
use App\Support\Mail\MailboxMailerFactory;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;

/**
 * Helpers for central (platform) API tests: Super Admin user, bearer token, central URLs.
 */
trait InteractsWithCentral
{
    protected User $centralAdmin;

    /**
     * Turns the platform mailbox on and routes it to Laravel's in-memory `array` mailer
     * (Mail::fake() does not cover Mail::build()). Returns the transport holding the sent emails.
     * Tenant-DB tests must call tearDownPlatformMailbox(): their central rows are not rolled back.
     */
    protected function usePlatformMailbox(): ArrayTransport
    {
        PlatformEmailSetting::singleton()->forceFill([
            'enabled' => true,
            'host' => 'smtp.test.local',
            'port' => 587,
            'encryption' => 'tls',
            'from_address' => 'no-reply@platform.test',
            'from_name' => 'Platform',
        ])->save();

        $this->app->instance(MailboxMailerFactory::class, new class extends MailboxMailerFactory
        {
            public function make(string $host, int $port, string $encryption, ?string $username, ?string $password): Mailer
            {
                return Mail::mailer('array');
            }
        });

        $transport = Mail::mailer('array')->getSymfonyTransport();
        assert($transport instanceof ArrayTransport);
        $transport->flush();

        return $transport;
    }

    protected function tearDownPlatformMailbox(): void
    {
        PlatformEmailSetting::query()->delete();
    }

    protected function setUpCentralAdmin(): void
    {
        SyncCentralRbac::dispatchSync();

        $this->centralAdmin = User::factory()->create([
            'name' => 'Platform Admin',
            'is_active' => true,
            'central_role_id' => $this->superAdminRole()->id,
        ]);
    }

    protected function tearDownCentralUsers(string ...$userIds): void
    {
        User::withTrashed()->whereIn('id', $userIds)->get()->each(function (User $user): void {
            $user->tokens()->delete();
            $user->forceDelete();
        });
    }

    protected function superAdminRole(): CentralRole
    {
        return CentralRole::query()
            ->where('name', (string) config('central_rbac.super_admin_role'))
            ->firstOrFail();
    }

    protected function centralToken(?User $user = null): string
    {
        return ($user ?? $this->centralAdmin)->createToken('central-tests')->plainTextToken;
    }

    /**
     * @return $this
     */
    protected function asCentralRequest(?string $bearerToken = null): static
    {
        // The app instance is shared across calls: drop tenant context left by a prior
        // tenant request and re-resolve Sanctum from the Bearer header.
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        Auth::forgetGuards();

        $headers = ['Accept' => 'application/json'];
        if ($bearerToken !== null) {
            $headers['Authorization'] = 'Bearer '.$bearerToken;
        }

        return $this->withHeaders($headers);
    }

    protected function centralUrl(string $path): string
    {
        $domain = (string) (config('tenancy.central_domains')[0] ?? 'app.localhost');

        return 'http://'.$domain.'/api/'.ltrim($path, '/');
    }
}
