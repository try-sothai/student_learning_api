<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Models\StudentNote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentNoteController extends Controller
{
    public function index(Request $request, string|int $student): JsonResponse
    {
        $resolvedStudent = $this->resolveStudent($student);
        if (!$resolvedStudent) {
            return response()->json(['message' => 'Student not found.'], 404);
        }

        $actor = $request->user();
        $notes = StudentNote::with('author:id,name,role')
            ->where('student_id', $resolvedStudent->id)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (StudentNote $n) => $this->formatNote($n, $actor))
            ->values();

        return response()->json([
            'message' => 'Student notes retrieved successfully.',
            'data' => $notes,
        ], 200);
    }

    public function store(Request $request, string|int $student): JsonResponse
    {
        $resolvedStudent = $this->resolveStudent($student);
        if (!$resolvedStudent) {
            return response()->json(['message' => 'Student not found.'], 404);
        }

        $validated = $request->validate([
            'note' => ['required', 'string', 'max:5000'],
        ]);

        $actor = $request->user();
        $note = StudentNote::create([
            'student_id' => $resolvedStudent->id,
            'author_id' => $actor->id,
            'note' => trim($validated['note']),
        ]);
        $note->load('author:id,name,role');

        return response()->json([
            'message' => 'Student note saved successfully.',
            'data' => $this->formatNote($note, $actor),
        ], 201);
    }

    public function update(Request $request, string|int $student, int $noteId): JsonResponse
    {
        $resolvedStudent = $this->resolveStudent($student);
        if (!$resolvedStudent) {
            return response()->json(['message' => 'Student not found.'], 404);
        }

        $note = StudentNote::where('student_id', $resolvedStudent->id)
            ->where('id', $noteId)
            ->first();

        if (!$note) {
            return response()->json(['message' => 'Note not found.'], 404);
        }

        $actor = $request->user();
        if (!$this->canModifyNote($actor, $note)) {
            return response()->json(['message' => 'You do not have permission to edit this note.'], 403);
        }

        $validated = $request->validate([
            'note' => ['required', 'string', 'max:5000'],
        ]);

        $note->update([
            'note' => trim($validated['note']),
        ]);
        $note->load('author:id,name,role');

        return response()->json([
            'message' => 'Student note updated successfully.',
            'data' => $this->formatNote($note, $actor),
        ], 200);
    }

    public function destroy(Request $request, string|int $student, int $noteId): JsonResponse
    {
        $resolvedStudent = $this->resolveStudent($student);
        if (!$resolvedStudent) {
            return response()->json(['message' => 'Student not found.'], 404);
        }

        $note = StudentNote::where('student_id', $resolvedStudent->id)
            ->where('id', $noteId)
            ->first();

        if (!$note) {
            return response()->json(['message' => 'Note not found.'], 404);
        }

        $actor = $request->user();
        if (!$this->canModifyNote($actor, $note)) {
            return response()->json(['message' => 'You do not have permission to delete this note.'], 403);
        }

        $note->delete();

        return response()->json([
            'message' => 'Student note deleted successfully.',
        ], 200);
    }

    private function resolveStudent(string|int $identifier): ?Student
    {
        $raw = trim((string) $identifier);
        if ($raw === '') {
            return null;
        }

        if (is_numeric($raw)) {
            $byUser = Student::where('user_id', (int) $raw)->first();
            if ($byUser) {
                return $byUser;
            }
            $byId = Student::find((int) $raw);
            if ($byId) {
                return $byId;
            }
        }

        return Student::where('student_code', $raw)
            ->orWhere('student_id', $raw)
            ->first();
    }

    private function canModifyNote($actor, StudentNote $note): bool
    {
        if (!$actor) {
            return false;
        }
        $role = strtolower((string) ($actor->role ?? ''));
        if ($role === 'admin') {
            return true;
        }
        return (int) $note->author_id === (int) $actor->id;
    }

    private function formatNote(StudentNote $note, $actor): array
    {
        $canModify = $this->canModifyNote($actor, $note);
        return [
            'id' => $note->id,
            'student_id' => $note->student_id,
            'author_id' => $note->author_id,
            'author_name' => $note->author?->name ?? 'Administrator',
            'author_role' => $note->author?->role ?? 'admin',
            'note' => $note->note,
            'created_at' => $note->created_at?->toIso8601String(),
            'updated_at' => $note->updated_at?->toIso8601String(),
            'can_edit' => $canModify,
            'can_delete' => $canModify,
        ];
    }
}
