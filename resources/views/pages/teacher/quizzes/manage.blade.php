@extends('layouts.teacher')
@section('title', $quiz->title . ' · Quiz Reopen & Access · Teacher · SLAS')
@section('content')
@php
    $statusBadgeClass = function (?string $status): string {
        return match ($status) {
            'Reopened' => 'bg-primary-subtle text-primary',
            'Extended' => 'bg-info-subtle text-info',
            'Submitted' => 'bg-success-subtle text-success',
            'Late Submitted' => 'bg-warning-subtle text-warning',
            'In Progress' => 'bg-warning-subtle text-warning',
            'Expired' => 'bg-danger-subtle text-danger',
            default => 'bg-secondary-subtle text-secondary',
        };
    };
    $courseLabel = trim(($quiz->course?->code ? $quiz->course->code . ' · ' : '') . ($quiz->course?->name ?? 'Course'));
    $offeringLabel = $quiz->courseOffering?->classRoom?->name ?? $quiz->courseOffering?->section_name ?? 'All Enrolled Students';
@endphp

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <div class="small text-primary fw-semibold mb-1">
            <a href="{{ route('teacher.dashboard') }}" class="text-decoration-none">Dashboard</a>
            <span class="text-muted mx-1">/</span>
            <a href="{{ route('teacher.quizzes') }}" class="text-decoration-none">Quizzes</a>
            <span class="text-muted mx-1">/</span>
            Manage Access &amp; Reopen
        </div>
        <h1 class="h2 fw-bold mb-1">{{ $quiz->title }}</h1>
        <p class="text-muted mb-0">
            {{ $courseLabel }} • {{ $offeringLabel }}
            @if($quiz->semester?->name) • {{ $quiz->semester->name }} @endif
        </p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-primary" href="{{ route('teacher.quizzes') }}">
            <i class="bi bi-arrow-left me-1"></i>Back to Quizzes
        </a>
        <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#reopenWholeQuizModal">
            <i class="bi bi-unlock me-1"></i>Reopen Whole Quiz
        </button>
    </div>
</div>

@if(session('status'))
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        {{ session('status') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
@endif
@if(session('error'))
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        {{ session('error') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
@endif
@if($errors->any())
    <div class="alert alert-danger">
        <ul class="mb-0">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3">
        <div class="card portal-card border-0 h-100">
            <div class="card-body p-3 d-flex justify-content-between align-items-center">
                <div>
                    <div class="small text-muted">Eligible Students</div>
                    <div class="h3 fw-bold mb-0 mt-1">{{ number_format($allStudents->count()) }}</div>
                </div>
                <span class="portal-stat-icon"><i class="bi bi-people"></i></span>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card portal-card border-0 h-100">
            <div class="card-body p-3 d-flex justify-content-between align-items-center">
                <div>
                    <div class="small text-muted">Active Overrides</div>
                    <div class="h3 fw-bold mb-0 mt-1">{{ number_format($activeOverridesCount) }}</div>
                </div>
                <span class="portal-stat-icon"><i class="bi bi-shield-check"></i></span>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card portal-card border-0 h-100">
            <div class="card-body p-3 d-flex justify-content-between align-items-center">
                <div>
                    <div class="small text-muted">Recorded Attempts</div>
                    <div class="h3 fw-bold mb-0 mt-1">{{ number_format($totalRecordedAttempts) }}</div>
                </div>
                <span class="portal-stat-icon"><i class="bi bi-journal-check"></i></span>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card portal-card border-0 h-100">
            <div class="card-body p-3 d-flex justify-content-between align-items-center">
                <div>
                    <div class="small text-muted">Audit Log Events</div>
                    <div class="h3 fw-bold mb-0 mt-1">{{ number_format($totalAuditLogs) }}</div>
                </div>
                <span class="portal-stat-icon"><i class="bi bi-clock-history"></i></span>
            </div>
        </div>
    </div>
</div>

<div class="card portal-card border-0 mb-4">
    <div class="card-body p-4">
        <div class="row g-3 small">
            <div class="col-md-3">
                <div class="text-muted">Original Deadline</div>
                <div class="fw-semibold">{{ $quiz->end_at?->format('M d, Y H:i') ?? 'No deadline' }}</div>
                @if($quiz->start_at)
                    <div class="text-muted">Opens: {{ $quiz->start_at->format('M d, Y H:i') }}</div>
                @endif
            </div>
            <div class="col-md-3">
                <div class="text-muted">Base Attempt Limit &amp; Scoring</div>
                <div class="fw-semibold">
                    {{ $quiz->max_attempts ? $quiz->max_attempts . ' attempt(s)' : 'Unlimited attempts' }}
                    • {{ ucfirst($quiz->scoring_method ?: 'highest') }}
                </div>
                <div class="text-muted">{{ $totalQuestions }} question(s) • {{ number_format($totalMarks, 1) }} marks • {{ (int) ($quiz->duration_minutes ?: 30) }} min</div>
            </div>
            <div class="col-md-3">
                <div class="text-muted">Quiz Status &amp; Late Policy</div>
                <div class="fw-semibold">
                    {{ ucfirst($quiz->status) }}
                    @if($quiz->allow_late_submission)
                        <span class="badge bg-info-subtle text-info ms-1">Late Allowed</span>
                    @endif
                </div>
                @if($quiz->reopened_until)
                    <div class="text-primary">Global Reopen Until: {{ $quiz->reopened_until->format('M d, Y H:i') }}</div>
                @endif
            </div>
            <div class="col-md-3">
                <div class="text-muted">Single Source of Truth</div>
                <div class="fw-semibold">Unified AssessmentReopenService</div>
                <div class="text-muted">Synced across Web &amp; Android</div>
            </div>
        </div>
    </div>
</div>

<ul class="nav nav-tabs mb-3">
    <li class="nav-item">
        <a class="nav-link {{ $tab === 'students' ? 'active fw-semibold' : '' }}"
           href="{{ route('teacher.quizzes.manage', ['quiz' => $quiz->id, 'tab' => 'students']) }}">
            <i class="bi bi-people me-1"></i>Students &amp; Permissions ({{ $allStudents->count() }})
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link {{ $tab === 'attempts' ? 'active fw-semibold' : '' }}"
           href="{{ route('teacher.quizzes.manage', ['quiz' => $quiz->id, 'tab' => 'attempts']) }}">
            <i class="bi bi-list-check me-1"></i>Attempt History ({{ $totalRecordedAttempts }})
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link {{ $tab === 'audit-logs' ? 'active fw-semibold' : '' }}"
           href="{{ route('teacher.quizzes.manage', ['quiz' => $quiz->id, 'tab' => 'audit-logs']) }}">
            <i class="bi bi-journal-text me-1"></i>Audit Logs ({{ $totalAuditLogs }})
        </a>
    </li>
</ul>

@if($tab === 'students')
    <div class="card portal-card border-0">
        <div class="card-body p-4 border-bottom">
            <form method="GET" action="{{ route('teacher.quizzes.manage', $quiz->id) }}" class="row g-2 align-items-end">
                <input type="hidden" name="tab" value="students">
                <div class="col-md-5">
                    <label class="form-label small text-muted mb-1" for="filterSearch">Search Student</label>
                    <input class="form-control" id="filterSearch" name="search" type="search" value="{{ $search }}" placeholder="Search by student name, code, or email">
                </div>
                <div class="col-md-4">
                    <label class="form-label small text-muted mb-1" for="filterStatus">Permission Status</label>
                    <select class="form-select" id="filterStatus" name="status">
                        <option value="">All 7 Statuses</option>
                        @foreach($statusOptions as $opt)
                            <option value="{{ $opt }}" @selected($statusFilter === $opt)>{{ $opt }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button class="btn btn-outline-primary flex-grow-1" type="submit">Filter</button>
                    @if($search !== '' || $statusFilter !== '')
                        <a class="btn btn-light" href="{{ route('teacher.quizzes.manage', ['quiz' => $quiz->id, 'tab' => 'students']) }}">Reset</a>
                    @endif
                </div>
            </form>

            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3 pt-3 border-top">
                <div class="d-flex align-items-center gap-2">
                    <input class="form-check-input mt-0" id="selectAllStudentsCheckbox" type="checkbox">
                    <label class="small fw-semibold mb-0" for="selectAllStudentsCheckbox">Select All Shown</label>
                    <span class="badge bg-light text-dark border" id="selectedStudentsBadge">0 selected</span>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <button class="btn btn-sm btn-primary" type="button" id="btnBulkReopen">
                        <i class="bi bi-unlock me-1"></i>Reopen / Override Selected
                    </button>
                    <button class="btn btn-sm btn-outline-primary" type="button" id="btnBulkExtend">
                        <i class="bi bi-calendar-plus me-1"></i>Extend Deadline
                    </button>
                    <button class="btn btn-sm btn-outline-secondary" type="button" id="btnBulkGrantAttempts">
                        <i class="bi bi-plus-circle me-1"></i>+Attempts
                    </button>
                    <button class="btn btn-sm btn-outline-danger" type="button" id="btnBulkRevoke">
                        <i class="bi bi-slash-circle me-1"></i>Revoke Selected
                    </button>
                </div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table portal-table align-middle mb-0">
                <thead>
                    <tr>
                        <th class="ps-4" style="width:36px"></th>
                        <th>Student</th>
                        <th>Status</th>
                        <th>Attempts</th>
                        <th>Deadlines</th>
                        <th>Effective Permissions &amp; Score</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($studentRows as $row)
                        @php
                            $ov = $row['override'] ?? [];
                            $extLocal = !empty($row['extended_deadline'])
                                ? \Carbon\Carbon::parse($row['extended_deadline'])->format('Y-m-d\TH:i')
                                : '';
                            $origDlLabel = !empty($row['original_deadline'])
                                ? \Carbon\Carbon::parse($row['original_deadline'])->format('M d, Y H:i')
                                : 'No deadline';
                            $extDlLabel = !empty($row['extended_deadline'])
                                ? \Carbon\Carbon::parse($row['extended_deadline'])->format('M d, Y H:i')
                                : null;
                        @endphp
                        <tr>
                            <td class="ps-4">
                                <input class="form-check-input student-select-checkbox"
                                       type="checkbox"
                                       value="{{ $row['student_id'] }}"
                                       data-student-name="{{ $row['student_name'] }}">
                            </td>
                            <td>
                                <div class="fw-semibold">{{ $row['student_name'] }}</div>
                                <div class="small text-muted">{{ $row['student_code'] }} @if(!empty($row['email'])) • {{ $row['email'] }} @endif</div>
                            </td>
                            <td>
                                <span class="badge {{ $statusBadgeClass($row['reopen_status']) }}">{{ $row['reopen_status'] }}</span>
                                <div class="small text-muted mt-1">{{ $row['student_submission_status'] }}</div>
                            </td>
                            <td class="small">
                                <div class="fw-semibold">
                                    {{ $row['used_attempts'] }} / {{ $row['effective_attempt_limit'] ?? '∞' }}
                                    @if((int) $row['additional_attempts'] > 0)
                                        <span class="badge bg-primary-subtle text-primary ms-1">+{{ $row['additional_attempts'] }}</span>
                                    @endif
                                </div>
                                <div class="text-muted">
                                    {{ $row['remaining_attempts'] === null ? 'Unlimited left' : ($row['remaining_attempts'] . ' left') }}
                                </div>
                            </td>
                            <td class="small">
                                <div>Orig: {{ $origDlLabel }}</div>
                                @if($extDlLabel)
                                    <div class="text-primary fw-semibold">Ext: {{ $extDlLabel }}</div>
                                @endif
                            </td>
                            <td class="small">
                                <div class="d-flex flex-wrap gap-1 mb-1">
                                    <span class="badge {{ $row['allow_submission'] ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger' }}">
                                        {{ $row['allow_submission'] ? 'Allowed' : 'Blocked' }}
                                    </span>
                                    @if($row['allow_late_submission'])
                                        <span class="badge bg-info-subtle text-info">Late OK</span>
                                    @endif
                                    @if($row['allow_resubmission'])
                                        <span class="badge bg-primary-subtle text-primary">Retake OK</span>
                                    @endif
                                </div>
                                @if($row['latest_score'] !== null)
                                    <div class="fw-semibold text-dark">Score: {{ number_format((float) $row['latest_score'], 1) }} / {{ number_format($totalMarks, 1) }}</div>
                                @endif
                                @if(!empty($ov['reason']))
                                    <div class="text-muted text-truncate" style="max-width:220px" title="{{ $ov['reason'] }}">Note: {{ $ov['reason'] }}</div>
                                @endif
                            </td>
                            <td class="text-end pe-4">
                                <div class="d-inline-flex flex-wrap justify-content-end gap-1">
                                    <button class="btn btn-sm btn-primary btn-row-reopen"
                                            type="button"
                                            data-student-id="{{ $row['student_id'] }}"
                                            data-student-name="{{ $row['student_name'] }}"
                                            data-allow-submission="{{ $row['allow_submission'] ? '1' : '0' }}"
                                            data-allow-resubmission="{{ $row['allow_resubmission'] ? '1' : '0' }}"
                                            data-allow-late="{{ $row['allow_late_submission'] ? '1' : '0' }}"
                                            data-effective-limit="{{ $row['effective_attempt_limit'] ?? '' }}"
                                            data-extended-deadline="{{ $extLocal }}"
                                            data-reason="{{ $ov['reason'] ?? '' }}"
                                            data-notes="{{ $ov['teacher_notes'] ?? '' }}">
                                        Reopen
                                    </button>
                                    <button class="btn btn-sm btn-outline-primary btn-row-extend"
                                            type="button"
                                            data-student-id="{{ $row['student_id'] }}"
                                            data-student-name="{{ $row['student_name'] }}"
                                            data-extended-deadline="{{ $extLocal }}">
                                        Extend
                                    </button>
                                    <button class="btn btn-sm btn-outline-secondary btn-row-attempts"
                                            type="button"
                                            data-student-id="{{ $row['student_id'] }}"
                                            data-student-name="{{ $row['student_name'] }}">
                                        +Attempts
                                    </button>
                                    <a class="btn btn-sm btn-outline-secondary"
                                       href="{{ route('teacher.quizzes.manage', ['quiz' => $quiz->id, 'tab' => 'attempts', 'student_id' => $row['student_id']]) }}">
                                        History
                                    </a>
                                    @if($row['has_active_override'])
                                        <button class="btn btn-sm btn-outline-danger btn-row-revoke"
                                                type="button"
                                                data-student-id="{{ $row['student_id'] }}"
                                                data-student-name="{{ $row['student_name'] }}">
                                            Revoke
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted py-5">No matching enrolled students found for this quiz.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@elseif($tab === 'attempts')
    <div class="card portal-card border-0 mb-4">
        <div class="card-body p-4">
            <form method="GET" action="{{ route('teacher.quizzes.manage', $quiz->id) }}" class="row g-2 align-items-end">
                <input type="hidden" name="tab" value="attempts">
                <div class="col-md-6">
                    <label class="form-label small text-muted mb-1" for="attemptStudentFilter">Filter by Student</label>
                    <select class="form-select" id="attemptStudentFilter" name="student_id">
                        <option value="">All Students</option>
                        @foreach($allStudents as $stRow)
                            <option value="{{ $stRow['student_id'] }}" @selected($studentFilterId === (int) $stRow['student_id'])>
                                {{ $stRow['student_name'] }} ({{ $stRow['student_code'] }})
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button class="btn btn-outline-primary flex-grow-1" type="submit">Apply</button>
                    @if($studentFilterId)
                        <a class="btn btn-light" href="{{ route('teacher.quizzes.manage', ['quiz' => $quiz->id, 'tab' => 'attempts']) }}">Reset</a>
                    @endif
                </div>
            </form>
        </div>
    </div>

    @if($studentSummaries->isNotEmpty())
        <div class="card portal-card border-0 mb-4">
            <div class="card-body p-4 pb-3"><h2 class="h5 fw-bold mb-0">Official Score Summary ({{ ucfirst($quiz->scoring_method ?: 'highest') }})</h2></div>
            <div class="table-responsive">
                <table class="table portal-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="ps-4">Student</th>
                            <th>Attempts Used</th>
                            <th>Total Recorded</th>
                            <th>Scoring Policy</th>
                            <th class="text-end pe-4">Effective Score</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($studentSummaries as $sum)
                            <tr>
                                <td class="ps-4 fw-semibold">{{ $sum['student_name'] }} <span class="text-muted small">({{ $sum['student_code'] }})</span></td>
                                <td>{{ $sum['attempts_used'] }}</td>
                                <td>{{ $sum['total_attempts_recorded'] }}</td>
                                <td>{{ ucfirst($sum['scoring_method']) }}</td>
                                <td class="text-end pe-4 fw-bold">
                                    {{ $sum['effective_score'] !== null ? number_format((float) $sum['effective_score'], 1) . ' / ' . number_format($totalMarks, 1) : 'Pending Grading' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <div class="card portal-card border-0">
        <div class="card-body p-4 pb-3"><h2 class="h5 fw-bold mb-0">All Recorded Quiz Attempts &amp; Preserved Answers</h2></div>
        <div class="table-responsive">
            <table class="table portal-table align-middle mb-0">
                <thead>
                    <tr>
                        <th class="ps-4">Attempt</th>
                        <th>Student</th>
                        <th>Timestamps</th>
                        <th>Status &amp; Flags</th>
                        <th>Score</th>
                        <th class="text-end pe-4">Answers</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($attemptRows as $att)
                        <tr>
                            <td class="ps-4 fw-semibold">#{{ $att['attempt_number'] }}</td>
                            <td>
                                <div class="fw-semibold">{{ $att['student_name'] }}</div>
                                <div class="small text-muted">{{ $att['student_code'] }}</div>
                            </td>
                            <td class="small">
                                <div>Started: {{ $att['started_at']?->format('M d, Y H:i') ?? '-' }}</div>
                                <div class="text-muted">Submitted: {{ $att['submitted_at']?->format('M d, Y H:i') ?? 'In Progress' }}</div>
                            </td>
                            <td>
                                <span class="badge bg-secondary-subtle text-secondary">{{ ucfirst(str_replace('_', ' ', (string) $att['attempt_status'])) }}</span>
                                <span class="badge {{ $att['grading_status'] === 'pending_grading' ? 'bg-warning-subtle text-warning' : 'bg-success-subtle text-success' }}">{{ ucfirst(str_replace('_', ' ', (string) $att['grading_status'])) }}</span>
                                @if($att['is_late'])
                                    <span class="badge bg-info-subtle text-info">Late</span>
                                @endif
                                @if($att['is_reset'])
                                    <span class="badge bg-danger-subtle text-danger">Reset</span>
                                @endif
                            </td>
                            <td class="fw-semibold">
                                @if($att['score'] !== null)
                                    {{ number_format((float) $att['score'], 1) }} / {{ number_format((float) $att['total_marks'], 1) }}
                                @else
                                    <span class="text-muted">Pending</span>
                                @endif
                            </td>
                            <td class="text-end pe-4">
                                <button class="btn btn-sm btn-outline-primary"
                                        type="button"
                                        data-bs-toggle="collapse"
                                        data-bs-target="#attemptAnswers{{ $att['attempt_id'] }}"
                                        aria-expanded="false">
                                    View Answers ({{ count($att['answers']) }})
                                </button>
                            </td>
                        </tr>
                        <tr class="collapse bg-light" id="attemptAnswers{{ $att['attempt_id'] }}">
                            <td colspan="6" class="p-3 ps-4 pe-4">
                                @if(empty($att['answers']))
                                    <div class="small text-muted">No question responses recorded for this attempt.</div>
                                @else
                                    <div class="table-responsive">
                                        <table class="table table-sm table-bordered bg-white mb-0">
                                            <thead>
                                                <tr>
                                                    <th>#</th>
                                                    <th>Question</th>
                                                    <th>Student Answer</th>
                                                    <th>Correct Key</th>
                                                    <th>Result &amp; Marks</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach($att['answers'] as $ans)
                                                    <tr>
                                                        <td>{{ $ans['number'] }}</td>
                                                        <td>
                                                            <div class="fw-semibold">{{ $ans['question_text'] }}</div>
                                                            <div class="small text-muted">{{ ucfirst(str_replace('_', ' ', $ans['question_type'])) }}</div>
                                                        </td>
                                                        <td>{{ $ans['student_answer'] !== null && $ans['student_answer'] !== '' ? $ans['student_answer'] : '—' }}</td>
                                                        <td>{{ $ans['correct_answer'] ?? '—' }}</td>
                                                        <td class="small">
                                                            @if($ans['is_correct'] === true)
                                                                <span class="badge bg-success-subtle text-success">Correct</span>
                                                            @elseif($ans['is_correct'] === false)
                                                                <span class="badge bg-danger-subtle text-danger">Incorrect</span>
                                                            @else
                                                                <span class="badge bg-warning-subtle text-warning">{{ ucfirst(str_replace('_', ' ', $ans['grading_status'])) }}</span>
                                                            @endif
                                                            <span class="ms-1 fw-semibold">
                                                                {{ $ans['marks_obtained'] !== null ? number_format((float) $ans['marks_obtained'], 1) : '0' }} / {{ number_format((float) $ans['marks'], 1) }}
                                                            </span>
                                                            @if(!empty($ans['teacher_feedback']))
                                                                <div class="text-muted mt-1">Feedback: {{ $ans['teacher_feedback'] }}</div>
                                                            @endif
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-5">No quiz attempts have been recorded yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@else
    <div class="card portal-card border-0 mb-4">
        <div class="card-body p-4">
            <form method="GET" action="{{ route('teacher.quizzes.manage', $quiz->id) }}" class="row g-2 align-items-end">
                <input type="hidden" name="tab" value="audit-logs">
                <div class="col-md-3">
                    <label class="form-label small text-muted mb-1" for="logStudentFilter">Student</label>
                    <select class="form-select" id="logStudentFilter" name="student_id">
                        <option value="">All Students</option>
                        @foreach($allStudents as $stRow)
                            <option value="{{ $stRow['student_id'] }}" @selected($studentFilterId === (int) $stRow['student_id'])>
                                {{ $stRow['student_name'] }} ({{ $stRow['student_code'] }})
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small text-muted mb-1" for="logActionFilter">Action Type</label>
                    <select class="form-select" id="logActionFilter" name="action_type">
                        <option value="">All Actions</option>
                        @foreach($actionTypes as $act)
                            <option value="{{ $act }}" @selected($actionFilter === $act)>{{ $act }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1" for="logDateFrom">From</label>
                    <input class="form-control" id="logDateFrom" name="date_from" type="date" value="{{ $dateFrom }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1" for="logDateTo">To</label>
                    <input class="form-control" id="logDateTo" name="date_to" type="date" value="{{ $dateTo }}">
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button class="btn btn-outline-primary flex-grow-1" type="submit">Filter</button>
                    @if($studentFilterId || $actionFilter !== '' || $dateFrom !== '' || $dateTo !== '')
                        <a class="btn btn-light" href="{{ route('teacher.quizzes.manage', ['quiz' => $quiz->id, 'tab' => 'audit-logs']) }}">Reset</a>
                    @endif
                </div>
            </form>
        </div>
    </div>

    <div class="card portal-card border-0">
        <div class="card-body p-4 pb-3"><h2 class="h5 fw-bold mb-0">Quiz Reopen &amp; Override Audit Trail</h2></div>
        <div class="table-responsive">
            <table class="table portal-table align-middle mb-0">
                <thead>
                    <tr>
                        <th class="ps-4">Timestamp</th>
                        <th>Action</th>
                        <th>Student / Scope</th>
                        <th>Performed By</th>
                        <th>Deadline Change</th>
                        <th>Attempt Limit</th>
                        <th class="pe-4">Reason &amp; Notes</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($auditLogs as $log)
                        <tr>
                            <td class="ps-4 small">{{ $log->created_at?->format('M d, Y H:i') ?? '-' }}</td>
                            <td><span class="badge bg-primary-subtle text-primary">{{ strtoupper((string) $log->action_type) }}</span></td>
                            <td class="small">
                                @if($log->student)
                                    <div class="fw-semibold">{{ $log->student->english_name ?: trim(($log->student->first_name ?? '') . ' ' . ($log->student->last_name ?? '')) }}</div>
                                    <div class="text-muted">{{ $log->student->student_code }}</div>
                                @else
                                    <span class="badge bg-info-subtle text-info">Whole Quiz</span>
                                @endif
                            </td>
                            <td class="small">{{ $log->performer?->name ?? 'System' }}</td>
                            <td class="small">
                                {{ $log->previous_deadline?->format('M d H:i') ?? '—' }} →
                                <span class="fw-semibold">{{ $log->new_deadline?->format('M d H:i') ?? '—' }}</span>
                            </td>
                            <td class="small">
                                {{ $log->previous_attempt_limit ?? '∞' }} →
                                <span class="fw-semibold">{{ $log->new_attempt_limit ?? '∞' }}</span>
                            </td>
                            <td class="pe-4 small">
                                <div>{{ $log->reason ?: '—' }}</div>
                                @if($log->teacher_notes)
                                    <div class="text-muted">Note: {{ $log->teacher_notes }}</div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted py-5">No audit log events recorded for this quiz yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endif

<!-- Modal 1: Student Reopen & Permission Override -->
<div class="modal fade" id="reopenStudentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form class="modal-content border-0" method="POST" action="{{ route('teacher.quizzes.reopen', $quiz->id) }}">
            @csrf
            <div id="reopenStudentInputs"></div>
            <div class="modal-header border-0">
                <div>
                    <h2 class="modal-title h5 fw-bold" id="reopenStudentModalTitle">Reopen Quiz &amp; Override Permissions</h2>
                    <div class="small text-muted" id="reopenStudentTargetLabel">Selected student(s)</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body pt-0">
                <div class="row g-3">
                    <div class="col-md-4">
                        <input type="hidden" name="allow_submission" value="0">
                        <div class="form-check form-switch">
                            <input class="form-check-input" id="modalAllowSubmission" name="allow_submission" type="checkbox" value="1" checked>
                            <label class="form-check-label fw-semibold" for="modalAllowSubmission">Allow Submission</label>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <input type="hidden" name="allow_resubmission" value="0">
                        <div class="form-check form-switch">
                            <input class="form-check-input" id="modalAllowResubmission" name="allow_resubmission" type="checkbox" value="1" checked>
                            <label class="form-check-label fw-semibold" for="modalAllowResubmission">Allow Retake</label>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <input type="hidden" name="allow_late_submission" value="0">
                        <div class="form-check form-switch">
                            <input class="form-check-input" id="modalAllowLate" name="allow_late_submission" type="checkbox" value="1" checked>
                            <label class="form-check-label fw-semibold" for="modalAllowLate">Allow Late Attempt</label>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mt-1">
                    <div class="col-md-6">
                        <label class="form-label" for="modalAdditionalAttempts">+Additional Attempts</label>
                        <input class="form-control" id="modalAdditionalAttempts" name="additional_attempts" type="number" min="0" max="50" value="0">
                        <div class="form-text">Adds extra attempts on top of current limit.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="modalTargetAttemptLimit">Override Total Attempt Limit (Optional)</label>
                        <input class="form-control" id="modalTargetAttemptLimit" name="target_attempt_limit" type="number" min="{{ $quiz->max_attempts ?: 1 }}" max="100" placeholder="Leave blank to keep/add extra attempts">
                        <div class="form-text" id="modalLimitHelp">Base quiz limit: {{ $quiz->max_attempts ?: 'Unlimited' }}.</div>
                    </div>
                </div>

                <div class="row g-3 mt-1">
                    <div class="col-md-7">
                        <label class="form-label" for="modalReopenDeadline">Extended Deadline (Preserves Original Deadline)</label>
                        <input class="form-control" id="modalReopenDeadline" name="new_deadline" type="datetime-local">
                    </div>
                    <div class="col-md-5 d-flex align-items-end pb-2">
                        <div>
                            <input type="hidden" name="reset_attempts" value="0">
                            <div class="form-check">
                                <input class="form-check-input" id="modalResetAttempts" name="reset_attempts" type="checkbox" value="1">
                                <label class="form-check-label" for="modalResetAttempts">Reset attempt counter (archives history)</label>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-3">
                    <label class="form-label" for="modalReopenReason">Reason</label>
                    <input class="form-control" id="modalReopenReason" name="reason" type="text" maxlength="1000" placeholder="e.g., Medical excuse / Approved retake">
                </div>
                <div class="mt-3">
                    <label class="form-label" for="modalReopenNotes">Teacher Notes (Optional)</label>
                    <textarea class="form-control" id="modalReopenNotes" name="teacher_notes" rows="2" maxlength="2000"></textarea>
                </div>
            </div>
            <div class="modal-footer border-0">
                <button class="btn btn-light" type="button" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary" type="submit">Apply Reopen &amp; Save</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal 2: Whole-Quiz Reopen -->
<div class="modal fade" id="reopenWholeQuizModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content border-0" method="POST" action="{{ route('teacher.quizzes.reopen', $quiz->id) }}">
            @csrf
            <input type="hidden" name="scope" value="assessment">
            <div class="modal-header border-0">
                <h2 class="modal-title h5 fw-bold">Reopen Whole Quiz</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body pt-0">
                <p class="small text-muted">Publishes/reopens this quiz for the class without removing student-specific overrides or historical attempts.</p>
                <div class="mb-3">
                    <label class="form-label" for="wholeQuizDeadline">Reopened Until (Optional)</label>
                    <input class="form-control" id="wholeQuizDeadline" name="new_deadline" type="datetime-local">
                </div>
                <input type="hidden" name="allow_late_submission" value="0">
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" id="wholeQuizAllowLate" name="allow_late_submission" type="checkbox" value="1" checked>
                    <label class="form-check-label" for="wholeQuizAllowLate">Allow Late Quiz Submissions</label>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="wholeQuizReason">Reason</label>
                    <input class="form-control" id="wholeQuizReason" name="reason" type="text" maxlength="1000">
                </div>
            </div>
            <div class="modal-footer border-0">
                <button class="btn btn-light" type="button" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary" type="submit">Reopen Whole Quiz</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal 3: Extend Deadline -->
<div class="modal fade" id="extendDeadlineModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content border-0" method="POST" action="{{ route('teacher.quizzes.extend-deadline', $quiz->id) }}">
            @csrf
            <div id="extendStudentInputs"></div>
            <div class="modal-header border-0">
                <div>
                    <h2 class="modal-title h5 fw-bold">Extend Quiz Deadline</h2>
                    <div class="small text-muted" id="extendStudentTargetLabel">Selected student(s)</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body pt-0">
                <div class="mb-3">
                    <label class="form-label" for="extendNewDeadline">Extended Deadline</label>
                    <input class="form-control" id="extendNewDeadline" name="new_deadline" type="datetime-local" required>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="extendReason">Reason</label>
                    <input class="form-control" id="extendReason" name="reason" type="text" maxlength="1000">
                </div>
                <div>
                    <label class="form-label" for="extendNotes">Teacher Notes (Optional)</label>
                    <textarea class="form-control" id="extendNotes" name="teacher_notes" rows="2" maxlength="2000"></textarea>
                </div>
            </div>
            <div class="modal-footer border-0">
                <button class="btn btn-light" type="button" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary" type="submit">Save Extension</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal 4: Grant Additional Attempts -->
<div class="modal fade" id="grantAttemptsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content border-0" method="POST" action="{{ route('teacher.quizzes.grant-attempts', $quiz->id) }}">
            @csrf
            <div id="grantStudentInputs"></div>
            <div class="modal-header border-0">
                <div>
                    <h2 class="modal-title h5 fw-bold">Grant Additional Quiz Attempts</h2>
                    <div class="small text-muted" id="grantStudentTargetLabel">Selected student(s)</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body pt-0">
                <div class="mb-3">
                    <label class="form-label" for="grantAdditionalInput">Additional Attempts</label>
                    <input class="form-control" id="grantAdditionalInput" name="additional_attempts" type="number" min="1" max="50" value="1" required>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="grantReason">Reason</label>
                    <input class="form-control" id="grantReason" name="reason" type="text" maxlength="1000">
                </div>
                <div>
                    <label class="form-label" for="grantNotes">Teacher Notes (Optional)</label>
                    <textarea class="form-control" id="grantNotes" name="teacher_notes" rows="2" maxlength="2000"></textarea>
                </div>
            </div>
            <div class="modal-footer border-0">
                <button class="btn btn-light" type="button" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary" type="submit">Grant Attempts</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal 5: Revoke Override Permissions -->
<div class="modal fade" id="revokeOverrideModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content border-0" method="POST" action="{{ route('teacher.quizzes.revoke', $quiz->id) }}">
            @csrf
            <div id="revokeStudentInputs"></div>
            <div class="modal-header border-0">
                <div>
                    <h2 class="modal-title h5 fw-bold text-danger">Revoke Reopen &amp; Override Permissions</h2>
                    <div class="small text-muted" id="revokeStudentTargetLabel">Selected student(s)</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body pt-0">
                <p class="small text-muted">
                    Deactivates the active reopen/extension/extra-attempt override immediately across Web and Android.
                    Previous quiz attempts, answers, and scores remain completely intact.
                </p>
                <div>
                    <label class="form-label" for="revokeReason">Revocation Reason (Optional)</label>
                    <input class="form-control" id="revokeReason" name="reason" type="text" maxlength="1000">
                </div>
            </div>
            <div class="modal-footer border-0">
                <button class="btn btn-light" type="button" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-danger" type="submit">Revoke Permission</button>
            </div>
        </form>
    </div>
</div>

    <div class="d-none" aria-hidden="true">
        <button type="button" id="triggerReopenModal" data-bs-toggle="modal" data-bs-target="#reopenStudentModal"></button>
        <button type="button" id="triggerExtendModal" data-bs-toggle="modal" data-bs-target="#extendDeadlineModal"></button>
        <button type="button" id="triggerGrantModal" data-bs-toggle="modal" data-bs-target="#grantAttemptsModal"></button>
        <button type="button" id="triggerRevokeModal" data-bs-toggle="modal" data-bs-target="#revokeOverrideModal"></button>
    </div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const checkboxes = Array.from(document.querySelectorAll('.student-select-checkbox'));
    const selectAll = document.getElementById('selectAllStudentsCheckbox');
    const selectedBadge = document.getElementById('selectedStudentsBadge');

    function getSelected() {
        return checkboxes.filter((cb) => cb.checked).map((cb) => ({
            id: cb.value,
            name: cb.dataset.studentName || ('Student #' + cb.value),
        }));
    }

    function syncSelectionUi() {
        const count = checkboxes.filter((cb) => cb.checked).length;
        if (selectedBadge) selectedBadge.textContent = count + ' selected';
        if (selectAll) selectAll.checked = checkboxes.length > 0 && count === checkboxes.length;
    }

    if (selectAll) {
        selectAll.addEventListener('change', () => {
            checkboxes.forEach((cb) => { cb.checked = selectAll.checked; });
            syncSelectionUi();
        });
    }
    checkboxes.forEach((cb) => cb.addEventListener('change', syncSelectionUi));

    function fillStudentInputs(containerId, items) {
        const container = document.getElementById(containerId);
        if (!container) return;
        container.innerHTML = '';
        items.forEach((item) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'student_ids[]';
            input.value = String(item.id);
            container.appendChild(input);
        });
    }

    function openBootstrapModal(modalId, fallbackTriggerId) {
        const el = document.getElementById(modalId);
        if (el && window.bootstrap && window.bootstrap.Modal) {
            window.bootstrap.Modal.getOrCreateInstance(el).show();
            return;
        }
        const trig = fallbackTriggerId ? document.getElementById(fallbackTriggerId) : null;
        if (trig) trig.click();
    }

    function openReopenModal(items, opts = {}) {
        fillStudentInputs('reopenStudentInputs', items);
        document.getElementById('reopenStudentTargetLabel').textContent =
            items.length === 1 ? items[0].name : (items.length + ' selected students');
        document.getElementById('modalAllowSubmission').checked = opts.allowSubmission !== '0';
        document.getElementById('modalAllowResubmission').checked = opts.allowResubmission !== '0';
        document.getElementById('modalAllowLate').checked = opts.allowLate !== '0';
        document.getElementById('modalAdditionalAttempts').value = '0';
        document.getElementById('modalTargetAttemptLimit').value = '';
        document.getElementById('modalLimitHelp').textContent =
            opts.effectiveLimit ? ('Current effective limit: ' + opts.effectiveLimit + ' (Base: {{ $quiz->max_attempts ?: "Unlimited" }})')
                                : 'Base quiz limit: {{ $quiz->max_attempts ?: "Unlimited" }}';
        document.getElementById('modalReopenDeadline').value = opts.extendedDeadline || '';
        document.getElementById('modalResetAttempts').checked = false;
        document.getElementById('modalReopenReason').value = opts.reason || '';
        document.getElementById('modalReopenNotes').value = opts.notes || '';
        openBootstrapModal('reopenStudentModal', 'triggerReopenModal');
    }

    function openExtendModal(items, presetDeadline = '') {
        fillStudentInputs('extendStudentInputs', items);
        document.getElementById('extendStudentTargetLabel').textContent =
            items.length === 1 ? items[0].name : (items.length + ' selected students');
        document.getElementById('extendNewDeadline').value = presetDeadline;
        openBootstrapModal('extendDeadlineModal', 'triggerExtendModal');
    }

    function openGrantModal(items) {
        fillStudentInputs('grantStudentInputs', items);
        document.getElementById('grantStudentTargetLabel').textContent =
            items.length === 1 ? items[0].name : (items.length + ' selected students');
        document.getElementById('grantAdditionalInput').value = '1';
        openBootstrapModal('grantAttemptsModal', 'triggerGrantModal');
    }

    function openRevokeModal(items) {
        fillStudentInputs('revokeStudentInputs', items);
        document.getElementById('revokeStudentTargetLabel').textContent =
            items.length === 1 ? items[0].name : (items.length + ' selected students');
        openBootstrapModal('revokeOverrideModal', 'triggerRevokeModal');
    }

    document.querySelectorAll('.btn-row-reopen').forEach((btn) => {
        btn.addEventListener('click', () => {
            openReopenModal([{ id: btn.dataset.studentId, name: btn.dataset.studentName }], {
                allowSubmission: btn.dataset.allowSubmission,
                allowResubmission: btn.dataset.allowResubmission,
                allowLate: btn.dataset.allowLate,
                effectiveLimit: btn.dataset.effectiveLimit,
                extendedDeadline: btn.dataset.extendedDeadline,
                reason: btn.dataset.reason,
                notes: btn.dataset.notes,
            });
        });
    });

    document.querySelectorAll('.btn-row-extend').forEach((btn) => {
        btn.addEventListener('click', () => {
            openExtendModal([{ id: btn.dataset.studentId, name: btn.dataset.studentName }], btn.dataset.extendedDeadline || '');
        });
    });

    document.querySelectorAll('.btn-row-attempts').forEach((btn) => {
        btn.addEventListener('click', () => {
            openGrantModal([{ id: btn.dataset.studentId, name: btn.dataset.studentName }]);
        });
    });

    document.querySelectorAll('.btn-row-revoke').forEach((btn) => {
        btn.addEventListener('click', () => {
            openRevokeModal([{ id: btn.dataset.studentId, name: btn.dataset.studentName }]);
        });
    });

    function requireSelection(callback) {
        const items = getSelected();
        if (items.length === 0) {
            window.alert('Select at least one student first.');
            return;
        }
        callback(items);
    }

    const bulkReopenBtn = document.getElementById('btnBulkReopen');
    const bulkExtendBtn = document.getElementById('btnBulkExtend');
    const bulkGrantBtn = document.getElementById('btnBulkGrantAttempts');
    const bulkRevokeBtn = document.getElementById('btnBulkRevoke');

    if (bulkReopenBtn) bulkReopenBtn.addEventListener('click', () => requireSelection((items) => openReopenModal(items)));
    if (bulkExtendBtn) bulkExtendBtn.addEventListener('click', () => requireSelection((items) => openExtendModal(items)));
    if (bulkGrantBtn) bulkGrantBtn.addEventListener('click', () => requireSelection((items) => openGrantModal(items)));
    if (bulkRevokeBtn) bulkRevokeBtn.addEventListener('click', () => requireSelection((items) => openRevokeModal(items)));
});
</script>
@endsection
