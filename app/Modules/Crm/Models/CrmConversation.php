<?php

namespace App\Modules\Crm\Models;

use App\Contracts\TenantAware;
use App\Models\User;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class CrmConversation extends Model implements TenantAware
{
    use HasFactory, BelongsToTenant;

    protected $table = 'crm_conversations';

    protected $guarded = ['id'];

    protected $casts = [
        'last_message_at' => 'datetime',
    ];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(CrmChannel::class, 'channel_id');
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(CrmMessage::class, 'conversation_id');
    }

    public function counterparty(): MorphTo
    {
        return $this->morphTo();
    }
}
