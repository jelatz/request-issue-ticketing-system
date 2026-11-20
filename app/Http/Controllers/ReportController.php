<?php

namespace App\Http\Controllers;

use App\Models\InternalSystem;
use App\Services\ReportService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function __construct(
        private readonly ReportService $reportService
    ) {}

    public function index(Request $request): Response
    {
        $filters = $this->validateFilters($request);
        $reportData = $this->reportService->buildOverview($request->user(), $filters);

        return Inertia::render('Reports/Index', [
            'summary' => $reportData['summary'],
            'by_system' => $reportData['by_system'],
            'by_status' => $reportData['by_status'],
            'by_priority' => $reportData['by_priority'],
            'by_engineer' => $reportData['by_engineer'],
            'by_department' => $reportData['by_department'],
            'filters' => $request->only(['date_from', 'date_to', 'type', 'internal_system_id']),
            'systems' => InternalSystem::query()->orderByRaw('LOWER(name) ASC')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        $filters = $this->validateFilters($request);

        $section = $request->input('section', 'tickets');
        if (! in_array($section, ['tickets', 'systems', 'engineers', 'departments'], true)) {
            $section = 'tickets';
        }

        $user = $request->user();
        $filename = 'calltek-report-'.$section.'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($section, $user, $filters): void {
            $handle = fopen('php://output', 'w');

            if ($section === 'systems') {
                fputcsv($handle, ['System Name', 'Total', 'Issues', 'Engineering Requests', 'Active', 'Resolved/Closed', 'Critical']);
                foreach ($this->reportService->buildBySystem($user, $filters) as $row) {
                    fputcsv($handle, [
                        $row['system_name'],
                        $row['total'],
                        $row['issues'],
                        $row['requests'],
                        $row['active'],
                        $row['resolved_closed'],
                        $row['critical'],
                    ]);
                }
            } elseif ($section === 'engineers') {
                fputcsv($handle, ['Engineer ID', 'Name', 'Department', 'Led Total', 'Collaborating Total', 'Active Led', 'Resolved/Closed Led', 'Avg Resolution Hours', 'Subtasks Completed']);
                foreach ($this->reportService->buildByEngineer($user, $filters) as $row) {
                    fputcsv($handle, [
                        $row['id'],
                        $row['name'],
                        $row['department'] ?? '',
                        $row['led_total'],
                        $row['collaborating_total'],
                        $row['active_led'],
                        $row['resolved_closed_led'],
                        $row['avg_resolution_hours'] ?? 'N/A',
                        $row['subtasks_completed'],
                    ]);
                }
            } elseif ($section === 'departments') {
                fputcsv($handle, ['Department', 'Total', 'Issues', 'Engineering Requests', 'Resolved/Closed']);
                foreach ($this->reportService->buildByDepartment($user, $filters) as $row) {
                    fputcsv($handle, [
                        $row['department'],
                        $row['total'],
                        $row['issues'],
                        $row['requests'],
                        $row['resolved_closed'],
                    ]);
                }
            } else {
                fputcsv($handle, [
                    'Ticket Number',
                    'Type',
                    'Title',
                    'System',
                    'Priority',
                    'Status',
                    'Requester',
                    'Department',
                    'Lead Engineer',
                    'Target Due Date',
                    'Created At',
                    'Resolved At',
                    'Closed At',
                ]);

                foreach ($this->reportService->streamTickets($user, $filters) as $ticket) {
                    fputcsv($handle, [
                        $ticket->ticket_number,
                        $ticket->type,
                        $ticket->title,
                        $ticket->internalSystem?->name ?? 'Unassigned / General',
                        $ticket->priority,
                        $ticket->status,
                        $ticket->requester?->name ?? '',
                        $ticket->requester?->department ?? '',
                        $ticket->leadEngineer?->name ?? 'Unassigned',
                        $ticket->target_due_date?->toDateString() ?? '',
                        $ticket->created_at?->toDateTimeString() ?? '',
                        $ticket->resolved_at?->toDateTimeString() ?? '',
                        $ticket->closed_at?->toDateTimeString() ?? '',
                    ]);
                }
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Validate and extract report filter query parameters.
     *
     * @return array{
     *     date_from?: string|null,
     *     date_to?: string|null,
     *     type?: string|null,
     *     internal_system_id?: int|string|null,
     * }
     */
    private function validateFilters(Request $request): array
    {
        return $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => array_values(array_filter([
                'nullable',
                'date_format:Y-m-d',
                $request->filled('date_from') ? 'after_or_equal:date_from' : null,
            ])),
            'type' => ['nullable', 'in:issue,engineering_request'],
            'internal_system_id' => ['nullable', 'integer', 'exists:internal_systems,id'],
        ]);
    }
}
