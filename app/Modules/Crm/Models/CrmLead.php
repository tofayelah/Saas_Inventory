<?php

namespace App\Modules\Crm\Models;

use App\Contracts\TenantAware;
use App\Models\Customer;
use App\Models\User;
use App\Models\Warehouse;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class CrmLead extends Model implements TenantAware
{
    use HasFactory, BelongsToTenant;

    protected $table = 'crm_leads';

    protected $guarded = ['id'];

    protected $casts = [
        'score' => 'integer',
        'custom_fields' => 'array',
        'converted_at' => 'datetime',
    ];

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function convertedCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'converted_customer_id');
    }

    public function convertedDeal(): BelongsTo
    {
        return $this->belongsTo(CrmDeal::class, 'converted_deal_id');
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
