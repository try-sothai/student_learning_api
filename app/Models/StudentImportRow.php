<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentImportRow extends Model
{
    protected $fillable = [
        'student_import_id', 'row_number', 'student_identifier', 'status', 'data', 'errors',
        'temp_password_encrypted', 'student_id',
    ];

    protected $hidden = ['temp_password_encrypted'];

    protected function casts(): array
    {
        return ['data' => 'array', 'errors' => 'array'];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(StudentImport::class, 'student_import_id');
    }

    public function toApi(): array
    {
        $d = $this->data ?? [];

        return [
            'id' => $this->id,
            'row_number' => $this->row_number,
            'student_identifier' => $this->student_identifier,
            'username' => $this->student_identifier,
            'student_id' => $this->student_id,
            'role' => $this->status === 'imported' ? 'student' : null,
            'english_name' => $d['english_name'] ?? null,
            'khmer_name' => $d['khmer_name'] ?? null,
            'department_code' => $d['department_code'] ?? null,
            'program_code' => $d['program_code'] ?? null,
            'status' => $this->status,
            'has_generated_password' => $this->status === 'imported' && $this->temp_password_encrypted !== null,
            'errors' => $this->errors ?? [],
        ];
    }
}
