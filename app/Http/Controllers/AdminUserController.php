<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\Admin;
use App\Models\AttendanceDetail;
use App\Models\ClassRoom;
use App\Models\Department;
use App\Models\GpaRecord;
use App\Models\Program;
use App\Models\Student;
use App\Models\StudentEmergencyContact;
use App\Models\StudentFamilyInformation;
use App\Models\StudentNote;
use App\Models\Teacher;
use App\Models\User;
use App\Rules\ValidYearLevel;
use App\Services\AcademicProfileResolver;
use App\Services\StudentImportValidationService;
use App\Services\TeacherAccountService;
use App\Services\TeacherProfileService;
use App\Support\PersonNameHelper;
use App\Support\YearLevel;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminUserController extends Controller
{
    public function statistics(Request $request)
    {
        $totalStudents = Student::count();
        $activeStudents = Student::whereIn('status', ['enrolled', 'active'])->count();
        $inactiveStudents = Student::whereIn('status', ['inactive', 'suspended', 'graduated'])->count();
        $studentsByYearLevel = [
            'Y1' => Student::forYearLevel(1)->count(),
            'Y2' => Student::forYearLevel(2)->count(),
            'Y3' => Student::forYearLevel(3)->count(),
            'Y4' => Student::forYearLevel(4)->count(),
            'unassigned' => Student::missingYearLevel()->count(),
        ];

        $totalTeachers = Teacher::count();
        $activeTeachers = Teacher::where('status', 'active')->count();
        $inactiveTeachers = Teacher::where('status', 'inactive')->count();

        $totalAdmins = Admin::count();
        $activeAdmins = Admin::where('status', 'active')->count();
        $inactiveAdmins = Admin::where('status', 'inactive')->count();

        $totalFacultyUsers = Department::count();

        return response()->json([
            'message' => 'User statistics retrieved successfully.',
            'data' => [
                'total_students' => $totalStudents,
                'active_students' => $activeStudents,
                'inactive_students' => $inactiveStudents,
                'students_by_year_level' => $studentsByYearLevel,
                'total_teachers' => $totalTeachers,
                'active_teachers' => $activeTeachers,
                'inactive_teachers' => $inactiveTeachers,
                'total_administrators' => $totalAdmins,
                'total_admins' => $totalAdmins,
                'active_admins' => $activeAdmins,
                'inactive_admins' => $inactiveAdmins,
                'total_faculty_users' => $totalFacultyUsers,
            ],
        ], 200);
    }

    public function index(Request $request)
    {
        $role = strtolower($request->query('role', 'all'));
        $status = strtolower($request->query('status', 'all'));
        $search = trim($request->query('search', ''));
        $departmentFilter = trim((string) $request->query('department', $request->query('department_id', $request->query('faculty', $request->query('faculty_id', '')))));
        $yearLevelFilter = trim((string) $request->query('year_level', ''));
        $academicYearIdFilter = $request->filled('academic_year_id') ? (int) $request->query('academic_year_id') : null;
        $classIdFilter = $request->filled('class_id') ? (int) $request->query('class_id') : null;
        $semesterIdFilter = $request->filled('semester_id') ? (int) $request->query('semester_id') : null;
        $missingYearLevel = $request->boolean('missing_year_level') || strtolower($yearLevelFilter) === 'missing';
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

        $query = User::with([
            'student.department',
            'student.program',
            'student.classRoom.academicYear',
            'student.courseEnrollments.semester.academicYear',
            'student.familyInformation',
            'student.emergencyContacts',
            'teacher.department',
            'teacher.office',
            'teacher.specializationItems',
            'teacher.courses',
            'teacher.classes',
            'admin',
        ]);

        // Filter by role
        if ($role !== 'all') {
            if ($role === 'student' || $role === 'students') {
                $query->where('role', 'student');
            } elseif ($role === 'teacher' || $role === 'teachers') {
                $query->where('role', 'teacher');
            } elseif ($role === 'admin' || $role === 'admins' || $role === 'administrator') {
                $query->where('role', 'admin');
            } elseif ($role === 'faculty') {
                $query->whereIn('role', ['teacher', 'faculty']);
            }
        }

        // Filter by department / faculty
        if (! in_array(strtolower($departmentFilter), ['', 'all', 'all departments', 'all faculties'], true)) {
            $query->where(function ($q) use ($departmentFilter) {
                if (ctype_digit($departmentFilter)) {
                    $deptId = (int) $departmentFilter;
                    $q->whereHas('student', fn ($sq) => $sq->where('department_id', $deptId))
                      ->orWhereHas('teacher', fn ($tq) => $tq->where('department_id', $deptId));
                } else {
                    $q->whereHas('student.department', fn ($dq) => $dq->where('name', 'like', "%{$departmentFilter}%")->orWhere('code', $departmentFilter))
                      ->orWhereHas('teacher.department', fn ($dq) => $dq->where('name', 'like', "%{$departmentFilter}%")->orWhere('code', $departmentFilter))
                      ->orWhereHas('teacher', fn ($tq) => $tq->where('specialization', 'like', "%{$departmentFilter}%"))
                      ->orWhereHas('admin', fn ($aq) => $aq->where('department', 'like', "%{$departmentFilter}%"));
                }
            });
        }

        // Filter by status
        if ($status !== 'all') {
            if ($status === 'active') {
                $query->where(function ($q) {
                    $q->whereIn('status', ['active', 'enrolled'])
                      ->orWhereNull('status');
                });
            } elseif ($status === 'inactive') {
                $query->whereIn('status', ['inactive', 'suspended', 'graduated']);
            }
        }

        if ($missingYearLevel) {
            $query->whereHas('student', fn ($sq) => $sq->missingYearLevel());
        } elseif ($yearLevelFilter !== '' && strtolower($yearLevelFilter) !== 'all') {
            $variants = YearLevel::dbVariants($yearLevelFilter);
            if ($variants === []) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereHas('student', fn ($sq) => $sq->whereIn('year_level', $variants));
            }
        }

        if ($academicYearIdFilter) {
            $query->whereHas('student.classRoom', fn ($cq) => $cq->where('academic_year_id', $academicYearIdFilter));
        }

        if ($classIdFilter) {
            $query->whereHas('student', fn ($sq) => $sq->where('class_id', $classIdFilter));
        }

        if ($semesterIdFilter) {
            $query->whereHas('student.courseEnrollments', fn ($eq) => $eq->where('semester_id', $semesterIdFilter));
        }

        // Search
        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhereHas('student', function ($sq) use ($search) {
                      $sq->where('student_code', 'like', "%{$search}%")
                        ->orWhere('khmer_name', 'like', "%{$search}%")
                        ->orWhere('english_name', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%");
                  })
                  ->orWhereHas('teacher', function ($tq) use ($search) {
                      $tq->where('teacher_code', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('specialization', 'like', "%{$search}%")
                        ->orWhereHas('office', fn ($oq) => $oq->where('display_name', 'like', "%{$search}%"));
                  })
                  ->orWhereHas('admin', function ($aq) use ($search) {
                      $aq->where('admin_code', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%");
                  });
            });
        }

        $paginator = $query->latest()->paginate($perPage);

        $transformed = $paginator->getCollection()->map(fn (User $user) => $this->formatUserPayload($user));

        return response()->json([
            'message' => 'Users retrieved successfully.',
            'data' => $transformed,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ], 200);
    }

    private function resolveUser(User|string|int $userOrId): User
    {
        if ($userOrId instanceof User) {
            return $userOrId;
        }
        $idStr = trim((string) $userOrId);
        if (ctype_digit($idStr)) {
            $found = User::find((int) $idStr);
            if ($found) {
                return $found;
            }
        }
        $student = Student::where('student_code', $idStr)->first();
        if ($student && $student->user) {
            return $student->user;
        }
        $teacher = Teacher::where('teacher_code', $idStr)->first();
        if ($teacher && $teacher->user) {
            return $teacher->user;
        }
        $admin = Admin::where('admin_code', $idStr)->first();
        if ($admin && $admin->user) {
            return $admin->user;
        }

        abort(404, 'User not found.');
    }

    public function show(string|int $user)
    {
        $user = $this->resolveUser($user);
        $user->load([
            'student.department',
            'student.program',
            'student.classRoom.academicYear',
            'student.courseEnrollments.semester.academicYear',
            'student.familyInformation',
            'student.emergencyContacts',
            'student.courses.teacher.user',
            'student.gpaRecords.semester',
            'teacher.department',
            'teacher.office',
            'teacher.specializationItems',
            'teacher.courses',
            'teacher.classes',
            'admin',
        ]);

        return response()->json([
            'message' => 'User details retrieved successfully.',
            'data' => $this->formatUserPayload($user, true),
        ], 200);
    }

    private function formatUserPayload(User $user, bool $detailed = false): array
    {
        $code = '';
        $deptName = '';
        $deptCode = '';
        $deptId = null;
        $programId = null;
        $programName = '';
        $programCode = '';
        $academicYearId = null;
        $academicYearName = '';
        $classId = null;
        $classCode = '';
        $semesterId = null;
        $semesterName = '';
        $yearLevel = '';
        $yearLevelCode = '';
        $yearLevelName = '';
        $yearLevelKhmer = '';
        $yearLevelLabel = '';
        $gender = '';
        $phone = '';
        $dob = '';
        $placeOfBirth = '';
        $address = '';
        $khmerName = '';
        $englishName = '';
        $position = '';
        $workType = '';
        $officeLocationId = null;
        $officeLocation = '';
        $biography = '';
        $specialization = '';
        $specializations = [];
        $familyInfo = null;
        $emergencyContact = null;
        $attendanceSummary = null;
        $attendanceRecords = [];
        $gpaSummary = null;
        $enrolledCourses = [];
        $studentNotes = [];

        if ($user->student) {
            $s = $user->student;
            $code = $s->student_code ?? $s->student_id ?? ('STD' . str_pad((string) $s->id, 3, '0', STR_PAD_LEFT));
            $deptId = $s->department_id;
            $deptName = $s->department?->name ?? '';
            $deptCode = $s->department?->code ?? '';
            $programId = $s->program_id;
            $programName = $s->program?->name ?? $s->major ?? '';
            $programCode = $s->program?->code ?? '';
            $academicYearId = $s->classRoom?->academic_year_id;
            $academicYearName = $s->classRoom?->academicYear?->name ?? ($s->resolveAcademicYearName() ?? '');
            $classId = $s->class_id;
            $classCode = $s->classRoom?->code ?? '';
            $latestEnrollment = $s->relationLoaded('courseEnrollments')
                ? $s->courseEnrollments->sortByDesc('semester_id')->first()
                : null;
            $semesterId = $latestEnrollment?->semester_id;
            $semesterName = $latestEnrollment?->semester?->name ?? '';
            $parsedYl = YearLevel::parse($s->year_level);
            $yearLevel = $parsedYl !== null ? (string) $parsedYl : (string) ($s->year_level ?? '');
            $yearLevelCode = YearLevel::code($s->year_level) ?? '';
            $yearLevelName = YearLevel::name($s->year_level) ?? '';
            $yearLevelKhmer = YearLevel::khmer($s->year_level) ?? '';
            $yearLevelLabel = YearLevel::label($s->year_level) ?? '';
            $gender = $s->gender ?? '';
            $phone = $s->phone ?? '';
            $dob = $s->date_of_birth?->format('Y-m-d') ?? '';
            $placeOfBirth = $s->place_of_birth ?? '';
            $address = $s->address ?? '';
            $khmerName = $s->khmer_name ?? '';
            $englishName = $s->english_name ?? '';
            $resolvedDisplayName = PersonNameHelper::displayName(
                $s->english_name,
                $s->khmer_name,
                $s->first_name,
                $s->last_name,
                $user->name ?: $code
            );
            $familyInfo = $s->familyInformation;
            $emergencyContact = $s->emergencyContacts?->first();

            if ($detailed) {
                $details = AttendanceDetail::with('attendance.course')
                    ->where('student_id', $s->id)
                    ->orderByDesc('id')
                    ->get();
                $totalAtt = $details->count();
                $presentAtt = $details->where('status', 'present')->count();
                $lateAtt = $details->where('status', 'late')->count();
                $absentAtt = $details->where('status', 'absent')->count();
                $excusedAtt = $details->where('status', 'excused')->count();
                $rate = $totalAtt > 0 ? round((($presentAtt + $lateAtt) / $totalAtt) * 100, 1) : 0.0;

                $attendanceSummary = [
                    'total_classes' => $totalAtt,
                    'present' => $presentAtt,
                    'absent' => $absentAtt,
                    'late' => $lateAtt,
                    'excused' => $excusedAtt,
                    'attendance_rate' => $rate,
                ];

                $attendanceRecords = $details->take(50)->map(function ($detail) {
                    $att = $detail->attendance;
                    $course = $att?->course;
                    return [
                        'id' => $detail->id,
                        'date' => $att?->attendance_date?->format('Y-m-d') ?? '',
                        'course_code' => $course?->code ?? $course?->course_code ?? '',
                        'course_name' => $course?->name ?? $course?->course_name ?? '',
                        'status' => $detail->status ?? '',
                        'check_in_time' => $att?->start_time ?? '',
                        'remarks' => $detail->remarks ?? '',
                    ];
                })->values()->all();

                if (Schema::hasTable('student_notes')) {
                    $studentNotes = StudentNote::with('author:id,name,role')
                        ->where('student_id', $s->id)
                        ->orderByDesc('updated_at')
                        ->orderByDesc('id')
                        ->get()
                        ->map(fn ($n) => [
                            'id' => $n->id,
                            'student_id' => $n->student_id,
                            'author_id' => $n->author_id,
                            'author_name' => $n->author?->name ?? 'Administrator',
                            'author_role' => $n->author?->role ?? 'admin',
                            'note' => $n->note,
                            'created_at' => $n->created_at?->toIso8601String(),
                            'updated_at' => $n->updated_at?->toIso8601String(),
                            'can_edit' => true,
                            'can_delete' => true,
                        ])->values()->all();
                }

                $latestGpa = $s->gpaRecords?->sortByDesc('id')->first();
                $gpaVal = $latestGpa ? (float) ($latestGpa->cumulative_gpa ?? $latestGpa->semester_gpa ?? $latestGpa->gpa ?? 0) : 0.0;
                $gpaSummary = [
                    'gpa' => round($gpaVal, 2),
                    'standing' => $gpaVal >= 3.5 ? "Dean's List" : ($gpaVal >= 2.0 ? 'Good Standing' : ($gpaVal > 0 ? 'Academic Probation' : 'Enrolled')),
                ];

                if ($s->relationLoaded('courses')) {
                    $enrolledCourses = $s->courses->map(fn ($c) => [
                        'id' => $c->id,
                        'code' => $c->code ?? $c->course_code,
                        'name' => $c->name ?? $c->course_name,
                        'credits' => $c->credits ?? $c->credit,
                        'status' => $c->pivot?->status ?? 'enrolled',
                        'grade' => $c->pivot?->grade,
                        'final_score' => $c->pivot?->final_score,
                        'teacher_name' => $c->teacher?->user?->name ?? trim(($c->teacher?->first_name ?? '') . ' ' . ($c->teacher?->last_name ?? '')),
                    ])->values()->all();
                }
            }
        } elseif ($user->teacher) {
            $t = $user->teacher;
            $code = $t->teacher_code ?? $t->teacher_id ?? ('TCH' . str_pad((string) $t->id, 3, '0', STR_PAD_LEFT));
            $khmerName = $t->khmer_name ?? '';
            $englishName = $t->english_name ?? '';
            $resolvedDisplayName = PersonNameHelper::displayName(
                $t->english_name,
                $t->khmer_name,
                $t->first_name,
                $t->last_name,
                $user->name ?: $code
            );
            $deptId = $t->department_id;
            $deptName = $t->department?->name ?? '';
            $deptCode = $t->department?->code ?? '';
            $gender = $t->gender ?? '';
            $phone = $t->phone ?? '';
            $dob = $t->date_of_birth ? $t->date_of_birth->format('Y-m-d') : '';
            $position = $t->position ?? 'Lecturer';
            $workType = $t->work_type ?? 'Full-time';
            $officeLocationId = $t->office_location_id;
            $officeLocation = $t->office?->display_name ?? '';
            $biography = $t->biography ?? '';
            $specializations = $t->specializations;
            $specialization = $specializations !== [] ? implode(', ', $specializations) : ($t->specialization ?? '');
        } elseif ($user->admin) {
            $a = $user->admin;
            $code = $a->admin_code ?? $a->admin_id ?? ('ADM' . str_pad((string) $a->id, 3, '0', STR_PAD_LEFT));
            $deptName = $a->department ?? 'Administration';
            $phone = $a->phone ?? '';
            $position = $a->position ?? 'System Administrator';
        } else {
            $code = 'USR' . str_pad((string) $user->id, 3, '0', STR_PAD_LEFT);
        }

        return [
            'id' => $user->id,
            'name' => $resolvedDisplayName ?? ($user->name ?: $code),
            'email' => $user->email ?? '',
            'raw_email' => $user->email,
            'role' => $user->role,
            'status' => $user->status ?? 'active',
            'code' => $code,
            'user_code' => $code,
            'student_code' => $code,
            'student_id' => $code,
            'student_identifier' => $code,
            'teacher_code' => $code,
            'admin_code' => $code,
            'khmer_name' => $khmerName,
            'english_name' => $englishName,
            'hire_date' => $user->teacher?->hire_date?->format('Y-m-d') ?? '',
            'gender' => $gender,
            'phone' => $phone,
            'date_of_birth' => $dob,
            'place_of_birth' => $placeOfBirth,
            'current_address' => $address,
            'address' => $address,
            'department_id' => $deptId,
            'department_name' => $deptName,
            'department_code' => $deptCode,
            'faculty_id' => $deptId,
            'faculty_name' => $deptName,
            'faculty_code' => $deptCode,
            'program_id' => $programId,
            'program_name' => $programName,
            'program_code' => $programCode,
            'major_id' => $programId,
            'major_name' => $programName,
            'major_code' => $programCode,
            'year_level' => $yearLevel,
            'year_level_code' => $yearLevelCode,
            'year_level_name' => $yearLevelName,
            'year_level_khmer' => $yearLevelKhmer,
            'year_level_label' => $yearLevelLabel,
            'academic_year_id' => $academicYearId,
            'academic_year_name' => $academicYearName,
            'class_id' => $classId,
            'class_code' => $classCode,
            'class_name' => $user->student?->classRoom?->name ?? $user->student?->classRoom?->code ?? '',
            'semester_id' => $semesterId,
            'semester_name' => $semesterName,
            'position' => $position,
            'work_type' => $workType,
            'office_location_id' => $officeLocationId,
            'office_location' => $officeLocation,
            'bio' => $biography,
            'biography' => $biography,
            'specialization' => $specialization,
            'specializations' => $specializations,
            'family_information' => $familyInfo,
            'emergency_contact' => $emergencyContact,
            'attendance_summary' => $attendanceSummary,
            'attendance_records' => $attendanceRecords,
            'gpa_summary' => $gpaSummary,
            'enrolled_courses' => $enrolledCourses,
            'enrolled_courses_count' => count($enrolledCourses),
            'notes' => $studentNotes,
            'profile_picture' => $user->profile_picture_url,
            'login_id' => $code,
            'teacher_profile_status' => $user->teacher ? strtolower((string) ($user->teacher->status ?: 'active')) : null,
            'login_account_status' => strtolower((string) ($user->status ?: 'active')),
            'account_created' => true,
            'account_linked' => $user->teacher
                ? (strtolower((string) $user->role) === 'teacher' && ! empty($user->password))
                : true,
            'can_login' => $user->teacher
                ? (strtolower((string) ($user->status ?: 'active')) === 'active'
                    && strtolower((string) ($user->teacher->status ?: 'active')) === 'active'
                    && strtolower((string) $user->role) === 'teacher'
                    && ! empty($user->password))
                : (strtolower((string) ($user->status ?: 'active')) === 'active'),
            'student' => $user->student,
            'teacher' => $user->teacher,
            'admin' => $user->admin,
            'created_at' => $user->created_at?->toIso8601String(),
            'updated_at' => $user->updated_at?->toIso8601String(),
        ];
    }

    public function store(Request $request, AcademicProfileResolver $resolver, TeacherProfileService $profiles, TeacherAccountService $teacherAccounts)
    {
        if ($request->exists('email') && trim((string) $request->input('email')) === '') {
            $request->merge(['email' => null]);
        }
        if ($request->exists('date_of_birth') && trim((string) $request->input('date_of_birth')) === '') {
            $request->merge(['date_of_birth' => null]);
        }
        if ($request->exists('hire_date') && trim((string) $request->input('hire_date')) === '') {
            $request->merge(['hire_date' => null]);
        }
        $this->normalizeAcademicAliases($request);

        $role = strtolower($request->input('role', 'student'));

        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'khmer_name' => 'nullable|string|max:150',
            'english_name' => 'nullable|string|max:150',
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => in_array($role, ['student', 'teacher', 'faculty'], true) ? 'nullable|string|max:100' : 'required|string|min:8|confirmed',
            'role' => 'required|string|in:student,teacher,admin,faculty',
            'status' => 'nullable|string|in:active,inactive,suspended,enrolled',
            'student_code' => 'nullable|string|max:50|unique:students,student_code',
            'student_id' => 'nullable|string|max:50',
            'teacher_code' => 'nullable|string|max:50|unique:teachers,teacher_code',
            'teacher_id' => 'nullable|string|max:50',
            'admin_code' => 'nullable|string|max:50|unique:admins,admin_code',
            'admin_id' => 'nullable|string|max:50',
            'code' => 'nullable|string|max:50',
            'user_code' => 'nullable|string|max:50',
            'gender' => 'nullable|string|max:20',
            'phone' => 'nullable|string|max:30',
            'date_of_birth' => 'nullable|date',
            'hire_date' => 'nullable|date',
            'department_id' => 'nullable|integer|exists:departments,id',
            'department' => 'nullable|string|max:255',
            'department_name' => 'nullable|string|max:255',
            'program_id' => 'nullable|integer|exists:programs,id',
            'program' => 'nullable|string|max:255',
            'major' => 'nullable|string|max:255',
            'year_level' => ['nullable', new ValidYearLevel()],
            'academic_year_id' => 'nullable|integer|exists:academic_years,id',
            'academic_year' => 'nullable|string|max:50',
            'class_id' => 'nullable|integer|exists:classes,id',
            'semester_id' => 'nullable|integer|exists:semesters,id',
            'position' => 'nullable|string|max:255',
            'work_type' => 'nullable|string|max:50',
            'office_location_id' => 'nullable|integer|exists:office_locations,id',
            'office_location' => 'nullable|string|max:191',
            'specialization' => 'nullable|string|max:1000',
            'specializations' => 'nullable|array',
            'specializations.*' => 'nullable|string|max:120',
            'biography' => 'nullable|string|max:2000',
            'bio' => 'nullable|string|max:2000',
        ], [], [
            'department_id' => 'faculty',
            'program_id' => 'major',
        ]);

        $khmerName = PersonNameHelper::clean($validated['khmer_name'] ?? null);
        $englishName = PersonNameHelper::clean($validated['english_name'] ?? null);
        $rawName = PersonNameHelper::clean($validated['name'] ?? null);
        if ($khmerName === null && $englishName === null) {
            if ($rawName === null) {
                throw ValidationException::withMessages([
                    'name' => ['At least one of Khmer Name or English Name is required.'],
                ]);
            }
            if (in_array($role, ['student', 'teacher', 'faculty'], true)) {
                if (preg_match('/\p{Khmer}/u', $rawName)) {
                    $khmerName = $rawName;
                } else {
                    $englishName = $rawName;
                }
            }
        }
        $displayName = PersonNameHelper::displayName($englishName, $khmerName, $rawName, null, '');

        $status = $validated['status'] ?? 'active';

        if (in_array($role, ['teacher', 'faculty'], true)) {
            $suppliedCode = trim((string) ($validated['teacher_code'] ?? $validated['teacher_id'] ?? $validated['code'] ?? $validated['user_code'] ?? ''));
            if ($suppliedCode !== '') {
                $teacherAccounts->assertUniqueLoginCode($suppliedCode, null, 'teacher_code');
            }
        }

        return DB::transaction(function () use ($request, $validated, $role, $status, $resolver, $profiles, $teacherAccounts, $khmerName, $englishName, $displayName) {
            if ($role === 'student') {
                $rawPassword = (string) config('student_import.default_initial_password', 'STD@12345');
            } elseif (in_array($role, ['teacher', 'faculty'], true)) {
                $rawPassword = (string) config('slas.default_teacher_password', 'TCH@12345');
            } else {
                $rawPassword = $validated['password'];
            }

            $user = User::create([
                'name' => $displayName,
                'email' => ! empty($validated['email']) ? strtolower(trim($validated['email'])) : null,
                'password' => Hash::make($rawPassword),
                'role' => $role === 'faculty' ? 'teacher' : $role,
                'status' => $status,
            ]);

            $departmentId = $resolver->resolveDepartmentId(
                $validated['department_id'] ?? null,
                $validated['department_name'] ?? $validated['department'] ?? 'Computer Science'
            );

            [$firstName, $lastName] = PersonNameHelper::splitFirstLast($displayName);

            if ($role === 'student') {
                $studentCode = $validated['student_code'] ?? $validated['student_id'] ?? $validated['code'] ?? $validated['user_code'] ?? ('STU' . str_pad((string) $user->id, 4, '0', STR_PAD_LEFT));
                $yearLevelStorage = array_key_exists('year_level', $validated) && $validated['year_level'] !== null && $validated['year_level'] !== ''
                    ? YearLevel::storageValue($validated['year_level'])
                    : null;

                $programId = $validated['program_id'] ?? null;
                $majorName = $validated['major'] ?? $validated['program'] ?? null;
                if (! $programId && ! empty($majorName)) {
                    $prog = Program::where('department_id', $departmentId)
                        ->where(fn ($q) => $q->where('name', $majorName)->orWhere('code', $majorName))
                        ->first()
                        ?? Program::where('name', $majorName)->orWhere('code', $majorName)->first();
                    $programId = $prog?->id;
                } elseif ($programId && ! $majorName) {
                    $majorName = Program::where('id', $programId)->value('name');
                }

                if ($departmentId && $programId) {
                    $progObj = Program::find($programId);
                    if ($progObj && $progObj->department_id
                        && ! StudentImportValidationService::areDepartmentAndProgramCompatible((int) $progObj->department_id, (int) $departmentId)) {
                        throw ValidationException::withMessages([
                            'program_id' => ['Selected major does not belong to the selected faculty.'],
                        ]);
                    }
                }

                $classId = $validated['class_id'] ?? null;
                $ayId = $validated['academic_year_id'] ?? null;
                if (! $ayId && ! empty($validated['academic_year'])) {
                    $ayId = AcademicYear::where('name', str_replace('–', '-', trim($validated['academic_year'])))->value('id');
                }
                if (! $classId && $departmentId && $ayId && $yearLevelStorage !== null) {
                    $dept = Department::find($departmentId);
                    $ay = AcademicYear::find($ayId);
                    if ($dept && $ay) {
                        $lvlInt = (int) $yearLevelStorage;
                        $code = substr("{$dept->code}-Y{$lvlInt}-{$ay->name}", 0, 40);
                        $classRoom = ClassRoom::firstOrCreate(['code' => $code], [
                            'department_id' => $dept->id,
                            'academic_year_id' => $ay->id,
                            'name' => substr("{$dept->name} Year {$lvlInt} ({$ay->name})", 0, 100),
                            'year_level' => $lvlInt,
                            'status' => 'active',
                        ]);
                        $classId = $classRoom->id;
                    }
                }

                Student::create([
                    'user_id' => $user->id,
                    'student_code' => $studentCode,
                    'khmer_name' => $khmerName,
                    'english_name' => $englishName,
                    'department_id' => $departmentId,
                    'program_id' => $programId,
                    'class_id' => $classId,
                    'major' => $majorName,
                    'year_level' => $yearLevelStorage,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'date_of_birth' => $validated['date_of_birth'] ?? null,
                    'gender' => $validated['gender'] ?? 'Male',
                    'phone' => $validated['phone'] ?? null,
                    'status' => $status === 'active' ? 'enrolled' : 'inactive',
                ]);
            } elseif ($role === 'teacher' || $role === 'faculty') {
                $rawTeacherCode = trim((string) ($validated['teacher_code'] ?? $validated['teacher_id'] ?? $validated['code'] ?? $validated['user_code'] ?? ''));
                $teacherCode = $rawTeacherCode !== ''
                    ? $teacherAccounts->normalizeCode($rawTeacherCode)
                    : ('TCH' . str_pad((string) $user->id, 5, '0', STR_PAD_LEFT));
                $officeId = $profiles->resolveOfficeLocationId(
                    $validated['office_location_id'] ?? null,
                    $validated['office_location'] ?? null,
                    $request->user()?->id
                );
                $teacher = Teacher::create([
                    'user_id' => $user->id,
                    'teacher_code' => $teacherCode,
                    'khmer_name' => $khmerName,
                    'english_name' => $englishName,
                    'department_id' => $departmentId,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'date_of_birth' => $validated['date_of_birth'] ?? null,
                    'hire_date' => $validated['hire_date'] ?? null,
                    'gender' => $validated['gender'] ?? 'Male',
                    'phone' => $validated['phone'] ?? null,
                    'office_location_id' => $officeId,
                    'position' => $validated['position'] ?? 'Lecturer',
                    'work_type' => $validated['work_type'] ?? 'Full-time',
                    'specialization' => null,
                    'biography' => $profiles->cleanBiography($validated['biography'] ?? $validated['bio'] ?? null),
                    'status' => $status,
                ]);
                if (array_key_exists('specializations', $validated) || array_key_exists('specialization', $validated)) {
                    $profiles->syncSpecializations(
                        $teacher,
                        $validated['specializations'] ?? null,
                        $validated['specialization'] ?? null,
                        $request->user()?->id
                    );
                }
            } elseif ($role === 'admin') {
                $adminCode = $validated['admin_code'] ?? $validated['admin_id'] ?? $validated['code'] ?? $validated['user_code'] ?? ('ADM' . str_pad((string) $user->id, 5, '0', STR_PAD_LEFT));
                Admin::create([
                    'user_id' => $user->id,
                    'admin_code' => $adminCode,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'department' => $validated['department'] ?? 'Administration',
                    'position' => $validated['position'] ?? 'System Administrator',
                    'phone' => $validated['phone'] ?? null,
                    'status' => $status,
                ]);
            }

            return response()->json([
                'message' => 'User created successfully.',
                'data' => $this->formatUserPayload($user->fresh(['student.department', 'teacher.department', 'teacher.office', 'teacher.specializationItems', 'admin']), true),
            ], 201);
        });
    }

    public function update(Request $request, string|int $user, AcademicProfileResolver $resolver, TeacherProfileService $profiles, TeacherAccountService $teacherAccounts)
    {
        $user = $this->resolveUser($user);
        if ($request->exists('email') && trim((string) $request->input('email')) === '') {
            $request->merge(['email' => null]);
        }
        if ($request->exists('date_of_birth') && trim((string) $request->input('date_of_birth')) === '') {
            $request->merge(['date_of_birth' => null]);
        }
        if ($request->exists('hire_date') && trim((string) $request->input('hire_date')) === '') {
            $request->merge(['hire_date' => null]);
        }
        $this->normalizeAcademicAliases($request);

        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'english_name' => 'nullable|string|max:150',
            'khmer_name' => 'nullable|string|max:150',
            'first_name' => 'nullable|string|max:100',
            'last_name' => 'nullable|string|max:100',
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => 'nullable|string|min:8|confirmed',
            'role' => 'nullable|string|in:student,teacher,admin,faculty',
            'status' => 'nullable|string|in:active,inactive,suspended,enrolled,graduated',
            'student_identifier' => 'nullable|string|max:50',
            'student_code' => 'nullable|string|max:50',
            'teacher_code' => 'nullable|string|max:50',
            'admin_code' => 'nullable|string|max:50',
            'gender' => 'nullable|string|max:20',
            'phone' => 'nullable|string|max:30',
            'date_of_birth' => 'nullable|date',
            'hire_date' => 'nullable|date',
            'place_of_birth' => 'nullable|string|max:190',
            'current_address' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255',
            'department_id' => 'nullable|integer|exists:departments,id',
            'department' => 'nullable|string|max:255',
            'department_name' => 'nullable|string|max:255',
            'program_id' => 'nullable|integer|exists:programs,id',
            'program' => 'nullable|string|max:255',
            'major' => 'nullable|string|max:255',
            'year_level' => ['nullable', new ValidYearLevel()],
            'academic_year_id' => 'nullable|integer|exists:academic_years,id',
            'academic_year' => 'nullable|string|max:50',
            'class_id' => 'nullable|integer|exists:classes,id',
            'semester_id' => 'nullable|integer|exists:semesters,id',
            'position' => 'nullable|string|max:255',
            'work_type' => 'nullable|string|max:50',
            'office_location_id' => 'nullable|integer|exists:office_locations,id',
            'office_location' => 'nullable|string|max:191',
            'specialization' => 'nullable|string|max:1000',
            'specializations' => 'nullable|array',
            'specializations.*' => 'nullable|string|max:120',
            'biography' => 'nullable|string|max:2000',
            'bio' => 'nullable|string|max:2000',
            'father_khmer_name' => 'nullable|string|max:150',
            'father_english_name' => 'nullable|string|max:150',
            'father_phone' => 'nullable|string|max:30',
            'mother_khmer_name' => 'nullable|string|max:150',
            'mother_english_name' => 'nullable|string|max:150',
            'mother_phone' => 'nullable|string|max:30',
            'emergency_contact_name' => 'nullable|string|max:150',
            'emergency_relationship' => 'nullable|string|max:80',
            'emergency_contact_phone' => 'nullable|string|max:30',
            'emergency_contact_address' => 'nullable|string|max:255',
        ], [], [
            'department_id' => 'faculty',
            'program_id' => 'major',
        ]);

        if ($user->teacher && $request->filled('teacher_code')) {
            $teacherAccounts->assertUniqueLoginCode((string) $request->input('teacher_code'), $user->teacher->id, 'teacher_code');
        }

        $resolvedProfileNames = null;
        if ($user->student || $user->teacher) {
            $profileModel = $user->student ?? $user->teacher;
            $nextKhmer = array_key_exists('khmer_name', $validated)
                ? PersonNameHelper::clean($validated['khmer_name'])
                : $profileModel->khmer_name;
            $nextEnglish = array_key_exists('english_name', $validated)
                ? PersonNameHelper::clean($validated['english_name'])
                : $profileModel->english_name;

            $explicitNameUpdate = array_key_exists('khmer_name', $validated) || array_key_exists('english_name', $validated);
            if (! $explicitNameUpdate && array_key_exists('name', $validated)) {
                $cleanedName = PersonNameHelper::clean($validated['name']);
                if ($cleanedName === null) {
                    throw ValidationException::withMessages(['name' => ['At least one of Khmer Name or English Name is required.']]);
                }
                if ($nextEnglish !== null || $nextKhmer === null) {
                    $nextEnglish = $cleanedName;
                } else {
                    $nextKhmer = $cleanedName;
                }
            }

            $nextDisplay = PersonNameHelper::displayName(
                $nextEnglish,
                $nextKhmer,
                ($explicitNameUpdate || array_key_exists('name', $validated)) ? null : $profileModel->first_name,
                ($explicitNameUpdate || array_key_exists('name', $validated)) ? null : $profileModel->last_name,
                ''
            );

            if ($nextDisplay === '') {
                throw ValidationException::withMessages([
                    'english_name' => ['At least one of Khmer Name or English Name is required.'],
                ]);
            }

            $resolvedProfileNames = [
                'khmer' => $nextKhmer,
                'english' => $nextEnglish,
                'display' => $nextDisplay,
            ];
        }

        DB::transaction(function () use ($request, $validated, $user, $resolver, $profiles, $teacherAccounts, $resolvedProfileNames) {
            $displayName = $resolvedProfileNames['display'] ?? ($validated['english_name'] ?? $validated['name'] ?? null);
            if (!$displayName && (!empty($validated['first_name']) || !empty($validated['last_name']))) {
                $displayName = trim(($validated['first_name'] ?? '') . ' ' . ($validated['last_name'] ?? ''));
            }
            if ($displayName) {
                $user->name = $displayName;
            }
            if (array_key_exists('email', $validated)) {
                $user->email = ! empty($validated['email']) ? strtolower(trim((string) $validated['email'])) : null;
            }
            if (!empty($validated['password'])) {
                $user->password = Hash::make($validated['password']);
            }
            if (isset($validated['role'])) {
                $user->role = $validated['role'] === 'faculty' ? 'teacher' : $validated['role'];
            }
            if (isset($validated['status'])) {
                $rawStatus = strtolower($validated['status']);
                $user->status = in_array($rawStatus, ['enrolled', 'active'], true) ? 'active' : 'inactive';
            }
            $user->save();

            $nameParts = explode(' ', trim($user->name), 2);
            $firstName = $validated['first_name'] ?? $nameParts[0];
            $lastName = $validated['last_name'] ?? ($nameParts[1] ?? $firstName);

            if ($user->student) {
                $s = $user->student;
                $deptId = $resolver->resolveDepartmentId(
                    $validated['department_id'] ?? null,
                    $validated['department_name'] ?? $validated['department'] ?? null
                ) ?? $s->department_id;

                $programId = $validated['program_id'] ?? $s->program_id;
                $programName = $validated['program'] ?? $validated['major'] ?? null;
                if (empty($validated['program_id']) && $programName) {
                    $prog = Program::where('department_id', $deptId)
                        ->where(fn ($q) => $q->where('name', $programName)->orWhere('code', $programName))
                        ->first()
                        ?? Program::where('name', $programName)->orWhere('code', $programName)->first();
                    if ($prog) {
                        $programId = $prog->id;
                        $programName = $prog->name;
                    }
                } elseif ($programId) {
                    $programName = Program::where('id', $programId)->value('name') ?? $s->major;
                }

                if ($request->hasAny(['department_id', 'department', 'department_name', 'program_id', 'program', 'major'])
                    && $deptId && $programId) {
                    $progObj = Program::find($programId);
                    if ($progObj && $progObj->department_id
                        && ! StudentImportValidationService::areDepartmentAndProgramCompatible((int) $progObj->department_id, (int) $deptId)) {
                        throw ValidationException::withMessages([
                            'program_id' => ['Selected major does not belong to the selected faculty.'],
                        ]);
                    }
                }

                $previousYearLevel = $s->year_level;
                $yearLevel = $s->year_level;
                if (array_key_exists('year_level', $validated)) {
                    $yearLevel = ($validated['year_level'] !== null && $validated['year_level'] !== '')
                        ? YearLevel::storageValue($validated['year_level'])
                        : null;
                }

                $classId = $validated['class_id'] ?? $s->class_id;
                $ayId = $validated['academic_year_id'] ?? null;
                if (!$ayId && !empty($validated['academic_year'])) {
                    $ayName = str_replace('–', '-', $validated['academic_year']);
                    $ayId = AcademicYear::where('name', $ayName)->value('id');
                }
                if (empty($validated['class_id']) && $deptId && $ayId && $yearLevel) {
                    $dept = Department::find($deptId);
                    $ay = AcademicYear::find($ayId);
                    if ($dept && $ay) {
                        $lvlInt = (int) YearLevel::parse($yearLevel);
                        if ($lvlInt >= 1) {
                            $code = substr("{$dept->code}-Y{$lvlInt}-{$ay->name}", 0, 40);
                            $classRoom = ClassRoom::firstOrCreate(['code' => $code], [
                                'department_id' => $dept->id,
                                'academic_year_id' => $ay->id,
                                'name' => substr("{$dept->name} Year {$lvlInt} ({$ay->name})", 0, 100),
                                'year_level' => $lvlInt,
                                'status' => 'active',
                            ]);
                            $classId = $classRoom->id;
                        }
                    }
                }

                $studentStatus = $s->status;
                if (isset($validated['status'])) {
                    $rawStatus = strtolower($validated['status']);
                    $studentStatus = $rawStatus === 'active' ? 'enrolled' : $rawStatus;
                }

                $newCode = $validated['student_identifier'] ?? $validated['student_code'] ?? $s->student_code;
                [$splitFirst, $splitLast] = PersonNameHelper::splitFirstLast($resolvedProfileNames['display']);
                $nextDob = array_key_exists('date_of_birth', $validated)
                    ? ($validated['date_of_birth'] !== null ? Carbon::parse((string) $validated['date_of_birth'])->toDateString() : null)
                    : $s->date_of_birth;

                $s->update([
                    'student_code' => $newCode,
                    'khmer_name' => $resolvedProfileNames['khmer'],
                    'english_name' => $resolvedProfileNames['english'],
                    'first_name' => $splitFirst,
                    'last_name' => $splitLast,
                    'gender' => $validated['gender'] ?? $s->gender,
                    'phone' => array_key_exists('phone', $validated) ? $validated['phone'] : $s->phone,
                    'date_of_birth' => $nextDob,
                    'place_of_birth' => array_key_exists('place_of_birth', $validated) ? $validated['place_of_birth'] : $s->place_of_birth,
                    'address' => $validated['current_address'] ?? $validated['address'] ?? $s->address,
                    'department_id' => $deptId,
                    'program_id' => $programId,
                    'class_id' => $classId,
                    'major' => $programName ?? $s->major,
                    'year_level' => $yearLevel,
                    'status' => $studentStatus,
                ]);

                if ((string) $previousYearLevel !== (string) $yearLevel) {
                    Log::info('Admin updated student year level via admin/users', [
                        'admin_id' => $request->user()?->id,
                        'student_id' => $s->id,
                        'student_code' => $s->student_code,
                        'from_year_level' => $previousYearLevel,
                        'to_year_level' => $yearLevel,
                    ]);
                }

                $hasFamilyFields = collect([
                    'father_khmer_name', 'father_english_name', 'father_phone',
                    'mother_khmer_name', 'mother_english_name', 'mother_phone',
                ])->contains(fn ($k) => array_key_exists($k, $validated));

                if ($hasFamilyFields) {
                    StudentFamilyInformation::updateOrCreate(
                        ['student_id' => $s->id],
                        [
                            'father_khmer_name' => $validated['father_khmer_name'] ?? $s->familyInformation?->father_khmer_name,
                            'father_english_name' => $validated['father_english_name'] ?? $s->familyInformation?->father_english_name,
                            'father_phone' => $validated['father_phone'] ?? $s->familyInformation?->father_phone,
                            'mother_khmer_name' => $validated['mother_khmer_name'] ?? $s->familyInformation?->mother_khmer_name,
                            'mother_english_name' => $validated['mother_english_name'] ?? $s->familyInformation?->mother_english_name,
                            'mother_phone' => $validated['mother_phone'] ?? $s->familyInformation?->mother_phone,
                        ]
                    );
                }

                $hasEmergencyFields = collect([
                    'emergency_contact_name', 'emergency_relationship',
                    'emergency_contact_phone', 'emergency_contact_address',
                ])->contains(fn ($k) => array_key_exists($k, $validated));

                if ($hasEmergencyFields && !empty($validated['emergency_contact_name'])) {
                    $existingEm = $s->emergencyContacts()->first();
                    if ($existingEm) {
                        $existingEm->update([
                            'name' => $validated['emergency_contact_name'],
                            'relationship' => $validated['emergency_relationship'] ?? $existingEm->relationship,
                            'phone' => $validated['emergency_contact_phone'] ?? $existingEm->phone,
                            'address' => $validated['emergency_contact_address'] ?? $existingEm->address,
                        ]);
                    } else {
                        StudentEmergencyContact::create([
                            'student_id' => $s->id,
                            'name' => $validated['emergency_contact_name'],
                            'relationship' => $validated['emergency_relationship'] ?? null,
                            'phone' => $validated['emergency_contact_phone'] ?? null,
                            'address' => $validated['emergency_contact_address'] ?? null,
                        ]);
                    }
                }
            }

            if ($user->teacher) {
                $t = $user->teacher;
                $deptId = $resolver->resolveDepartmentId(
                    $validated['department_id'] ?? null,
                    $validated['department_name'] ?? $validated['department'] ?? null
                ) ?? $t->department_id;

                $officeId = $t->office_location_id;
                if (array_key_exists('office_location_id', $validated) || array_key_exists('office_location', $validated)) {
                    $officeId = $profiles->resolveOfficeLocationId(
                        $validated['office_location_id'] ?? null,
                        $validated['office_location'] ?? null,
                        $request->user()?->id
                    );
                }

                $biography = $t->biography;
                if (array_key_exists('biography', $validated) || array_key_exists('bio', $validated)) {
                    $biography = $profiles->cleanBiography($validated['biography'] ?? $validated['bio'] ?? null);
                }

                [$splitTFirst, $splitTLast] = PersonNameHelper::splitFirstLast($resolvedProfileNames['display']);
                $nextTDob = array_key_exists('date_of_birth', $validated)
                    ? ($validated['date_of_birth'] !== null ? Carbon::parse((string) $validated['date_of_birth'])->toDateString() : null)
                    : $t->date_of_birth;
                $nextTHire = array_key_exists('hire_date', $validated)
                    ? ($validated['hire_date'] !== null ? Carbon::parse((string) $validated['hire_date'])->toDateString() : null)
                    : $t->hire_date;

                $t->update([
                    'teacher_code' => isset($validated['teacher_code']) && trim($validated['teacher_code']) !== ''
                        ? $teacherAccounts->normalizeCode($validated['teacher_code'])
                        : $t->teacher_code,
                    'khmer_name' => $resolvedProfileNames['khmer'],
                    'english_name' => $resolvedProfileNames['english'],
                    'first_name' => $splitTFirst,
                    'last_name' => $splitTLast,
                    'department_id' => $deptId,
                    'date_of_birth' => $nextTDob,
                    'hire_date' => $nextTHire,
                    'gender' => $validated['gender'] ?? $t->gender,
                    'phone' => array_key_exists('phone', $validated) ? $validated['phone'] : $t->phone,
                    'office_location_id' => $officeId,
                    'position' => $validated['position'] ?? $t->position,
                    'work_type' => $validated['work_type'] ?? $t->work_type,
                    'biography' => $biography,
                    'status' => $user->status,
                ]);

                if (array_key_exists('specializations', $validated) || array_key_exists('specialization', $validated)) {
                    $profiles->syncSpecializations(
                        $t,
                        $validated['specializations'] ?? null,
                        $validated['specialization'] ?? null,
                        $request->user()?->id
                    );
                }
            }

            if ($user->admin) {
                $a = $user->admin;
                $a->update([
                    'admin_code' => $validated['admin_code'] ?? $a->admin_code,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'department' => $validated['department'] ?? $a->department,
                    'position' => $validated['position'] ?? $a->position,
                    'phone' => array_key_exists('phone', $validated) ? $validated['phone'] : $a->phone,
                    'status' => $user->status,
                ]);
            }

            if ($user->status !== 'active') {
                $user->tokens()->delete();
            }
        });

        $user->load([
            'student.department',
            'student.program',
            'student.classRoom.academicYear',
            'student.courseEnrollments.semester.academicYear',
            'student.familyInformation',
            'student.emergencyContacts',
            'student.courses.teacher.user',
            'student.gpaRecords.semester',
            'teacher.department',
            'teacher.office',
            'teacher.specializationItems',
            'teacher.courses',
            'teacher.classes',
            'admin',
        ]);

        return response()->json([
            'message' => 'User updated successfully.',
            'data' => $this->formatUserPayload($user, true),
        ], 200);
    }

    public function updateStatus(Request $request, string|int $user)
    {
        $user = $this->resolveUser($user);
        $validated = $request->validate([
            'status' => 'required|string|in:active,inactive,suspended,enrolled',
        ]);

        $status = strtolower($validated['status']);
        $user->status = in_array($status, ['enrolled', 'active'], true) ? 'active' : 'inactive';
        $user->save();

        if ($user->student) {
            $user->student->update(['status' => $user->status === 'active' ? 'enrolled' : 'inactive']);
        }
        if ($user->teacher) {
            $user->teacher->update(['status' => $user->status]);
        }
        if ($user->admin) {
            $user->admin->update(['status' => $user->status]);
        }
        if ($user->status !== 'active') {
            $user->tokens()->delete();
        }

        return response()->json([
            'message' => 'User status updated successfully.',
            'data' => [
                'id' => $user->id,
                'status' => $user->status,
            ],
        ], 200);
    }

    public function destroy(Request $request, string|int $user)
    {
        $user = $this->resolveUser($user);
        $authUser = $request->user();
        if ($authUser && $authUser->id === $user->id) {
            return response()->json([
                'message' => 'Self-deletion is prohibited. You cannot delete your own account.',
            ], 403);
        }

        DB::transaction(function () use ($user) {
            if ($user->student) $user->student->delete();
            if ($user->teacher) $user->teacher->delete();
            if ($user->admin) $user->admin->delete();
            $user->delete();
        });

        return response()->json([
            'message' => 'User deleted successfully.',
        ], 200);
    }

    private function normalizeAcademicAliases(Request $request): void
    {
        $merge = [];
        if (! $request->filled('department_id') && $request->filled('faculty_id')) {
            $merge['department_id'] = $request->input('faculty_id');
        }
        if (! $request->filled('department') && $request->filled('faculty')) {
            $merge['department'] = $request->input('faculty');
        }
        if (! $request->filled('department_name') && $request->filled('faculty_name')) {
            $merge['department_name'] = $request->input('faculty_name');
        }
        if (! $request->filled('program_id') && $request->filled('major_id')) {
            $merge['program_id'] = $request->input('major_id');
        }
        if (! $request->filled('major') && $request->filled('major_name')) {
            $merge['major'] = $request->input('major_name');
        }
        if ($merge !== []) {
            $request->merge($merge);
        }
    }
}
