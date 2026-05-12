<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\TermsController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\FsTeamController;
use App\Http\Controllers\RpwsisTeamController;
use App\Http\Controllers\ContractManagementTeamController;
use App\Http\Controllers\RowTeamController;
use App\Http\Controllers\PcrTeamController;
use App\Http\Controllers\PaoTeamController;
use App\Http\Controllers\AdministrativeController;
use App\Http\Controllers\GuestController;
use App\Http\Controllers\MapController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\RpwsisAccomplishmentController;
use App\Http\Controllers\DataTableImportController;


Route::get('/phpinfo-test', function () {
       phpinfo();
   });
// Authentication Routes
Route::get('/', [AuthController::class, 'showLogin'])->name('login');
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
Route::get('/email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])->middleware('signed')->name('verification.verify');

Route::post('/guest/authenticate', [GuestController::class, 'authenticate'])->name('guest.authenticate');
Route::get('/guest/terms', [GuestController::class, 'terms'])->name('guest.terms');
Route::post('/guest/accept-terms', [GuestController::class, 'acceptTerms'])->name('guest.accept');
Route::get('/guest/dashboard', [GuestController::class, 'index'])->name('guest.dashboard');
Route::post('/guest/logout', [GuestController::class, 'logout'])->name('guest.logout');
Route::get('/guest/pao-team/pow/export-excel', [PaoTeamController::class, 'exportPowExcel'])->name('guest.pao.pow.export');

Route::get('/map', [MapController::class, 'Showmap'])->name('map');
Route::get('/map/notifications-feed', [MapController::class, 'mapNotifications'])->name('map.notifications.feed');
Route::get('/map/file/{path}', [MapController::class, 'serveMapFile'])->where('path', '.*')->name('map.file');
Route::get('/map/api/status', [MapController::class, 'mapApiStatus'])->name('map.api.status');
Route::get('/api/irrigated-areas', [MapController::class, 'irrigatedAreasInBounds'])->name('map.api.irrigated_areas');
Route::get('/map/render/irrigated/municipality/{municipality}', [MapController::class, 'renderedMunicipalityIrrigatedOverlay'])->where('municipality', '.*')->name('map.render.irrigated.municipality');
Route::get('/map/render/{category}', [MapController::class, 'renderedOverlay'])->name('map.render');
Route::get('/map/overlay/files/{category}', [MapController::class, 'overlayFiles'])->name('map.overlay.files');

Route::get('/irrigated-chart-data', [MapController::class, 'getIrrigatedChartData']);
Route::get('/guest/{team_slug}/dashboard', [GuestController::class, 'teamDashboard'])->name('guest.team.dashboard');
Route::get('/guest/team/{team_slug}/downloadables', [GuestController::class, 'teamDownloadables'])->name('guest.team.downloadables');
Route::get('/guest/team/{team_slug}/resolutions', [GuestController::class, 'teamResolutions'])->name('guest.team.resolutions');
// Routes that require login
Route::middleware(['auth', 'check.active'])->group(function () {
    Route::get('/email/verify', [AuthController::class, 'showVerificationNotice'])->name('verification.notice');
    Route::post('/email/verification-notification', [AuthController::class, 'resendVerificationEmail'])->middleware('throttle:6,1')->name('verification.send');

    Route::middleware('verified.except_admin')->group(function () {
        // Terms and Conditions (RA10173)
        Route::get('/terms', [TermsController::class, 'show'])->name('terms.show');
        Route::post('/terms/agree', [TermsController::class, 'agree'])->name('terms.agree');

        Route::get('/administrative', [AdministrativeController::class, 'index'])->name('administrative.index');
        Route::post('/administrative', [AdministrativeController::class, 'store'])->name('administrative.store');
        Route::delete('/administrative/{id}', [AdministrativeController::class, 'destroy'])->name('administrative.destroy');
        Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::post('/notifications/mark-all-read', [NotificationController::class, 'markAllRead'])->name('notifications.read_all');


    //guest
    // Route::get('/guest/dashboard', [App\Http\Controllers\GuestController::class, 'index'])->name('guest.dashboard');

    //Map Routes
        Route::post('/map/upload', [MapController::class, 'upload'])->name('map.upload');
        Route::get('/map/files', [MapController::class, 'fileManager'])->name('map.files');
        Route::delete('/map/delete-folder', [MapController::class, 'deleteFolder']);
        Route::delete('/map/delete', [MapController::class, 'deleteFile']);
        Route::get('/map/notifications', [MapController::class, 'mapNotifications'])->name('map.notifications');
        Route::post('/map/notifications/clear-old', [MapController::class, 'clearOldMapNotifications'])->name('map.notifications.clear_old');

        // Protected Routes (Must have agreed to terms)
        Route::middleware(['check.terms'])->group(function () {

        // Admin Routes
        Route::middleware(['check.role:admin'])->prefix('admin')->group(function () {
            Route::get('/dashboard', [AdminController::class, 'index'])->name('admin.dashboard');
            Route::get('/audit-trail', [AdminController::class, 'auditTrail'])->name('admin.audit');
            Route::get('/audit-trail/export', [AdminController::class, 'exportAuditTrail'])->name('admin.audit.export');
            Route::get('/users', [AdminController::class, 'manageUsers'])->name('admin.users');
            Route::post('/users', [AdminController::class, 'storeUser'])->name('admin.users.store');
            Route::patch('/users/{user}/status', [AdminController::class, 'updateUserStatus'])->name('admin.users.status');
            Route::patch('/users/{user}/password', [AdminController::class, 'updateUserPassword'])->name('admin.users.password');
            Route::delete('/users/{user}', [AdminController::class, 'destroyUser'])->name('admin.users.destroy');
            // Add more admin routes here

            Route::post('/events', [AdminController::class, 'storeEvent'])->name('admin.events.store');
            Route::patch('/events/{id}', [AdminController::class, 'updateEvent'])->name('admin.events.update');
            Route::delete('/events/{id}', [AdminController::class, 'destroyEvent'])->name('admin.events.destroy');
            // Manage Custom Categories
            Route::post('/event-categories', [AdminController::class, 'storeCategory'])->name('admin.categories.store');
            Route::delete('/event-categories/{id}', [AdminController::class, 'destroyCategory'])->name('admin.categories.destroy');

            //Downloadables
            Route::post('/downloadables/upload', [AdminController::class, 'uploadDownloadable'])->name('admin.downloadables.upload');
            //IA Resolutions
            Route::post('/resolutions/upload', [AdminController::class, 'uploadResolution'])->name('admin.resolutions.upload');
        });

        // ==========================================
        // FS Team Routes
        // ==========================================
        Route::prefix('fs-team')->group(function () {

            // 👁️ VIEWERS (Open to all logged-in agency staff)
            Route::get('/dashboard', [FsTeamController::class, 'index'])->name('fs.dashboard');
            Route::get('/downloadables', [FsTeamController::class, 'downloadables'])->name('fs.downloadables');
            Route::get('/ia-resolutions', [FsTeamController::class, 'resolutions'])->name('fs.resolutions');
            Route::get('/hydro-geo/export-excel', [FsTeamController::class, 'exportHydroExcel'])->name('fs.hydro.export');
            Route::get('/fsde/export-excel', [FsTeamController::class, 'exportFsdeExcel'])->name('fs.fsde.export');

            // 🔒 EDITORS ONLY (Locked to FS Team and Admin)
            Route::middleware(['check.role:fs_team,admin'])->group(function () {
                Route::post('/downloadables/upload', [FsTeamController::class, 'uploadForm'])->name('fs.downloadables.upload');
                Route::post('/downloadables/{id}/update', [FsTeamController::class, 'updateForm'])->name('fs.downloadables.update');
                Route::delete('/downloadables/{id}/delete', [FsTeamController::class, 'deleteForm'])->name('fs.downloadables.delete');

                //resolutions

                Route::post('/ia-resolutions/upload', [FsTeamController::class, 'uploadResolution'])->name('fs.resolutions.upload');
                Route::post('/ia-resolutions/{id}/update', [FsTeamController::class, 'updateResolution'])->name('fs.resolutions.update');

                Route::delete('/ia-resolutions/{id}/delete', [FsTeamController::class, 'deleteResolution'])->name('fs.resolutions.delete');

                Route::post('/ia-resolutions/{id}/status', [FsTeamController::class, 'updateResolutionStatus'])->name('fs.resolutions.update_status');
                Route::post('/projects/{project}/update-status', [FsTeamController::class, 'updateStatus'])->name('fs.projects.update');
                Route::post('/hydro-geo', [FsTeamController::class, 'storeHydroGeo'])->name('fs.hydro.store');
                Route::post('/fsde/store', [FsTeamController::class, 'storeFsde'])->name('fs.fsde.store');
                Route::post('/hydro-geo/import', [DataTableImportController::class, 'import'])->defaults('table', 'fs-hydro')->name('fs.hydro.import');
                Route::post('/fsde/import', [DataTableImportController::class, 'import'])->defaults('table', 'fs-fsde')->name('fs.fsde.import');
                Route::put('/hydro-geo/{id}', [FsTeamController::class, 'updateHydroGeo'])->name('fs.hydro.update');
                Route::put('/fsde/{id}', [FsTeamController::class, 'updateFsde'])->name('fs.fsde.update');
                Route::delete('/hydro-geo/{id}', [FsTeamController::class, 'destroyHydroGeo'])->name('fs.hydro.destroy');
                Route::delete('/fsde/{id}', [FsTeamController::class, 'destroyFsde'])->name('fs.fsde.destroy');
            });
        });

        // ==========================================
        // RP-WSIS Team Routes
        // ==========================================
        Route::prefix('rpwsis_team')->group(function () {

            // 👁️ VIEWERS (Open to all logged-in agency staff)
            Route::get('/dashboard', [RpwsisTeamController::class, 'index'])->name('rpwsis.dashboard');
            Route::get('/downloadables', [RpwsisTeamController::class, 'downloadables'])->name('rpwsis.downloadables');
            Route::get('/ia-resolutions', [RpwsisTeamController::class, 'resolutions'])->name('rpwsis.resolutions');
            Route::get('/accomplishments/export-excel', [RpwsisTeamController::class, 'exportAccomplishmentExcel'])->name('rpwsis.accomplishments.export');
            Route::get('/summary/export-excel', [RpwsisTeamController::class, 'exportSummaryExcel'])->name('rpwsis.summary.export');

            // 🔒 EDITORS ONLY (Locked to RP-WSIS Team and Admin)
            Route::middleware(['check.role:rpwsis_team,admin'])->group(function () {
                Route::post('/downloadables/upload', [RpwsisTeamController::class, 'uploadForm'])->name('rpwsis.downloadables.upload');
                Route::post('/downloadables/{id}/update', [RpwsisTeamController::class, 'updateForm'])->name('rpwsis.downloadables.update');
                Route::delete('/downloadables/{id}/delete', [RpwsisTeamController::class, 'deleteForm'])->name('rpwsis.downloadables.delete');

                Route::post('/ia-resolutions/upload', [RpwsisTeamController::class, 'uploadResolution'])->name('rpwsis.resolutions.upload');

                //delete
                Route::delete('/ia-resolutions/{id}/delete', [RpwsisTeamController::class, 'deleteResolution'])->name('rpwsis.resolutions.delete');

                Route::post('/ia-resolutions/{id}/update', [RpwsisTeamController::class, 'updateResolution'])->name('rpwsis.resolutions.update');
                Route::post('/ia-resolutions/{id}/status', [RpwsisTeamController::class, 'updateResolutionStatus'])->name('rpwsis.resolutions.update_status');

                //status table
                Route::post('/accomplishments/store', [RpwsisTeamController::class, 'storeAccomplishment'])->name('rpwsis.accomplishments.store');
                Route::post('/accomplishments/import', [DataTableImportController::class, 'import'])->defaults('table', 'rpwsis-accomplishments')->name('rpwsis.accomplishments.import');
                Route::put('/accomplishments/{id}/update', [RpwsisTeamController::class, 'updateAccomplishment'])->name('rpwsis.accomplishments.update');
                //delete
                Route::delete('/accomplishments/{id}/delete', [RpwsisTeamController::class, 'deleteAccomplishment'])->name('rpwsis.accomplishments.delete');
                //summary table
                //summary table
                Route::post('/summary/store', [RpwsisTeamController::class, 'storeSummary'])->name('rpwsis.summary.store');
                Route::post('/summary/import', [DataTableImportController::class, 'import'])->defaults('table', 'rpwsis-summary')->name('rpwsis.summary.import');
                Route::put('/summary/{id}/update', [RpwsisTeamController::class, 'updateSummary'])->name('rpwsis.summary.update');
                Route::delete('/summary/{id}/delete', [RpwsisTeamController::class, 'deleteSummary'])->name('rpwsis.summary.delete');

                // Nursery table routes
                    Route::post('/nursery/store', [App\Http\Controllers\RpwsisTeamController::class, 'storeNursery'])->name('rpwsis.nursery.store');
                    Route::post('/nursery/import', [DataTableImportController::class, 'import'])->defaults('table', 'rpwsis-nursery')->name('rpwsis.nursery.import');
                    Route::put('/nursery/{id}/update', [App\Http\Controllers\RpwsisTeamController::class, 'updateNursery'])->name('rpwsis.nursery.update');
                    Route::delete('/nursery/{id}/delete', [App\Http\Controllers\RpwsisTeamController::class, 'deleteNursery'])->name('rpwsis.nursery.delete');

                    // Signages table routes
                Route::post('/signages/store', [App\Http\Controllers\RpwsisTeamController::class, 'storeSignages'])->name('rpwsis.signages.store');
                Route::post('/signages/import', [DataTableImportController::class, 'import'])->defaults('table', 'rpwsis-signages')->name('rpwsis.signages.import');
                Route::put('/signages/{id}/update', [App\Http\Controllers\RpwsisTeamController::class, 'updateSignages'])->name('rpwsis.signages.update');
                Route::delete('/signages/{id}/delete', [App\Http\Controllers\RpwsisTeamController::class, 'deleteSignages'])->name('rpwsis.signages.delete');

                // Infrastructure table routes
                Route::post('/infrastructure/store', [App\Http\Controllers\RpwsisTeamController::class, 'storeInfrastructure'])->name('rpwsis.infrastructure.store');
                Route::post('/infrastructure/import', [DataTableImportController::class, 'import'])->defaults('table', 'rpwsis-infrastructure')->name('rpwsis.infrastructure.import');
                Route::put('/infrastructure/{id}/update', [App\Http\Controllers\RpwsisTeamController::class, 'updateInfrastructure'])->name('rpwsis.infrastructure.update');
                Route::delete('/infrastructure/{id}/delete', [App\Http\Controllers\RpwsisTeamController::class, 'deleteInfrastructure'])->name('rpwsis.infrastructure.delete');

            });


        });



        // ==========================================
        // Contract Management Team Routes
        // ==========================================
        Route::prefix('cm_team')->group(function () {

            // 👁️ VIEWERS (Open to all logged-in agency staff)
            Route::get('/dashboard', [ContractManagementTeamController::class, 'index'])->name('cm.dashboard');
            Route::get('/downloadables', [ContractManagementTeamController::class, 'downloadables'])->name('cm.downloadables');
            Route::get('/ia-resolutions', [ContractManagementTeamController::class, 'resolutions'])->name('cm.resolutions');
            Route::get('/procurement/export-excel', [ContractManagementTeamController::class, 'exportProcurementExcel'])->name('cm.procurement.export');

            // 🔒 EDITORS ONLY (Locked to Contract Management Team and Admin)
            Route::middleware(['check.role:cm_team,admin'])->group(function () {
                Route::post('/downloadables/upload', [ContractManagementTeamController::class, 'uploadForm'])->name('cm.downloadables.upload');
                Route::post('/downloadables/{id}/update', [ContractManagementTeamController::class, 'updateForm'])->name('cm.downloadables.update');
                Route::delete('/downloadables/{id}/delete', [ContractManagementTeamController::class, 'deleteForm'])->name('cm.downloadables.delete');

                Route::post('/ia-resolutions/upload', [ContractManagementTeamController::class, 'uploadResolution'])->name('cm.resolutions.upload');

                //delete
                Route::delete('/resolutions/{id}/delete', [ContractManagementTeamController::class, 'deleteResolution'])->name('cm.resolutions.delete');

                Route::post('/ia-resolutions/{id}/update', [ContractManagementTeamController::class, 'updateResolution'])->name('cm.resolutions.update');
                Route::post('/ia-resolutions/{id}/status', [ContractManagementTeamController::class, 'updateResolutionStatus'])->name('cm.resolutions.update_status');

                Route::post('/procurement/store', [ContractManagementTeamController::class, 'storeProcurement'])->name('cm.procurement.store');
                Route::post('/procurement/import', [DataTableImportController::class, 'import'])->defaults('table', 'cm-procurement')->name('cm.procurement.import');
                Route::put('/procurement/update', [ContractManagementTeamController::class, 'updateProcurement'])->name('cm.procurement.update');
                Route::delete('/procurement/{id}', [ContractManagementTeamController::class, 'destroyProcurement'])->name('cm.procurement.destroy');

                Route::delete('/procurement/{id}/delete-ca-file', [ContractManagementTeamController::class, 'deleteCAFile'])->name('cm.procurement.delete_ca');
                Route::delete('/procurement/{id}/delete-ntp-file', [ContractManagementTeamController::class, 'deleteNTPFile'])->name('cm.procurement.delete_ntp');
            });
        });

        // ==========================================
        // Right Of Way Team Routes
        // ==========================================
        Route::prefix('row_team')->group(function () {

            // 👁️ VIEWERS (Open to all logged-in agency staff)
            Route::get('/dashboard', [RowTeamController::class, 'index'])->name('row.dashboard');
            Route::get('/downloadables', [RowTeamController::class, 'downloadables'])->name('row.downloadables');
            Route::get('/ia-resolutions', [RowTeamController::class, 'resolutions'])->name('row.resolutions');

            // 🔒 EDITORS ONLY (Locked to Row Team and Admin)
            Route::middleware(['check.role:row_team,admin'])->group(function () {
                Route::post('/downloadables/upload', [RowTeamController::class, 'uploadForm'])->name('row.downloadables.upload');
                Route::post('/downloadables/{id}/update', [RowTeamController::class, 'updateForm'])->name('row.downloadables.update');
                Route::delete('/downloadables/{id}/delete', [RowTeamController::class, 'deleteForm'])->name('row.downloadables.delete');

                Route::post('/ia-resolutions/upload', [RowTeamController::class, 'uploadResolution'])->name('row.resolutions.upload');

                //delete
                Route::delete('/resolutions/{id}/delete', [RowTeamController::class, 'deleteResolution'])->name('row.resolutions.delete');

                Route::post('/ia-resolutions/{id}/update', [RowTeamController::class, 'updateResolution'])->name('row.resolutions.update');
                Route::post('/ia-resolutions/{id}/status', [RowTeamController::class, 'updateResolutionStatus'])->name('row.resolutions.update_status');
            });
        });

        // ==========================================
        // Program Completion Report Team Routes
        // ==========================================
        Route::prefix('pcr_team')->group(function () {

            // 👁️ VIEWERS (Open to all logged-in agency staff)
            Route::get('/dashboard', [PcrTeamController::class, 'index'])->name('pcr.dashboard');
            Route::get('/downloadables', [PcrTeamController::class, 'downloadables'])->name('pcr.downloadables');
            Route::get('/ia-resolutions', [PcrTeamController::class, 'resolutions'])->name('pcr.resolutions');
            Route::get('/status/export-excel', [PcrTeamController::class, 'exportPcrStatusExcel'])->name('pcr.status.export');

            // 🔒 EDITORS ONLY (Locked to PCR Team and Admin)
            Route::middleware(['check.role:pcr_team,admin'])->group(function () {
                Route::post('/downloadables/upload', [PcrTeamController::class, 'uploadForm'])->name('pcr.downloadables.upload');
                Route::post('/downloadables/{id}/update', [PcrTeamController::class, 'updateForm'])->name('pcr.downloadables.update');
                Route::delete('/downloadables/{id}/delete', [PcrTeamController::class, 'deleteForm'])->name('pcr.downloadables.delete');

                Route::post('/ia-resolutions/upload', [PcrTeamController::class, 'uploadResolution'])->name('pcr.resolutions.upload');

                //delete
                Route::delete('/resolutions/{id}/delete', [PcrTeamController::class, 'deleteResolution'])->name('pcr.resolutions.delete');

                Route::post('/ia-resolutions/{id}/update', [PcrTeamController::class, 'updateResolution'])->name('pcr.resolutions.update');
                Route::post('/ia-resolutions/{id}/status', [PcrTeamController::class, 'updateResolutionStatus'])->name('pcr.resolutions.update_status');
                Route::post('/status/store', [PcrTeamController::class, 'storePcrStatus'])->name('pcr.status.store');
                Route::post('/status/import', [DataTableImportController::class, 'import'])->defaults('table', 'pcr-status')->name('pcr.status.import');
                Route::put('/status/update', [PcrTeamController::class, 'updatePcrStatus'])->name('pcr.status.update');
                Route::delete('/status/delete/{id}', [PcrTeamController::class, 'deletePcrStatus'])->name('pcr.status.delete');
            });
        });

        // ==========================================
        // Programming Team Routes
        // ==========================================
        Route::prefix('pao_team')->group(function () {

            // 👁️ VIEWERS (Open to all logged-in agency staff)
            Route::get('/dashboard', [PaoTeamController::class, 'index'])->name('pao.dashboard');
            Route::get('/downloadables', [PaoTeamController::class, 'downloadables'])->name('pao.downloadables');
            Route::get('/ia-resolutions', [PaoTeamController::class, 'resolutions'])->name('pao.resolutions');
            Route::get('/pow/export-excel', [PaoTeamController::class, 'exportPowExcel'])->name('pao.pow.export');

            // 🔒 EDITORS ONLY (Locked to Programming Team and Admin)
            Route::middleware(['check.role:pao_team,admin'])->group(function () {
                Route::post('/downloadables/upload', [PaoTeamController::class, 'uploadForm'])->name('pao.downloadables.upload');
                Route::post('/downloadables/{id}/update', [PaoTeamController::class, 'updateForm'])->name('pao.downloadables.update');
                Route::delete('/downloadables/{id}/delete', [PaoTeamController::class, 'deleteForm'])->name('pao.downloadables.delete');

                Route::post('/ia-resolutions/upload', [PaoTeamController::class, 'uploadResolution'])->name('pao.resolutions.upload');

                //delete
                Route::delete('/resolutions/{id}/delete', [PaoTeamController::class, 'deleteResolution'])->name('pao.resolutions.delete');

                Route::post('/ia-resolutions/{id}/update', [PaoTeamController::class, 'updateResolution'])->name('pao.resolutions.update');
                Route::post('/ia-resolutions/{id}/status', [PaoTeamController::class, 'updateResolutionStatus'])->name('pao.resolutions.update_status');

                Route::post('/pow/store', [PaoTeamController::class, 'storePow'])->name('pao.pow.store');
                Route::post('/pow/import', [DataTableImportController::class, 'import'])->defaults('table', 'pao-pow')->name('pao.pow.import');
                Route::put('/pow/update', [PaoTeamController::class, 'updatePow'])->name('pao.pow.update');
                Route::delete('/pow/delete/{id}', [PaoTeamController::class, 'deletePow'])->name('pao.pow.delete');
            });
        });

        });
    });
}); // Ensure user is active before allowing access to any routes within this group
