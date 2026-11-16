<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Application notification (Android inbox). Unrelated to Illuminate\Notifications\Notification.
 */
class Notification extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'title',
        'message',
        'type',
        'reference_type',
        'reference_id',
        'is_read',
        'read_at',
    ];

    protected $appends = [
        'category',
        'time_ago',
        'date_group',
    ];

    protected function casts(): array
    {
        return [
            'is_read' => 'boolean',
            'read_at' => 'datetime',
            'reference_id' => 'integer',
        ];
    }

    public function getCategoryAttribute(): string
    {
        $type = strtolower((string) ($this->attributes['type'] ?? 'system'));
        if (in_array($type, ['assignment', 'quiz', 'exam', 'grade', 'course', 'academic'], true)) {
            return 'Academic';
        }
        if (in_array($type, ['recommendation', 'prediction', 'risk'], true)) {
            return 'Recommendations';
        }
        return 'System';
    }

    public function getTimeAgoAttribute(): string
    {
        if (! $this->created_at) {
            return 'Just now';
        }
        return $this->created_at->diffForHumans();
    }

    public function getDateGroupAttribute(): string
    {
        if (! $this->created_at) {
            return 'TODAY';
        }
        if ($this->created_at->isToday()) {
            return 'TODAY';
        }
        if ($this->created_at->isYesterday()) {
            return 'YESTERDAY';
        }
        if ($this->created_at->greaterThanOrEqualTo(now()->startOfWeek())) {
            return 'THIS WEEK';
        }
        return 'EARLIER';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
