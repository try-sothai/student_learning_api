<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Support\PersonNameHelper;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Coordinates transactional Teacher + User provisioning, login code normalization,
 * account link diagnostics, and safe account repair without altering unrelated accounts.
 */
class TeacherAccountService
{
    public function __construct(
        private readonly AcademicProfileResolver $resolver,
        private readonly TeacherProfileService $profiles,
    ) {
    }

    public function defaultInitialPassword(): string
    {
        // Note: initial development/demo credential; administrators should require password change before production.
        return (string) config('slas.default_teacher_password', 'TCH@12345');
    }

    public function normalizeCode(string $rawCode): string
    {
        return strtoupper(trim($rawCode));
    }

    public function normalizeEmail(?string $rawEmail): ?string
    {
        if ($rawEmail === null) {
            return null;
        }
        $clean = strtolower(trim($rawEmail));

        return $clean !== '' ? $clean : null;
    }

    public function normalizeStatus(?string $rawStatus): string
    {
        $raw = strtolower(trim((string) ($rawStatus ?? 'active')));

        return in_array($raw, ['active', 'enrolled', 'enabled'], true) ? 'active' : 'inactive';
    }

    /**
     * Prevents duplicate login identifiers across Teachers, Students, and Admins.
     *
     * @throws ValidationException
     */
    public function assertUniqueLoginCode(string $rawCode, ?int $ignoreTeacherId = null, string $errorKey = 'teacher_id'): string
    {
        $code = $this->normalizeCode($rawCode);
        if ($code === '') {
            throw ValidationException::withMessages([$errorKey => ['Teacher ID is required.']]);
        }

        $teacherQuery = Teacher::withTrashed()->whereRaw('UPPER(teacher_code) = ?', [$code]);
        if ($ignoreTeacherId !== null) {
            $teacherQuery->where('id', '!=', $ignoreTeacherId);
        }
        if ($teacherQuery->exists()) {
            throw ValidationException::withMessages([$errorKey => ["Teacher ID '{$code}' is already registered."]]);
        }

        $studentExists = Student::withTrashed()->whereRaw('UPPER(student_code) = ?', [$code])->exists();
        $adminExists = Admin::whereRaw('UPPER(admin_code) = ?', [$code])->exists();
        if ($studentExists || $adminExists) {
            throw ValidationException::withMessages([$errorKey => ["Login ID '{$code}' is already in use by another account."]]);
        }

        return $code;
    }

    /**
     * Atomically creates a Teacher profile, linked User login account, role='teacher', and hashed initial password.
     *
     * @return array{teacher: Teacher, user: User, initial_password: string, account_created: bool, account_linked: bool, account_status: string, login_id: string}
     */
    public function createTeacherWithAccount(array $data, ?int $actorUserId = null): array
    {
        $teacherCode = $this->assertUniqueLoginCode(
            (string) ($data['teacher_id'] ?? $data['teacher_code'] ?? ''),
            null,
            isset($data['teacher_id']) ? 'teacher_id' : 'teacher_code'
        );

        $email = $this->normalizeEmail($data['email'] ?? null);
        if ($email !== null && User::whereRaw('LOWER(email) = ?', [$email])->exists()) {
            throw ValidationException::withMessages(['email' => ["The email '{$email}' has already been taken."]]);
        }

        $khmerName = PersonNameHelper::clean($data['khmer_name'] ?? null);
        $englishName = PersonNameHelper::clean($data['english_name'] ?? null);
        $rawName = PersonNameHelper::clean($data['name'] ?? null);
        $rawFirst = PersonNameHelper::clean($data['first_name'] ?? null);
        $rawLast = PersonNameHelper::clean($data['last_name'] ?? null);

        if ($khmerName === null && $englishName === null) {
            $legacyName = $rawName ?? PersonNameHelper::clean(trim(($rawFirst ?? '') . ' ' . ($rawLast ?? '')));
            if ($legacyName === null) {
                throw ValidationException::withMessages([
                    'english_name' => ['At least one of Khmer Name or English Name is required.'],
                ]);
            }
            if (preg_match('/\p{Khmer}/u', $legacyName)) {
                $khmerName = $legacyName;
            } else {
                $englishName = $legacyName;
            }
        }

        $displayName = PersonNameHelper::displayName($englishName, $khmerName, $rawFirst, $rawLast, $teacherCode);
        if ($rawFirst !== null) {
            $firstName = $rawFirst;
            $lastName = $rawLast ?? $rawFirst;
        } else {
            [$firstName, $lastName] = PersonNameHelper::splitFirstLast($displayName);
        }

        $dob = ! empty($data['date_of_birth']) ? Carbon::parse((string) $data['date_of_birth'])->toDateString() : null;
        $hireDate = ! empty($data['hire_date']) ? Carbon::parse((string) $data['hire_date'])->toDateString() : null;

        $departmentId = $this->resolver->resolveDepartmentId(
            isset($data['department_id']) ? (int) $data['department_id'] : null,
            $data['department'] ?? $data['department_name'] ?? 'Computer Science',
        );

        $normalizedStatus = $this->normalizeStatus($data['status'] ?? 'active');
        $initialPassword = $this->defaultInitialPassword();

        $result = DB::transaction(function () use (
            $data,
            $teacherCode,
            $khmerName,
            $englishName,
            $displayName,
            $email,
            $firstName,
            $lastName,
            $dob,
            $hireDate,
            $departmentId,
            $normalizedStatus,
            $initialPassword,
            $actorUserId
        ) {
            $user = User::create([
                'name' => $displayName,
                'email' => $email,
                'password' => Hash::make($initialPassword),
                'role' => 'teacher',
                'status' => $normalizedStatus,
            ]);

            $officeId = $this->profiles->resolveOfficeLocationId(
                isset($data['office_location_id']) ? (int) $data['office_location_id'] : null,
                $data['office_location'] ?? null,
                $actorUserId
            );

            $biography = $this->profiles->cleanBiography($data['biography'] ?? $data['bio'] ?? null);

            $teacher = Teacher::create([
                'user_id' => $user->id,
                'teacher_code' => $teacherCode,
                'khmer_name' => $khmerName,
                'english_name' => $englishName,
                'department_id' => $departmentId,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'date_of_birth' => $dob,
                'hire_date' => $hireDate,
                'office_location_id' => $officeId,
                'position' => $data['position'] ?? 'Lecturer',
                'work_type' => $data['work_type'] ?? 'Full-time',
                'specialization' => null,
                'biography' => $biography,
                'gender' => $data['gender'] ?? 'Male',
                'phone' => $data['phone'] ?? null,
                'status' => $normalizedStatus,
            ]);

            if (array_key_exists('specializations', $data) || array_key_exists('specialization', $data)) {
                $this->profiles->syncSpecializations(
                    $teacher,
                    $data['specializations'] ?? null,
                    $data['specialization'] ?? null,
                    $actorUserId
                );
            }

            if ((int) $teacher->user_id !== (int) $user->id) {
                throw new \RuntimeException('Failed to connect Teacher profile to User login account.');
            }

            return ['teacher' => $teacher, 'user' => $user];
        });

        $teacher = $result['teacher']->fresh(['user', 'department', 'office', 'specializationItems']);

        return [
            'teacher' => $teacher,
            'user' => $teacher->user,
            'initial_password' => $initialPassword,
            'account_created' => true,
            'account_linked' => true,
            'account_status' => $normalizedStatus,
            'login_id' => $teacher->teacher_code,
        ];
    }

    /**
     * Safely computes login/account readiness diagnostics for Admin Detail / API responses.
     *
     * @return array<string, mixed>
     */
    public function buildAccountDiagnostics(Teacher $teacher): array
    {
        $teacher->loadMissing('user');
        $user = $teacher->user;
        $profileStatus = $this->normalizeStatus($teacher->status ?? 'active');

        $accountCreated = $user !== null;
        $roleValid = $user !== null && strtolower((string) $user->role) === 'teacher';
        $hasPassword = $user !== null && ! empty($user->password);
        $accountLinked = $teacher->user_id !== null && $accountCreated && $roleValid && $hasPassword;

        if (! $accountCreated) {
            $loginAccountStatus = 'not_created';
        } elseif (! $roleValid) {
            $loginAccountStatus = 'role_mismatch';
        } elseif (! $hasPassword) {
            $loginAccountStatus = 'no_password';
        } else {
            $loginAccountStatus = $this->normalizeStatus($user->status ?? 'active');
        }

        $canLogin = $accountLinked && $profileStatus === 'active' && $loginAccountStatus === 'active';

        return [
            'login_id' => $teacher->teacher_code,
            'teacher_profile_status' => $profileStatus,
            'login_account_status' => $loginAccountStatus,
            'account_created' => $accountCreated,
            'account_linked' => $accountLinked,
            'can_login' => $canLogin,
        ];
    }

    /**
     * Creates or repairs a missing User login account link for an existing Teacher without
     * creating duplicates or overwriting existing passwords unless missing.
     *
     * @return array{teacher: Teacher, created_new_user: bool, password_assigned: ?string, diagnostics: array<string, mixed>}
     */
    public function provisionOrRepairAccount(Teacher $teacher, ?string $emailOverride = null): array
    {
        $teacher->loadMissing('user');
        $email = $this->normalizeEmail($emailOverride);

        $outcome = DB::transaction(function () use ($teacher, $email) {
            $profileStatus = $this->normalizeStatus($teacher->status ?? 'active');
            $user = $teacher->user;

            if ($user) {
                $roleLower = strtolower((string) $user->role);
                if ($roleLower !== 'teacher') {
                    // Protect Admin / Student accounts from unintended role hijacking.
                    $hasOtherProfile = $user->student()->exists() || $user->admin()->exists();
                    if ($hasOtherProfile) {
                        throw ValidationException::withMessages([
                            'user_id' => ['Linked user belongs to another role profile and cannot be reassigned.'],
                        ]);
                    }
                    $user->role = 'teacher';
                }

                $passwordAssigned = null;
                if (empty($user->password)) {
                    $passwordAssigned = $this->defaultInitialPassword();
                    $user->password = Hash::make($passwordAssigned);
                }

                if ($email !== null && $email !== strtolower((string) $user->email)) {
                    $taken = User::whereRaw('LOWER(email) = ?', [$email])->where('id', '!=', $user->id)->exists();
                    if ($taken) {
                        throw ValidationException::withMessages(['email' => ["The email '{$email}' has already been taken."]]);
                    }
                    $user->email = $email;
                }

                $user->status = $profileStatus;
                $user->save();

                return ['created_new_user' => false, 'password_assigned' => $passwordAssigned];
            }

            // No linked user yet: check if an unlinked teacher-role user exists with supplied email.
            $existingUnlinkedTeacherUser = null;
            if ($email !== null) {
                $candidate = User::whereRaw('LOWER(email) = ?', [$email])->first();
                if ($candidate) {
                    if (strtolower((string) $candidate->role) !== 'teacher' || $candidate->teacher()->exists() || $candidate->student()->exists() || $candidate->admin()->exists()) {
                        throw ValidationException::withMessages(['email' => ["The email '{$email}' is already linked to another user account."]]);
                    }
                    $existingUnlinkedTeacherUser = $candidate;
                }
            }

            if ($existingUnlinkedTeacherUser) {
                $passwordAssigned = null;
                if (empty($existingUnlinkedTeacherUser->password)) {
                    $passwordAssigned = $this->defaultInitialPassword();
                    $existingUnlinkedTeacherUser->password = Hash::make($passwordAssigned);
                }
                $existingUnlinkedTeacherUser->status = $profileStatus;
                $existingUnlinkedTeacherUser->save();

                $teacher->forceFill(['user_id' => $existingUnlinkedTeacherUser->id])->save();

                return ['created_new_user' => false, 'password_assigned' => $passwordAssigned];
            }

            $initialPassword = $this->defaultInitialPassword();
            $fullName = $teacher->resolveDisplayName();
            $user = User::create([
                'name' => $fullName !== '' ? $fullName : $teacher->teacher_code,
                'email' => $email,
                'password' => Hash::make($initialPassword),
                'role' => 'teacher',
                'status' => $profileStatus,
            ]);

            $teacher->forceFill(['user_id' => $user->id])->save();

            return ['created_new_user' => true, 'password_assigned' => $initialPassword];
        });

        $fresh = $teacher->fresh(['user', 'department', 'office', 'specializationItems']);

        return [
            'teacher' => $fresh,
            'created_new_user' => $outcome['created_new_user'],
            'password_assigned' => $outcome['password_assigned'],
            'diagnostics' => $this->buildAccountDiagnostics($fresh),
        ];
    }
}
