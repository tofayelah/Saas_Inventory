<?php

namespace App\Modules\Crm\Models;

use App\Contracts\TenantAware;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmAutomationLog extends Model implements TenantAware
{
    use HasFactory, BelongsToTenant;

    public $timestamps = false;

    protected $table = 'crm_automation_logs';

    protected $guarded = ['id'];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(CrmAutomationRule::class, 'rule_id');
    }
}
