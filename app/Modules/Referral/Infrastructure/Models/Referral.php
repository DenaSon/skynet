<?php

namespace App\Modules\Referral\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $referrer_user_id
 * @property int $invited_user_id
 */
final class Referral extends Model
{
    public $timestamps = false;

    protected $fillable = ['referrer_user_id', 'invited_user_id', 'created_at'];
}
