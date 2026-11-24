<?php

namespace App\Contracts;

interface TenantAwareJob
{
    /**
     * Get the tenant ID that this background job belongs to.
     */
    public function getTenantId(): int;
}
