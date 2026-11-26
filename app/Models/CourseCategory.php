<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The TYPE of a course (General Education, Major Core, Major Elective, ...).
 * Deliberately separate from Faculty (departments) and Major (programs).
 */
class CourseCategory extends Model
{
    protected $fillable = ['name', 'code', 'description', 'status'];

    public function courses(): HasMany
    {
        return $this->hasMany(Course::class, 'course_category_id');
    }
}
