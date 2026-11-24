<?php

namespace App\Modules\Crm\Models;

use App\Contracts\TenantAware;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class CrmCampaignRecipient extends Model implements TenantAware
{
    use HasFactory, BelongsToTenant;

    protected $table = 'crm_campaign_recipients';

    protected $guarded = ['id'];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(CrmCampaign::class, 'campaign_id');
    }

    public function recipient(): MorphTo
    {
        return $this->morphTo();
    }
}
