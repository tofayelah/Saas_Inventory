<?php

namespace App\Modules\Crm\Models;

use App\Contracts\TenantAware;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CrmAutomationRule extends Model implements TenantAware
{
    use HasFactory, BelongsToTenant;

    protected $table = 'crm_automation_rules';

    protected $guarded = ['id'];

    protected $casts = [
        'conditions' => 'array',
        'actions' => 'array',
        'is_active' => 'boolean',
    ];

    public function logs(): HasMany
    {
        return $this->hasMany(CrmAutomationLog::class, 'rule_id');
    }
}
