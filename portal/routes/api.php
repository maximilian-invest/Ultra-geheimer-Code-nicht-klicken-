<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\AdminApiController;
use App\Http\Controllers\Portal\PortalApiController;

// Health check
Route::get('/ping', function () {
    return response()->json(['status' => 'ok', 'timestamp' => now()->toIso8601String()]);
});

// Deploy diagnostics (temporary)
Route::get('/deploy-check', function () {
    $buildPath = public_path('build/manifest.json');
    $manifest = file_exists($buildPath) ? json_decode(file_get_contents($buildPath), true) : null;
    $dashboardKey = 'resources/js/Pages/Admin/Dashboard.vue';
    $dashboardFile = $manifest[$dashboardKey]['file'] ?? null;
    $dashboardExists = $dashboardFile ? file_exists(public_path('build/' . $dashboardFile)) : false;
    return response()->json([
        'manifest_exists' => file_exists($buildPath),
        'manifest_entries' => $manifest ? count($manifest) : 0,
        'dashboard_file' => $dashboardFile,
        'dashboard_exists' => $dashboardExists,
        'gitignore_build_commented' => str_contains(file_get_contents(base_path('.gitignore')), '# portal/public/build/'),
        'build_dir_files' => count(glob(public_path('build/assets/*'))),
        'deploy_commit' => trim(shell_exec('cd ' . base_path() . ' && git log -1 --oneline 2>/dev/null') ?: 'unknown'),
    ]);
});

// Legacy admin API — maps ?action=xxx to sub-controllers
// Session-Middleware erlaubt Auth::user() aus Browser-Requests (Cookie).
// api.key bleibt fuer externe API-Aufrufe (Email Manager, MCP Tools).
Route::match(['get', 'post'], 'admin_api.php', [AdminApiController::class, 'handle'])
    ->middleware([
        'api.key',
        \Illuminate\Cookie\Middleware\EncryptCookies::class,
        \Illuminate\Session\Middleware\StartSession::class,
    ]);

// Legacy portal API endpoint
Route::match(["get", "post"], "portal_api.php", [PortalApiController::class, "handle"])
    ->middleware("api.key");


// Website Public API (no auth required, rate-limited)
Route::prefix('website')->middleware('throttle:60,1')->group(function () {
    Route::get('/properties', [\App\Http\Controllers\WebsiteApiController::class, 'properties']);
    Route::get('/property/{id}', [\App\Http\Controllers\WebsiteApiController::class, 'property']);
    Route::get('/image/{id}', [\App\Http\Controllers\WebsiteApiController::class, 'image']);
    Route::get('/content', [\App\Http\Controllers\WebsiteApiController::class, 'content']);
    Route::post('/upload', [\App\Http\Controllers\WebsiteApiController::class, 'upload']);
});
Route::get('/openimmo/willhaben.xml', [\App\Http\Controllers\OpenImmoController::class, 'willhabenFeed']);
Route::get('/openimmo/status', [\App\Http\Controllers\OpenImmoController::class, 'feedStatus']);
