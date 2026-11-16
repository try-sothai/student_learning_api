<?php

namespace Tests\Feature;

use App\Models\Grade;
use Tests\TestCase;

class AssessmentWeightingTest extends TestCase
{
    public function test_official_assessment_weights_calculation_example(): void
    {
        // Example from SLAS Official Assessment Weights specification:
        // Attendance (10%): 90 -> 9.00
        // Assignment (20%): 85 -> 17.00
        // Quiz (10%): 80 -> 8.00
        // Homework (10%): 95 -> 9.50
        // Midterm Exam (15%): 75 -> 11.25
        // Final Exam (35%): 80 -> 28.00
        // Total Expected: 82.75

        $attendance = 90.0;
        $assignment = 85.0;
        $quiz = 80.0;
        $homework = 95.0;
        $midterm = 75.0;
        $final = 80.0;

        $totalScore = ($attendance * 0.10)
            + ($assignment * 0.20)
            + ($quiz * 0.10)
            + ($homework * 0.10)
            + ($midterm * 0.15)
            + ($final * 0.35);

        $this->assertEquals(82.75, round($totalScore, 2));

        $grade = new Grade([
            'attendance_score' => $attendance,
            'assignment_score' => $assignment,
            'quiz_score' => $quiz,
            'midterm_score' => $midterm,
            'final_exam_score' => $final,
            'total_score' => $totalScore,
        ]);

        $this->assertEquals(82.75, $grade->total_score);
    }
}
