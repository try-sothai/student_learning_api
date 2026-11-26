<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'code', 'description', 'status'];

    public function classes(): HasMany
    {
        return $this->hasMany(ClassRoom::class);
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    public function teachers(): HasMany
    {
        return $this->hasMany(Teacher::class);
    }

    public function courses(): HasMany
    {
        return $this->hasMany(Course::class);
    }

    public function programs(): HasMany
    {
        return $this->hasMany(Program::class);
    }

    /** A Department row is a Faculty; its Majors live in the programs table. */
    public function majors(): HasMany
    {
        return $this->hasMany(Program::class);
    }

    public function classSchedules(): HasMany
    {
        return $this->hasMany(ClassSchedule::class);
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class);
    }

    public function specializations(): HasMany
    {
        return $this->hasMany(Specialization::class);
    }
}
