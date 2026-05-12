<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Controllers\Concerns\BuildsResolutionAnalytics;
use App\Http\Controllers\Concerns\HandlesAsyncRequests;
use App\Models\IaResolution;
use App\Models\IaResolutionFile;
use App\Models\Downloadable;
use App\Models\Event;
use Illuminate\Support\Facades\Storage;
use App\Models\EventCategory;
use App\Models\HydroGeoProject;
use App\Models\FsdeProject;
use App\Services\SystemNotificationService;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use Illuminate\Support\Carbon;

class FsTeamController extends Controller
{
    use HandlesAsyncRequests;
    use BuildsResolutionAnalytics;

    private function notifications(): SystemNotificationService
    {
        return app(SystemNotificationService::class);
    }

    private function validateHydroGeo(Request $request): array
    {
        return $request->validate([
            'year' => ['required', 'digits:4', 'integer', 'min:2000', 'max:2100'],
            'district' => ['required', 'string', 'max:100'],
            'project_code' => ['required', 'string', 'max:100'],
            'system_name' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:2000'],
            'municipality' => ['required', 'string', 'max:100'],
            'status' => [
                'required',
                Rule::in([
                    'For Schedule',
                    'For Interpretation',
                    'For Submission of Raw data',
                    'Relocation',
                    'Interpreted',
                    'Not Applicable',
                    'C/O Contractor',
                    'Open Source',
                    'With Geo-res',
                ])
            ],
            'result' => ['nullable', 'string', 'max:100'],
        ]);
    }

    private function validateFsde(Request $request): array
    {
        return $request->validate([
            'year' => ['required', 'digits:4', 'integer', 'min:2000', 'max:2100'],
            'type_of_study' => ['required', 'string', 'max:255'],
            'project_name' => ['required', 'string', 'max:1000'],
            'municipality' => ['required', 'string', 'max:100'],
            'consultant' => ['required', 'string', 'max:255'],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date', 'after_or_equal:period_start'],
            'contract_amount' => ['nullable', 'numeric', 'min:0'],
            'actual_obligation' => ['nullable', 'numeric', 'min:0'],
            'value_of_acc' => ['nullable', 'numeric', 'min:0'],
            'actual_expenditures' => ['nullable', 'numeric', 'min:0'],
            'acc_month' => ['required', Rule::in(['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'])],
            'acc_year' => ['required', 'digits:4', 'integer', 'min:2000', 'max:2100'],
            'acc_phy' => ['nullable', 'numeric', 'between:0,100'],
            'acc_fin' => ['nullable', 'numeric', 'between:0,100'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    // 1. Dashboard
    public function index(Request $request)
    {
        // Fetch resolutions for the project table
        $resolutions = IaResolution::where('team', 'fs_team')
            ->latest()
            ->paginate(8, ['*'], 'active_projects_page')
            ->withQueryString();

        // Fetch all events so the dashboard can show upcoming and past entries.
        $events = Event::with('category')
            ->orderBy('event_date', 'asc')
            ->get();

        $upcomingEventsQuery = Event::with('category')
            ->where('event_date', '>', now()->format('Y-m-d'))
            ->orWhere(function ($query) {
                $today = now()->format('Y-m-d');
                $currentTime = now()->format('H:i:s');
                $query->where('event_date', $today)
                    ->whereRaw("TIME(STR_TO_DATE(SUBSTRING_INDEX(TRIM(`event_time`), ' - ', -1), '%h:%i %p')) > '{$currentTime}'");
            })
            ->orderBy('event_date', 'asc');

        $paginatedEvents = (clone $upcomingEventsQuery)
            ->paginate(5, ['*'], 'events_page')
            ->withQueryString();

        $categories = EventCategory::all();
        $analytics = $this->buildResolutionAnalytics('fs_team');
        // 2. Calculate the dynamic KPI numbers for the top cards
        $totalProjects = HydroGeoProject::count();
        $conducted = HydroGeoProject::whereIn('status', ['For Interpretation', 'Interpreted', 'For Submission of Raw data'])->count();
        $remaining = HydroGeoProject::where('status', 'For Schedule')->count();
        $feasible = HydroGeoProject::where('result', 'Feasible')->count();
        $hydroExportData = HydroGeoProject::all();
        $fsdeExportData = FsdeProject::all();

        // 3. Fetch the table data with filtering and pagination
        $hydroQuery = HydroGeoProject::query();
        if ($request->filled('hydro_search')) {
            $search = trim((string) $request->input('hydro_search'));
            $hydroQuery->where(function ($query) use ($search) {
                $query->where('year', 'like', "%{$search}%")
                    ->orWhere('district', 'like', "%{$search}%")
                    ->orWhere('project_code', 'like', "%{$search}%")
                    ->orWhere('system_name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('municipality', 'like', "%{$search}%")
                    ->orWhere('status', 'like', "%{$search}%")
                    ->orWhere('result', 'like', "%{$search}%");
            });
        }
        if ($request->filled('hydro_status')) {
            $hydroQuery->where('status', $request->input('hydro_status'));
        }
        if ($request->filled('hydro_district')) {
            $hydroQuery->where('district', $request->input('hydro_district'));
        }

        $fsdeQuery = FsdeProject::query();
        if ($request->filled('fsde_search')) {
            $search = trim((string) $request->input('fsde_search'));
            $fsdeQuery->where(function ($query) use ($search) {
                $query->where('year', 'like', "%{$search}%")
                    ->orWhere('project_name', 'like', "%{$search}%")
                    ->orWhere('municipality', 'like', "%{$search}%")
                    ->orWhere('type_of_study', 'like', "%{$search}%")
                    ->orWhere('consultant', 'like', "%{$search}%")
                    ->orWhere('remarks', 'like', "%{$search}%");
            });
        }
        if ($request->filled('fsde_year')) {
            $fsdeQuery->where('year', $request->input('fsde_year'));
        }
        if ($request->filled('fsde_municipality')) {
            $fsdeQuery->where('municipality', $request->input('fsde_municipality'));
        }

        $hydroProjects = $hydroQuery->orderByDesc('year')->paginate(8, ['*'], 'hydro_page')->withQueryString();
        $fsdeProjects = $fsdeQuery->orderByDesc('year')->paginate(8, ['*'], 'fsde_page')->withQueryString();
        $hydroDistricts = HydroGeoProject::select('district')->whereNotNull('district')->distinct()->orderBy('district')->pluck('district');
        $hydroStatuses = HydroGeoProject::select('status')->whereNotNull('status')->distinct()->orderBy('status')->pluck('status');
        $fsdeYears = FsdeProject::select('year')->whereNotNull('year')->distinct()->orderByDesc('year')->pluck('year');
        $fsdeMunicipalities = FsdeProject::select('municipality')->whereNotNull('municipality')->distinct()->orderBy('municipality')->pluck('municipality');
        return view('fs-team.dashboard', compact(
            'totalProjects',
            'conducted',
            'remaining',
            'feasible',
            'resolutions',
            'events',
            'paginatedEvents',
            'categories',
            'analytics',
            'hydroProjects',
            'fsdeProjects',
            'hydroDistricts',
            'hydroStatuses',
            'fsdeYears',
            'fsdeMunicipalities',
            'hydroExportData',
            'fsdeExportData'
        ));

    }

    // 2. View Downloadables Page
    public function downloadables()
    {
        $files = Downloadable::where('team', 'fs_team')->get();
        return view('fs-team.downloadables', compact('files'));
    }

    // 3. View IA Resolutions Page
    public function resolutions()
    {
        $resolutions = IaResolution::with('files')->where('team', 'fs_team')->latest()->get();
        return view('shared.team-resolutions', [
            'pageTitle' => 'IA Resolutions',
            'headerTitle' => 'IA Resolutions',
            'headerDesc' => 'Manage status entries and attached files for the Feasibility Study team.',
            'teamRole' => 'fs_team',
            'uploadRouteName' => 'fs.resolutions.upload',
            'deleteRouteName' => 'fs.resolutions.delete',
            'resolutions' => $resolutions,
        ]);
    }

    // 4. Upload Downloadable
    public function uploadForm(Request $request)
    {
        $fileValidationMessages = [
            'documents.required' => 'Please select at least one file to upload.',
            'documents.array' => 'Please upload valid files only.',
            'documents.min' => 'Please select at least one file to upload.',
            'document.required' => 'Please select a file to upload.',
            'document.file' => 'Only document files are allowed.',
            'document.mimes' => 'Only document files are allowed. Please upload PDF, DOC, DOCX, XLS, or XLSX files only.',
            'document.max' => 'Each uploaded file must not be larger than 100MB.',
        ];

        $singleFile = $request->file('document');
        $multipleFiles = $request->file('documents', []);
        $files = collect(is_array($multipleFiles) ? $multipleFiles : [])->filter()->values();

        if ($files->isEmpty() && $singleFile) {
            $files = collect([$singleFile]);
        }

        if ($files->isEmpty()) {
            $request->validate(['documents' => ['required', 'array', 'min:1']], $fileValidationMessages);
        }

        foreach ($files as $file) {
            validator(['document' => $file], [
                'document' => ['required', 'file', 'mimes:pdf,doc,docx,xls,xlsx', 'max:102400'],
            ], $fileValidationMessages)->validate();

            $path = $file->store('forms', 'public');
            $rawName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
            $cleanTitle = ucwords(str_replace(['_', '-'], ' ', $rawName));

            Downloadable::create([
                'title' => $cleanTitle,
                'file_path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'team' => 'fs_team'
            ]);
        }

        $message = $files->count() === 1
            ? 'File uploaded successfully.'
            : "{$files->count()} files uploaded successfully.";

        $teamLabel = $this->notifications()->teamLabel('fs_team');
        $actorLabel = $this->notifications()->actorLabel($request->user());
        $fileMessage = $files->count() === 1
            ? "{$actorLabel} uploaded {$files->first()->getClientOriginalName()} to {$teamLabel} downloadables."
            : "{$actorLabel} uploaded {$files->count()} files to {$teamLabel} downloadables.";
        $this->notifications()->notifyTeamAndAdmins($request->user(), 'fs_team', 'Downloadables updated', $fileMessage, [
            'type' => 'downloadable',
            'team' => 'fs_team',
            'team_label' => $teamLabel,
        ]);

        return $this->successResponse($request, $message);
    }

    public function updateForm(Request $request, $id)
    {
        $request->validate(['document' => 'required|file|mimes:pdf,doc,docx,xls,xlsx|max:102400']);
        $downloadable = Downloadable::findOrFail($id);
        $file = $request->file('document');

        $previousName = $downloadable->original_name;

        if (Storage::disk('public')->exists($downloadable->file_path)) {
            Storage::disk('public')->delete($downloadable->file_path);
        }
        $path = $file->store('forms', 'public');
        $downloadable->update(['file_path' => $path, 'original_name' => $file->getClientOriginalName()]);

        $teamLabel = $this->notifications()->teamLabel('fs_team');
        $actorLabel = $this->notifications()->actorLabel($request->user());
        $this->notifications()->notifyTeamAndAdmins(
            $request->user(),
            'fs_team',
            'Downloadable updated',
            "{$actorLabel} replaced {$previousName} with {$file->getClientOriginalName()} in {$teamLabel} downloadables.",
            [
                'type' => 'downloadable',
                'team' => 'fs_team',
                'team_label' => $teamLabel,
            ]
        );

        return $this->successResponse($request, 'File updated successfully.');
    }

    // 6. Delete Downloadable
    public function deleteForm(Request $request, $id)
    {
        $downloadable = Downloadable::findOrFail($id);

        $deletedName = $downloadable->original_name;

        if (Storage::disk('public')->exists($downloadable->file_path)) {
            Storage::disk('public')->delete($downloadable->file_path);
        }


        // if ($downloadable->team !== 'fs_team') {
//     abort(403);
// }
        $downloadable->delete();

        $teamLabel = $this->notifications()->teamLabel('fs_team');
        $actorLabel = $this->notifications()->actorLabel($request->user());
        $this->notifications()->notifyTeamAndAdmins(
            $request->user(),
            'fs_team',
            'Downloadable removed',
            "{$actorLabel} removed {$deletedName} from {$teamLabel} downloadables.",
            [
                'type' => 'downloadable',
                'team' => 'fs_team',
                'team_label' => $teamLabel,
            ]
        );

        return $this->successResponse($request, 'File deleted successfully.');
    }

    // 7. Upload Resolution
    public function uploadResolution(Request $request)
    {
        $fileValidationMessages = [
            'documents.required' => 'Please select at least one file to upload.',
            'documents.array' => 'Please upload valid files only.',
            'documents.min' => 'Please select at least one file to upload.',
            'document.required' => 'Please select a file to upload.',
            'document.file' => 'Only document files are allowed.',
            'document.mimes' => 'Only document files are allowed. Please upload PDF, DOC, DOCX, XLS, or XLSX files only.',
            'document.max' => 'Each uploaded file must not be larger than 100MB.',
        ];

        $singleFile = $request->file('document');
        $multipleFiles = $request->file('documents', []);
        $files = collect(is_array($multipleFiles) ? $multipleFiles : [])->filter()->values();

        if ($files->isEmpty() && $singleFile) {
            $files = collect([$singleFile]);
        }

        if ($files->isEmpty()) {
            $request->validate(['documents' => ['required', 'array', 'min:1']], $fileValidationMessages);
        }

        foreach ($files as $file) {
            validator(['document' => $file], [
                'document' => ['required', 'file', 'mimes:pdf,doc,docx,xls,xlsx', 'max:102400'],
            ], $fileValidationMessages)->validate();

            IaResolution::attachUploadedFile($file, 'fs_team');
        }

        $message = $files->count() === 1
            ? 'Resolution uploaded successfully.'
            : "{$files->count()} resolutions uploaded successfully.";

        $teamLabel = $this->notifications()->teamLabel('fs_team');
        $actorLabel = $this->notifications()->actorLabel($request->user());
        $resolutionMessage = $files->count() === 1
            ? "{$actorLabel} uploaded {$files->first()->getClientOriginalName()} to {$teamLabel} IA resolutions."
            : "{$actorLabel} uploaded {$files->count()} files to {$teamLabel} IA resolutions.";
        $this->notifications()->notifyTeamAndAdmins($request->user(), 'fs_team', 'IA resolutions updated', $resolutionMessage, [
            'type' => 'ia_resolution',
            'team' => 'fs_team',
            'team_label' => $teamLabel,
        ]);

        return $this->successResponse($request, $message);
    }

    public function updateResolution(Request $request, $id)
    {
        $request->validate(['document' => 'required|file|mimes:pdf,doc,docx,xls,xlsx|max:102400']);
        $resolution = IaResolution::findOrFail($id);
        $file = $request->file('document');

        $previousName = $resolution->original_name;

        if (Storage::disk('public')->exists($resolution->file_path)) {
            Storage::disk('public')->delete($resolution->file_path);
        }
        $path = $file->store('resolutions', 'public');
        $resolution->update(['file_path' => $path, 'original_name' => $file->getClientOriginalName()]);

        $resolutionTeam = $resolution->team ?: 'fs_team';
        $teamLabel = $this->notifications()->teamLabel($resolutionTeam);
        $actorLabel = $this->notifications()->actorLabel($request->user());
        $this->notifications()->notifyTeamAndAdmins(
            $request->user(),
            $resolutionTeam,
            'IA resolution updated',
            "{$actorLabel} replaced {$previousName} with {$file->getClientOriginalName()} in {$teamLabel} IA resolutions.",
            [
                'type' => 'ia_resolution',
                'team' => $resolutionTeam,
                'team_label' => $teamLabel,
            ]
        );

        return $this->successResponse($request, 'Resolution updated successfully.');
    }

    // 8. Update Resolution Status
    public function updateResolutionStatus(Request $request, $id)
    {
        $request->validate(['status' => 'required|string']);
        $resolution = IaResolution::findOrFail($id);
        $resolutionTeam = $resolution->team ?: 'fs_team';
        $previousStatus = $resolution->status ?: 'no status';
        $updatedStatus = IaResolution::normalizeStatusForTeam($request->status, $resolutionTeam);
        $resolution->update(['status' => $updatedStatus]);
        $teamLabel = $this->notifications()->teamLabel($resolutionTeam);
        $actorLabel = $this->notifications()->actorLabel($request->user());
        $previousStatusLabel = IaResolution::displayStatusLabel($previousStatus, $resolutionTeam);
        $updatedStatusLabel = IaResolution::displayStatusLabel($updatedStatus, $resolutionTeam);
        $this->notifications()->notifyTeamAndAdmins(
            $request->user(),
            $resolutionTeam,
            'IA resolution status changed',
            "{$actorLabel} changed the status of {$resolution->title} in {$teamLabel} from {$previousStatusLabel} to {$updatedStatusLabel}.",
            [
                'type' => 'ia_resolution_status',
                'team' => $resolutionTeam,
                'team_label' => $teamLabel,
                'status' => $updatedStatus,
            ]
        );

        return $this->successResponse($request, 'Resolution status updated successfully.');
    }

    public function storeHydroGeo(Request $request)
    {
        $validated = $this->validateHydroGeo($request);

        HydroGeoProject::create($validated);

        return $this->successResponse($request, 'Added successfully.');
    }

    public function storeFsde(Request $request)
    {
        $validated = $this->validateFsde($request);

        $data = collect($validated)
            ->except(['acc_month', 'acc_year', 'acc_phy', 'acc_fin'])
            ->toArray();

        $month = $validated['acc_month'];
        $data[$month . '_phy'] = $validated['acc_phy'] ?? null;
        $data[$month . '_fin'] = $validated['acc_fin'] ?? null;
        $data['acc_year'] = $validated['acc_year'];

        FsdeProject::create($data);

        return $this->successResponse($request, 'Added successfully.');
    }

    public function updateHydroGeo(Request $request, $id)
    {
        $project = HydroGeoProject::findOrFail($id);
        $project->update($this->validateHydroGeo($request));

        return $this->successResponse($request, 'Updated successfully.');
    }

    public function updateFsde(Request $request, $id)
    {
        $project = FsdeProject::findOrFail($id);
        $validated = $this->validateFsde($request);

        $data = collect($validated)
            ->except(['acc_month', 'acc_year', 'acc_phy', 'acc_fin'])
            ->toArray();

        $month = $validated['acc_month'];
        $data[$month . '_phy'] = $validated['acc_phy'] ?? null;
        $data[$month . '_fin'] = $validated['acc_fin'] ?? null;
        $data['acc_year'] = $validated['acc_year'];

        $project->update($data);

        return $this->successResponse($request, 'Updated successfully.');
    }

    public function destroyHydroGeo(Request $request, $id)
    {
        $project = HydroGeoProject::findOrFail($id);
        $project->delete();

        return $this->successResponse($request, 'Hydro-Geo data deleted successfully.');
    }

    public function destroyFsde(Request $request, $id)
    {
        $project = FsdeProject::findOrFail($id);
        $project->delete();

        return $this->successResponse($request, 'FSDE data deleted successfully.');
    }

    public function exportHydroExcel(Request $request): StreamedResponse
    {
        $rows = $this->buildHydroExportQuery($request)
            ->orderBy('year')
            ->orderBy('district')
            ->orderBy('municipality')
            ->orderBy('system_name')
            ->get();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Summary Report Sheet1');

        foreach ([
            'A' => 10,
            'B' => 16,
            'C' => 18,
            'D' => 22,
            'E' => 58,
            'F' => 18,
            'G' => 34,
            'H' => 28,
        ] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        $sheet->getRowDimension(1)->setRowHeight(28);

        $sheet->fromArray([
            'YEAR',
            'DISTRICT',
            'PROJECT CODE',
            'SYSTEM',
            'DESCRIPTION REMARKS',
            'MUNICIPALITY',
            'STATUS',
            'RESULT (FEASIBLE OR NOT FEASIBLE)',
        ], null, 'A1');

        $sheet->getStyle('A1:H1')->applyFromArray([
            'font' => [
                'name' => 'Arial',
                'bold' => true,
                'size' => 10,
                'color' => ['rgb' => 'FFFFFF'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '2F5597'],
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => '000000'],
                ],
            ],
        ]);

        $currentRow = 2;
        foreach ($rows as $row) {
            $sheet->setCellValue("A{$currentRow}", $row->year);
            $sheet->setCellValue("B{$currentRow}", $row->district);
            $sheet->setCellValue("C{$currentRow}", $row->project_code);
            $sheet->setCellValue("D{$currentRow}", $row->system_name);
            $sheet->setCellValue("E{$currentRow}", $row->description);
            $sheet->setCellValue("F{$currentRow}", $row->municipality);
            $sheet->setCellValue("G{$currentRow}", $row->status);
            $sheet->setCellValue("H{$currentRow}", $row->result);
            $sheet->getRowDimension($currentRow)->setRowHeight(26);
            $currentRow++;
        }

        $dataEndRow = max($currentRow - 1, 2);
        $sheet->getStyle("A2:H{$dataEndRow}")->applyFromArray([
            'font' => [
                'name' => 'Arial',
                'size' => 10,
            ],
            'alignment' => [
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => '000000'],
                ],
            ],
        ]);

        $summaryStart = $dataEndRow + 3;
        $sheet->setCellValue("A{$summaryStart}", 'Summary:');
        $sheet->getStyle("A{$summaryStart}")->getFont()->setBold(true)->setName('Arial')->setSize(11);

        $projectsWithGeores =
            $rows->where('status', 'For Interpretation')->count() +
            $rows->filter(fn($row) => str_contains((string) $row->status, 'For Submission of Raw data'))->count() +
            $rows->where('status', 'Relocation')->count() +
            $rows->where('result', 'Feasible')->count();

        $summaryRows = [
            ['Total Projects for SPIP', $rows->count()],
            ['Projects with Geores', $projectsWithGeores],
            ['Breakdown:', null],
            ['For Interpretation', $rows->where('status', 'For Interpretation')->count()],
            ['Raw Data (For Submission)', $rows->filter(fn($row) => str_contains((string) $row->status, 'For Submission of Raw data'))->count()],
            ['Relocation (Not Feasible)', $rows->where('status', 'Relocation')->count()],
            ['Feasible', $rows->where('result', 'Feasible')->count()],
            ['', null],
            ['For Schedule', $rows->where('status', 'For Schedule')->count()],
            ['Open Source', $rows->where('status', 'Open Source')->count()],
            ['Provision of Pumps', $rows->filter(fn($row) => str_contains(strtolower((string) $row->description), 'provision of water pumps'))->count()],
        ];

        $summaryRow = $summaryStart + 1;
        foreach ($summaryRows as [$label, $value]) {
            if ($label !== '' && $value !== null) {
                $sheet->mergeCells("A{$summaryRow}:B{$summaryRow}");
            }

            $sheet->setCellValue("A{$summaryRow}", $label);
            if ($value !== null) {
                $sheet->setCellValue("C{$summaryRow}", $value);
            }

            if ($label === 'Breakdown:' || $label === '') {
                $sheet->getStyle("A{$summaryRow}:C{$summaryRow}")->getFont()->setBold(true);
            } else {
                $sheet->getStyle("A{$summaryRow}:C{$summaryRow}")->applyFromArray([
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => Border::BORDER_THIN,
                            'color' => ['rgb' => '000000'],
                        ],
                    ],
                ]);
            }

            $sheet->getStyle("A{$summaryRow}:C{$summaryRow}")->getFont()->setName('Arial')->setSize(10);
            $sheet->getStyle("A{$summaryRow}:C{$summaryRow}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getStyle("A{$summaryRow}:C{$summaryRow}")->getAlignment()->setWrapText(true);
            $summaryRow++;
        }

        $sheet->getStyle("A" . ($summaryStart + 1) . ":C" . ($summaryRow - 1))->getFill()->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('E2F0D9');
        $sheet->getStyle("A{$summaryStart}:C{$summaryStart}")->getFill()->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('FFFFFF');

        $writer = new Xlsx($spreadsheet);
        $filename = $this->buildExportFilename('HYDRO-GEO', $request, [
            'hydro_search' => 'Search',
            'hydro_district' => 'District',
            'hydro_status' => 'Status',
        ]);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    public function exportFsdeExcel(Request $request): StreamedResponse
    {
        $rows = $this->buildFsdeExportQuery($request)
            ->orderBy('year')
            ->orderBy('project_name')
            ->get();

        $currentDate = now();
        $previousDate = now()->copy()->subMonth();
        $currentMonthKey = strtolower($currentDate->format('M'));
        $previousMonthKey = strtolower($previousDate->format('M'));

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($currentDate->format('F Y'));

        foreach ([
            'A' => 8,
            'B' => 34,
            'C' => 18,
            'D' => 20,
            'E' => 16,
            'F' => 16,
            'G' => 28,
            'H' => 16,
            'I' => 16,
            'J' => 16,
            'K' => 16,
            'L' => 16,
            'M' => 16,
            'N' => 12,
            'O' => 12,
            'P' => 12,
            'Q' => 12,
            'R' => 42,
        ] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        foreach (range(1, 5) as $row) {
            $sheet->getRowDimension($row)->setRowHeight(30);
        }
        $sheet->getRowDimension(6)->setRowHeight(24);
        $sheet->getRowDimension(7)->setRowHeight(22);
        $sheet->getRowDimension(9)->setRowHeight(22);
        $sheet->getRowDimension(11)->setRowHeight(30);
        $sheet->getRowDimension(12)->setRowHeight(24);

        $sheet->mergeCells('A6:R6');
        $sheet->mergeCells('A7:R7');
        $sheet->mergeCells('A9:R9');

        foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G', 'J', 'K', 'L', 'M', 'R'] as $column) {
            $sheet->mergeCells("{$column}11:{$column}12");
        }
        $sheet->mergeCells('H11:I11');
        $sheet->mergeCells('N11:O11');
        $sheet->mergeCells('P11:Q11');
        $sheet->mergeCells('A13:R13');
        $sheet->mergeCells('A17:B17');

        $sheet->setCellValue('A6', 'MONTHLY ACCOMPLISHMENT REPORT');
        $sheet->setCellValue('A7', 'Feasibility Study and Detailed Engineering');
        $sheet->setCellValue('A9', 'As of ' . $currentDate->format('F j, Y'));

        $sheet->setCellValue('A11', 'YEAR');
        $sheet->setCellValue('B11', 'Proposed Project Name');
        $sheet->setCellValue('C11', 'Municipality');
        $sheet->setCellValue('D11', 'Type of Study/ Activity');
        $sheet->setCellValue('E11', "Total Funding Requirement (P'000)");
        $sheet->setCellValue('F11', "Approved Budget (P'000)");
        $sheet->setCellValue('G11', 'Mode of Implementation & Name of Consultant');
        $sheet->setCellValue('H11', 'Peeriod of Engagement');
        $sheet->setCellValue('H12', 'Start of Activity');
        $sheet->setCellValue('I12', 'End of Activity');
        $sheet->setCellValue('J11', "Contract Amount (P'000)");
        $sheet->setCellValue('K11', "Actual Obligation (P'000)");
        $sheet->setCellValue('L11', "Value of Accomplishment (P'000)");
        $sheet->setCellValue('M11', "Actual Expenditures (P'000)");
        $sheet->setCellValue('N11', 'Accomplishment as of ' . $previousDate->format('F j, Y'));
        $sheet->setCellValue('N12', 'PHY (%)');
        $sheet->setCellValue('O12', 'FIN(%)');
        $sheet->setCellValue('P11', 'Accomplishment as of ' . $currentDate->format('F j, Y'));
        $sheet->setCellValue('P12', 'PHY (%)');
        $sheet->setCellValue('Q12', 'FIN(%)');
        $sheet->setCellValue('R11', 'REMARKS');
        $sheet->setCellValue('A13', 'PANGASINAN');

        $sheet->getStyle('A6:R9')->applyFromArray([
            'font' => [
                'name' => 'Arial',
                'bold' => true,
                'size' => 12,
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);
        $sheet->getStyle('A7')->getFont()->setSize(11);
        $sheet->getStyle('A9')->getFont()->setSize(10)->setItalic(true);

        $sheet->getStyle('A11:R12')->applyFromArray([
            'font' => [
                'name' => 'Arial',
                'bold' => true,
                'size' => 9,
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'D9EAD3'],
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => '000000'],
                ],
            ],
        ]);

        $sheet->getStyle('A13:R13')->applyFromArray([
            'font' => [
                'name' => 'Arial',
                'bold' => true,
                'size' => 10,
                'color' => ['rgb' => 'FFFFFF'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_LEFT,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '70AD47'],
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => '000000'],
                ],
            ],
        ]);

        $currentRow = 14;
        foreach ($rows as $row) {
            $contractAmount = $this->normalizeExcelNumber($row->contract_amount);
            $actualObligation = $this->normalizeExcelNumber($row->actual_obligation);
            $valueOfAccomplishment = $this->normalizeExcelNumber($row->value_of_acc);
            $actualExpenditures = $this->normalizeExcelNumber($row->actual_expenditures);
            $previousPhy = $this->normalizeExcelNumber($row->{$previousMonthKey . '_phy'});
            $previousFin = $this->normalizeExcelNumber($row->{$previousMonthKey . '_fin'});
            $currentPhy = $this->normalizeExcelNumber($row->{$currentMonthKey . '_phy'});
            $currentFin = $this->normalizeExcelNumber($row->{$currentMonthKey . '_fin'});

            $sheet->setCellValue("A{$currentRow}", $row->year);
            $sheet->setCellValue("B{$currentRow}", $row->project_name);
            $sheet->setCellValue("C{$currentRow}", $row->municipality);
            $sheet->setCellValue("D{$currentRow}", $row->type_of_study);
            $sheet->setCellValue("E{$currentRow}", $contractAmount ?? '');
            $sheet->setCellValue("F{$currentRow}", $contractAmount ?? '');
            $sheet->setCellValue("G{$currentRow}", $row->consultant);
            $sheet->setCellValue("H{$currentRow}", $row->period_start ? \Carbon\Carbon::parse($row->period_start)->format('F d, Y') : '');
            $sheet->setCellValue("I{$currentRow}", $row->period_end ? \Carbon\Carbon::parse($row->period_end)->format('F d, Y') : '');
            $sheet->setCellValue("J{$currentRow}", $contractAmount ?? '');
            $sheet->setCellValue("K{$currentRow}", $actualObligation ?? '');
            $sheet->setCellValue("L{$currentRow}", $valueOfAccomplishment ?? '');
            $sheet->setCellValue("M{$currentRow}", $actualExpenditures ?? '');
            $sheet->setCellValue("N{$currentRow}", $previousPhy ?? '');
            $sheet->setCellValue("O{$currentRow}", $previousFin ?? '');
            $sheet->setCellValue("P{$currentRow}", $currentPhy ?? '');
            $sheet->setCellValue("Q{$currentRow}", $currentFin ?? '');
            $sheet->setCellValue("R{$currentRow}", $row->remarks);
            $sheet->getRowDimension($currentRow)->setRowHeight(34);
            $currentRow++;
        }

        $totalContractAmount = $rows->sum(fn($row) => $this->normalizeExcelNumber($row->contract_amount) ?? 0.0);
        $totalActualObligation = $rows->sum(fn($row) => $this->normalizeExcelNumber($row->actual_obligation) ?? 0.0);
        $totalValueOfAccomplishment = $rows->sum(fn($row) => $this->normalizeExcelNumber($row->value_of_acc) ?? 0.0);
        $totalActualExpenditures = $rows->sum(fn($row) => $this->normalizeExcelNumber($row->actual_expenditures) ?? 0.0);

        $sheet->setCellValue("A{$currentRow}", 'TOTAL FOR PANGASINAN');
        $sheet->setCellValue("E{$currentRow}", $totalContractAmount);
        $sheet->setCellValue("F{$currentRow}", $totalContractAmount);
        $sheet->setCellValue("J{$currentRow}", $totalContractAmount);
        $sheet->setCellValue("K{$currentRow}", $totalActualObligation);
        $sheet->setCellValue("L{$currentRow}", $totalValueOfAccomplishment);
        $sheet->setCellValue("M{$currentRow}", $totalActualExpenditures);

        $sheet->getStyle("A{$currentRow}:R{$currentRow}")->applyFromArray([
            'font' => [
                'name' => 'Arial',
                'bold' => true,
                'size' => 10,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'E2F0D9'],
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => '000000'],
                ],
            ],
        ]);

        $dataEndRow = $currentRow;
        $sheet->getStyle("A14:R{$dataEndRow}")->applyFromArray([
            'font' => [
                'name' => 'Arial',
                'size' => 9,
            ],
            'alignment' => [
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => '000000'],
                ],
            ],
        ]);

        foreach (['B', 'C', 'D', 'G', 'R'] as $column) {
            $sheet->getStyle("{$column}14:{$column}{$dataEndRow}")
                ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        }
        foreach (['A', 'E', 'F', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q'] as $column) {
            $sheet->getStyle("{$column}14:{$column}{$dataEndRow}")
                ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }

        foreach (['E', 'F', 'J', 'K', 'L', 'M'] as $column) {
            $sheet->getStyle("{$column}14:{$column}{$dataEndRow}")
                ->getNumberFormat()->setFormatCode('0.00');
        }
        foreach (['N', 'O', 'P', 'Q'] as $column) {
            $sheet->getStyle("{$column}14:{$column}{$dataEndRow}")
                ->getNumberFormat()->setFormatCode('0.00');
        }

        $signatureHeaderRow = $dataEndRow + 2;
        $signatureNameRow = $signatureHeaderRow + 2;
        $signatureTitleRow = $signatureHeaderRow + 3;

        $sheet->setCellValue("E{$signatureHeaderRow}", 'Checked by:');
        $sheet->setCellValue("I{$signatureHeaderRow}", 'Reviewed by:');
        $sheet->setCellValue("M{$signatureHeaderRow}", 'Submitted by:');

        $sheet->mergeCells("B{$signatureNameRow}:C{$signatureNameRow}");
        $sheet->mergeCells("E{$signatureNameRow}:G{$signatureNameRow}");
        $sheet->mergeCells("I{$signatureNameRow}:K{$signatureNameRow}");
        $sheet->mergeCells("N{$signatureNameRow}:Q{$signatureNameRow}");
        $sheet->mergeCells("B{$signatureTitleRow}:C{$signatureTitleRow}");
        $sheet->mergeCells("E{$signatureTitleRow}:G{$signatureTitleRow}");
        $sheet->mergeCells("I{$signatureTitleRow}:K{$signatureTitleRow}");
        $sheet->mergeCells("N{$signatureTitleRow}:Q{$signatureTitleRow}");

        $sheet->setCellValue("B{$signatureNameRow}", 'ENGR. JESSELLE U. LEAÑO');
        $sheet->setCellValue("E{$signatureNameRow}", 'ENGR. RENZ WILSON L. ETRATA');
        $sheet->setCellValue("I{$signatureNameRow}", 'ENGR. WEYNARD JOSEPH P. UNTALAN');
        $sheet->setCellValue("N{$signatureNameRow}", 'ENGR. JOHN N. MOLANO, MSME');
        $sheet->setCellValue("B{$signatureTitleRow}", 'Economist A');
        $sheet->setCellValue("E{$signatureTitleRow}", 'Unit Head, Planning Unit');
        $sheet->setCellValue("I{$signatureTitleRow}", 'Chief, Engineering Section');
        $sheet->setCellValue("N{$signatureTitleRow}", 'Division Manager A, Pangasinan IMO');

        $sheet->getStyle("E{$signatureHeaderRow}:M{$signatureHeaderRow}")->getFont()->setName('Arial')->setSize(10);
        $sheet->getStyle("B{$signatureNameRow}:Q{$signatureTitleRow}")->applyFromArray([
            'font' => [
                'name' => 'Arial',
                'size' => 10,
                'bold' => true,
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
        ]);
        $sheet->getStyle("B{$signatureTitleRow}:Q{$signatureTitleRow}")->getFont()->setBold(false);

        $this->addFsExcelLogo($sheet, storage_path('app/public/excel_export_assets/page_1_image_6.png'), 'G1', 109, 8, 8);
        $this->addFsExcelLogo($sheet, storage_path('app/public/excel_export_assets/page_1_image_4.png'), 'I1', 109, 8, 10);
        $this->addFsExcelLogo($sheet, storage_path('app/public/excel_export_assets/page_1_image_5.png'), 'K1', 109, 8, 8);

        $writer = new Xlsx($spreadsheet);
        $filename = $this->buildExportFilename('MONTHLY FSDE STATUS REPORT', $request, [
            'fsde_search' => 'Search',
            'fsde_year' => 'Year',
            'fsde_municipality' => 'Municipality',
        ]);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
    // 9. Delete IA Resolution
    public function deleteResolution(Request $request, $id)
    {
        $resolutionFile = IaResolutionFile::with('resolution')->findOrFail($id);
        $resolution = $resolutionFile->resolution;
        $deletedName = $resolutionFile->original_name;

        if (Storage::disk('public')->exists($resolutionFile->file_path)) {
            Storage::disk('public')->delete($resolutionFile->file_path);
        }

        $resolutionFile->delete();

        $resolutionTeam = $resolution?->team ?: 'fs_team';
        if ($resolution) {
            if ($resolution->files()->exists()) {
                $resolution->refreshPrimaryAttachment();
            } else {
                $resolution->delete();
            }
        }
        $teamLabel = $this->notifications()->teamLabel($resolutionTeam);
        $actorLabel = $this->notifications()->actorLabel($request->user());
        $this->notifications()->notifyTeamAndAdmins(
            $request->user(),
            $resolutionTeam,
            'IA resolution removed',
            "{$actorLabel} removed {$deletedName} from {$teamLabel} IA resolutions.",
            [
                'type' => 'ia_resolution',
                'team' => $resolutionTeam,
                'team_label' => $teamLabel,
            ]
        );

        return $this->successResponse($request, 'Resolution deleted successfully.');
    }

    private function addFsExcelLogo($sheet, string $path, string $coordinates, int $height, int $offsetX = 0, int $offsetY = 0): void
    {
        if (!file_exists($path)) {
            return;
        }

        $drawing = new Drawing();
        $drawing->setPath($path);
        $drawing->setCoordinates($coordinates);
        $drawing->setHeight($height);
        $drawing->setOffsetX($offsetX);
        $drawing->setOffsetY($offsetY);
        $drawing->setWorksheet($sheet);
    }

    private function normalizeExcelNumber($value): ?float
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (!is_string($value)) {
            return null;
        }

        $cleaned = trim($value);
        if ($cleaned === '') {
            return null;
        }

        $cleaned = str_replace(',', '', $cleaned);
        $cleaned = preg_replace('/[^0-9.\-]/', '', $cleaned);

        if ($cleaned === '' || !is_numeric($cleaned)) {
            return null;
        }

        return (float) $cleaned;
    }

    private function buildHydroExportQuery(Request $request)
    {
        $query = HydroGeoProject::query();

        if ($request->filled('hydro_search')) {
            $search = trim((string) $request->input('hydro_search'));
            $query->where(function ($builder) use ($search) {
                $builder->where('year', 'like', "%{$search}%")
                    ->orWhere('district', 'like', "%{$search}%")
                    ->orWhere('project_code', 'like', "%{$search}%")
                    ->orWhere('system_name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('municipality', 'like', "%{$search}%")
                    ->orWhere('status', 'like', "%{$search}%")
                    ->orWhere('result', 'like', "%{$search}%");
            });
        }

        if ($request->filled('hydro_status')) {
            $query->where('status', $request->input('hydro_status'));
        }

        if ($request->filled('hydro_district')) {
            $query->where('district', $request->input('hydro_district'));
        }

        return $query;
    }

    private function buildFsdeExportQuery(Request $request)
    {
        $query = FsdeProject::query();

        if ($request->filled('fsde_search')) {
            $search = trim((string) $request->input('fsde_search'));
            $query->where(function ($builder) use ($search) {
                $builder->where('year', 'like', "%{$search}%")
                    ->orWhere('project_name', 'like', "%{$search}%")
                    ->orWhere('municipality', 'like', "%{$search}%")
                    ->orWhere('type_of_study', 'like', "%{$search}%")
                    ->orWhere('consultant', 'like', "%{$search}%")
                    ->orWhere('remarks', 'like', "%{$search}%");
            });
        }

        if ($request->filled('fsde_year')) {
            $query->where('year', $request->input('fsde_year'));
        }

        if ($request->filled('fsde_municipality')) {
            $query->where('municipality', $request->input('fsde_municipality'));
        }

        return $query;
    }

    private function buildExportFilename(string $baseTitle, Request $request, array $filterMap): string
    {
        $parts = [$baseTitle, 'as of', now()->format('F j, Y')];

        foreach ($filterMap as $key => $label) {
            $value = trim((string) $request->input($key, ''));
            if ($value === '') {
                continue;
            }

            $parts[] = $label;
            $parts[] = $value;
        }

        $filename = collect($parts)
            ->filter()
            ->implode(' ')
            . '.xlsx';

        return preg_replace('/[\\\\\\/:*?"<>|]+/', '-', $filename);
    }
}
