<?php

namespace App\Services;

use App\Exceptions\TenantContextMissingException;
use App\Models\Team;

class TenantContext
{
    protected ?int $tenantId = null;
    protected ?Team $tenant = null;
    protected bool $isPrivileged = false;
    protected bool $isHttpRequest = false;
    protected int $explicitDepth = 0;

    /**
     * Check if executing within an explicit executeAsTenant block.
     */
    public function isExplicit(): bool
    {
        return $this->explicitDepth > 0;
    }

    /**
     * Set the current active tenant by Team model or integer ID.
     */
    public function setTenant(Team|int|null $tenant): self
    {
        if ($tenant instanceof Team) {
            $this->tenant = $tenant;
            $this->tenantId = (int) $tenant->id;
        } elseif (is_numeric($tenant)) {
            $this->tenantId = (int) $tenant;
            $this->tenant = null; // Lazy loaded if needed
        } else {
            $this->tenantId = null;
            $this->tenant = null;
        }

        // Keep Spatie team permissions synchronized
        if ($this->tenantId && function_exists('setPermissionsTeamId')) {
            setPermissionsTeamId($this->tenantId);
        }

        return $this;
    }

    /**
     * Get the active tenant ID.
     */
    public function getTenantId(): ?int
    {
        return $this->tenantId;
    }

    /**
     * Get the active Team model.
     */
    public function getTenant(): ?Team
    {
        if ($this->tenant === null && $this->tenantId !== null) {
            $this->tenant = Team::withoutGlobalScopes()->find($this->tenantId);
        }

        return $this->tenant;
    }

    /**
     * Check if a tenant context is currently active.
     */
    public function hasTenant(): bool
    {
        return $this->tenantId !== null && $this->tenantId > 0;
    }

    /**
     * Check if privileged (unscoped platform) mode is active.
     */
    public function isPrivileged(): bool
    {
        return $this->isPrivileged;
    }

    /**
     * Set privileged mode directly.
     */
    public function setPrivileged(bool $privileged): self
    {
        $this->isPrivileged = $privileged;
        return $this;
    }

    /**
     * Mark whether the current execution is within an HTTP request lifecycle.
     */
    public function setHttpRequestContext(bool $isHttp): self
    {
        $this->isHttpRequest = $isHttp;
        return $this;
    }

    /**
     * Check if currently processing an HTTP request.
     */
    public function isHttpRequest(): bool
    {
        return $this->isHttpRequest;
    }

    /**
     * Reset the tenant context.
     */
    public function clear(): void
    {
        $this->tenantId = null;
        $this->tenant = null;
        $this->isPrivileged = false;
        $this->isHttpRequest = false;
        $this->explicitDepth = 0;

        if (function_exists('setPermissionsTeamId')) {
            setPermissionsTeamId(null);
        }
    }

    /**
     * Execute a callback within an explicit tenant context, restoring previous context afterward.
     */
    public function executeAsTenant(Team|int $tenant, callable $callback): mixed
    {
        $previousTenantId = $this->tenantId;
        $previousTenant = $this->tenant;
        $previousPrivileged = $this->isPrivileged;

        $this->explicitDepth++;
        try {
            $this->setTenant($tenant);
            $this->isPrivileged = false;
            return $callback();
        } finally {
            $this->explicitDepth--;
            $this->tenantId = $previousTenantId;
            $this->tenant = $previousTenant;
            $this->isPrivileged = $previousPrivileged;

            if (function_exists('setPermissionsTeamId')) {
                setPermissionsTeamId($previousTenantId);
            }
        }
    }

    /**
     * Alias for executeAsTenant.
     */
    public function runWithTenant(Team|int $tenant, callable $callback): mixed
    {
        return $this->executeAsTenant($tenant, $callback);
    }

    /**
     * Execute a callback in an authorized privileged context (for platform super admin maintenance only).
     */
    public function executePrivileged(callable $callback): mixed
    {
        $previousPrivileged = $this->isPrivileged;

        try {
            $this->isPrivileged = true;
            return $callback();
        } finally {
            $this->isPrivileged = $previousPrivileged;
        }
    }
}
