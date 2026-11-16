<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentEmergencyContact extends Model
{
    protected $fillable = ['student_id', 'name', 'relationship', 'phone', 'address'];
}
