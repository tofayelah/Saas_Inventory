<?php

namespace App\Modules\Crm\Models;

use App\Contracts\TenantAware;
use App\Models\Customer;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmConsent extends Model implements TenantAware
{
    use HasFactory, BelongsToTenant;

    protected $table = 'crm_consents';

    protected $guarded = ['id'];

    protected $casts = [
        'opted_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }
}
