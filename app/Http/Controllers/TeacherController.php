<?php

namespace App\Http\Controllers;

use App\Models\Teacher;
use App\Services\AcademicProfileResolver;
use App\Services\TeacherAccountService;
use App\Services\TeacherProfileService;
use App\Support\PersonNameHelper;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TeacherController extends Controller
{
    private function presentTeacher(Teacher $teacher, TeacherAccountService $accounts): array
    {
        $diag = $accounts->buildAccountDiagnostics($teacher);
        $payload = $teacher->toArray();
        $payload['name'] = $teacher->resolveDisplayName();
        $payload['khmer_name'] = $teacher->khmer_name ?? '';
        $payload['english_name'] = $teacher->english_name ?? '';
        $payload['date_of_birth'] = $teacher->date_of_birth?->format('Y-m-d') ?? '';
        $payload['hire_date'] = $teacher->hire_date?->format('Y-m-d') ?? '';
        $payload['email'] = $teacher->user?->email ?? '';
        $payload['department_name'] = $teacher->department?->name ?? '';
        $payload['code'] = $teacher->teacher_code;
        $payload['login_id'] = $diag['login_id'];
        $payload['teacher_profile_status'] = $diag['teacher_profile_status'];
        $payload['login_account_status'] = $diag['login_account_status'];
        $payload['account_created'] = $diag['account_created'];
        $payload['account_linked'] = $diag['account_linked'];
        $payload['can_login'] = $diag['can_login'];
        $payload['account_diagnostics'] = $diag;

        return $payload;
    }

    public function index(Request $request, TeacherAccountService $accounts)
    {
        $query = Teacher::with(['user', 'department', 'office', 'specializationItems']);

        $status = strtolower(trim((string) $request->query('status', 'all')));
        if ($status !== '' && $status !== 'all') {
            $query->where('status', $status === 'active' ? 'active' : 'inactive');
        }

        $department = trim((string) $request->query('department', $request->query('department_id', '')));
        if ($department !== '' && strtolower($department) !== 'all' && strtolower($department) !== 'all departments') {
            if (ctype_digit($department)) {
                $query->where('department_id', (int) $department);
            } else {
                $query->whereHas('department', fn ($dq) => $dq->where('name', 'like', "%{$department}%")->orWhere('code', $department));
            }
        }

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('teacher_code', 'like', "%{$search}%")
                    ->orWhere('khmer_name', 'like', "%{$search}%")
                    ->orWhere('english_name', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('specialization', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($uq) => $uq->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"))
                    ->orWhereHas('department', fn ($dq) => $dq->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('office', fn ($oq) => $oq->where('display_name', 'like', "%{$search}%"));
            });
        }

        if ($request->filled('per_page')) {
            $perPage = min(max((int) $request->query('per_page', 15), 1), 100);
            $paginator = $query->latest()->paginate($perPage);
            $items = $paginator->getCollection()->map(fn (Teacher $t) => $this->presentTeacher($t, $accounts))->values();

            return response()->json([
                'message' => 'Teachers retrieved successfully.',
                'data' => $items,
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ],
            ], 200);
        }

        $teachers = $query->latest()->get()->map(fn (Teacher $t) => $this->presentTeacher($t, $accounts))->values();

        return response()->json([
            'message' => 'Teachers retrieved successfully.',
            'data' => $teachers,
        ], 200);
    }

    public function store(Request $request, TeacherAccountService $accounts)
    {
        if ($request->exists('email') && trim((string) $request->input('email')) === '') {
            $request->merge(['email' => null]);
        }

        $validated = $request->validate([
            'teacher_id' => 'required|string|max:50',
            'khmer_name' => 'nullable|string|max:150',
            'english_name' => 'nullable|string|max:150',
            'name' => 'nullable|string|max:200',
            'first_name' => 'nullable|string|max:100',
            'last_name' => 'nullable|string|max:100',
            'email' => 'nullable|email|max:255',
            'password' => 'nullable|string|max:100',
            'department_id' => 'nullable|integer|exists:departments,id',
            'department' => 'nullable|string|max:255',
            'position' => 'nullable|string|max:100',
            'work_type' => 'nullable|string|max:50',
            'specialization' => 'nullable|string|max:1000',
            'specializations' => 'nullable|array',
            'specializations.*' => 'nullable|string|max:120',
            'office_location_id' => 'nullable|integer|exists:office_locations,id',
            'office_location' => 'nullable|string|max:191',
            'biography' => 'nullable|string|max:2000',
            'bio' => 'nullable|string|max:2000',
            'date_of_birth' => 'nullable|date',
            'hire_date' => 'nullable|date',
            'gender' => 'nullable|string|max:20',
            'phone' => 'nullable|string|max:30',
            'status' => 'nullable|string|max:50',
        ]);

        $provisioned = $accounts->createTeacherWithAccount($validated, $request->user()?->id);
        $teacher = $provisioned['teacher'];
        $presented = $this->presentTeacher($teacher, $accounts);

        return response()->json([
            'message' => 'Teacher profile and login account created successfully.',
            'username' => $provisioned['login_id'],
            'login_id' => $provisioned['login_id'],
            'initial_password' => $provisioned['initial_password'],
            'role' => 'teacher',
            'account_created' => true,
            'account_linked' => true,
            'account_status' => $provisioned['account_status'],
            'user' => $provisioned['user'],
            'data' => $presented,
        ], 201);
    }

    public function show(Teacher $teacher, TeacherAccountService $accounts)
    {
        $teacher->load(['user', 'department', 'office', 'specializationItems', 'courses', 'classes']);

        return response()->json([
            'message' => 'Teacher retrieved successfully.',
            'data' => $this->presentTeacher($teacher, $accounts),
        ], 200);
    }

    public function update(Request $request, Teacher $teacher, AcademicProfileResolver $resolver, TeacherProfileService $profiles, TeacherAccountService $accounts)
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

        $validated = $request->validate([
            'teacher_id' => 'sometimes|required|string|max:50',
            'khmer_name' => 'nullable|string|max:150',
            'english_name' => 'nullable|string|max:150',
            'name' => 'nullable|string|max:200',
            'first_name' => 'nullable|string|max:100',
            'last_name' => 'nullable|string|max:100',
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($teacher->user?->id)],
            'department_id' => 'nullable|integer|exists:departments,id',
            'department' => 'nullable|string|max:255',
            'position' => 'nullable|string|max:100',
            'work_type' => 'nullable|string|max:50',
            'specialization' => 'nullable|string|max:1000',
            'specializations' => 'nullable|array',
            'specializations.*' => 'nullable|string|max:120',
            'office_location_id' => 'nullable|integer|exists:office_locations,id',
            'office_location' => 'nullable|string|max:191',
            'biography' => 'nullable|string|max:2000',
            'bio' => 'nullable|string|max:2000',
            'date_of_birth' => 'nullable|date',
            'hire_date' => 'nullable|date',
            'gender' => 'nullable|string|max:20',
            'phone' => 'nullable|string|max:30',
            'status' => 'nullable|string|max:50',
        ]);

        $newCode = $teacher->teacher_code;
        if (array_key_exists('teacher_id', $validated)) {
            $newCode = $accounts->assertUniqueLoginCode((string) $validated['teacher_id'], $teacher->id, 'teacher_id');
        }

        $departmentId = $resolver->resolveDepartmentId(
            $validated['department_id'] ?? null,
            $validated['department'] ?? null,
        ) ?? $teacher->department_id;

        $khmerName = array_key_exists('khmer_name', $validated)
            ? PersonNameHelper::clean($validated['khmer_name'])
            : $teacher->khmer_name;
        $englishName = array_key_exists('english_name', $validated)
            ? PersonNameHelper::clean($validated['english_name'])
            : $teacher->english_name;

        if (! array_key_exists('khmer_name', $validated) && ! array_key_exists('english_name', $validated)
            && array_key_exists('name', $validated)) {
            $cleanedName = PersonNameHelper::clean($validated['name']);
            if ($cleanedName === null) {
                throw ValidationException::withMessages(['name' => ['At least one of Khmer Name or English Name is required.']]);
            }
            $englishName = $cleanedName;
        }

        $displayName = PersonNameHelper::displayName(
            $englishName,
            $khmerName,
            $validated['first_name'] ?? $teacher->first_name,
            $validated['last_name'] ?? $teacher->last_name,
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

        $dob = array_key_exists('date_of_birth', $validated)
            ? ($validated['date_of_birth'] !== null ? Carbon::parse((string) $validated['date_of_birth'])->toDateString() : null)
            : $teacher->date_of_birth;
        $hireDate = array_key_exists('hire_date', $validated)
            ? ($validated['hire_date'] !== null ? Carbon::parse((string) $validated['hire_date'])->toDateString() : null)
            : $teacher->hire_date;

        $normalizedStatus = isset($validated['status'])
            ? $accounts->normalizeStatus($validated['status'])
            : $teacher->status;

        DB::transaction(function () use ($request, $validated, $teacher, $departmentId, $khmerName, $englishName, $displayName, $firstName, $lastName, $dob, $hireDate, $newCode, $normalizedStatus, $profiles, $accounts) {
            if ($teacher->user) {
                if (array_key_exists('email', $validated)) {
                    $teacher->user->email = $accounts->normalizeEmail($validated['email']);
                }
                if (isset($validated['status'])) {
                    $teacher->user->status = $normalizedStatus;
                }
                if (strtolower((string) $teacher->user->role) !== 'teacher'
                    && ! $teacher->user->student()->exists()
                    && ! $teacher->user->admin()->exists()) {
                    $teacher->user->role = 'teacher';
                }
                $teacher->user->name = $displayName;
                $teacher->user->save();

                if ($normalizedStatus !== 'active') {
                    $teacher->user->tokens()->delete();
                }
            }

            $officeId = $teacher->office_location_id;
            if (array_key_exists('office_location_id', $validated) || array_key_exists('office_location', $validated)) {
                $officeId = $profiles->resolveOfficeLocationId(
                    $validated['office_location_id'] ?? null,
                    $validated['office_location'] ?? null,
                    $request->user()?->id
                );
            }

            $biography = $teacher->biography;
            if (array_key_exists('biography', $validated) || array_key_exists('bio', $validated)) {
                $biography = $profiles->cleanBiography($validated['biography'] ?? $validated['bio'] ?? null);
            }

            $teacher->update([
                'teacher_code' => $newCode,
                'khmer_name' => $khmerName,
                'english_name' => $englishName,
                'department_id' => $departmentId,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'date_of_birth' => $dob,
                'hire_date' => $hireDate,
                'office_location_id' => $officeId,
                'position' => $validated['position'] ?? $teacher->position,
                'work_type' => $validated['work_type'] ?? $teacher->work_type,
                'biography' => $biography,
                'gender' => $validated['gender'] ?? $teacher->gender,
                'phone' => array_key_exists('phone', $validated) ? $validated['phone'] : $teacher->phone,
                'status' => $normalizedStatus,
            ]);

            if (array_key_exists('specializations', $validated) || array_key_exists('specialization', $validated)) {
                $profiles->syncSpecializations(
                    $teacher,
                    $validated['specializations'] ?? null,
                    $validated['specialization'] ?? null,
                    $request->user()?->id
                );
            }
        });

        $fresh = $teacher->fresh(['user', 'department', 'office', 'specializationItems', 'courses', 'classes']);

        return response()->json([
            'message' => 'Teacher updated successfully.',
            'data' => $this->presentTeacher($fresh, $accounts),
        ], 200);
    }

    public function provisionAccount(Request $request, Teacher $teacher, TeacherAccountService $accounts)
    {
        $validated = $request->validate([
            'email' => 'nullable|email|max:255',
        ]);

        $res = $accounts->provisionOrRepairAccount($teacher, $validated['email'] ?? null);
        $presented = $this->presentTeacher($res['teacher'], $accounts);

        $payload = [
            'message' => $res['created_new_user']
                ? 'Login account created and linked to teacher profile.'
                : 'Teacher login account link verified and synchronized.',
            'created_new_user' => $res['created_new_user'],
            'login_id' => $res['teacher']->teacher_code,
            'data' => $presented,
        ];
        if ($res['password_assigned'] !== null) {
            $payload['initial_password'] = $res['password_assigned'];
        }

        return response()->json($payload, 200);
    }

    public function destroy(Teacher $teacher)
    {
        $teacher->delete();

        return response()->json([
            'message' => 'Teacher deleted successfully.',
        ], 200);
    }
}
