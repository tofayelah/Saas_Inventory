<?php

namespace App\Models;

use App\Services\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

class Activity extends SpatieActivity
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'team_id',
        'log_name',
        'description',
        'subject_type',
        'event',
        'subject_id',
        'causer_type',
        'causer_id',
        'properties',
        'batch_uuid',
    ];

    protected static function booted(): void
    {
        static::creating(function (Activity $activity) {
            // Auto-populate team_id from TenantContext or subject if not explicitly assigned
            if (empty($activity->team_id)) {
                $tenantId = app(TenantContext::class)->getTenantId();
                if ($tenantId) {
                    $activity->team_id = $tenantId;
                } elseif ($activity->subject && isset($activity->subject->team_id)) {
                    $activity->team_id = $activity->subject->team_id;
                }
            }
        });
    }

    /**
     * Relationship to the owning Team/Tenant.
     */
    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Local scope for querying activities belonging to a specific tenant.
     */
    public function scopeForTenant(Builder $query, int|Team $tenant): Builder
    {
        $tenantId = $tenant instanceof Team ? $tenant->id : $tenant;
        return $query->where('team_id', $tenantId);
    }

    /**
     * Local scope to query platform/super-admin security events.
     */
    public function scopePlatformOnly(Builder $query): Builder
    {
        return $query->whereNull('team_id');
    }
}
