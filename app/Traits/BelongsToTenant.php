<?php

namespace App\Traits;

use App\Contracts\TenantAware;
use App\Exceptions\TenantContextMissingException;
use App\Exceptions\TenantIsolationException;
use App\Models\Scopes\TenantScope;
use App\Models\Team;
use App\Services\TenantContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

trait BelongsToTenant
{
    /**
     * Boot the BelongsToTenant trait for a model.
     */
    protected static function bootBelongsToTenant(): void
    {
        // 1. Automatically apply the fail-closed tenant global scope
        static::addGlobalScope(new TenantScope());

        // 2. Automatically enforce and assign team_id on model creation
        static::creating(function ($model) {
            /** @var TenantContext $tenantContext */
            $tenantContext = app(TenantContext::class);
            $activeTenantId = $tenantContext->getTenantId()
                ?: (Auth::check() ? (int) Auth::user()->current_team_id : null);

            if ($activeTenantId) {
                // In HTTP requests, strictly enforce active tenant context regardless of any client input
                if ($tenantContext->isHttpRequest() || empty($model->team_id)) {
                    $model->team_id = $activeTenantId;
                }
            } elseif ($tenantContext->isPrivileged()) {
                // In privileged mode, allow explicit team_id assignment if provided
                if (empty($model->team_id)) {
                    throw new TenantContextMissingException(
                        "Cannot create record for [" . get_class($model) . "] in privileged mode without explicitly supplying team_id."
                    );
                }
            } else {
                // If neither active tenant nor privileged mode is set, reject creation if team_id not explicitly set
                if (empty($model->team_id)) {
                    throw new TenantContextMissingException(
                        "Cannot create record for [" . get_class($model) . "] without an active tenant context."
                    );
                }
            }
        });

        // 3. Prohibit changing team_id on existing records
        static::updating(function ($model) {
            /** @var TenantContext $tenantContext */
            $tenantContext = app(TenantContext::class);

            if ($model->isDirty('team_id') && !$tenantContext->isPrivileged()) {
                throw new TenantIsolationException(
                    "Mutating the tenant identifier (team_id) on existing record [" . get_class($model) . " #{$model->getKey()}] is strictly prohibited."
                );
            }
        });
    }

    /**
     * Get the tenant ID for this model instance.
     */
    public function getTenantId(): ?int
    {
        return $this->team_id ? (int) $this->team_id : null;
    }

    /**
     * Relationship: The tenant/team this model belongs to.
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id');
    }

    /**
     * Alias relationship: The tenant this model belongs to.
     */
    public function tenant(): BelongsTo
    {
        return $this->team();
    }
}
