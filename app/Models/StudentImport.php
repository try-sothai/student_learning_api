<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentImport extends Model
{
    public const PENDING = 'pending';
    public const PROCESSING = 'processing';
    public const COMPLETED = 'completed';
    public const COMPLETED_WITH_ERRORS = 'completed_with_errors';
    public const FAILED = 'failed';
    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'user_id', 'original_filename', 'stored_path', 'status', 'total_rows', 'valid_rows',
        'invalid_rows', 'duplicate_rows', 'processed_rows', 'success_rows', 'failed_rows',
        'skipped_rows', 'cancel_requested', 'failure_message', 'started_at', 'finished_at',
    ];

    protected $hidden = ['stored_path'];

    protected function casts(): array
    {
        return [
            'cancel_requested' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function rows(): HasMany
    {
        return $this->hasMany(StudentImportRow::class);
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::COMPLETED, self::COMPLETED_WITH_ERRORS, self::FAILED, self::CANCELLED], true);
    }

    public function toApi(): array
    {
        $duration = $this->started_at ? ($this->finished_at ?? now())->diffInSeconds($this->started_at, true) : null;
        $processed = $this->processed_rows;
        $target = max(1, $this->valid_rows);

        return [
            'id' => $this->id,
            'original_filename' => $this->original_filename,
            'status' => $this->status,
            'queued' => $this->status === self::PROCESSING && ! $this->started_at,
            'imported_by' => $this->user?->name,
            'imported_by_id' => $this->user_id,
            'total_rows' => $this->total_rows,
            'valid_rows' => $this->valid_rows,
            'invalid_rows' => $this->invalid_rows,
            'duplicate_rows' => $this->duplicate_rows,
            'new_students' => $this->valid_rows,
            'processed_rows' => $processed,
            'success_rows' => $this->success_rows,
            'failed_rows' => $this->failed_rows,
            'skipped_rows' => $this->skipped_rows,
            'progress_percent' => $this->isFinished() && $this->status !== self::FAILED
                ? 100 : (int) min(100, round($processed / $target * 100)),
            'duration_seconds' => $duration === null ? null : (int) $duration,
            'has_generated_credentials' => $this->rows()
                ->where('status', 'imported')
                ->whereNotNull('temp_password_encrypted')
                ->exists(),
            'failure_message' => $this->failure_message,
            'created_at' => $this->created_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
        ];
    }
}
