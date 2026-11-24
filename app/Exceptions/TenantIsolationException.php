<?php

namespace App\Exceptions;

use RuntimeException;

class TenantIsolationException extends RuntimeException
{
    // Thrown when an illegal cross-tenant mutation or team_id modification is attempted
}
