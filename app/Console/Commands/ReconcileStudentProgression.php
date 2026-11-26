<?php

namespace App\Console\Commands;

use App\Services\AcademicProgressionService;
use Illuminate\Console\Command;

class ReconcileStudentProgression extends Command
{
    protected $signature = 'slas:reconcile-student-progression {--student_id= : Optional specific student ID}';

    protected $description = 'Fallback reconciliation to evaluate Final Grade completion and automatically progress eligible students.';

    public function handle(AcademicProgressionService $progressionService): int
    {
        $studentId = $this->option('student_id');
        if ($studentId) {
            $res = $progressionService->evaluateAndProgressStudent((int) $studentId, 'scheduled_reconciliation');
            $this->info(json_encode($res));

            return self::SUCCESS;
        }

        $summary = $progressionService->reconcileAllEligibleStudents('scheduled_reconciliation');
        $this->info("Evaluated {$summary['evaluated']} student(s); progressed {$summary['progressed']} student(s).");

        return self::SUCCESS;
    }
}
