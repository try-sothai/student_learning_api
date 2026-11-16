<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Shared notifications inbox for every role (student, teacher, admin).
 * Notifications are keyed by user_id, so the same logic works for anyone.
 */
class NotificationController extends Controller
{
    /**
     * Show the notifications inbox for the authenticated user, rendered with
     * the layout that matches their role (or JSON for API requests).
     */
    public function index(Request $request): View|JsonResponse
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            $apiUser = auth('sanctum')->user() ?? $request->user();
            $query = Notification::query()->latest();

            if ($apiUser) {
                if ($apiUser->role === 'admin' && $request->boolean('all', true)) {
                    // Admin can view system-wide notifications or their own
                    if ($request->filled('user_id')) {
                        $query->where('user_id', (int) $request->query('user_id'));
                    }
                } else {
                    $query->where('user_id', $apiUser->id);
                }
            } elseif ($request->filled('user_id')) {
                $query->where('user_id', (int) $request->query('user_id'));
            }

            if ($request->filled('type')) {
                $query->where('type', $request->query('type'));
            }
            if ($request->filled('is_read')) {
                $query->where('is_read', filter_var($request->query('is_read'), FILTER_VALIDATE_BOOLEAN));
            }

            $items = $query->limit(100)->get();
            $unreadCount = (clone $query)->where('is_read', false)->count();

            return response()->json([
                'message' => 'Notifications retrieved successfully.',
                'unread_count' => $unreadCount,
                'data' => $items,
            ], 200);
        }

        $userId = Auth::id();

        $notifications = Notification::where('user_id', $userId)
            ->latest()
            ->paginate(15);

        $all = Notification::where('user_id', $userId)->get();

        $summary = [
            'total' => $all->count(),
            'unread' => $all->where('is_read', false)->count(),
            'academic' => $all->filter(fn (Notification $n) => in_array($n->type, ['assignment', 'quiz', 'grade'], true))->count(),
            'system' => $all->filter(fn (Notification $n) => in_array($n->type, ['system', 'attendance', 'course'], true))->count(),
        ];

        $view = match (Auth::user()->role) {
            'admin' => 'pages.admin.notifications.index',
            'teacher' => 'pages.teacher.notifications.index',
            default => 'pages.student.notifications.index',
        };

        return view($view, [
            'notifications' => $notifications,
            'summary' => $summary,
            'readAllRoute' => $this->readAllRoute(),
        ]);
    }

    public function show(Request $request, Notification $notification): JsonResponse
    {
        $apiUser = auth('sanctum')->user() ?? $request->user();
        if ($apiUser && $apiUser->role === 'student' && (int) $notification->user_id !== (int) $apiUser->id) {
            return response()->json(['message' => 'Unauthorized access.'], 403);
        }

        return response()->json([
            'message' => 'Notification retrieved successfully.',
            'data' => $notification,
        ], 200);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => 'nullable|integer|exists:users,id',
            'target_role' => 'nullable|string|in:all,student,teacher,admin',
            'title' => 'required|string|max:255',
            'message' => 'required|string|max:2000',
            'type' => 'nullable|string|max:50',
        ]);

        $type = $validated['type'] ?? 'system';

        if (!empty($validated['user_id'])) {
            $notification = Notification::create([
                'user_id' => $validated['user_id'],
                'title' => $validated['title'],
                'message' => $validated['message'],
                'type' => $type,
                'is_read' => false,
            ]);

            return response()->json([
                'message' => 'Notification created successfully.',
                'data' => $notification,
            ], 201);
        }

        $targetRole = $validated['target_role'] ?? null;
        if ($targetRole) {
            $userIds = $targetRole === 'all'
                ? User::pluck('id')
                : User::where('role', $targetRole)->pluck('id');

            $created = null;
            foreach ($userIds as $uid) {
                $created = Notification::create([
                    'user_id' => $uid,
                    'title' => $validated['title'],
                    'message' => $validated['message'],
                    'type' => $type,
                    'is_read' => false,
                ]);
            }

            return response()->json([
                'message' => 'Notifications broadcast successfully.',
                'data' => $created,
            ], 201);
        }

        $apiUser = auth('sanctum')->user() ?? $request->user();
        $notification = Notification::create([
            'user_id' => $apiUser?->id ?? User::value('id'),
            'title' => $validated['title'],
            'message' => $validated['message'],
            'type' => $type,
            'is_read' => false,
        ]);

        return response()->json([
            'message' => 'Notification created successfully.',
            'data' => $notification,
        ], 201);
    }

    public function update(Request $request, Notification $notification): JsonResponse
    {
        $apiUser = auth('sanctum')->user() ?? $request->user();
        if ($apiUser && $apiUser->role === 'student' && (int) $notification->user_id !== (int) $apiUser->id) {
            return response()->json(['message' => 'Unauthorized access.'], 403);
        }

        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'message' => 'sometimes|required|string|max:2000',
            'type' => 'nullable|string|max:50',
            'is_read' => 'nullable|boolean',
        ]);

        if (! array_key_exists('is_read', $validated) && ($request->is('*/read') || empty($validated))) {
            $validated['is_read'] = true;
        }

        if (array_key_exists('is_read', $validated)) {
            $isRead = (bool) $validated['is_read'];
            $validated['is_read'] = $isRead;
            $validated['read_at'] = $isRead ? ($notification->read_at ?? now()) : null;
        }

        $notification->update($validated);

        return response()->json([
            'message' => 'Notification updated successfully.',
            'data' => $notification->fresh(),
        ], 200);
    }

    public function destroy(Notification $notification): JsonResponse
    {
        $notification->delete();

        return response()->json([
            'message' => 'Notification deleted successfully.',
        ], 200);
    }

    /**
     * Mark every unread notification for the current user as read.
     */
    public function markAllRead(Request $request): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            $apiUser = auth('sanctum')->user() ?? $request->user();
            $query = Notification::where('is_read', false);
            if ($apiUser && $apiUser->role !== 'admin') {
                $query->where('user_id', $apiUser->id);
            } elseif ($apiUser) {
                $query->where('user_id', $apiUser->id);
            }
            $query->update(['is_read' => true, 'read_at' => now()]);

            return response()->json([
                'message' => 'All notifications marked as read.',
            ], 200);
        }

        Notification::where('user_id', Auth::id())
            ->where('is_read', false)
            ->update(['is_read' => true, 'read_at' => now()]);

        return redirect()->route($this->indexRoute())
            ->with('status', 'All notifications marked as read.');
    }

    private function indexRoute(): string
    {
        return match (Auth::user()->role) {
            'admin' => 'admin.notifications',
            'teacher' => 'teacher.notifications',
            default => 'student.notifications',
        };
    }

    public function apiUnreadCount(Request $request): JsonResponse
    {
        $userId = $request->user()?->id;
        $count = Notification::where('user_id', $userId)->where('is_read', false)->count();

        if ($count == 0 && $request->user()?->role === 'admin') {
            $count = Notification::where('is_read', false)->count();
        }

        return response()->json([
            'status' => 'success',
            'unread_count' => $count,
            'count' => $count,
        ]);
    }

    private function readAllRoute(): string
    {
        return match (Auth::user()->role) {
            'admin' => 'admin.notifications.read-all',
            'teacher' => 'teacher.notifications.read-all',
            default => 'student.notifications.read-all',
        };
    }
}
