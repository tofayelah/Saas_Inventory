<?php

namespace App\Modules\Crm\Models;

use App\Contracts\TenantAware;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CrmChannel extends Model implements TenantAware
{
    use HasFactory, BelongsToTenant;

    protected $table = 'crm_channels';

    protected $guarded = ['id'];

    protected $casts = [
        'is_active' => 'boolean',
        'rate_limit_per_second' => 'integer',
        'settings' => 'array',
        'credentials' => 'encrypted:array',
    ];

    public function conversations(): HasMany
    {
        return $this->hasMany(CrmConversation::class, 'channel_id');
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(CrmCampaign::class, 'channel_id');
    }
}
