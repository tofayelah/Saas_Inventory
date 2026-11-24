<?php

namespace App\Modules\Crm\Models;

use App\Contracts\TenantAware;
use App\Models\Customer;
use App\Models\ServiceTicket;
use App\Models\User;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class CrmSupportCase extends Model implements TenantAware
{
    use HasFactory, BelongsToTenant;

    protected $table = 'crm_support_cases';

    protected $guarded = ['id'];

    protected $casts = [
        'sla_due_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function escalatedServiceTicket(): BelongsTo
    {
        return $this->belongsTo(ServiceTicket::class, 'escalated_service_ticket_id');
    }

    public function activities(): MorphMany
    {
        return $this->morphMany(CrmActivity::class, 'related');
    }
}
