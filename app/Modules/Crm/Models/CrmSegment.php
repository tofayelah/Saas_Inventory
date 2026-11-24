<?php

namespace App\Modules\Crm\Models;

use App\Contracts\TenantAware;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CrmSegment extends Model implements TenantAware
{
    use HasFactory, BelongsToTenant;

    protected $table = 'crm_segments';

    protected $guarded = ['id'];

    protected $casts = [
        'filter_criteria' => 'array',
        'member_count' => 'integer',
    ];

    public function campaigns(): HasMany
    {
        return $this->hasMany(CrmCampaign::class, 'segment_id');
    }
}
