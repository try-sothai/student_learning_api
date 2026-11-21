<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Models\User;
use App\Services\AcademicProfileResolver;
use App\Support\PersonNameHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StudentController extends Controller
{
    public function index()
    {
        $students = Student::with(['user', 'department', 'classRoom'])->latest()->get();

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
            'class_id' => 'nullable|integer|exists:classes,id',
            'department' => 'nullable|string|max:255',
            'major' => 'nullable|string|max:255',
            'academic_year' => 'nullable|string|max:20',
            'year_level' => 'nullable|integer|min:1|max:10',
            'semester' => 'nullable|string|max:50',
            'advisor' => 'nullable|string|max:255',
            'enrollment_date' => 'nullable|date',
            'status' => 'nullable|string|max:50',
        ]);

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

        $classId = $validated['class_id']
            ?? $resolver->findClassId($departmentId, $validated['academic_year'] ?? null, $validated['year_level'] ?? null);

        $initialPassword = (string) config('student_import.default_initial_password', 'STD@12345');

        [$user, $student] = DB::transaction(function () use ($validated, $khmerName, $englishName, $displayName, $firstName, $lastName, $departmentId, $classId, $initialPassword) {
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
                'class_id' => $classId,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'date_of_birth' => $validated['date_of_birth'] ?? null,
                'gender' => $validated['gender'],
                'nationality' => $validated['nationality'] ?? 'Cambodian',
                'phone' => $validated['phone'] ?? null,
                'address' => $validated['address'] ?? null,
                'major' => $validated['major'] ?? null,
                'year_level' => $validated['year_level'] ?? null,
                'advisor' => $validated['advisor'] ?? null,
                'enrollment_date' => $validated['enrollment_date'] ?? null,
                'status' => $validated['status'] ?? 'enrolled',
            ]);

            return [$user, $student];
        });

        return response()->json([
            'message' => 'Student created successfully.',
            'user' => $user,
            'data' => $student->load(['user', 'department', 'classRoom']),
        ], 201);
    }

    public function show(Student $student)
    {
        return response()->json([
            'message' => 'Student retrieved successfully.',
            'data' => $student->load(['user', 'department', 'classRoom']),
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
            'class_id' => 'nullable|integer|exists:classes,id',
            'department' => 'nullable|string|max:255',
            'major' => 'nullable|string|max:255',
            'academic_year' => 'nullable|string|max:20',
            'year_level' => 'nullable|integer|min:1|max:10',
            'semester' => 'nullable|string|max:50',
            'advisor' => 'nullable|string|max:255',
            'enrollment_date' => 'nullable|date',
            'status' => 'nullable|string|max:50',
        ]);

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

        $classId = $validated['class_id']
            ?? $resolver->findClassId(
                $departmentId,
                $validated['academic_year'] ?? null,
                $validated['year_level'] ?? null,
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

        $student->fill([
            'student_code' => $validated['student_id'],
            'khmer_name' => $khmerName,
            'english_name' => $englishName,
            'department_id' => $departmentId,
            'class_id' => $classId,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'date_of_birth' => array_key_exists('date_of_birth', $validated) ? $validated['date_of_birth'] : $student->date_of_birth,
            'gender' => $validated['gender'],
            'nationality' => $validated['nationality'] ?? $student->nationality,
            'phone' => $validated['phone'] ?? $student->phone,
            'address' => $validated['address'] ?? $student->address,
            'major' => $validated['major'] ?? $student->major,
            'year_level' => $validated['year_level'] ?? $student->year_level,
            'advisor' => $validated['advisor'] ?? $student->advisor,
            'enrollment_date' => array_key_exists('enrollment_date', $validated) ? $validated['enrollment_date'] : $student->enrollment_date,
            'status' => $validated['status'] ?? $student->status,
        ]);
        $student->save();

        return response()->json([
            'message' => 'Student updated successfully.',
            'user' => $student->user,
            'data' => $student->fresh()->load(['user', 'department', 'classRoom']),
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
