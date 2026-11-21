<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Models\Teacher;
use App\Models\Admin;
use App\Models\User;
use App\Services\AcademicProfileResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Show Login Page
    |--------------------------------------------------------------------------
    */

    public function showLoginForm()
    {
        return view('pages.auth.login');
    }


    /*
    |--------------------------------------------------------------------------
    | Login
    |--------------------------------------------------------------------------
    */

    public function login(Request $request)
    {
        $request->validate([
            'login_id' => 'required|string',
            'password' => 'required|string',
        ]);

        $loginId = trim($request->login_id);

        $user = null;

        /*
        |--------------------------------------------------------------------------
        | Find Student
        |--------------------------------------------------------------------------
        */

        $student = Student::where('student_code', $loginId)
            ->with('user')
            ->first();

        if ($student) {

            $user = $student->user;

        } else {

            /*
            |--------------------------------------------------------------------------
            | Find Teacher
            |--------------------------------------------------------------------------
            */

            $teacher = Teacher::where('teacher_code', $loginId)
                ->with('user')
                ->first();

            if ($teacher) {

                $user = $teacher->user;

            } else {

                /*
                |--------------------------------------------------------------------------
                | Find Admin
                |--------------------------------------------------------------------------
                */

                $admin = Admin::where('admin_code', $loginId)
                    ->with('user')
                    ->first();

                if ($admin) {
                    $user = $admin->user;
                }
            }
        }


        /*
        |--------------------------------------------------------------------------
        | User Not Found
        |--------------------------------------------------------------------------
        */

        if (!$user || !Hash::check($request->password, $user->password)) {

            return back()
                ->withInput($request->only('login_id'))
                ->withErrors([
                    'login_id' => 'Invalid Student / Teacher / Admin ID or password.',
                ]);
        }

        if ($this->isAccountInactive($user)) {
            return back()
                ->withInput($request->only('login_id'))
                ->withErrors([
                    'login_id' => 'Account is inactive or deactivated. Please contact an administrator.',
                ]);
        }


        /*
        |--------------------------------------------------------------------------
        | Login
        |--------------------------------------------------------------------------
        */

        Auth::login($user);

        $request->session()->regenerate();


        /*
        |--------------------------------------------------------------------------
        | Redirect According To Role
        |--------------------------------------------------------------------------
        */

        switch ($user->role) {

            case 'student':
                return redirect()->route('student.dashboard');

            case 'teacher':
                return redirect()->route('teacher.dashboard');

            case 'admin':
                return redirect()->route('admin.dashboard');

            default:

                Auth::logout();

                return back()->withErrors([
                    'login_id' => 'Invalid user role.',
                ]);
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Logout
    |--------------------------------------------------------------------------
    */

    public function logout(Request $request)
    {
        Auth::logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    public function logoutApi(Request $request)
    {
        $user = $request->user();
        if ($user && $user->currentAccessToken()) {
            $user->currentAccessToken()->delete();
        }

        return response()->json([
            'message' => 'Logged out successfully.',
        ], 200);
    }

    public function loginApi(Request $request)
    {
        $request->validate([
            'login_id' => 'required|string',
            'password' => 'required|string',
        ]);

        $loginId = trim($request->input('login_id'));
        $throttleKey = Str::transliterate(Str::lower($loginId) . '|' . $request->ip());

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            return response()->json([
                'message' => 'Too many login attempts. Please try again in ' . $seconds . ' seconds.',
            ], 429);
        }

        $user = $this->findUserByLoginId($loginId);

        if (! $user || ! Hash::check($request->password, $user->password)) {
            RateLimiter::hit($throttleKey, 300); // Lock for 5 minutes
            return response()->json([
                'message' => 'Invalid credentials.',
            ], 401);
        }

        if ($this->isAccountInactive($user)) {
            RateLimiter::clear($throttleKey);
            return response()->json([
                'message' => 'Account is inactive or deactivated. Please contact an administrator.',
            ], 403);
        }

        RateLimiter::clear($throttleKey);

        $token = $user->createToken('api-token')->plainTextToken;

        $user->load(['student', 'teacher', 'admin']);

        $resolvedLoginId = null;
        if ($user->role === 'student' && $user->student) {
            $resolvedLoginId = $user->student->student_code;
            $user->student_id = $resolvedLoginId;
        } elseif ($user->role === 'teacher' && $user->teacher) {
            $resolvedLoginId = $user->teacher->teacher_code;
            $user->teacher_id = $resolvedLoginId;
        } elseif ($user->role === 'admin' && $user->admin) {
            $resolvedLoginId = $user->admin->admin_code;
            $user->admin_id = $resolvedLoginId;
        }

        return response()->json([
            'message' => 'Login successful.',
            'user' => $user,
            'role' => $user->role,
            'login_id' => $resolvedLoginId ?? $loginId,
            'student_id' => $resolvedLoginId ?? $request->login_id,
            'token' => $token,
        ], 200);
    }

    public function register(Request $request, AcademicProfileResolver $resolver)
    {
        return response()->json([
            'message' => 'Public student registration is disabled. Student accounts must be created by an administrator.',
        ], 403);
    }

    public function profile(Request $request)
    {
        $user = $request->user();
        if ($user) {
            if ($user->role === 'student') {
                $user->load(['student.department', 'student.classRoom', 'student.program']);
            } elseif ($user->role === 'teacher') {
                $user->load(['teacher.department']);
            } elseif ($user->role === 'admin') {
                $user->load(['admin']);
            }
        }

        return response()->json([
            'message' => 'Profile retrieved successfully.',
            'user' => $user,
            'profile' => $this->getProfileData($user),
            'data' => $user,
        ], 200);
    }

    public function updateProfile(Request $request, AcademicProfileResolver $resolver)
    {
        $user = $request->user();

        if ($user->role === 'student') {
            $forbiddenFields = array_values(array_diff(
                array_keys($request->all()),
                ['profile_picture', '_token', '_method']
            ));

            if ($forbiddenFields !== []) {
                $message = 'Students may only update their profile picture.';

                if ($request->expectsJson()) {
                    return response()->json([
                        'message' => $message,
                        'forbidden_fields' => $forbiddenFields,
                    ], 422);
                }

                return back()->withErrors(['profile' => $message]);
            }

            $validated = $request->validate([
                'profile_picture' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            ]);

            if ($request->hasFile('profile_picture')) {
                if ($user->profile_picture && Storage::disk('public')->exists($user->profile_picture)) {
                    Storage::disk('public')->delete($user->profile_picture);
                }

                $user->profile_picture = Storage::disk('public')->putFile('profile-pictures', $request->file('profile_picture'));
                $user->save();
            }

            if (! $request->expectsJson()) {
                return back()->with('status', 'Profile picture updated successfully.');
            }

            $user = $user->fresh();

            return response()->json([
                'message' => 'Profile picture updated successfully.',
                'user' => $user,
                'profile' => $this->getProfileData($user),
            ], 200);
        }

        $rules = [
            'name' => 'required|string|max:255',
            'email' => [
                'nullable',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'password' => 'nullable|string|min:8|confirmed',
            'profile_picture' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
        ];

        $rules['first_name'] = 'nullable|string|max:100';
        $rules['last_name'] = 'nullable|string|max:100';
        $rules['department'] = 'nullable|string|max:255';
        $rules['position'] = 'nullable|string|max:255';
        $rules['phone'] = 'nullable|string|max:30';

        $validated = $request->validate($rules);

        $user->name = $validated['name'];
        if (array_key_exists('email', $validated) && $validated['email'] !== null) {
            $user->email = $validated['email'];
        }

        if ($request->hasFile('profile_picture')) {
            if ($user->profile_picture && Storage::disk('public')->exists($user->profile_picture)) {
                Storage::disk('public')->delete($user->profile_picture);
            }

            $user->profile_picture = Storage::disk('public')->putFile('profile-pictures', $request->file('profile_picture'));
        }

        if (!empty($validated['password'])) {
            $user->password = Hash::make($validated['password']);
        }

        $user->save();

        $profile = $this->getRoleProfile($user);

        if ($profile) {
            if (isset($validated['first_name'])) {
                $profile->first_name = $validated['first_name'];
            }

            if (isset($validated['last_name'])) {
                $profile->last_name = $validated['last_name'];
            }

            if (isset($validated['department'])) {
                if ($profile instanceof Admin) {
                    // Admins keep a free-text administrative department label.
                    $profile->department = $validated['department'];
                } else {
                    // Students / teachers reference the departments table.
                    $profile->department_id = $resolver->resolveDepartmentId(null, $validated['department']);
                }
            }

            if (isset($validated['position']) && ! ($profile instanceof Student)) {
                $profile->position = $validated['position'];
            }

            $profile->save();
        }

        if (! $request->expectsJson()) {
            return back()->with('status', 'Profile updated successfully.');
        }

        return response()->json([
            'message' => 'Profile updated successfully.',
            'user' => $user->fresh(),
            'profile' => $this->getProfileData($user->fresh()),
        ], 200);
    }

    public function changePassword(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if (! Hash::check($validated['current_password'], $user->password)) {
            return response()->json([
                'message' => 'The current password provided is incorrect.',
                'errors' => [
                    'current_password' => ['The current password provided is incorrect.']
                ]
            ], 422);
        }

        $user->password = Hash::make($validated['password']);
        $user->save();

        return response()->json([
            'message' => 'Password changed successfully.',
        ], 200);
    }

    protected function findUserByLoginId(string $loginId): ?User
    {
        $normalized = strtoupper(trim($loginId));
        if ($normalized === '') {
            return null;
        }

        $student = Student::whereRaw('UPPER(student_code) = ?', [$normalized])->with('user')->first();
        if ($student && $student->user && strtolower((string) $student->user->role) === 'student') {
            $student->user->setRelation('student', $student);
            return $student->user;
        }

        $teacher = Teacher::whereRaw('UPPER(teacher_code) = ?', [$normalized])->with('user')->first();
        if ($teacher && $teacher->user && strtolower((string) $teacher->user->role) === 'teacher') {
            $teacher->user->setRelation('teacher', $teacher);
            return $teacher->user;
        }

        $admin = Admin::whereRaw('UPPER(admin_code) = ?', [$normalized])->with('user')->first();
        if ($admin && $admin->user && strtolower((string) $admin->user->role) === 'admin') {
            $admin->user->setRelation('admin', $admin);
            return $admin->user;
        }

        return null;
    }

    protected function isAccountInactive(User $user): bool
    {
        $userStatus = strtolower((string) ($user->status ?? 'active'));
        if ($userStatus !== 'active') {
            return true;
        }

        $role = strtolower((string) $user->role);
        if ($role === 'teacher') {
            $teacher = $user->teacher;
            if (! $teacher || strtolower((string) ($teacher->status ?? 'inactive')) !== 'active') {
                return true;
            }
        } elseif ($role === 'student') {
            $student = $user->student;
            $sStatus = strtolower((string) ($student?->status ?? 'inactive'));
            if (! $student || in_array($sStatus, ['inactive', 'suspended', 'graduated', 'dropped'], true)) {
                return true;
            }
        } elseif ($role === 'admin') {
            $admin = $user->admin;
            if ($admin && strtolower((string) ($admin->status ?? 'active')) !== 'active') {
                return true;
            }
        }

        return false;
    }

    protected function getRoleProfile(User $user)
    {
        if ($user->role === 'student') {
            return $user->student?->load(['department', 'classRoom', 'program']);
        }

        if ($user->role === 'teacher') {
            return $user->teacher?->load(['department']);
        }

        if ($user->role === 'admin') {
            return $user->admin;
        }

        return null;
    }

    protected function getProfileData(User $user): array
    {
        $profile = $this->getRoleProfile($user);

        return [
            'role' => $user->role,
            'name' => $user->name,
            'email' => $user->email,
            'profile' => $profile ? $profile->toArray() : null,
        ];
    }
}


