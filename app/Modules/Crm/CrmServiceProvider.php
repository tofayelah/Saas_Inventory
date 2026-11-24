<?php

namespace App\Modules\Crm;

use App\Modules\Crm\Models\CrmCampaign;
use App\Modules\Crm\Models\CrmDeal;
use App\Modules\Crm\Models\CrmLead;
use App\Modules\Crm\Models\CrmSupportCase;
use App\Modules\Crm\Policies\CrmCampaignPolicy;
use App\Modules\Crm\Policies\CrmDealPolicy;
use App\Modules\Crm\Policies\CrmLeadPolicy;
use App\Modules\Crm\Policies\CrmSupportCasePolicy;
use App\Modules\Crm\Services\CampaignService;
use App\Modules\Crm\Services\CrmBulkActionService;
use App\Modules\Crm\Services\LeadService;
use App\Modules\Crm\Services\TenantBoundaryValidator;
use App\Modules\Crm\Services\TenantMailService;
use App\Modules\Crm\Services\WebhookDeduplicationService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class CrmServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantBoundaryValidator::class);
        $this->app->singleton(LeadService::class);
        $this->app->singleton(CampaignService::class);
        $this->app->singleton(WebhookDeduplicationService::class);
        $this->app->singleton(TenantMailService::class);
        $this->app->singleton(CrmBulkActionService::class);
    }

    public function boot(): void
    {
        Gate::policy(CrmLead::class, CrmLeadPolicy::class);
        Gate::policy(CrmDeal::class, CrmDealPolicy::class);
        Gate::policy(CrmCampaign::class, CrmCampaignPolicy::class);
        Gate::policy(CrmSupportCase::class, CrmSupportCasePolicy::class);
    }
}
