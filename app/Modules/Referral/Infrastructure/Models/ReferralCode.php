<?php

namespace App\Modules\Referral\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $user_id
 * @property string $code
 */
final class ReferralCode extends Model
{
    protected $fillable = ['user_id', 'code'];
}
