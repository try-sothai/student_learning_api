<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OfficeLocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'building',
        'room_number',
        'display_name',
        'created_by',
    ];

    public function teachers(): HasMany
    {
        return $this->hasMany(Teacher::class);
    }
}
