<?php

namespace App\Http\Controllers;

use App\Models\Program;
use App\Models\Student;
use App\Models\User;
use App\Rules\ValidYearLevel;
use App\Services\AcademicProfileResolver;
use App\Services\StudentImportValidationService;
use App\Support\PersonNameHelper;
use App\Support\YearLevel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StudentController extends Controller
{
    public function index(Request $request)
    {
        $user = $this->resolveApiUser($request);
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $query = Student::with(['user', 'department', 'program', 'classRoom.academicYear']);

        if ($user->role === 'student') {
            $query->where('id', $user->student?->id ?? -1);
        } elseif ($user->role === 'teacher') {
            $teacher = $this->resolveTeacherForUser($user);
            $authorizedIds = $teacher ? $this->teacherAuthService()->getAuthorizedStudentIds($teacher) : [];
            if (empty($authorizedIds)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('id', $authorizedIds);
            }
        } elseif ($user->role !== 'admin') {
            return response()->json(['message' => 'Unauthorized action.'], 403);
        }

        if ($request->boolean('missing_year_level') || strtolower((string) $request->query('year_level')) === 'missing') {
            $query->missingYearLevel();
        } elseif ($request->filled('year_level') && strtolower((string) $request->query('year_level')) !== 'all') {
            $query->forYearLevel($request->query('year_level'));
        }
        if ($request->filled('department_id') || $request->filled('faculty_id')) {
            $query->where('department_id', (int) ($request->query('department_id') ?? $request->query('faculty_id')));
        }
        if ($request->filled('class_id')) {
            $query->where('class_id', (int) $request->query('class_id'));
        }
        if ($request->filled('academic_year_id')) {
            $ayId = (int) $request->query('academic_year_id');
            $query->whereHas('classRoom', fn ($q) => $q->where('academic_year_id', $ayId));
        }
        if ($request->filled('search')) {
            $term = trim((string) $request->query('search'));
            $query->where(function ($q) use ($term) {
                $q->where('student_code', 'like', "%{$term}%")
                    ->orWhere('english_name', 'like', "%{$term}%")
                    ->orWhere('khmer_name', 'like', "%{$term}%")
                    ->orWhere('first_name', 'like', "%{$term}%")
                    ->orWhere('last_name', 'like', "%{$term}%");
            });
        }

        $students = $query->latest()->get();

        return response()->json([
            'message' => 'Students retrieved successfully.',
            'data' => $students,
        ], 200);
    }

    public function store(Request $request, AcademicProfileResolver $resolver)
    {
        if ($request->exists('email') && trim((string) $request->input('email')) === '') {
            $request->merge(['email' => null]);
        }
        if (! $request->filled('department_id') && $request->filled('faculty_id')) {
            $request->merge(['department_id' => $request->input('faculty_id')]);
        }
        if (! $request->filled('department') && $request->filled('faculty')) {
            $request->merge(['department' => $request->input('faculty')]);
        }
        if (! $request->filled('program_id') && $request->filled('major_id')) {
            $request->merge(['program_id' => $request->input('major_id')]);
        }

        // The request key stays `student_id` (Android contract); stored in students.student_code.
        $validated = $request->validate([
            'student_id' => 'required|string|max:50|unique:students,student_code',
            'khmer_name' => 'nullable|string|max:150',
            'english_name' => 'nullable|string|max:150',
            'first_name' => 'nullable|string|max:100',
            'last_name' => 'nullable|string|max:100',
            'gender' => 'required|string|max:20',
            'date_of_birth' => 'nullable|date',
            'nationality' => 'nullable|string|max:100',
            'phone' => 'nullable|string|max:30',
            'address' => 'nullable|string|max:255',
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => 'nullable|string|max:100',
            'department_id' => 'nullable|integer|exists:departments,id',
            'program_id' => 'nullable|integer|exists:programs,id',
            'class_id' => 'nullable|integer|exists:classes,id',
            'department' => 'nullable|string|max:255',
            'major' => 'nullable|string|max:255',
            'academic_year' => 'nullable|string|max:20',
            'year_level' => ['nullable', new ValidYearLevel()],
            'semester' => 'nullable|string|max:50',
            'advisor' => 'nullable|string|max:255',
            'enrollment_date' => 'nullable|date',
            'status' => 'nullable|string|max:50',
        ], [], [
            'department_id' => 'faculty',
            'program_id' => 'major',
        ]);

        $normalizedYearLevel = array_key_exists('year_level', $validated) && $validated['year_level'] !== null && $validated['year_level'] !== ''
            ? YearLevel::storageValue($validated['year_level'])
            : null;

        $khmerName = PersonNameHelper::clean($validated['khmer_name'] ?? null);
        $englishName = PersonNameHelper::clean($validated['english_name'] ?? null);
        $rawFirst = PersonNameHelper::clean($validated['first_name'] ?? null);
        $rawLast = PersonNameHelper::clean($validated['last_name'] ?? null);

        if ($khmerName === null && $englishName === null && $rawFirst === null && $rawLast === null) {
            throw ValidationException::withMessages([
                'english_name' => ['At least one of Khmer Name or English Name is required.'],
            ]);
        }

        $displayName = PersonNameHelper::displayName($englishName, $khmerName, $rawFirst, $rawLast);
        if ($rawFirst !== null) {
            $firstName = $rawFirst;
            $lastName = $rawLast ?? $rawFirst;
        } else {
            [$firstName, $lastName] = PersonNameHelper::splitFirstLast($displayName);
        }

        $departmentId = $resolver->resolveDepartmentId(
            $validated['department_id'] ?? null,
            $validated['department'] ?? null,
        );

        $programId = $validated['program_id'] ?? null;
        $majorName = $validated['major'] ?? null;
        if ($programId) {
            $progObj = Program::find($programId);
            if ($progObj && $departmentId && $progObj->department_id
                && ! StudentImportValidationService::areDepartmentAndProgramCompatible((int) $progObj->department_id, (int) $departmentId)) {
                throw ValidationException::withMessages([
                    'program_id' => ['Selected major does not belong to the selected faculty.'],
                ]);
            }
            $majorName = $majorName ?: $progObj?->name;
        } elseif (filled($majorName) && $departmentId) {
            $programId = Program::where('department_id', $departmentId)
                ->where(fn ($q) => $q->where('name', $majorName)->orWhere('code', $majorName))
                ->value('id');
        }

        $classId = $validated['class_id']
            ?? $resolver->findClassId($departmentId, $validated['academic_year'] ?? null, $normalizedYearLevel);

        $initialPassword = (string) config('student_import.default_initial_password', 'STD@12345');

        [$user, $student] = DB::transaction(function () use ($validated, $khmerName, $englishName, $displayName, $firstName, $lastName, $departmentId, $programId, $majorName, $classId, $initialPassword, $normalizedYearLevel) {
            $user = User::create([
                'name' => $displayName,
                'email' => ! empty($validated['email']) ? strtolower(trim($validated['email'])) : null,
                'password' => Hash::make($initialPassword),
                'role' => 'student',
                'status' => 'active',
            ]);

            $student = Student::create([
                'user_id' => $user->id,
                'student_code' => strtoupper(trim($validated['student_id'])),
                'khmer_name' => $khmerName,
                'english_name' => $englishName,
                'department_id' => $departmentId,
                'program_id' => $programId,
                'class_id' => $classId,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'date_of_birth' => $validated['date_of_birth'] ?? null,
                'gender' => $validated['gender'],
                'nationality' => $validated['nationality'] ?? 'Cambodian',
                'phone' => $validated['phone'] ?? null,
                'address' => $validated['address'] ?? null,
                'major' => $majorName,
                'year_level' => $normalizedYearLevel,
                'advisor' => $validated['advisor'] ?? null,
                'enrollment_date' => $validated['enrollment_date'] ?? null,
                'status' => $validated['status'] ?? 'enrolled',
            ]);

            return [$user, $student];
        });

        return response()->json([
            'message' => 'Student created successfully.',
            'user' => $user,
            'data' => $student->load(['user', 'department', 'program', 'classRoom.academicYear']),
        ], 201);
    }

    public function show(Request $request, Student $student)
    {
        if ($denied = $this->authorizeStudentScopedAccess($request, (int) $student->id)) {
            return $denied;
        }

        return response()->json([
            'message' => 'Student retrieved successfully.',
            'data' => $student->load(['user', 'department', 'program', 'classRoom.academicYear']),
        ], 200);
    }

    public function update(Request $request, Student $student, AcademicProfileResolver $resolver)
    {
        if ($request->exists('email') && trim((string) $request->input('email')) === '') {
            $request->merge(['email' => null]);
        }
        if ($request->exists('date_of_birth') && trim((string) $request->input('date_of_birth')) === '') {
            $request->merge(['date_of_birth' => null]);
        }
        if (! $request->filled('department_id') && $request->filled('faculty_id')) {
            $request->merge(['department_id' => $request->input('faculty_id')]);
        }
        if (! $request->filled('department') && $request->filled('faculty')) {
            $request->merge(['department' => $request->input('faculty')]);
        }
        if (! $request->filled('program_id') && $request->filled('major_id')) {
            $request->merge(['program_id' => $request->input('major_id')]);
        }

        $validated = $request->validate([
            'student_id' => ['required', 'string', 'max:50', Rule::unique('students', 'student_code')->ignore($student->id)],
            'khmer_name' => 'nullable|string|max:150',
            'english_name' => 'nullable|string|max:150',
            'first_name' => 'nullable|string|max:100',
            'last_name' => 'nullable|string|max:100',
            'gender' => 'required|string|max:20',
            'date_of_birth' => 'nullable|date',
            'nationality' => 'nullable|string|max:100',
            'phone' => 'nullable|string|max:30',
            'address' => 'nullable|string|max:255',
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($student->user?->id)],
            'password' => 'nullable|string|min:8|confirmed',
            'department_id' => 'nullable|integer|exists:departments,id',
            'program_id' => 'nullable|integer|exists:programs,id',
            'class_id' => 'nullable|integer|exists:classes,id',
            'department' => 'nullable|string|max:255',
            'major' => 'nullable|string|max:255',
            'academic_year' => 'nullable|string|max:20',
            'year_level' => ['nullable', new ValidYearLevel()],
            'semester' => 'nullable|string|max:50',
            'advisor' => 'nullable|string|max:255',
            'enrollment_date' => 'nullable|date',
            'status' => 'nullable|string|max:50',
        ], [], [
            'department_id' => 'faculty',
            'program_id' => 'major',
        ]);

        $nextYearLevel = array_key_exists('year_level', $validated)
            ? ($validated['year_level'] !== null && $validated['year_level'] !== ''
                ? YearLevel::storageValue($validated['year_level'])
                : null)
            : $student->year_level;

        $khmerName = array_key_exists('khmer_name', $validated)
            ? PersonNameHelper::clean($validated['khmer_name'])
            : $student->khmer_name;
        $englishName = array_key_exists('english_name', $validated)
            ? PersonNameHelper::clean($validated['english_name'])
            : $student->english_name;
        $displayName = PersonNameHelper::displayName(
            $englishName,
            $khmerName,
            $validated['first_name'] ?? $student->first_name,
            $validated['last_name'] ?? $student->last_name,
            ''
        );
        if ($displayName === '') {
            throw ValidationException::withMessages([
                'english_name' => ['At least one of Khmer Name or English Name is required.'],
            ]);
        }
        [$firstName, $lastName] = isset($validated['first_name'])
            ? [trim($validated['first_name']), trim((string) ($validated['last_name'] ?? $validated['first_name']))]
            : PersonNameHelper::splitFirstLast($displayName);

        $departmentId = $resolver->resolveDepartmentId(
            $validated['department_id'] ?? null,
            $validated['department'] ?? null,
        ) ?? $student->department_id;

        $programId = $validated['program_id'] ?? $student->program_id;
        $majorName = $validated['major'] ?? $student->major;
        if (! empty($validated['program_id'])) {
            $progObj = Program::find($validated['program_id']);
            if ($progObj && $departmentId && $progObj->department_id
                && ! StudentImportValidationService::areDepartmentAndProgramCompatible((int) $progObj->department_id, (int) $departmentId)) {
                throw ValidationException::withMessages([
                    'program_id' => ['Selected major does not belong to the selected faculty.'],
                ]);
            }
            if ($progObj) {
                $majorName = $validated['major'] ?? $progObj->name;
            }
        } elseif (! empty($validated['major']) && $departmentId) {
            $matchedProg = Program::where('department_id', $departmentId)
                ->where(fn ($q) => $q->where('name', $validated['major'])->orWhere('code', $validated['major']))
                ->first();
            if ($matchedProg) {
                $programId = $matchedProg->id;
            }
        }

        $classId = $validated['class_id']
            ?? $resolver->findClassId(
                $departmentId,
                $validated['academic_year'] ?? null,
                $nextYearLevel,
            )
            ?? $student->class_id;

        if ($student->user) {
            if (array_key_exists('email', $validated)) {
                $student->user->email = ! empty($validated['email']) ? strtolower(trim($validated['email'])) : null;
            }

            if (isset($validated['password'])) {
                $student->user->password = Hash::make($validated['password']);
            }

            $student->user->name = $displayName;
            $student->user->save();
        }

        $previousYearLevel = $student->year_level;
        $student->fill([
            'student_code' => $validated['student_id'],
            'khmer_name' => $khmerName,
            'english_name' => $englishName,
            'department_id' => $departmentId,
            'program_id' => $programId,
            'class_id' => $classId,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'date_of_birth' => array_key_exists('date_of_birth', $validated) ? $validated['date_of_birth'] : $student->date_of_birth,
            'gender' => $validated['gender'],
            'nationality' => $validated['nationality'] ?? $student->nationality,
            'phone' => $validated['phone'] ?? $student->phone,
            'address' => $validated['address'] ?? $student->address,
            'major' => $majorName,
            'year_level' => $nextYearLevel,
            'advisor' => $validated['advisor'] ?? $student->advisor,
            'enrollment_date' => array_key_exists('enrollment_date', $validated) ? $validated['enrollment_date'] : $student->enrollment_date,
            'status' => $validated['status'] ?? $student->status,
        ]);
        $student->save();

        if ((string) $previousYearLevel !== (string) $nextYearLevel) {
            Log::info('Admin updated student year level', [
                'admin_id' => $request->user()?->id,
                'student_id' => $student->id,
                'student_code' => $student->student_code,
                'from_year_level' => $previousYearLevel,
                'to_year_level' => $nextYearLevel,
            ]);
        }

        return response()->json([
            'message' => 'Student updated successfully.',
            'user' => $student->user,
            'data' => $student->fresh()->load(['user', 'department', 'program', 'classRoom.academicYear']),
        ], 200);
    }

    public function destroy(Student $student)
    {
        $student->delete();

        return response()->json([
            'message' => 'Student deleted successfully.',
        ], 200);
    }
}
