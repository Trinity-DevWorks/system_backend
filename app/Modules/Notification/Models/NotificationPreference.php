<?php

declare(strict_types=1);

namespace App\Modules\Notification\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * User preference for one notification type on one delivery channel.
 *
 * What: Eloquent model for `notification_preferences`.
 * Used for: PreferenceService load/save and Dispatcher channel filtering.
 * Solves: Persists opt-in/opt-out so delivery respects user choices across sessions.
 *
 * @property int $id
 * @property string $user_id
 * @property string $type
 * @property string $channel
 * @property bool $enabled
 */
class NotificationPreference extends Model
{
    protected $fillable = [
        'user_id',
        'type',
        'channel',
        'enabled',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
