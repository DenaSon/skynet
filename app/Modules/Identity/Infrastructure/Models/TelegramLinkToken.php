<?php

namespace App\Modules\Identity\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $user_id
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property-read User $user
 */
final class TelegramLinkToken extends Model
{
    protected $fillable = ['user_id', 'token_hash', 'expires_at'];

    protected $hidden = ['token_hash'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }
}
