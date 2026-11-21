<?php

namespace App\Http\Controllers;

use App\Models\ClassRoom;
use App\Models\Course;
use App\Models\FacultyReview;
use App\Models\Grade;
use App\Models\Teacher;
use App\Services\TeacherProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Faculty Evaluators = Teachers viewed through their assignments
 * (courses.teacher_id, class_teacher) and faculty_reviews. No separate
 * faculty table exists or is created.
 */
class FacultyEvaluatorController extends Controller
{
    /** total_score (0-100) at or above which a grade counts as passed. */
    public const PASS_MARK = 50;

    public function statistics(): JsonResponse
    {
        $reviewed = FacultyReview::where('status', 'completed')->distinct()->count('teacher_id');
        $totalTeachers = Teacher::count();

        return response()->json([
            'message' => 'Faculty statistics retrieved successfully.',
            'data' => [
                'faculty_reviewed' => $reviewed,
                'courses_assigned' => Course::whereNotNull('teacher_id')->count(),
                'classes_assigned' => (int) DB::table('class_teacher')->distinct()->count('class_id'),
                'reviews_needed' => max(0, $totalTeachers - $reviewed),
                'total_faculty' => $totalTeachers,
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 10), 1), 100);
        $search = trim((string) $request->query('search', ''));
        $reviewStatus = strtolower((string) $request->query('review_status', ''));

        $query = Teacher::with(['department', 'office', 'specializationItems', 'user:id,name,email'])
            ->withCount(['courses', 'classes']);

        if ($request->filled('department_id')) {
            $query->where('department_id', (int) $request->query('department_id'));
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('teacher_code', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($u) => $u->where('email', 'like', "%{$search}%"));
            });
        }

        if ($reviewStatus === 'completed') {
            $query->whereHas('facultyReviews', fn ($r) => $r->where('status', 'completed'));
        } elseif (in_array($reviewStatus, ['pending', 'pending_review'], true)) {
            $query->whereDoesntHave('facultyReviews', fn ($r) => $r->where('status', 'completed'));
        }

        $paginator = $query->orderBy('first_name')->orderBy('last_name')->paginate($perPage);
        $ids = $paginator->getCollection()->pluck('id')->all();
        $metrics = $this->metricsFor($ids);

        $items = $paginator->getCollection()->map(fn (Teacher $t) => $this->present($t, $metrics[$t->id] ?? null));

        return response()->json([
            'message' => 'Faculty evaluators retrieved successfully.',
            'data' => $items,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(Teacher $teacher): JsonResponse
    {
        $teacher->load(['department', 'office', 'specializationItems', 'user:id,name,email'])->loadCount(['courses', 'classes']);
        $metrics = $this->metricsFor([$teacher->id]);

        return response()->json([
            'message' => 'Faculty evaluator retrieved successfully.',
            'data' => $this->present($teacher, $metrics[$teacher->id] ?? null),
        ]);
    }

    public function update(Request $request, Teacher $teacher, TeacherProfileService $profiles): JsonResponse
    {
        $validated = $request->validate([
            'first_name' => 'sometimes|string|max:100',
            'last_name' => 'sometimes|string|max:100',
            'position' => 'sometimes|nullable|string|max:100',
            'work_type' => 'sometimes|nullable|string|max:50',
            'specialization' => 'sometimes|nullable|string|max:1000',
            'specializations' => 'sometimes|nullable|array',
            'specializations.*' => 'nullable|string|max:120',
            'office_location_id' => 'sometimes|nullable|integer|exists:office_locations,id',
            'office_location' => 'sometimes|nullable|string|max:191',
            'biography' => 'sometimes|nullable|string|max:2000',
            'bio' => 'sometimes|nullable|string|max:2000',
            'phone' => 'sometimes|nullable|string|max:30',
            'department_id' => 'sometimes|nullable|integer|exists:departments,id',
        ]);

        DB::transaction(function () use ($request, $teacher, $validated, $profiles) {
            $data = collect($validated)->only(['first_name', 'last_name', 'position', 'work_type', 'phone', 'department_id'])->all();
            if (array_key_exists('office_location_id', $validated) || array_key_exists('office_location', $validated)) {
                $data['office_location_id'] = $profiles->resolveOfficeLocationId(
                    $validated['office_location_id'] ?? null,
                    $validated['office_location'] ?? null,
                    $request->user()?->id
                );
            }
            if (array_key_exists('biography', $validated) || array_key_exists('bio', $validated)) {
                $data['biography'] = $profiles->cleanBiography($validated['biography'] ?? $validated['bio'] ?? null);
            }
            $teacher->update($data);
            if (array_key_exists('specializations', $validated) || array_key_exists('specialization', $validated)) {
                $profiles->syncSpecializations(
                    $teacher,
                    $validated['specializations'] ?? null,
                    $validated['specialization'] ?? null,
                    $request->user()?->id
                );
            }
            if ($teacher->user && (isset($validated['first_name']) || isset($validated['last_name']))) {
                $teacher->user->update(['name' => trim($teacher->first_name . ' ' . $teacher->last_name)]);
            }
        });

        return $this->show($teacher->fresh());
    }

    public function courses(Teacher $teacher): JsonResponse
    {
        $courses = $teacher->courses()->get(['id', 'code', 'name', 'course_code', 'course_name', 'department_id', 'status']);

        return response()->json(['message' => 'Assigned courses retrieved successfully.', 'data' => $courses]);
    }

    public function classes(Teacher $teacher): JsonResponse
    {
        $classes = $teacher->classes()->get()->map(fn ($c) => [
            'id' => $c->id,
            'code' => $c->code,
            'name' => $c->name,
            'course_id' => $c->pivot->course_id,
        ]);

        return response()->json(['message' => 'Assigned classes retrieved successfully.', 'data' => $classes]);
    }

    public function assignCourses(Request $request, Teacher $teacher): JsonResponse
    {
        $validated = $request->validate([
            'course_ids' => 'required|array|min:1',
            'course_ids.*' => 'integer|distinct|exists:courses,id',
        ]);

        DB::transaction(function () use ($teacher, $validated) {
            Course::whereIn('id', $validated['course_ids'])->update(['teacher_id' => $teacher->id]);
        });

        return $this->courses($teacher);
    }

    public function assignClasses(Request $request, Teacher $teacher): JsonResponse
    {
        $validated = $request->validate([
            'class_ids' => 'required|array|min:1',
            'class_ids.*' => 'integer|distinct|exists:classes,id',
            'course_id' => 'nullable|integer|exists:courses,id',
            'semester_id' => 'nullable|integer|exists:semesters,id',
            'academic_year_id' => 'nullable|integer|exists:academic_years,id',
        ]);

        DB::transaction(function () use ($teacher, $validated) {
            $courseId = $validated['course_id'] ?? null;
            $semesterId = $validated['semester_id']
                ?? \App\Models\Semester::where('status', 'active')->value('id')
                ?? \App\Models\Semester::orderByDesc('id')->value('id');

            foreach ($validated['class_ids'] as $classId) {
                $exists = DB::table('class_teacher')
                    ->where('class_id', $classId)->where('teacher_id', $teacher->id)
                    ->when($courseId, fn ($q) => $q->where('course_id', $courseId), fn ($q) => $q->whereNull('course_id'))
                    ->exists();
                if (! $exists) {
                    $teacher->classes()->attach($classId, ['course_id' => $courseId]);
                }

                if ($courseId && $semesterId) {
                    $classRoom = ClassRoom::find($classId);
                    $offering = \App\Models\CourseOffering::firstOrCreate(
                        [
                            'course_id' => $courseId,
                            'class_id' => $classId,
                            'semester_id' => $semesterId,
                        ],
                        [
                            'academic_year_id' => $validated['academic_year_id'] ?? $classRoom?->academic_year_id,
                            'status' => 'active',
                        ]
                    );

                    DB::table('course_offering_teachers')->updateOrInsert(
                        ['course_offering_id' => $offering->id, 'teacher_id' => $teacher->id],
                        ['role' => 'primary', 'status' => 'active', 'assigned_at' => now(), 'updated_at' => now(), 'created_at' => now()]
                    );

                    // Sync existing class students into the course offering enrollment
                    $students = \App\Models\Student::where('class_id', $classId)->get(['id', 'enrollment_date', 'status']);
                    foreach ($students as $stu) {
                        if (in_array(strtolower((string) $stu->status), ['inactive', 'suspended', 'graduated', 'dropped'], true)) {
                            continue;
                        }
                        \App\Models\CourseEnrollment::updateOrCreate(
                            ['student_id' => $stu->id, 'course_offering_id' => $offering->id],
                            [
                                'course_id' => $courseId,
                                'semester_id' => $semesterId,
                                'enrollment_date' => $stu->enrollment_date?->toDateString() ?? now()->toDateString(),
                                'status' => 'enrolled',
                            ]
                        );
                    }
                }
            }
        });

        return $this->classes($teacher);
    }

    /** Remove course and/or class assignments. Courses are unassigned, never deleted. */
    public function removeAssignments(Request $request, Teacher $teacher): JsonResponse
    {
        $validated = $request->validate([
            'course_ids' => 'sometimes|array',
            'course_ids.*' => 'integer',
            'class_ids' => 'sometimes|array',
            'class_ids.*' => 'integer',
        ]);

        DB::transaction(function () use ($teacher, $validated) {
            if (! empty($validated['course_ids'])) {
                Course::where('teacher_id', $teacher->id)
                    ->whereIn('id', $validated['course_ids'])->update(['teacher_id' => null]);
            }
            if (! empty($validated['class_ids'])) {
                $teacher->classes()->detach($validated['class_ids']);
            }
        });

        return response()->json(['message' => 'Assignments removed successfully.']);
    }

    public function reviews(Teacher $teacher): JsonResponse
    {
        $reviews = $teacher->facultyReviews()->with('reviewer:id,name')->latest()->get();

        return response()->json(['message' => 'Faculty reviews retrieved successfully.', 'data' => $reviews]);
    }

    public function submitReview(Request $request, Teacher $teacher): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['pending', 'completed'])],
            'rating' => 'required_if:status,completed|nullable|numeric|min:1|max:5',
            'comments' => 'nullable|string|max:2000',
            'semester_id' => 'nullable|integer|exists:semesters,id',
        ]);

        $review = DB::transaction(fn () => FacultyReview::create([
            'teacher_id' => $teacher->id,
            'semester_id' => $validated['semester_id'] ?? null,
            'reviewer_id' => $request->user()->id,
            'rating' => $validated['rating'] ?? null,
            'status' => $validated['status'],
            'comments' => $validated['comments'] ?? null,
            'reviewed_at' => $validated['status'] === 'completed' ? now() : null,
        ]));

        return response()->json(['message' => 'Faculty review submitted successfully.', 'data' => $review], 201);
    }

    /**
     * Batch pass rate and rating per teacher (real records only).
     *
     * @return array<int, array{pass_rate: ?float, rating: ?float, review_status: string}>
     */
    private function metricsFor(array $teacherIds): array
    {
        if (! $teacherIds) {
            return [];
        }

        $grades = Grade::query()
            ->join('courses', 'courses.id', '=', 'grades.course_id')
            ->whereIn('courses.teacher_id', $teacherIds)
            ->whereNotNull('grades.total_score')
            ->groupBy('courses.teacher_id')
            ->selectRaw('courses.teacher_id as tid, COUNT(*) as total, SUM(CASE WHEN grades.total_score >= ? THEN 1 ELSE 0 END) as passed', [self::PASS_MARK])
            ->get()->keyBy('tid');

        $ratings = FacultyReview::where('status', 'completed')->whereNotNull('rating')
            ->whereIn('teacher_id', $teacherIds)
            ->groupBy('teacher_id')
            ->selectRaw('teacher_id, AVG(rating) as avg_rating')
            ->get()->keyBy('teacher_id');

        $completed = FacultyReview::where('status', 'completed')->whereIn('teacher_id', $teacherIds)
            ->distinct()->pluck('teacher_id')->flip();

        $out = [];
        foreach ($teacherIds as $id) {
            $g = $grades->get($id);
            $out[$id] = [
                'pass_rate' => $g && $g->total > 0 ? round($g->passed / $g->total * 100, 1) : null,
                'rating' => $ratings->has($id) ? round((float) $ratings->get($id)->avg_rating, 2) : null,
                'review_status' => $completed->has($id) ? 'Completed' : 'Pending Review',
            ];
        }

        return $out;
    }

    private function present(Teacher $t, ?array $m): array
    {
        return [
            'id' => $t->id,
            'faculty_id' => $t->teacher_code,
            'name' => trim($t->first_name . ' ' . $t->last_name),
            'email' => $t->user?->email,
            'phone' => $t->phone,
            'position' => $t->position,
            'work_type' => $t->work_type ?? 'Full-time',
            'office_location_id' => $t->office_location_id,
            'office_location' => $t->office?->display_name,
            'specialization' => $t->specialization,
            'specializations' => $t->specializations,
            'biography' => $t->biography,
            'department_id' => $t->department_id,
            'department' => $t->department?->name,
            'courses_count' => $t->courses_count ?? 0,
            'classes_count' => $t->classes_count ?? 0,
            'class_pass_rate' => $m['pass_rate'] ?? null,
            'average_rating' => $m['rating'] ?? null,
            'student_rating' => $m['rating'] ?? null,
            'review_status' => $m['review_status'] ?? 'Pending Review',
        ];
    }
}
