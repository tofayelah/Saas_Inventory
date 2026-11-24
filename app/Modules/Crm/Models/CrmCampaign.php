<?php

namespace App\Modules\Crm\Models;

use App\Contracts\TenantAware;
use App\Models\User;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CrmCampaign extends Model implements TenantAware
{
    use HasFactory, BelongsToTenant;

    protected $table = 'crm_campaigns';

    protected $guarded = ['id'];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'approved_at' => 'datetime',
        'total_recipients' => 'integer',
        'delivered_count' => 'integer',
        'failed_count' => 'integer',
    ];

    protected static function boot()
    {
        parent::boot();

        // Invalidate approval upon material modification
        static::updating(function (CrmCampaign $campaign) {
            $materialFields = ['message_body', 'template_id', 'segment_id', 'channel_id', 'scheduled_at'];
            if ($campaign->status === 'approved' && $campaign->isDirty($materialFields)) {
                $campaign->status = 'draft';
                $campaign->approved_by = null;
                $campaign->approved_at = null;
            }
        });
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(CrmChannel::class, 'channel_id');
    }

    public function segment(): BelongsTo
    {
        return $this->belongsTo(CrmSegment::class, 'segment_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(CrmCampaignRecipient::class, 'campaign_id');
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved' && $this->approved_by !== null && $this->approved_at !== null;
    }
}
