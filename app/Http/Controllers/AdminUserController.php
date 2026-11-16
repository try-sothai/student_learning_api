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
use App\Services\AcademicProfileResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class AdminUserController extends Controller
{
    public function statistics(Request $request)
    {
        $totalStudents = Student::count();
        $activeStudents = Student::whereIn('status', ['enrolled', 'active'])->count();
        $inactiveStudents = Student::whereIn('status', ['inactive', 'suspended', 'graduated'])->count();

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
        $departmentFilter = trim((string) $request->query('department', $request->query('department_id', '')));
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

        $query = User::with([
            'student.department',
            'student.program',
            'student.classRoom.academicYear',
            'student.familyInformation',
            'student.emergencyContacts',
            'teacher.department',
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

        // Filter by department
        if ($departmentFilter !== '' && strtolower($departmentFilter) !== 'all' && strtolower($departmentFilter) !== 'all departments') {
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
                        ->orWhere('last_name', 'like', "%{$search}%");
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
            'student.familyInformation',
            'student.emergencyContacts',
            'student.courses.teacher.user',
            'student.gpaRecords.semester',
            'teacher.department',
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
        $yearLevel = '';
        $gender = '';
        $phone = '';
        $dob = '';
        $placeOfBirth = '';
        $address = '';
        $khmerName = '';
        $englishName = '';
        $position = '';
        $specialization = '';
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
            $academicYearName = $s->classRoom?->academicYear?->name ?? '';
            $yearLevel = (string) ($s->year_level ?? '');
            $gender = $s->gender ?? '';
            $phone = $s->phone ?? '';
            $dob = $s->date_of_birth?->format('Y-m-d') ?? '';
            $placeOfBirth = $s->place_of_birth ?? '';
            $address = $s->address ?? '';
            $khmerName = $s->khmer_name ?? '';
            $englishName = $s->english_name ?: ($user->name ?: trim($s->first_name . ' ' . $s->last_name));
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
            $deptId = $t->department_id;
            $deptName = $t->department?->name ?? '';
            $deptCode = $t->department?->code ?? '';
            $gender = $t->gender ?? '';
            $phone = $t->phone ?? '';
            $dob = $t->date_of_birth ? (string) $t->date_of_birth : '';
            $position = $t->position ?? 'Lecturer';
            $specialization = $t->specialization ?? '';
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
            'name' => $user->name,
            'email' => $user->email ?? $code,
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
            'english_name' => $englishName ?: $user->name,
            'gender' => $gender,
            'phone' => $phone,
            'date_of_birth' => $dob,
            'place_of_birth' => $placeOfBirth,
            'current_address' => $address,
            'address' => $address,
            'department_id' => $deptId,
            'department_name' => $deptName,
            'department_code' => $deptCode,
            'program_id' => $programId,
            'program_name' => $programName,
            'program_code' => $programCode,
            'year_level' => $yearLevel,
            'academic_year_id' => $academicYearId,
            'academic_year_name' => $academicYearName,
            'class_name' => $user->student?->classRoom?->name ?? $user->student?->classRoom?->code ?? '',
            'position' => $position,
            'specialization' => $specialization,
            'family_information' => $familyInfo,
            'emergency_contact' => $emergencyContact,
            'attendance_summary' => $attendanceSummary,
            'attendance_records' => $attendanceRecords,
            'gpa_summary' => $gpaSummary,
            'enrolled_courses' => $enrolledCourses,
            'enrolled_courses_count' => count($enrolledCourses),
            'notes' => $studentNotes,
            'profile_picture' => $user->profile_picture_url,
            'student' => $user->student,
            'teacher' => $user->teacher,
            'admin' => $user->admin,
            'created_at' => $user->created_at?->toIso8601String(),
            'updated_at' => $user->updated_at?->toIso8601String(),
        ];
    }

    public function store(Request $request, AcademicProfileResolver $resolver)
    {
        $role = strtolower($request->input('role', 'student'));

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => 'required|string|min:8|confirmed',
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
            'department_id' => 'nullable|integer|exists:departments,id',
            'department' => 'nullable|string|max:255',
            'position' => 'nullable|string|max:255',
            'specialization' => 'nullable|string|max:255',
        ]);

        $status = $validated['status'] ?? 'active';

        return DB::transaction(function () use ($validated, $role, $status, $resolver) {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'] ?? null,
                'password' => Hash::make($validated['password']),
                'role' => $role === 'faculty' ? 'teacher' : $role,
                'status' => $status,
            ]);

            $departmentId = $resolver->resolveDepartmentId(
                $validated['department_id'] ?? null,
                $validated['department'] ?? 'Computer Science'
            );

            $nameParts = explode(' ', trim($validated['name']), 2);
            $firstName = $nameParts[0];
            $lastName = $nameParts[1] ?? '';

            if ($role === 'student') {
                $studentCode = $validated['student_code'] ?? $validated['student_id'] ?? $validated['code'] ?? $validated['user_code'] ?? ('STU' . str_pad((string) $user->id, 4, '0', STR_PAD_LEFT));
                Student::create([
                    'user_id' => $user->id,
                    'student_code' => $studentCode,
                    'english_name' => $validated['name'],
                    'department_id' => $departmentId,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'gender' => $validated['gender'] ?? 'Male',
                    'phone' => $validated['phone'] ?? null,
                    'status' => $status === 'active' ? 'enrolled' : 'inactive',
                ]);
            } elseif ($role === 'teacher' || $role === 'faculty') {
                $teacherCode = $validated['teacher_code'] ?? $validated['teacher_id'] ?? $validated['code'] ?? $validated['user_code'] ?? ('TCH' . str_pad((string) $user->id, 5, '0', STR_PAD_LEFT));
                Teacher::create([
                    'user_id' => $user->id,
                    'teacher_code' => $teacherCode,
                    'department_id' => $departmentId,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'gender' => $validated['gender'] ?? 'Male',
                    'phone' => $validated['phone'] ?? null,
                    'position' => $validated['position'] ?? 'Lecturer',
                    'specialization' => $validated['specialization'] ?? null,
                    'status' => $status,
                ]);
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
                'data' => $this->formatUserPayload($user->fresh(['student.department', 'teacher.department', 'admin']), true),
            ], 201);
        });
    }

    public function update(Request $request, string|int $user, AcademicProfileResolver $resolver)
    {
        $user = $this->resolveUser($user);
        if ($request->filled('email') && !filter_var($request->input('email'), FILTER_VALIDATE_EMAIL)) {
            $request->merge(['email' => null]);
        }

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
            'date_of_birth' => 'nullable|string|max:30',
            'place_of_birth' => 'nullable|string|max:190',
            'current_address' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255',
            'department_id' => 'nullable|integer|exists:departments,id',
            'department' => 'nullable|string|max:255',
            'program_id' => 'nullable|integer|exists:programs,id',
            'program' => 'nullable|string|max:255',
            'major' => 'nullable|string|max:255',
            'year_level' => 'nullable|string|max:50',
            'academic_year_id' => 'nullable|integer|exists:academic_years,id',
            'academic_year' => 'nullable|string|max:50',
            'semester_id' => 'nullable|integer|exists:semesters,id',
            'position' => 'nullable|string|max:255',
            'specialization' => 'nullable|string|max:255',
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
        ]);

        DB::transaction(function () use ($validated, $user, $resolver) {
            $displayName = $validated['english_name'] ?? $validated['name'] ?? null;
            if (!$displayName && (!empty($validated['first_name']) || !empty($validated['last_name']))) {
                $displayName = trim(($validated['first_name'] ?? '') . ' ' . ($validated['last_name'] ?? ''));
            }
            if ($displayName) {
                $user->name = $displayName;
            }
            if (array_key_exists('email', $validated) && $validated['email'] !== null) {
                $user->email = $validated['email'];
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
                    $validated['department'] ?? null
                ) ?? $s->department_id;

                $programId = $validated['program_id'] ?? $s->program_id;
                $programName = $validated['program'] ?? $validated['major'] ?? null;
                if (empty($validated['program_id']) && $programName) {
                    $prog = Program::where('name', $programName)->orWhere('code', $programName)->first();
                    if ($prog) {
                        $programId = $prog->id;
                        $programName = $prog->name;
                    }
                } elseif ($programId) {
                    $programName = Program::where('id', $programId)->value('name') ?? $s->major;
                }

                $yearLevel = $validated['year_level'] ?? $s->year_level;
                if (is_string($yearLevel) && preg_match('/^year\s*(\d+)$/i', $yearLevel, $m)) {
                    $yearLevel = $m[1];
                }

                $classId = $s->class_id;
                $ayId = $validated['academic_year_id'] ?? null;
                if (!$ayId && !empty($validated['academic_year'])) {
                    $ayName = str_replace('–', '-', $validated['academic_year']);
                    $ayId = AcademicYear::where('name', $ayName)->value('id');
                }
                if ($deptId && $ayId && $yearLevel) {
                    $dept = Department::find($deptId);
                    $ay = AcademicYear::find($ayId);
                    if ($dept && $ay) {
                        $lvlInt = (int) preg_replace('/\D/', '', (string) $yearLevel) ?: 1;
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

                $studentStatus = $s->status;
                if (isset($validated['status'])) {
                    $rawStatus = strtolower($validated['status']);
                    $studentStatus = $rawStatus === 'active' ? 'enrolled' : $rawStatus;
                }

                $newCode = $validated['student_identifier'] ?? $validated['student_code'] ?? $s->student_code;

                $s->update([
                    'student_code' => $newCode,
                    'khmer_name' => $validated['khmer_name'] ?? $s->khmer_name,
                    'english_name' => $validated['english_name'] ?? $displayName ?? $s->english_name,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'gender' => $validated['gender'] ?? $s->gender,
                    'phone' => array_key_exists('phone', $validated) ? $validated['phone'] : $s->phone,
                    'date_of_birth' => !empty($validated['date_of_birth']) ? $validated['date_of_birth'] : $s->date_of_birth,
                    'place_of_birth' => array_key_exists('place_of_birth', $validated) ? $validated['place_of_birth'] : $s->place_of_birth,
                    'address' => $validated['current_address'] ?? $validated['address'] ?? $s->address,
                    'department_id' => $deptId,
                    'program_id' => $programId,
                    'class_id' => $classId,
                    'major' => $programName ?? $s->major,
                    'year_level' => (string) $yearLevel,
                    'status' => $studentStatus,
                ]);

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
                    $validated['department'] ?? null
                ) ?? $t->department_id;

                $t->update([
                    'teacher_code' => $validated['teacher_code'] ?? $t->teacher_code,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'department_id' => $deptId,
                    'gender' => $validated['gender'] ?? $t->gender,
                    'phone' => array_key_exists('phone', $validated) ? $validated['phone'] : $t->phone,
                    'position' => $validated['position'] ?? $t->position,
                    'specialization' => $validated['specialization'] ?? $t->specialization,
                    'status' => $user->status,
                ]);
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
        });

        $user->load([
            'student.department',
            'student.program',
            'student.classRoom.academicYear',
            'student.familyInformation',
            'student.emergencyContacts',
            'student.courses.teacher.user',
            'student.gpaRecords.semester',
            'teacher.department',
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
}
