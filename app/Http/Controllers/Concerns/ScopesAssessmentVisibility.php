<?php

namespace App\Http\Controllers\Concerns;

use App\Services\AssessmentAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Role-aware visibility for the generic assessment resources
 * (assignments / quizzes / exams) so they can never list or open
 * records outside the caller's academic scope.
 *
 *  student -> only published/open/closed records of offerings they are enrolled in
 *  teacher -> only records of offerings they teach
 *  admin   -> everything
 */
trait ScopesAssessmentVisibility
{
    protected function scopeVisible(Builder $query, Request $request, string $type): Builder
    {
        $user = $request->user();
        abort_unless($user, 401, 'Unauthenticated.');
        $access = app(AssessmentAccessService::class);
        $scope = $access->resolveUserScope($user);

        if ($scope['student']) {
            $access->scopeForStudent($query, $scope['student']);
            $table = $query->getModel()->getTable();
            match ($type) {
                'quiz' => $query->whereIn("$table.status", ['published', 'open', 'closed']),
                'exam' => $query->whereNotIn("$table.status", ['draft', 'cancelled']),
                default => $query->whereIn("$table.status", ['published', 'closed']),
            };
        } elseif ($scope['teacher']) {
            $access->scopeForTeacher($query, $scope['teacher']);
        } elseif (! $scope['admin']) {
            abort(403, 'Forbidden.');
        }

        return $query;
    }

    protected function authorizeVisible(Model $model, Request $request, string $type): void
    {
        $found = $this->scopeVisible($model->newQuery(), $request, $type)->whereKey($model->getKey())->exists();
        abort_unless($found, 404, 'Record not found.');
    }
}
