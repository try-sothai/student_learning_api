<?php

namespace App\Console\Commands;

use App\Models\Teacher;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class StandardizeTeacherPasswords extends Command
{
    protected $signature = 'slas:reset-teacher-passwords';

    protected $description = 'Standardize the password for all existing teacher accounts to the configured SLAS initial teacher password using Hash::make.';

    public function handle(): int
    {
        $initialPassword = (string) config('slas.default_teacher_password', 'TCH@12345');

        $teachers = Teacher::withTrashed()->with('user')->orderBy('id')->get();
        $teacherUsers = User::where('role', 'teacher')->orderBy('id')->get();

        $updated = 0;
        $linked = 0;
        $failed = 0;
        $tokensRevoked = 0;
        $processedUserIds = [];

        DB::transaction(function () use (
            $teachers,
            $teacherUsers,
            $initialPassword,
            &$updated,
            &$linked,
            &$failed,
            &$tokensRevoked,
            &$processedUserIds
        ) {
            foreach ($teachers as $teacher) {
                $user = $teacher->user;

                if (! $user) {
                    $baseName = trim($teacher->first_name . ' ' . $teacher->last_name);
                    if ($baseName === '') {
                        $baseName = $teacher->teacher_code;
                    }

                    $user = User::create([
                        'name' => $baseName,
                        'email' => null,
                        'password' => Hash::make($initialPassword),
                        'role' => 'teacher',
                        'status' => strtolower((string) $teacher->status) === 'active' ? 'active' : 'inactive',
                    ]);

                    $teacher->forceFill(['user_id' => $user->id])->save();
                    $processedUserIds[$user->id] = true;
                    $linked++;
                    $updated++;
                    continue;
                }

                if (strtolower((string) $user->role) !== 'teacher') {
                    // Strict role separation: never reset Student or Admin passwords.
                    $failed++;
                    continue;
                }

                $user->forceFill([
                    'password' => Hash::make($initialPassword),
                ])->save();

                $tokensRevoked += $user->tokens()->delete();
                $processedUserIds[$user->id] = true;
                $updated++;
            }

            foreach ($teacherUsers as $user) {
                if (isset($processedUserIds[$user->id])) {
                    continue;
                }

                if (strtolower((string) $user->role) !== 'teacher') {
                    $failed++;
                    continue;
                }

                $user->forceFill([
                    'password' => Hash::make($initialPassword),
                ])->save();

                $tokensRevoked += $user->tokens()->delete();
                $processedUserIds[$user->id] = true;
                $updated++;
            }
        });

        Log::info('Standardized teacher account passwords', [
            'teacher_records_found' => $teachers->count(),
            'teacher_users_found' => $teacherUsers->count(),
            'linked_missing_users' => $linked,
            'updated_accounts' => $updated,
            'failed_accounts' => $failed,
            'tokens_revoked' => $tokensRevoked,
        ]);

        $this->info("Teacher records found: {$teachers->count()}");
        $this->info("Teacher users found: {$teacherUsers->count()}");
        $this->info("Missing users linked: {$linked}");
        $this->info("Updated teacher accounts: {$updated}");
        $this->info("Tokens revoked: {$tokensRevoked}");
        $this->info("Failed accounts: {$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
