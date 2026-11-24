<?php

namespace App\Modules\Crm\Models;

use App\Contracts\TenantAware;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CrmPipelineStage extends Model implements TenantAware
{
    use HasFactory, BelongsToTenant;

    protected $table = 'crm_pipeline_stages';

    protected $guarded = ['id'];

    protected $casts = [
        'probability_percentage' => 'decimal:2',
        'sort_order' => 'integer',
        'is_won_stage' => 'boolean',
        'is_lost_stage' => 'boolean',
    ];

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(CrmPipeline::class, 'pipeline_id');
    }

    public function deals(): HasMany
    {
        return $this->hasMany(CrmDeal::class, 'stage_id');
    }
}
