<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Student;
use App\Models\Teacher;
use App\Rules\ValidYearLevel;
use App\Support\YearLevel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminCourseController extends Controller
{
    public function show(Course $course): View
    {
        $course->load('teacher.user', 'students.user', 'students.classRoom', 'assignments');
        $availableStudents = Student::query()
            ->whereDoesntHave('courses', fn ($query) => $query->where('courses.id', $course->id))
            ->with('user')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        $teachers = Teacher::with('user')->orderBy('first_name')->get();

        return view('pages.admin.courses.show', compact('course', 'availableStudents', 'teachers'));
    }

    public function assignTeacher(Request $request, Course $course): RedirectResponse
    {
        $validated = $request->validate([
            'teacher_id' => 'nullable|exists:teachers,id',
        ]);

        $course->update(['teacher_id' => $validated['teacher_id'] ?? null]);

        return redirect()->route('admin.courses.show', $course)->with('status', 'Class teacher updated successfully.');
    }

    public function enrollStudents(Request $request, Course $course): RedirectResponse
    {
        $validated = $request->validate([
            'student_ids' => 'required|array|min:1',
            'student_ids.*' => 'required|integer|distinct|exists:students,id',
        ]);

        $studentLevels = Student::query()
            ->whereIn('id', $validated['student_ids'])
            ->pluck('year_level', 'id');

        $courseYearLevel = YearLevel::parse($course->year_level);

        foreach ($validated['student_ids'] as $studentId) {
            $course->students()->syncWithoutDetaching([
                $studentId => [
                    'status' => 'enrolled',
                    'year_level' => $courseYearLevel ?? YearLevel::parse($studentLevels->get($studentId)),
                ],
            ]);
        }

        return redirect()->route('admin.courses.show', $course)->with('status', 'Students enrolled in this course.');
    }

    public function removeStudent(Course $course, int $student): RedirectResponse
    {
        $course->students()->detach($student);

        return redirect()->route('admin.courses.show', $course)->with('status', 'Student removed from this course.');
    }

    public function edit(Course $course): View
    {
        return view('pages.admin.courses.edit', [
            'course' => $course,
            'teachers' => Teacher::with('user')->orderBy('first_name')->get(),
        ]);
    }

    public function update(Request $request, Course $course): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => ['required', 'string', 'max:50', Rule::unique('courses', 'code')->ignore($course->id)],
            'department' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:2000',
            'credits' => 'nullable|integer|min:1|max:30',
            'teacher_id' => 'nullable|exists:teachers,id',
            'academic_year' => 'nullable|string|max:20',
            'year_level' => ['nullable', new ValidYearLevel()],
            'semester' => 'nullable|string|max:50',
            'day_of_week' => 'nullable|string|max:20',
            'start_time' => 'nullable|date_format:H:i',
            'end_time' => 'nullable|date_format:H:i',
            'room' => 'nullable|string|max:100',
            'status' => 'required|in:active,inactive',
        ]);

        if (array_key_exists('year_level', $validated)) {
            $validated['year_level'] = filled($validated['year_level'])
                ? YearLevel::parse($validated['year_level'])
                : null;
        }

        $course->update($validated);

        return redirect()->route('admin.courses.show', $course)->with('status', 'Course updated successfully.');
    }

    public function destroy(Course $course): RedirectResponse
    {
        DB::transaction(function () use ($course) {
            $course->assignments()->forceDelete();
            $course->students()->detach();
            $course->forceDelete();
        });

        return redirect()->route('admin.courses')->with('status', 'Course and its assignments deleted.');
    }
}
