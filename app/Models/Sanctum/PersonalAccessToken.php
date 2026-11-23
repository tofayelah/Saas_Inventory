<?php

namespace App\Models\Sanctum;

use App\Models\Team;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

class PersonalAccessToken extends SanctumPersonalAccessToken
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'token',
        'abilities',
        'expires_at',
        'team_id',
    ];

    /**
     * Get the tenant/team that the personal access token belongs to.
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
