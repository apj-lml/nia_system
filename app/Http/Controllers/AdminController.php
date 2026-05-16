<?php

namespace App\Http\Controllers;

use App\Models\Downloadable;
use App\Models\AuditLog;
use App\Http\Controllers\Concerns\BuildsResolutionAnalytics;
use App\Http\Controllers\Concerns\HandlesAsyncRequests;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\IaResolution; // <-- Added this
use App\Models\Event;        // <-- Added this
use App\Services\SystemNotificationService;
use Illuminate\Support\Carbon;
use App\Models\EventCategory;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminController extends Controller
{
    use HandlesAsyncRequests;
    use BuildsResolutionAnalytics;

    private const EVENT_TEAM_LABELS = [
        'all' => 'All Teams',
        'fs_team' => 'FS Team',
        'rpwsis_team' => 'Social and Environmental Team',
        'cm_team' => 'Contract Management Team',
        'row_team' => 'Right Of Way Team',
        'pcr_team' => 'Program Completion Report Team',
        'pao_team' => 'Programming Team',
    ];

    // Security check
    private function checkAdmin()
    {
        if (strtolower(trim(auth()->user()->role)) !== 'admin') {
            abort(403, 'Unauthorized Access. Admins only.');
        }
    }

    private function notifications(): SystemNotificationService
    {
        return app(SystemNotificationService::class);
    }

    private function formatEventSchedule(Event $event): string
    {
        return $event->event_date->format('F j, Y') . ' at ' . trim((string) $event->event_time);
    }

    // 1. Admin Master Dashboard (FIXED)
    public function index(Request $request)
    {
        $users = User::all();
        $this->checkAdmin();
        $validatedResolutions = IaResolution::whereIn('status', ['validated', 'accomplished'])->count();
        $pendingResolutions = IaResolution::where(function ($query) {
            $query->where('status', 'on-going')
                ->orWhere('status', 'not-validated')
                ->orWhereNull('status');
        })->count();
        $resolutions = IaResolution::latest()->paginate(5, ['*'], 'active_projects_page')->withQueryString();

        $eventTagFilter = trim((string) $request->query('event_tag', ''));
        $eventTeamFilter = trim((string) $request->query('event_team', ''));

        $upcomingEventsQuery = Event::with('category')
            ->whereHas('category')
            ->where(function ($query) {
                $query->where('event_date', '>', now()->format('Y-m-d'))
                    ->orWhere(function ($query) {
                        $today = now()->format('Y-m-d');
                        $currentTime = now()->format('H:i:s');
                        $query->where('event_date', $today)
                            ->whereRaw("TIME(STR_TO_DATE(SUBSTRING_INDEX(TRIM(`event_time`), ' - ', -1), '%h:%i %p')) > '{$currentTime}'");
                    });
            })
            ->when($eventTagFilter !== '', fn ($query) => $query->where('event_category_id', $eventTagFilter))
            ->when($eventTeamFilter !== '' && $eventTeamFilter !== 'all', fn ($query) => $query->where('team', $eventTeamFilter))
            ->orderBy('event_date', 'asc');

        $events = (clone $upcomingEventsQuery)->get();
        $pastEvents = Event::with('category')
            ->whereHas('category')
            ->where(function ($query) {
                $query->where('event_date', '<', now()->format('Y-m-d'))
                    ->orWhere(function ($query) {
                        $today = now()->format('Y-m-d');
                        $currentTime = now()->format('H:i:s');
                        $query->where('event_date', $today)
                            ->whereRaw("TIME(STR_TO_DATE(SUBSTRING_INDEX(TRIM(`event_time`), ' - ', -1), '%h:%i %p')) <= '{$currentTime}'");
                    });
            })
            ->orderBy('event_date', 'desc')
            ->get();
        $paginatedEvents = (clone $upcomingEventsQuery)
            ->paginate(5, ['*'], 'events_page')
            ->withQueryString();

        // Fetch custom tags for the legend
        $categories = EventCategory::all();
        $downloadables = Downloadable::all();
        $recentAuditLogs = AuditLog::with('user')->latest()->take(8)->get();
        $analytics = $this->buildResolutionAnalytics();

        return view('admin.dashboard', compact(
            'resolutions',
            'events',
            'pastEvents',
            'paginatedEvents',
            'categories',
            'downloadables',
            'validatedResolutions',
            'pendingResolutions',
            'recentAuditLogs',
            'analytics',
            'eventTagFilter',
            'eventTeamFilter'
        ));
    }

    public function auditTrail(Request $request)
    {
        $this->checkAdmin();

        $search = trim((string) $request->query('search', ''));
        $action = trim((string) $request->query('action', ''));
        $team = trim((string) $request->query('team', ''));
        $user = trim((string) $request->query('user', ''));
        $dateFrom = trim((string) $request->query('date_from', ''));
        $dateTo = trim((string) $request->query('date_to', ''));

        $logs = $this->buildAuditTrailQuery($request)
            ->with('user')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        $actions = AuditLog::query()->select('action')->distinct()->orderBy('action')->pluck('action');
        $actionLabels = $actions
            ->mapWithKeys(fn ($actionValue) => [$actionValue => $this->formatAuditActionLabel($actionValue)]);
        $users = AuditLog::query()->whereNotNull('user_name')->select('user_name')->distinct()->orderBy('user_name')->pluck('user_name');
        $teams = AuditLog::query()
            ->whereNotNull('metadata->team')
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.team')) as team")
            ->distinct()
            ->orderBy('team')
            ->pluck('team');

        return view('admin.audit-trail', compact(
            'logs',
            'search',
            'action',
            'actions',
            'actionLabels',
            'team',
            'user',
            'dateFrom',
            'dateTo',
            'users',
            'teams'
        ));
    }

    public function exportAuditTrail(Request $request): StreamedResponse
    {
        $this->checkAdmin();

        $dateFrom = trim((string) $request->query('date_from', ''));
        $dateTo = trim((string) $request->query('date_to', ''));
        $logs = $this->buildAuditTrailQuery($request)
            ->with('user')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        $reportTitle = 'Activity Log as of ' . now()->format('F j, Y');

        if ($dateFrom !== '' && $dateTo !== '') {
            $reportTitle .= ' From ' . Carbon::parse($dateFrom)->format('F j, Y') . ' to ' . Carbon::parse($dateTo)->format('F j, Y');
        } elseif ($dateFrom !== '') {
            $reportTitle .= ' From ' . Carbon::parse($dateFrom)->format('F j, Y');
        } elseif ($dateTo !== '') {
            $reportTitle .= ' Until ' . Carbon::parse($dateTo)->format('F j, Y');
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Activity Log');
        $sheet->mergeCells('A1:I1');
        $sheet->setCellValue('A1', $reportTitle);

        $headers = [
            'A2' => 'Date',
            'B2' => 'Time',
            'C2' => 'User',
            'D2' => 'Role',
            'E2' => 'Action',
            'F2' => 'Subject Type',
            'G2' => 'Subject',
            'H2' => 'Description',
        ];

        foreach ($headers as $cell => $value) {
            $sheet->setCellValue($cell, $value);
        }

        foreach ([
            'A' => 14,
            'B' => 14,
            'C' => 24,
            'D' => 18,
            'E' => 24,
            'F' => 18,
            'G' => 28,
            'H' => 54,
        ] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        $sheet->getStyle('A1:H1')->applyFromArray([
            'font' => [
                'bold' => true,
                'size' => 12,
                'color' => ['rgb' => '0F172A'],
            ],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
            ],
        ]);

        $sheet->getStyle('A2:H2')->applyFromArray([
            'font' => [
                'bold' => true,
                'size' => 10,
                'color' => ['rgb' => 'FFFFFF'],
            ],
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => '1D4ED8'],
            ],
        ]);

        $sheet->getStyle('A1:H' . max(3, $logs->count() + 2))->getAlignment()->setWrapText(true);
        $sheet->getRowDimension(1)->setRowHeight(24);

        $row = 3;
            foreach ($logs as $log) {
                $sheet->setCellValue("A{$row}", optional($log->created_at)->format('Y-m-d'));
                $sheet->setCellValue("B{$row}", optional($log->created_at)->format('h:i:s A'));
                $sheet->setCellValue("C{$row}", $log->user_name);
                $sheet->setCellValue("D{$row}", $log->user_role);
                $sheet->setCellValue("E{$row}", $this->formatAuditActionLabel((string) $log->action));
                $sheet->setCellValue("F{$row}", $log->subject_type);
                $sheet->setCellValue("G{$row}", $log->subject_label);
                $sheet->setCellValue("H{$row}", $log->description);
                $row++;
            }

        $writer = new Xlsx($spreadsheet);
        $filename = $reportTitle . '.xlsx';

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    private function buildAuditTrailQuery(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $action = trim((string) $request->query('action', ''));
        $team = trim((string) $request->query('team', ''));
        $user = trim((string) $request->query('user', ''));
        $dateFrom = trim((string) $request->query('date_from', ''));
        $dateTo = trim((string) $request->query('date_to', ''));

        return AuditLog::query()
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($builder) use ($search) {
                    $builder->where('description', 'like', "%{$search}%")
                        ->orWhere('user_name', 'like', "%{$search}%")
                        ->orWhere('subject_label', 'like', "%{$search}%")
                        ->orWhere('user_role', 'like', "%{$search}%")
                        ->orWhere('action', 'like', "%{$search}%")
                        ->orWhere('subject_type', 'like', "%{$search}%")
                        ->orWhere('metadata->status', 'like', "%{$search}%")
                        ->orWhere('metadata->team', 'like', "%{$search}%");
                });
            })
            ->when($action !== '', fn ($query) => $query->where('action', $action))
            ->when($team !== '', fn ($query) => $query->where('metadata->team', $team))
            ->when($user !== '', fn ($query) => $query->where('user_name', $user))
            ->when($dateFrom !== '', fn ($query) => $query->whereDate('created_at', '>=', $dateFrom))
            ->when($dateTo !== '', fn ($query) => $query->whereDate('created_at', '<=', $dateTo));
    }

    private function formatAuditActionLabel(string $action): string
    {
        $parts = collect(explode('.', $action))
            ->filter()
            ->map(function ($part) {
                return match ($part) {
                    'rpwsis' => 'RP-WSIS',
                    'fsde' => 'FSDE',
                    'pcr' => 'PCR',
                    'pow' => 'POW',
                    default => str($part)->replace('_', ' ')->title()->value(),
                };
            })
            ->values();

        return $parts->implode(' - ');
    }

    //UploadDownloadables
    public function uploadDownloadable(Request $request)
    {
        $this->checkAdmin();

        $fileValidationMessages = [
            'document.required' => 'Please select a file to upload.',
            'document.file' => 'Only document files are allowed.',
            'document.mimes' => 'Only document files are allowed. Please upload PDF, DOC, DOCX, XLS, or XLSX files only.',
            'team.required' => 'Please select a team.',
            'team.in' => 'Please select a valid team.',
        ];

        $request->validate([
            'document' => 'required|file|mimes:pdf,doc,docx,xls,xlsx',
            'team' => 'required|in:fs_team,rpwsis_team,cm_team,row_team,pcr_team,pao_team'
        ], $fileValidationMessages);

        $file = $request->file('document');
        $path = $file->store('forms', 'public');

        $rawName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $cleanTitle = ucwords(str_replace(['_', '-'], ' ', $rawName));

        Downloadable::create([
            'title' => $cleanTitle,
            'file_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'team' => $request->team // 🔥 ADMIN CHOOSES TEAM
        ]);

        $teamLabel = $this->notifications()->teamLabel($request->team);
        $actorLabel = $this->notifications()->actorLabel($request->user());
        $this->notifications()->notifyTeamAndAdmins(
            $request->user(),
            $request->team,
            'New downloadable uploaded',
            "{$actorLabel} uploaded {$file->getClientOriginalName()} to {$teamLabel} downloadables.",
            [
                'type' => 'downloadable',
                'team' => $request->team,
                'team_label' => $teamLabel,
            ]
        );

        return $this->successResponse($request, 'File uploaded to selected team.');
    }

    //Upload IA Resolutions
    public function uploadResolution(Request $request)
    {
        $this->checkAdmin();

        $fileValidationMessages = [
            'document.required' => 'Please select a file to upload.',
            'document.file' => 'Only document files are allowed.',
            'document.mimes' => 'Only document files are allowed. Please upload PDF, DOC, DOCX, XLS, or XLSX files only.',
            'team.required' => 'Please select a team.',
            'team.in' => 'Please select a valid team.',
        ];

        $request->validate([
            'document' => 'required|file|mimes:pdf,doc,docx,xls,xlsx',
            'team' => 'required|in:fs_team,rpwsis_team,cm_team,row_team,pcr_team,pao_team'
        ], $fileValidationMessages);

        $file = $request->file('document');
        \App\Models\IaResolution::attachUploadedFile($file, $request->team);

        $teamLabel = $this->notifications()->teamLabel($request->team);
        $actorLabel = $this->notifications()->actorLabel($request->user());
        $this->notifications()->notifyTeamAndAdmins(
            $request->user(),
            $request->team,
            'New IA resolution uploaded',
            "{$actorLabel} uploaded {$file->getClientOriginalName()} to {$teamLabel} IA resolutions.",
            [
                'type' => 'ia_resolution',
                'team' => $request->team,
                'team_label' => $teamLabel,
            ]
        );

        return $this->successResponse($request, 'Resolution uploaded to selected team.');
    }

    // 2. Manage Users Page
    public function manageUsers(Request $request)
    {
        $this->checkAdmin();

        $allowedRoles = ['admin', 'fs_team', 'rpwsis_team', 'cm_team', 'row_team', 'pcr_team', 'pao_team'];
        $allowedSorts = ['name', 'email', 'created_at', 'role', 'is_active'];
        $allowedDirections = ['asc', 'desc'];

        $role = $request->query('role');
        $status = $request->query('status');
        $search = trim((string) $request->query('search', ''));
        $sort = $request->query('sort', 'created_at');
        $direction = strtolower((string) $request->query('direction', 'desc'));

        $query = User::query();

        if ($search !== '') {
            $query->where(function ($builder) use ($search) {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('role', 'like', "%{$search}%");
            });
        }

        if (in_array($role, $allowedRoles, true)) {
            $query->where('role', $role);
        }

        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        }

        if (! in_array($sort, $allowedSorts, true)) {
            $sort = 'created_at';
        }

        if (! in_array($direction, $allowedDirections, true)) {
            $direction = 'desc';
        }

        $users = $query
            ->orderBy($sort, $direction)
            ->orderBy('id', 'desc')
            ->paginate(5)
            ->withQueryString();

        return view('admin.users', compact('users', 'role', 'status', 'search', 'sort', 'direction'));
    }

    // 3. Store New User
    public function storeUser(Request $request)
    {
        $this->checkAdmin();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'role' => 'required|in:admin,fs_team,rpwsis_team,cm_team,row_team,pcr_team,pao_team',
        ]);

        $isAdmin = $validated['role'] === 'admin';

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'role' => $validated['role'],
            'is_active' => true,
            'email_verified_at' => now(),
            'agreed_to_terms' => $isAdmin,
        ]);

        if ($isAdmin) {
            return $this->successResponse($request, 'Admin account created successfully.');
        }

        return $this->successResponse($request, 'User account created successfully.');
    }

    public function updateUserStatus(Request $request, User $user)
    {
        $this->checkAdmin();

        $validated = $request->validate([
            'is_active' => 'required|boolean',
        ]);

        if (auth()->id() === $user->id && !(bool) $validated['is_active']) {
            $message = 'You cannot deactivate your own account while logged in.';

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => $message,
                ], 422);
            }

            return $this->errorResponse($request, $message);
        }

        $user->update([
            'is_active' => (bool) $validated['is_active'],
        ]);

        $status = $user->is_active ? 'activated' : 'deactivated';
        $message = "{$user->name}'s account was {$status} successfully.";

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => $message,
                'is_active' => $user->is_active,
            ]);
        }

        return back()->with('success', $message);
    }

    public function updateUserPassword(Request $request, User $user)
    {
        $this->checkAdmin();

        $validated = $request->validate([
            'password' => 'required|string|min:8|max:255',
        ]);

        $user->password = $validated['password'];
        $user->save();

        return $this->successResponse($request, "{$user->name}'s password was updated successfully.", [
            'plain_password' => $validated['password'],
            'user_id' => $user->id,
        ]);
    }

    public function destroyUser(Request $request, User $user)
    {
        $this->checkAdmin();

        if (auth()->id() === $user->id) {
            return $this->errorResponse($request, 'You cannot delete your own account while logged in.');
        }

        $userName = $user->name;
        $user->delete();

        return $this->successResponse($request, "{$userName}'s account was deleted successfully.");
    }

    private function validateEvent(Request $request, bool $isUpdate = false): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'event_date' => [$isUpdate ? 'required' : 'required', 'date', 'after_or_equal:today'],
            'event_time' => ['required', 'string', 'max:255'],
            'event_category_id' => ['required', 'integer', 'exists:event_categories,id'],
            'team' => ['required', 'string', 'in:' . implode(',', array_keys(self::EVENT_TEAM_LABELS))],
            'reminder_minutes' => ['nullable', 'integer', 'min:0', 'max:10080'],
            'recurrence_pattern' => ['nullable', 'string', 'in:none,daily,weekly,monthly'],
            'recurrence_until' => ['nullable', 'date', 'after_or_equal:event_date'],
        ]);
    }

    private function buildEventPayload(array $validated): array
    {
        $recurrencePattern = $validated['recurrence_pattern'] ?? 'none';

        return [
            'title' => trim($validated['title']),
            'description' => trim((string) ($validated['description'] ?? '')) ?: null,
            'event_date' => $validated['event_date'],
            'event_time' => trim($validated['event_time']),
            'event_category_id' => $validated['event_category_id'],
            'team' => $validated['team'],
            'reminder_minutes' => $validated['reminder_minutes'] ?? null,
            'recurrence_pattern' => $recurrencePattern === 'none' ? null : $recurrencePattern,
            'recurrence_until' => $recurrencePattern === 'none' ? null : ($validated['recurrence_until'] ?? null),
        ];
    }

    private function buildRecurringEventRows(array $payload): array
    {
        $rows = [];
        $pattern = $payload['recurrence_pattern'] ?? null;
        $startDate = Carbon::parse($payload['event_date'])->startOfDay();
        $endDate = !empty($payload['recurrence_until'])
            ? Carbon::parse($payload['recurrence_until'])->startOfDay()
            : $startDate->copy();
        $group = $pattern ? (string) Str::uuid() : null;

        $currentDate = $startDate->copy();

        while ($currentDate->lte($endDate)) {
            $rows[] = array_merge($payload, [
                'event_date' => $currentDate->toDateString(),
                'recurrence_group' => $group,
            ]);

            if (!$pattern) {
                break;
            }

            $nextDate = match ($pattern) {
                'daily' => $currentDate->copy()->addDay(),
                'weekly' => $currentDate->copy()->addWeek(),
                'monthly' => $currentDate->copy()->addMonthNoOverflow(),
                default => null,
            };

            if (!$nextDate || $nextDate->equalTo($currentDate)) {
                break;
            }

            $currentDate = $nextDate;
        }

        return $rows;
    }

    public function storeEvent(Request $request)
    {
        $this->checkAdmin();

        $validated = $this->validateEvent($request);
        $payload = $this->buildEventPayload($validated);
        $rows = $this->buildRecurringEventRows($payload);

        $createdEvents = collect();
        foreach ($rows as $row) {
            $createdEvents->push(Event::create($row));
        }

        $message = count($rows) > 1
            ? count($rows) . ' recurring events added to the calendar!'
            : 'Event added to the calendar!';

        $firstEvent = $createdEvents->first();
        if ($firstEvent) {
            $teamLabel = $this->notifications()->teamLabel($firstEvent->team);
            $actorLabel = $this->notifications()->actorLabel($request->user());
            $eventMessage = count($rows) > 1
                ? "{$actorLabel} added {$createdEvents->count()} calendar entries for {$firstEvent->title} starting {$this->formatEventSchedule($firstEvent)} for {$teamLabel}."
                : "{$actorLabel} added a new event for {$teamLabel}: {$firstEvent->title} on {$this->formatEventSchedule($firstEvent)}.";

            $this->notifications()->notifyAgency(
                $request->user(),
                count($rows) > 1 ? 'New recurring events added' : 'New event added',
                $eventMessage,
                [
                    'type' => 'event',
                    'team' => $firstEvent->team,
                    'team_label' => $teamLabel,
                ]
            );
        }

        return $this->successResponse($request, $message);
    }

    public function updateEvent(Request $request, $id)
    {
        $this->checkAdmin();

        $validated = $this->validateEvent($request, true);
        $event = Event::findOrFail($id);
        $event->update($this->buildEventPayload($validated));

        $teamLabel = $this->notifications()->teamLabel($event->team);
        $actorLabel = $this->notifications()->actorLabel($request->user());
        $this->notifications()->notifyAgency(
            $request->user(),
            'Event updated',
            "{$actorLabel} updated the event {$event->title} for {$teamLabel}. It is scheduled on {$this->formatEventSchedule($event)}.",
            [
                'type' => 'event',
                'team' => $event->team,
                'team_label' => $teamLabel,
            ]
        );

        return $this->successResponse($request, 'Event updated successfully.');
    }

    // Delete an Event
    public function storeCategory(Request $request)
    {
        $this->checkAdmin();

        $validated = $request->validate([
            'name' => 'required|string|max:50',
            'color' => 'required|string|max:10',
        ]);

        $normalizedName = mb_strtolower(trim($validated['name']));

        $tagAlreadyExists = EventCategory::query()
            ->get()
            ->contains(fn($category) => mb_strtolower(trim($category->name)) === $normalizedName);

        if ($tagAlreadyExists) {
            return $this->errorResponse($request, 'Tag name already exists. Please use a different tag name.');
        }

        EventCategory::create([
            'name' => trim($validated['name']),
            'color' => $validated['color'],
        ]);

        return $this->successResponse($request, 'New tag added to legend!');
    }

    // 4. New! Delete a Custom Tag
    public function destroyCategory(Request $request, $id)
    {
        $this->checkAdmin();

        $category = EventCategory::findOrFail($id);
        $linkedEventsQuery = Event::query()->where('event_category_id', $category->id);
        $deletedEventIds = $linkedEventsQuery->pluck('id')->all();
        $deletedEvents = count($deletedEventIds);

        $linkedEventsQuery->delete();
        $category->delete();

        $message = $deletedEvents > 0
            ? "Tag removed. {$deletedEvents} linked event(s) were also deleted."
            : 'Tag removed.';

        return $this->successResponse($request, $message, [
            'deleted_category_id' => (int) $id,
            'deleted_event_ids' => $deletedEventIds,
        ]);
    }

    public function destroyEvent(Request $request, $id)
    {
        $this->checkAdmin();

        Event::findOrFail($id)->delete();

        return $this->successResponse($request, 'Event removed from schedule.');
    }
}






// namespace App\Http\Controllers;

// use Illuminate\Http\Request;
// use App\Models\User;
// use Illuminate\Support\Facades\Hash;
// use App\Models\IaResolution;
// use App\Models\Event;

// class AdminController extends Controller
// {
//     public function index()
//     {
//         $this->checkAdmin();

//         // Fetch all resolutions across the whole agency
//         $resolutions = IaResolution::latest()->get();

//         // Fetch upcoming events
//         $events = Event::whereDate('event_date', '>=', now())
//             ->orderBy('event_date', 'asc')
//             ->take(5)
//             ->get();

//         return view('admin.dashboard', compact('resolutions', 'events'));
//     }
//     // Security check to ensure only Admins can run these methods
//     private function checkAdmin()
//     {
//         if (strtolower(trim(auth()->user()->role)) !== 'admin') {
//             abort(403, 'Unauthorized Access. Admins only.');
//         }
//     }

//     // 1. View the User Management Dashboard
//     public function manageUsers()
//     {
//         $this->checkAdmin();
//         $users = User::latest()->get();
//         return view('admin.users', compact('users'));
//     }

//     // 2. Create a New User
//     public function storeUser(Request $request)
//     {
//         $this->checkAdmin();

//         $request->validate([
//             'name' => 'required|string|max:255',
//             'email' => 'required|email|unique:users,email',
//             'password' => 'required|string|min:8',
//             // Lock down the roles perfectly to your database values:
//             'role' => 'required|in:admin,fs_team,rpwsis_team,cm_team,row_team,pcr_team,pao_team'
//         ]);

//         User::create([
//             'name' => $request->name,
//             'email' => $request->email,
//             'password' => Hash::make($request->password),
//             'role' => $request->role,
//         ]);

//         return back()->with('success', 'User account created successfully.');
//     }
// }
