<?php

namespace App\Modules\Crm\Models;

use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmWebhookEvent extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = 'crm_webhook_events';

    protected $guarded = ['id'];

    protected $casts = [
        'headers' => 'array',
        'processed_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isDuplicate(): bool
    {
        return $this->status === 'duplicate';
    }

    public function markAsProcessed(): void
    {
        $this->update([
            'status' => 'processed',
            'processed_at' => now(),
            'error_details' => null,
        ]);
    }

    public function markAsFailed(string $error): void
    {
        $this->update([
            'status' => 'failed',
            'error_details' => $error,
            'processed_at' => now(),
        ]);
    }
}
