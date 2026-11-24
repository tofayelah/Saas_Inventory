<?php

namespace App\Modules\Crm\Models;

use App\Contracts\TenantAware;
use App\Models\Customer;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmContact extends Model implements TenantAware
{
    use HasFactory, BelongsToTenant;

    protected $table = 'crm_contacts';

    protected $guarded = ['id'];

    protected $casts = [
        'is_primary' => 'boolean',
        'is_billing_contact' => 'boolean',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }
}
