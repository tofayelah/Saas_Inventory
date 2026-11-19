<?php

namespace App\Http\Middleware;

use App\Services\TenantContext;
use App\Services\TenantContextResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetTenantContext
{
    public function __construct(
        protected TenantContext $tenantContext,
        protected TenantContextResolver $resolver
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->resolver->resolve($request);

        $this->tenantContext->setHttpRequestContext(true);

        if ($tenant) {
            $this->tenantContext->setTenant($tenant);
        } else {
            $this->tenantContext->clear();
            $this->tenantContext->setHttpRequestContext(true);
        }

        try {
            $response = $next($request);
            return $response;
        } finally {
            $this->tenantContext->setHttpRequestContext(false);
        }
    }

    /**
     * Clean up context after response has been sent to client.
     */
    public function terminate(Request $request, Response $response): void
    {
        $this->tenantContext->clear();
    }
}
