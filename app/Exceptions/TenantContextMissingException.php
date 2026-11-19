<?php

namespace App\Exceptions;

use RuntimeException;

class TenantContextMissingException extends RuntimeException
{
    // Thrown when a tenant-scoped query or operation is attempted without an active tenant context
}
