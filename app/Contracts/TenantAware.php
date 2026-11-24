<?php

namespace App\Contracts;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

interface TenantAware
{
    /**
     * Get the tenant ID attribute for this model.
     */
    public function getTenantId(): ?int;

    /**
     * Relationship: The tenant team this model belongs to.
     */
    public function team(): BelongsTo;
}
