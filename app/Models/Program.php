<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Program extends Model
{
    protected $fillable = ['department_id', 'code', 'name', 'duration_years', 'status'];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
