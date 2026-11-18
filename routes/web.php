<?php

use App\Http\Controllers\Admin\InternalSystemController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\TicketCommentController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\TicketSubtaskController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return Auth::check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
});

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.store');
});

Route::middleware(['auth', 'active'])->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/tickets', [TicketController::class, 'index'])->name('tickets.index');
    Route::post('/tickets', [TicketController::class, 'store'])->name('tickets.store');

    Route::get('/tickets/kanban', [TicketController::class, 'kanban'])
        ->middleware('role:superadmin,engineer')
        ->name('tickets.kanban');

    Route::get('/tickets/{ticket}', [TicketController::class, 'show'])->name('tickets.show');
    Route::patch('/tickets/{ticket}/workflow', [TicketController::class, 'updateWorkflow'])
        ->name('tickets.workflow.update');
    Route::patch('/tickets/{ticket}/assignment', [TicketController::class, 'updateAssignment'])
        ->middleware('role:superadmin')
        ->name('tickets.assignment.update');

    Route::post('/tickets/{ticket}/comments', [TicketCommentController::class, 'store'])
        ->name('tickets.comments.store');

    Route::middleware('role:superadmin,engineer')->group(function (): void {
        Route::post('/tickets/{ticket}/subtasks', [TicketSubtaskController::class, 'store'])
            ->name('tickets.subtasks.store');
        Route::patch('/tickets/{ticket}/subtasks/{subtask}/toggle', [TicketSubtaskController::class, 'toggle'])
            ->name('tickets.subtasks.toggle');
        Route::delete('/tickets/{ticket}/subtasks/{subtask}', [TicketSubtaskController::class, 'destroy'])
            ->name('tickets.subtasks.destroy');
    });

    Route::get('/attachments/{attachment}/download', [TicketController::class, 'downloadAttachment'])
        ->name('attachments.download');

    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markRead'])
        ->name('notifications.read');
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])
        ->name('notifications.readAll');

    Route::middleware('role:superadmin,engineer')->group(function (): void {
        Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/export', [ReportController::class, 'exportCsv'])->name('reports.export');
    });

    Route::prefix('admin')
        ->name('admin.')
        ->middleware('role:superadmin')
        ->group(function (): void {
            Route::get('/users', [UserController::class, 'index'])->name('users.index');
            Route::post('/users', [UserController::class, 'store'])->name('users.store');
            Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');

            Route::get('/systems', [InternalSystemController::class, 'index'])->name('systems.index');
            Route::post('/systems', [InternalSystemController::class, 'store'])->name('systems.store');
            Route::put('/systems/{system}', [InternalSystemController::class, 'update'])->name('systems.update');
        });
});
