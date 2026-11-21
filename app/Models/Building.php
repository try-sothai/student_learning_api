<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Building extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'description',
        'status',
    ];

    public function floors(): HasMany
    {
        return $this->hasMany(Floor::class)->orderBy('floor_number');
    }

    public function classes(): HasMany
    {
        return $this->hasMany(ClassRoom::class, 'building_id');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(ClassSchedule::class, 'building_id');
    }
}
