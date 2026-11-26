<?php

namespace App\Http\Controllers;

use App\Models\Teacher;
use App\Models\User;
use App\Services\TeacherAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class Controller
{
    protected function resolveApiUser(Request $request): ?User
    {
        return auth('sanctum')->user() ?? $request->user();
    }

    protected function teacherAuthService(): TeacherAuthorizationService
    {
        return app(TeacherAuthorizationService::class);
    }

    protected function resolveTeacherForUser(User $user): ?Teacher
    {
        return $this->teacherAuthService()->resolveTeacher($user);
    }

    /**
     * Ensure the authenticated API user is allowed to access or manage a student's record.
     */
    protected function authorizeStudentScopedAccess(
        Request $request,
        ?int $studentId,
        ?int $courseId = null,
        bool $allowStudentSelf = true
    ): ?JsonResponse {
        $user = $this->resolveApiUser($request);
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($user->role === 'admin') {
            return null;
        }

        if ($user->role === 'student') {
            if (! $allowStudentSelf) {
                return response()->json(['message' => 'Unauthorized action.'], 403);
            }
            if (! $user->student) {
                return response()->json(['message' => 'Student profile not found.'], 404);
            }
            if (! $studentId || (int) $user->student->id !== $studentId) {
                return response()->json(['message' => 'Unauthorized access.'], 403);
            }

            return null;
        }

        if ($user->role === 'teacher') {
            $teacher = $this->resolveTeacherForUser($user);
            if (! $teacher) {
                return response()->json(['message' => 'Teacher profile not found.'], 403);
            }
            if ($courseId !== null && ! $this->teacherAuthService()->isCourseAuthorized($teacher, $courseId)) {
                return response()->json(['message' => 'Unauthorized access to course records.'], 403);
            }
            if (! $studentId || ! $this->teacherAuthService()->isStudentAuthorized($teacher, $studentId)) {
                return response()->json(['message' => 'Unauthorized access to student records.'], 403);
            }

            return null;
        }

        return response()->json(['message' => 'Unauthorized action.'], 403);
    }
}
