<?php

declare(strict_types=1);

namespace App\Modules\CompanyProfile\Models;

use App\Enums\AttachmentViewerCategory;
use App\Models\Attachment;
use App\Modules\InvoiceProof\Enums\WalletType;
use App\Modules\InvoiceProof\Support\BlockchainNetwork;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

#[Fillable([
    'company_name',
    'legal_name',
    'phone',
    'email',
    'website',
    'tax_number',
    'registration_number',
    'address',
    'wallet_address',
    'wallet_address_anvil',
    'wallet_address_sepolia',
    'wallet_type_anvil',
    'wallet_type_sepolia',
])]
class CompanyProfile extends Model implements AuditableContract
{
    use Auditable;
    use HasUuids;

    protected $table = 'company_profiles';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'wallet_type_anvil' => WalletType::class,
            'wallet_type_sepolia' => WalletType::class,
        ];
    }

    /**
     * Declared type of the active-network company wallet.
     *
     * @return Attribute<WalletType|null, never>
     */
    protected function walletType(): Attribute
    {
        return Attribute::get(
            fn (): ?WalletType => BlockchainNetwork::isSepolia() ? $this->wallet_type_sepolia : $this->wallet_type_anvil
        );
    }

    /**
     * Active-network company Safe. Writes land on the Anvil or Sepolia column.
     *
     * @return Attribute<string|null, string|null>
     */
    protected function walletAddress(): Attribute
    {
        return Attribute::make(
            get: function (): ?string {
                $column = BlockchainNetwork::walletColumn();
                if (array_key_exists($column, $this->attributes)) {
                    $value = $this->attributes[$column];

                    return is_string($value) ? $value : null;
                }

                $legacy = $this->attributes['wallet_address'] ?? null;

                return is_string($legacy) ? $legacy : null;
            },
            set: function (?string $value): array {
                return [BlockchainNetwork::walletColumn() => $value];
            },
        );
    }

    /**
     * Single row per tenant database.
     */
    public static function singleton(): self
    {
        /** @var self */
        return static::query()->firstOrCreate([], [
            'company_name' => (string) (tenant('name') ?: 'Company'),
        ]);
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /**
     * Current company logo (single-image slot).
     *
     * @return MorphOne<Attachment, $this>
     */
    public function logoAttachment(): MorphOne
    {
        return $this->morphOne(Attachment::class, 'attachable')
            ->where('is_primary', true)
            ->where('viewer_category', AttachmentViewerCategory::Image);
    }
}
