<?php

namespace App\Console\Commands;

use App\Models\Student;
use App\Models\StudentImportRow;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class StandardizeStudentPasswords extends Command
{
    protected $signature = 'slas:reset-student-passwords';

    protected $description = 'Standardize the password for all existing student accounts to the configured SLAS initial student password using Hash::make.';

    public function handle(): int
    {
        $initialPassword = (string) config('student_import.default_initial_password', 'STD@12345');

        $studentUsers = User::where('role', 'student')->orderBy('id')->get();
        $totalStudents = Student::withTrashed()->count();
        $studentsMissingUser = Student::withTrashed()
            ->where(function ($q) {
                $q->whereNull('user_id')
                    ->orWhereDoesntHave('user');
            })
            ->count();

        $updated = 0;
        $failed = 0;

        DB::transaction(function () use ($studentUsers, $initialPassword, &$updated, &$failed) {
            foreach ($studentUsers as $user) {
                if (strtolower((string) $user->role) !== 'student') {
                    $failed++;
                    continue;
                }

                $user->forceFill([
                    'password' => Hash::make($initialPassword),
                ])->save();

                // Revoke existing Sanctum tokens so students log in with the standardized password.
                $user->tokens()->delete();
                $updated++;
            }

            StudentImportRow::where('status', 'imported')
                ->whereNotNull('temp_password_encrypted')
                ->update(['temp_password_encrypted' => null]);
        });

        Log::info('Standardized student account passwords', [
            'student_users_found' => $studentUsers->count(),
            'student_records_count' => $totalStudents,
            'students_missing_user' => $studentsMissingUser,
            'updated_accounts' => $updated,
            'failed_accounts' => $failed,
        ]);

        $this->info("Student users found: {$studentUsers->count()}");
        $this->info("Student records count: {$totalStudents}");
        $this->info("Updated student accounts: {$updated}");
        $this->info("Failed accounts: {$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
