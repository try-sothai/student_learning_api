<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('quizzes')) {
            Schema::table('quizzes', function (Blueprint $table) {
                if (! Schema::hasColumn('quizzes', 'max_attempts')) {
                    $table->unsignedSmallInteger('max_attempts')->nullable()->default(1)->after('end_at');
                }
                if (! Schema::hasColumn('quizzes', 'scoring_method')) {
                    $table->string('scoring_method', 20)->default('highest')->after('max_attempts');
                }
                if (! Schema::hasColumn('quizzes', 'allow_late_submission')) {
                    $table->boolean('allow_late_submission')->default(false)->after('scoring_method');
                }
                if (! Schema::hasColumn('quizzes', 'reopened_at')) {
                    $table->dateTime('reopened_at')->nullable()->after('allow_late_submission');
                }
                if (! Schema::hasColumn('quizzes', 'reopened_until')) {
                    $table->dateTime('reopened_until')->nullable()->after('reopened_at');
                }
                if (! Schema::hasColumn('quizzes', 'reopened_by')) {
                    $table->foreignId('reopened_by')->nullable()->after('reopened_until')->constrained('users')->nullOnDelete();
                }
                if (! Schema::hasColumn('quizzes', 'version')) {
                    $table->unsignedInteger('version')->default(1)->after('reopened_by');
                }
                if (! Schema::hasColumn('quizzes', 'deleted_at')) {
                    $table->softDeletes();
                }
            });
        }

        if (Schema::hasTable('quiz_questions')) {
            Schema::table('quiz_questions', function (Blueprint $table) {
                if (! Schema::hasColumn('quiz_questions', 'is_required')) {
                    $table->boolean('is_required')->default(true)->after('marks');
                }
                if (! Schema::hasColumn('quiz_questions', 'grading_guidance')) {
                    $table->text('grading_guidance')->nullable()->after('correct_answer');
                }
                if (! Schema::hasColumn('quiz_questions', 'deleted_at')) {
                    $table->softDeletes();
                }
            });

            // Add non-unique (quiz_id, question_order) index BEFORE dropping the unique index so FK on quiz_id stays indexed.
            Schema::table('quiz_questions', function (Blueprint $table) {
                if (! Schema::hasIndex('quiz_questions', 'quiz_questions_quiz_order_idx')) {
                    $table->index(['quiz_id', 'question_order'], 'quiz_questions_quiz_order_idx');
                }
            });

            Schema::table('quiz_questions', function (Blueprint $table) {
                if (Schema::hasIndex('quiz_questions', 'quiz_questions_quiz_order_unique')) {
                    $table->dropUnique('quiz_questions_quiz_order_unique');
                }
            });
        }

        if (Schema::hasTable('quiz_attempts')) {
            Schema::table('quiz_attempts', function (Blueprint $table) {
                if (! Schema::hasColumn('quiz_attempts', 'grading_status')) {
                    $table->string('grading_status', 30)->default('not_submitted')->after('status');
                }
                if (! Schema::hasColumn('quiz_attempts', 'auto_graded_score')) {
                    $table->decimal('auto_graded_score', 6, 2)->nullable()->after('score');
                }
                if (! Schema::hasColumn('quiz_attempts', 'manual_graded_score')) {
                    $table->decimal('manual_graded_score', 6, 2)->nullable()->after('auto_graded_score');
                }
                if (! Schema::hasColumn('quiz_attempts', 'total_marks_snapshot')) {
                    $table->decimal('total_marks_snapshot', 6, 2)->nullable()->after('manual_graded_score');
                }
                if (! Schema::hasColumn('quiz_attempts', 'quiz_version')) {
                    $table->unsignedInteger('quiz_version')->default(1)->after('total_marks_snapshot');
                }
                if (! Schema::hasColumn('quiz_attempts', 'questions_snapshot')) {
                    $table->json('questions_snapshot')->nullable()->after('quiz_version');
                }
                if (! Schema::hasColumn('quiz_attempts', 'is_late')) {
                    $table->boolean('is_late')->default(false)->after('questions_snapshot');
                }
                if (! Schema::hasColumn('quiz_attempts', 'is_reset')) {
                    $table->boolean('is_reset')->default(false)->after('is_late');
                }
                if (! Schema::hasColumn('quiz_attempts', 'override_id')) {
                    $table->unsignedBigInteger('override_id')->nullable()->after('is_reset');
                }
                if (! Schema::hasColumn('quiz_attempts', 'graded_by')) {
                    $table->foreignId('graded_by')->nullable()->after('override_id')->constrained('users')->nullOnDelete();
                }
                if (! Schema::hasColumn('quiz_attempts', 'graded_at')) {
                    $table->dateTime('graded_at')->nullable()->after('graded_by');
                }
                if (! Schema::hasIndex('quiz_attempts', 'quiz_attempts_quiz_student_reset_idx')) {
                    $table->index(['quiz_id', 'student_id', 'is_reset'], 'quiz_attempts_quiz_student_reset_idx');
                }
                if (! Schema::hasIndex('quiz_attempts', 'quiz_attempts_quiz_grading_idx')) {
                    $table->index(['quiz_id', 'grading_status'], 'quiz_attempts_quiz_grading_idx');
                }
            });
        }

        if (Schema::hasTable('quiz_answers')) {
            Schema::table('quiz_answers', function (Blueprint $table) {
                if (! Schema::hasColumn('quiz_answers', 'grading_status')) {
                    $table->string('grading_status', 30)->default('pending_grading')->after('marks_obtained');
                }
                if (! Schema::hasColumn('quiz_answers', 'teacher_feedback')) {
                    $table->text('teacher_feedback')->nullable()->after('grading_status');
                }
                if (! Schema::hasColumn('quiz_answers', 'graded_by')) {
                    $table->foreignId('graded_by')->nullable()->after('teacher_feedback')->constrained('users')->nullOnDelete();
                }
                if (! Schema::hasColumn('quiz_answers', 'graded_at')) {
                    $table->dateTime('graded_at')->nullable()->after('graded_by');
                }
                if (! Schema::hasColumn('quiz_answers', 'question_snapshot')) {
                    $table->json('question_snapshot')->nullable()->after('graded_at');
                }
                if (! Schema::hasIndex('quiz_answers', 'quiz_answers_attempt_grading_idx')) {
                    $table->index(['quiz_attempt_id', 'grading_status'], 'quiz_answers_attempt_grading_idx');
                }
            });
        }

        if (! Schema::hasTable('quiz_student_overrides')) {
            Schema::create('quiz_student_overrides', function (Blueprint $table) {
                $table->id();
                $table->foreignId('quiz_id')->constrained('quizzes')->cascadeOnDelete();
                $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
                $table->foreignId('teacher_id')->nullable()->constrained('teachers')->nullOnDelete();
                $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
                $table->unsignedSmallInteger('extra_attempts')->default(0);
                $table->dateTime('extended_end_at')->nullable();
                $table->boolean('allow_late_submission')->default(false);
                $table->dateTime('attempts_reset_at')->nullable();
                $table->text('reason')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['quiz_id', 'student_id'], 'quiz_student_overrides_quiz_student_unique');
            });
        }

        if (! Schema::hasTable('quiz_override_logs')) {
            Schema::create('quiz_override_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('quiz_id')->constrained('quizzes')->cascadeOnDelete();
                $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
                $table->foreignId('teacher_id')->nullable()->constrained('teachers')->nullOnDelete();
                $table->foreignId('acted_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('action', 50);
                $table->text('reason')->nullable();
                $table->json('previous_setting')->nullable();
                $table->json('new_setting')->nullable();
                $table->timestamps();

                $table->index(['quiz_id', 'created_at'], 'quiz_override_logs_quiz_created_idx');
                $table->index(['quiz_id', 'student_id'], 'quiz_override_logs_quiz_student_idx');
            });
        }

        // Non-destructive status backfill for existing records
        if (Schema::hasTable('quiz_answers')) {
            DB::table('quiz_answers')
                ->whereNotNull('is_correct')
                ->where('grading_status', 'pending_grading')
                ->update(['grading_status' => 'auto_graded']);
        }
        if (Schema::hasTable('quiz_attempts')) {
            DB::table('quiz_attempts')
                ->whereIn('status', ['completed', 'graded', 'timed_out'])
                ->where('grading_status', 'not_submitted')
                ->update(['grading_status' => 'auto_graded']);
            DB::table('quiz_attempts')
                ->where('status', 'submitted')
                ->where('grading_status', 'not_submitted')
                ->update(['grading_status' => 'pending_grading']);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('quiz_override_logs')) {
            Schema::dropIfExists('quiz_override_logs');
        }
        if (Schema::hasTable('quiz_student_overrides')) {
            Schema::dropIfExists('quiz_student_overrides');
        }

        if (Schema::hasTable('quiz_answers')) {
            Schema::table('quiz_answers', function (Blueprint $table) {
                if (Schema::hasIndex('quiz_answers', 'quiz_answers_attempt_grading_idx')) {
                    $table->dropIndex('quiz_answers_attempt_grading_idx');
                }
                if (Schema::hasColumn('quiz_answers', 'graded_by')) {
                    $table->dropConstrainedForeignId('graded_by');
                }
                foreach (['question_snapshot', 'graded_at', 'teacher_feedback', 'grading_status'] as $col) {
                    if (Schema::hasColumn('quiz_answers', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        if (Schema::hasTable('quiz_attempts')) {
            Schema::table('quiz_attempts', function (Blueprint $table) {
                if (Schema::hasIndex('quiz_attempts', 'quiz_attempts_quiz_student_reset_idx')) {
                    $table->dropIndex('quiz_attempts_quiz_student_reset_idx');
                }
                if (Schema::hasIndex('quiz_attempts', 'quiz_attempts_quiz_grading_idx')) {
                    $table->dropIndex('quiz_attempts_quiz_grading_idx');
                }
                if (Schema::hasColumn('quiz_attempts', 'graded_by')) {
                    $table->dropConstrainedForeignId('graded_by');
                }
                foreach ([
                    'graded_at', 'override_id', 'is_reset', 'is_late',
                    'questions_snapshot', 'quiz_version', 'total_marks_snapshot',
                    'manual_graded_score', 'auto_graded_score', 'grading_status',
                ] as $col) {
                    if (Schema::hasColumn('quiz_attempts', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        if (Schema::hasTable('quiz_questions')) {
            Schema::table('quiz_questions', function (Blueprint $table) {
                if (Schema::hasColumn('quiz_questions', 'deleted_at')) {
                    $table->dropSoftDeletes();
                }
                foreach (['grading_guidance', 'is_required'] as $col) {
                    if (Schema::hasColumn('quiz_questions', $col)) {
                        $table->dropColumn($col);
                    }
                }
                if (! Schema::hasIndex('quiz_questions', 'quiz_questions_quiz_order_unique')) {
                    $table->unique(['quiz_id', 'question_order'], 'quiz_questions_quiz_order_unique');
                }
                if (Schema::hasIndex('quiz_questions', 'quiz_questions_quiz_order_idx')) {
                    $table->dropIndex('quiz_questions_quiz_order_idx');
                }
            });
        }

        if (Schema::hasTable('quizzes')) {
            Schema::table('quizzes', function (Blueprint $table) {
                if (Schema::hasColumn('quizzes', 'deleted_at')) {
                    $table->dropSoftDeletes();
                }
                if (Schema::hasColumn('quizzes', 'reopened_by')) {
                    $table->dropConstrainedForeignId('reopened_by');
                }
                foreach (['version', 'reopened_until', 'reopened_at', 'allow_late_submission', 'scoring_method', 'max_attempts'] as $col) {
                    if (Schema::hasColumn('quizzes', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
