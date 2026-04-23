<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Portal\DashboardController as PortalDashboardController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return auth()->check() ? redirect()->route('dashboard') : redirect()->route('login');
});

// Redirect dashboard based on user type
Route::get('/dashboard', function () {
    $user = auth()->user();
    if (in_array($user->user_type, ['admin', 'makler', 'assistenz'])) {
        return redirect()->route('admin.dashboard');
    }
    return redirect()->route('portal.dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

// Admin routes
Route::middleware(['auth', 'verified', 'role:admin,makler,assistenz'])->prefix('admin')->group(function () {
    Route::get('/', [AdminDashboardController::class, 'index'])->name('admin.dashboard');
});

// Customer portal routes
Route::middleware(['auth', 'verified'])->prefix('portal')->group(function () {
    Route::get('/', [PortalDashboardController::class, 'index'])->name('portal.dashboard');
    Route::get('/property/{property}', [PortalDashboardController::class, 'property'])->name('portal.property');
    Route::get('/api/analysis/{property}', [PortalDashboardController::class, 'analysis'])->name('portal.analysis');

    // Nachrichten-System: Owner sends messages
    Route::post('/property/{property}/message', [PortalDashboardController::class, 'sendMessage'])->name('portal.message.send');

    // Document download
    Route::get('/documents/download/{document}', [PortalDashboardController::class, 'downloadDocument'])->name('portal.document.download');
    Route::get("/files/download/{file}", [PortalDashboardController::class, "downloadPropertyFile"])->name("portal.file.download");

    // Market Report PDF Download
    Route::get("/bericht/{property}/pdf", [PortalDashboardController::class, "downloadPropertyReport"])->name("portal.bericht.pdf");
    Route::get("/bericht/{property}/bankbericht", [PortalDashboardController::class, "downloadBankReport"])->name("portal.bankbericht.pdf");
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// Property Links (admin) — see docs/superpowers/specs/2026-04-14-docs-link-sharing-design.md
Route::middleware(['auth', 'verified', 'role:admin,makler,assistenz'])
    ->prefix('admin/properties/{property}/links')
    ->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\PropertyLinkController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\Admin\PropertyLinkController::class, 'store']);
        Route::get('/active', [\App\Http\Controllers\Admin\PropertyLinkController::class, 'activeForProperty'])
            ->name('admin.property-links.active');
        Route::get('/{link}', [\App\Http\Controllers\Admin\PropertyLinkController::class, 'show']);
        Route::put('/{link}', [\App\Http\Controllers\Admin\PropertyLinkController::class, 'update']);
        Route::delete('/{link}', [\App\Http\Controllers\Admin\PropertyLinkController::class, 'destroy']);
        Route::post('/{link}/revoke', [\App\Http\Controllers\Admin\PropertyLinkController::class, 'revoke']);
        Route::post('/{link}/reactivate', [\App\Http\Controllers\Admin\PropertyLinkController::class, 'reactivate']);
    });

// Exposé (Admin: generate/preview)
Route::middleware(['auth', 'verified', 'role:admin,makler,assistenz'])->group(function () {
    Route::post('/admin/properties/{property}/expose',
        [\App\Http\Controllers\Admin\ExposeController::class, 'store']);
    Route::get('/admin/properties/{property}/expose/preview',
        [\App\Http\Controllers\Admin\ExposeController::class, 'preview']);
    Route::post('/admin/properties/{property}/expose/captions',
        [\App\Http\Controllers\Admin\ExposeController::class, 'updateCaptions']);
});

// DSGVO export + delete for link sessions (admin)
Route::middleware(['auth', 'verified', 'role:admin,makler,assistenz'])->prefix('admin/dsgvo')->group(function () {
    Route::get('/links', [\App\Http\Controllers\Admin\DsgvoLinkController::class, 'export']);
    Route::delete('/links', [\App\Http\Controllers\Admin\DsgvoLinkController::class, 'destroy']);
});

// Public document delivery
Route::prefix('docs')->group(function () {
    Route::get('{token}', [\App\Http\Controllers\PublicDocumentController::class, 'show'])->name('docs.show');
    Route::post('{token}/unlock', [\App\Http\Controllers\PublicDocumentController::class, 'unlock']);
    Route::get('{token}/file/{fileId}/{mode}', [\App\Http\Controllers\PublicDocumentController::class, 'file'])->where('mode', 'view|download');
    Route::post('{token}/event', [\App\Http\Controllers\PublicDocumentController::class, 'event']);
    Route::get('{token}/expose', [\App\Http\Controllers\PublicDocumentController::class, 'expose'])->name('docs.expose');
    Route::get('{token}/expose.pdf', [\App\Http\Controllers\PublicDocumentController::class, 'exposePdf'])->name('docs.expose.pdf');
});

require __DIR__.'/auth.php';
