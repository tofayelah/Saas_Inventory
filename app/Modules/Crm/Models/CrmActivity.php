<?php

namespace App\Modules\Crm\Models;

use App\Contracts\TenantAware;
use App\Models\User;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class CrmActivity extends Model implements TenantAware
{
    use HasFactory, BelongsToTenant;

    protected $table = 'crm_activities';

    protected $guarded = ['id'];

    protected $casts = [
        'due_date' => 'datetime',
        'is_completed' => 'boolean',
        'completed_at' => 'datetime',
    ];

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function related(): MorphTo
    {
        return $this->morphTo();
    }
}
