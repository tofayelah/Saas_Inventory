<?php

namespace App\Modules\Crm\Models;

use App\Contracts\TenantAware;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\User;
use App\Models\Warehouse;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class CrmDeal extends Model implements TenantAware
{
    use HasFactory, BelongsToTenant;

    protected $table = 'crm_deals';

    protected $guarded = ['id'];

    protected $casts = [
        'monetary_value' => 'decimal:2',
        'expected_close_date' => 'date',
        'actual_close_date' => 'date',
    ];

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(CrmPipeline::class, 'pipeline_id');
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(CrmPipelineStage::class, 'stage_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(CrmLead::class, 'lead_id');
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class, 'quotation_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(CrmDealItem::class, 'deal_id');
    }

    public function activities(): MorphMany
    {
        return $this->morphMany(CrmActivity::class, 'related');
    }

    public function scopeForBranch(Builder $query, int $warehouseId): Builder
    {
        return $query->where('warehouse_id', $warehouseId);
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('assigned_user_id', $userId);
    }
}
