<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('assignments')) {
            Schema::table('assignments', function (Blueprint $table) {
                if (! Schema::hasColumn('assignments', 'allow_late_submission')) {
                    $table->boolean('allow_late_submission')->default(false)->after('status');
                }
                if (! Schema::hasColumn('assignments', 'allow_resubmission')) {
                    $table->boolean('allow_resubmission')->default(false)->after('allow_late_submission');
                }
                if (! Schema::hasColumn('assignments', 'reopened_at')) {
                    $table->dateTime('reopened_at')->nullable()->after('allow_resubmission');
                }
                if (! Schema::hasColumn('assignments', 'reopened_until')) {
                    $table->dateTime('reopened_until')->nullable()->after('reopened_at');
                }
                if (! Schema::hasColumn('assignments', 'reopened_by')) {
                    $table->foreignId('reopened_by')->nullable()->after('reopened_until')->constrained('users')->nullOnDelete();
                }
            });
        }

        if (Schema::hasTable('assignment_submissions')) {
            Schema::table('assignment_submissions', function (Blueprint $table) {
                if (! Schema::hasColumn('assignment_submissions', 'version_number')) {
                    $table->unsignedSmallInteger('version_number')->default(1)->after('student_id');
                }
                if (! Schema::hasColumn('assignment_submissions', 'is_late')) {
                    $table->boolean('is_late')->default(false)->after('submitted_at');
                }
                if (! Schema::hasColumn('assignment_submissions', 'is_latest')) {
                    $table->boolean('is_latest')->default(true)->after('status');
                }
                if (! Schema::hasColumn('assignment_submissions', 'override_id')) {
                    $table->unsignedBigInteger('override_id')->nullable()->after('is_latest');
                }
                if (! Schema::hasColumn('assignment_submissions', 'submission_text')) {
                    $table->text('submission_text')->nullable()->after('file_size');
                }
            });

            // Add composite indexes BEFORE dropping the legacy unique index so InnoDB FK on assignment_id stays indexed.
            Schema::table('assignment_submissions', function (Blueprint $table) {
                if (! Schema::hasIndex('assignment_submissions', 'submissions_assignment_student_latest_idx')) {
                    $table->index(['assignment_id', 'student_id', 'is_latest'], 'submissions_assignment_student_latest_idx');
                }
                if (! Schema::hasIndex('assignment_submissions', 'submissions_assignment_student_version_unique')) {
                    $table->unique(['assignment_id', 'student_id', 'version_number'], 'submissions_assignment_student_version_unique');
                }
            });

            Schema::table('assignment_submissions', function (Blueprint $table) {
                if (Schema::hasIndex('assignment_submissions', 'submissions_assignment_student_unique')) {
                    $table->dropUnique('submissions_assignment_student_unique');
                }
            });

            DB::table('assignment_submissions')->whereNull('version_number')->update(['version_number' => 1]);
            DB::table('assignment_submissions')->whereNull('is_latest')->update(['is_latest' => true]);
            DB::table('assignment_submissions')->where('status', 'late')->update(['is_late' => true]);
        }

        if (! Schema::hasTable('assessment_student_overrides')) {
            Schema::create('assessment_student_overrides', function (Blueprint $table) {
                $table->id();
                $table->string('assessment_type', 20);
                $table->unsignedBigInteger('assessment_id');
                $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
                $table->foreignId('class_id')->nullable()->constrained('classes')->nullOnDelete();
                $table->foreignId('teacher_id')->nullable()->constrained('teachers')->nullOnDelete();
                $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
                $table->boolean('allow_submission')->default(true);
                $table->boolean('allow_resubmission')->default(false);
                $table->boolean('allow_late_submission')->default(false);
                $table->unsignedSmallInteger('additional_attempts')->default(0);
                $table->dateTime('extended_deadline')->nullable();
                $table->dateTime('attempts_reset_at')->nullable();
                $table->text('reason')->nullable();
                $table->text('teacher_notes')->nullable();
                $table->boolean('is_active')->default(true);
                $table->dateTime('revoked_at')->nullable();
                $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('revoke_reason')->nullable();
                $table->timestamps();

                $table->unique(['assessment_type', 'assessment_id', 'student_id'], 'assessment_overrides_type_id_student_unique');
                $table->index(['assessment_type', 'assessment_id', 'is_active'], 'assessment_overrides_lookup_idx');
                $table->index(['student_id', 'is_active'], 'assessment_overrides_student_idx');
            });
        }

        if (! Schema::hasTable('assessment_audit_logs')) {
            Schema::create('assessment_audit_logs', function (Blueprint $table) {
                $table->id();
                $table->string('assessment_type', 20);
                $table->unsignedBigInteger('assessment_id');
                $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
                $table->foreignId('class_id')->nullable()->constrained('classes')->nullOnDelete();
                $table->foreignId('teacher_id')->nullable()->constrained('teachers')->nullOnDelete();
                $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('action_type', 50);
                $table->dateTime('previous_deadline')->nullable();
                $table->dateTime('new_deadline')->nullable();
                $table->unsignedSmallInteger('previous_attempt_limit')->nullable();
                $table->unsignedSmallInteger('new_attempt_limit')->nullable();
                $table->json('previous_settings')->nullable();
                $table->json('new_settings')->nullable();
                $table->text('reason')->nullable();
                $table->text('teacher_notes')->nullable();
                $table->timestamps();

                $table->index(['assessment_type', 'assessment_id', 'created_at'], 'assessment_audit_type_id_created_idx');
                $table->index(['student_id', 'created_at'], 'assessment_audit_student_created_idx');
                $table->index(['performed_by', 'created_at'], 'assessment_audit_actor_created_idx');
                $table->index(['action_type', 'created_at'], 'assessment_audit_action_created_idx');
            });
        }

        $this->backfillQuizOverridesAndLogs();
    }

    private function backfillQuizOverridesAndLogs(): void
    {
        if (Schema::hasTable('quiz_student_overrides') && Schema::hasTable('assessment_student_overrides')) {
            $rows = DB::table('quiz_student_overrides')->get();
            foreach ($rows as $row) {
                $exists = DB::table('assessment_student_overrides')
                    ->where('assessment_type', 'quiz')
                    ->where('assessment_id', $row->quiz_id)
                    ->where('student_id', $row->student_id)
                    ->exists();
                if ($exists) {
                    continue;
                }

                $classId = DB::table('quizzes')
                    ->leftJoin('course_offerings', 'quizzes.course_offering_id', '=', 'course_offerings.id')
                    ->where('quizzes.id', $row->quiz_id)
                    ->value('course_offerings.class_id');

                DB::table('assessment_student_overrides')->insert([
                    'assessment_type' => 'quiz',
                    'assessment_id' => $row->quiz_id,
                    'student_id' => $row->student_id,
                    'class_id' => $classId,
                    'teacher_id' => $row->teacher_id,
                    'granted_by' => $row->granted_by,
                    'allow_submission' => true,
                    'allow_resubmission' => ((int) $row->extra_attempts) > 0 || $row->attempts_reset_at !== null,
                    'allow_late_submission' => (bool) $row->allow_late_submission,
                    'additional_attempts' => (int) $row->extra_attempts,
                    'extended_deadline' => $row->extended_end_at,
                    'attempts_reset_at' => $row->attempts_reset_at,
                    'reason' => $row->reason,
                    'teacher_notes' => null,
                    'is_active' => (bool) $row->is_active,
                    'created_at' => $row->created_at ?? now(),
                    'updated_at' => $row->updated_at ?? now(),
                ]);
            }
        }

        if (Schema::hasTable('quiz_override_logs') && Schema::hasTable('assessment_audit_logs')) {
            $alreadyHasQuizLogs = DB::table('assessment_audit_logs')->where('assessment_type', 'quiz')->exists();
            if (! $alreadyHasQuizLogs) {
                $logs = DB::table('quiz_override_logs')->get();
                foreach ($logs as $log) {
                    $classId = DB::table('quizzes')
                        ->leftJoin('course_offerings', 'quizzes.course_offering_id', '=', 'course_offerings.id')
                        ->where('quizzes.id', $log->quiz_id)
                        ->value('course_offerings.class_id');

                    DB::table('assessment_audit_logs')->insert([
                        'assessment_type' => 'quiz',
                        'assessment_id' => $log->quiz_id,
                        'student_id' => $log->student_id,
                        'class_id' => $classId,
                        'teacher_id' => $log->teacher_id,
                        'performed_by' => $log->acted_by,
                        'action_type' => $log->action,
                        'previous_settings' => $log->previous_setting,
                        'new_settings' => $log->new_setting,
                        'reason' => $log->reason,
                        'created_at' => $log->created_at ?? now(),
                        'updated_at' => $log->updated_at ?? now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('assessment_audit_logs')) {
            Schema::dropIfExists('assessment_audit_logs');
        }
        if (Schema::hasTable('assessment_student_overrides')) {
            Schema::dropIfExists('assessment_student_overrides');
        }

        if (Schema::hasTable('assignment_submissions')) {
            $hasDuplicates = DB::table('assignment_submissions')
                ->select('assignment_id', 'student_id')
                ->groupBy('assignment_id', 'student_id')
                ->havingRaw('COUNT(*) > 1')
                ->exists();

            if (! $hasDuplicates && ! Schema::hasIndex('assignment_submissions', 'submissions_assignment_student_unique')) {
                Schema::table('assignment_submissions', function (Blueprint $table) {
                    $table->unique(['assignment_id', 'student_id'], 'submissions_assignment_student_unique');
                });
            }

            Schema::table('assignment_submissions', function (Blueprint $table) {
                if (Schema::hasIndex('assignment_submissions', 'submissions_assignment_student_version_unique')) {
                    $table->dropUnique('submissions_assignment_student_version_unique');
                }
                if (Schema::hasIndex('assignment_submissions', 'submissions_assignment_student_latest_idx')) {
                    $table->dropIndex('submissions_assignment_student_latest_idx');
                }
                foreach (['submission_text', 'override_id', 'is_latest', 'is_late', 'version_number'] as $col) {
                    if (Schema::hasColumn('assignment_submissions', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        if (Schema::hasTable('assignments')) {
            Schema::table('assignments', function (Blueprint $table) {
                if (Schema::hasColumn('assignments', 'reopened_by')) {
                    $table->dropConstrainedForeignId('reopened_by');
                }
                foreach (['reopened_until', 'reopened_at', 'allow_resubmission', 'allow_late_submission'] as $col) {
                    if (Schema::hasColumn('assignments', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
