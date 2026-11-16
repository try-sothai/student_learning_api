<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentFamilyInformation extends Model
{
    protected $table = 'student_family_information';

    protected $fillable = [
        'student_id', 'father_khmer_name', 'father_english_name', 'father_phone',
        'mother_khmer_name', 'mother_english_name', 'mother_phone',
    ];
}
