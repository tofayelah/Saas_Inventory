<?php

namespace App\Modules\Crm\Services;

use App\Exceptions\TenantIsolationException;
use App\Models\User;
use App\Modules\Crm\Models\CrmCampaign;
use Illuminate\Support\Facades\DB;

class CampaignService
{
    public function __construct(
        protected TenantBoundaryValidator $boundaryValidator
    ) {}

    /**
     * Approve a campaign under strict dual-custody invariants.
     * Universal Rule: created_by !== approver_id.
     * No single-custody exception is permitted for any user, including Tenant Owners.
     */
    public function approveCampaign(int $teamId, int $campaignId, User $approver): CrmCampaign
    {
        return DB::transaction(function () use ($teamId, $campaignId, $approver) {
            /** @var CrmCampaign $campaign */
            $campaign = CrmCampaign::withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->where('id', $campaignId)
                ->lockForUpdate()
                ->first();

            if (!$campaign) {
                throw new TenantIsolationException("Campaign [ID: {$campaignId}] does not belong to active tenant [ID: {$teamId}].");
            }

            // Verify approver belongs to active tenant
            $this->boundaryValidator->validateAssignedUser($teamId, $approver->id);

            // STRICT UNCONDITIONAL DUAL-CUSTODY CHECK
            if ((int) $campaign->created_by === (int) $approver->id) {
                throw new TenantIsolationException("Dual-custody violation: Campaign creator cannot approve their own campaign.");
            }

            $campaign->update([
                'status' => 'approved',
                'approved_by' => $approver->id,
                'approved_at' => now(),
            ]);

            return $campaign;
        });
    }

    /**
     * Edit a campaign. If the campaign was previously approved, any material mutation
     * invalidates the approval and resets the campaign status to 'draft'.
     */
    public function editCampaign(int $teamId, int $campaignId, array $data): CrmCampaign
    {
        return DB::transaction(function () use ($teamId, $campaignId, $data) {
            /** @var CrmCampaign $campaign */
            $campaign = CrmCampaign::withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->where('id', $campaignId)
                ->lockForUpdate()
                ->first();

            if (!$campaign) {
                throw new TenantIsolationException("Campaign [ID: {$campaignId}] does not belong to active tenant [ID: {$teamId}].");
            }

            $campaign->fill($data);

            $materialFields = ['message_body', 'template_id', 'segment_id', 'channel_id', 'scheduled_at'];
            if ($campaign->getOriginal('status') === 'approved' && $campaign->isDirty($materialFields)) {
                $campaign->status = 'draft';
                $campaign->approved_by = null;
                $campaign->approved_at = null;
            }

            $campaign->save();

            return $campaign;
        });
    }
}
