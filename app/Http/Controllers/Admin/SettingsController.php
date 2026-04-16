<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class SettingsController extends Controller
{
    private function resolvedUser()
    {
        $user = \Auth::user();
        if (!$user || $user->user_type === 'eigentuemer') {
            $user = \App\Models\User::whereIn('user_type', ['admin', 'makler'])->first();
        }
        return $user;
    }

    public function get(Request $request): JsonResponse
    {
        // Use authenticated user, fallback to first admin/makler for API-key-based requests
        $user = $this->resolvedUser();

        $settings = DB::table('admin_settings')->where('user_id', $user->id ?? 1)->first();

        // Use full URL for email signatures (must work in external email clients)
        $baseUrl = rtrim(config('app.url'), '/');

        return response()->json([
            'name'  => $user->name ?? '',
            'email' => $user->email ?? '',
            'auto_reply_enabled' => (bool) ($settings->auto_reply_enabled ?? false),
            'auto_reply_text'    => $settings->auto_reply_text ?? null,
            'auto_reply_property_ids' => $settings->auto_reply_property_ids ?? null,
            'auto_reply_log'     => \Illuminate\Support\Facades\DB::table('auto_reply_log')
                ->orderBy('created_at', 'desc')->limit(20)->get()->toArray(),
            'phone' => $settings->phone ?? '',
            'signature_name'    => $settings->signature_name ?? ($user->name ?? ''),
            'signature_title'   => $settings->signature_title ?? '',
            'signature_company' => $settings->signature_company ?? 'SR-Homes Immobilien GmbH',
            'signature_phone'   => $settings->signature_phone ?? '+43 664 2600 930',
            'signature_website' => $settings->signature_website ?? 'www.sr-homes.at',
            'signature_logo_url'   => ($settings->signature_logo_path ?? null)
                ? $baseUrl . '/storage/' . $settings->signature_logo_path
                : null,
            'signature_banner_url' => ($settings->signature_banner_path ?? null)
                ? $baseUrl . '/storage/' . $settings->signature_banner_path
                : null,
            'signature_photo_url' => ($settings->signature_photo_path ?? null)
                ? $baseUrl . '/storage/' . $settings->signature_photo_path
                : null,
        ]);
    }

    public function save(Request $request): JsonResponse
    {
        $input = $request->json()->all();
        $user = $this->resolvedUser();
        $userId = $user->id ?? 1;

        // Update user name/email
        if (!empty($input['name'])) {
            DB::table('users')->where('id', $userId)->update(['name' => trim($input['name'])]);
        }
        if (!empty($input['email'])) {
            DB::table('users')->where('id', $userId)->update(['email' => trim($input['email'])]);
        }

        // Upsert admin_settings
        $existing = DB::table('admin_settings')->where('user_id', $userId)->first();
        $settingsData = [
            'auto_reply_enabled' => !empty($input['auto_reply_enabled']) ? 1 : 0,
            'auto_reply_text'    => trim($input['auto_reply_text'] ?? '') ?: null,
            'phone'             => trim($input['phone'] ?? ''),
            'signature_name'    => trim($input['signature_name'] ?? ''),
            'signature_title'   => trim($input['signature_title'] ?? ''),
            'signature_company' => trim($input['signature_company'] ?? ''),
            'signature_phone'   => trim($input['signature_phone'] ?? ''),
            'signature_website' => trim($input['signature_website'] ?? ''),
            'updated_at'        => now(),
        ];

        if ($existing) {
            DB::table('admin_settings')->where('user_id', $userId)->update($settingsData);
        } else {
            $settingsData['user_id'] = $userId;
            $settingsData['created_at'] = now();
            DB::table('admin_settings')->insert($settingsData);
        }

        // Sync signature fields to users table (used by Portal Ansprechpartner)
        $userSync = ['updated_at' => now()];
        if (!empty($input['signature_name'])) $userSync['signature_name'] = trim($input['signature_name']);
        if (!empty($input['signature_phone'])) $userSync['signature_phone'] = trim($input['signature_phone']);
        if (!empty($input['signature_title'])) $userSync['signature_title'] = trim($input['signature_title']);
        if (!empty($input['signature_company'])) $userSync['signature_company'] = trim($input['signature_company']);
        if (!empty($input['signature_website'])) $userSync['signature_website'] = trim($input['signature_website']);
        if (!empty($input['phone'])) $userSync['phone'] = trim($input['phone']);
        DB::table('users')->where('id', $userId)->update($userSync);

        return response()->json(['success' => true]);
    }

    /**
     * Upload signature image (logo or banner).
     */
    public function uploadSignatureImage(Request $request): JsonResponse
    {
        $type = $request->input('type'); // 'logo' or 'banner'
        if (!in_array($type, ['logo', 'banner', 'photo'])) {
            return response()->json(['error' => 'type must be logo or banner'], 400);
        }

        if (!$request->hasFile('image')) {
            return response()->json(['error' => 'No image uploaded'], 400);
        }

        $file = $request->file('image');
        $allowedMimes = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];
        if (!in_array($file->getMimeType(), $allowedMimes)) {
            return response()->json(['error' => 'Nur Bilder (PNG, JPG, GIF, WebP) erlaubt'], 400);
        }

        $user = $this->resolvedUser();
        $userId = $user->id ?? 1;

        // Store in public storage
        $filename = "signature_{$type}_" . time() . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs('signature_images', $filename, 'public');

        // Update admin_settings
        $column = match($type) { 'logo' => 'signature_logo_path', 'banner' => 'signature_banner_path', 'photo' => 'signature_photo_path' };
        $existing = DB::table('admin_settings')->where('user_id', $userId)->first();

        // Delete old file if exists
        if ($existing && $existing->$column) {
            $oldPath = storage_path('app/public/' . $existing->$column);
            if (file_exists($oldPath)) @unlink($oldPath);
        }

        if ($existing) {
            DB::table('admin_settings')->where('user_id', $userId)->update([$column => $path, 'updated_at' => now()]);
        } else {
            DB::table('admin_settings')->insert(['user_id' => $userId, $column => $path, 'created_at' => now(), 'updated_at' => now()]);
        }

        $baseUrl = rtrim(config('app.url'), '/');
        return response()->json([
            'success' => true,
            'url' => $baseUrl . '/storage/' . $path,
            'path' => $path,
        ]);
    }

    /**
     * Delete signature image.
     */
    public function deleteSignatureImage(Request $request): JsonResponse
    {
        $input = $request->json()->all();
        $type = $input['type'] ?? '';
        if (!in_array($type, ['logo', 'banner', 'photo'])) {
            return response()->json(['error' => 'type must be logo or banner'], 400);
        }

        $user = $this->resolvedUser();
        $userId = $user->id ?? 1;

        $column = match($type) { 'logo' => 'signature_logo_path', 'banner' => 'signature_banner_path', 'photo' => 'signature_photo_path' };
        $existing = DB::table('admin_settings')->where('user_id', $userId)->first();

        if ($existing && $existing->$column) {
            $oldPath = storage_path('app/public/' . $existing->$column);
            if (file_exists($oldPath)) @unlink($oldPath);
            DB::table('admin_settings')->where('user_id', $userId)->update([$column => null, 'updated_at' => now()]);
        }

        return response()->json(['success' => true]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $input = $request->json()->all();
        $user = $this->resolvedUser();

        $current = $input['current_password'] ?? '';
        $new = $input['new_password'] ?? '';

        if (!$current || !$new) {
            return response()->json(['error' => 'Aktuelles und neues Passwort erforderlich'], 400);
        }

        if (!Hash::check($current, $user->password)) {
            return response()->json(['error' => 'Aktuelles Passwort ist falsch'], 400);
        }

        if (strlen($new) < 8) {
            return response()->json(['error' => 'Neues Passwort muss mindestens 8 Zeichen lang sein'], 400);
        }

        $user->password = Hash::make($new);
        $user->save();

        return response()->json(['success' => true]);
    }

    public function toggleAutoReply(Request $request): JsonResponse
    {
        $input = $request->json()->all();
        $enabled = !empty($input['enabled']) ? 1 : 0;
        $text = trim($input['auto_reply_text'] ?? '') ?: null;

        $user = $this->resolvedUser();
        $userId = $user->id ?? 1;

        $existing = DB::table('admin_settings')->where('user_id', $userId)->first();
        $propertyIds = $input['auto_reply_property_ids'] ?? null;
        
        $data = ['auto_reply_enabled' => $enabled, 'updated_at' => now()];
        if ($text !== null) $data['auto_reply_text'] = $text;
        if ($propertyIds !== null) $data['auto_reply_property_ids'] = $propertyIds;

        if ($existing) {
            DB::table('admin_settings')->where('user_id', $userId)->update($data);
        } else {
            $data['user_id'] = $userId;
            $data['created_at'] = now();
            DB::table('admin_settings')->insert($data);
        }

        \Illuminate\Support\Facades\Log::info("Auto-Reply " . ($enabled ? 'ENABLED' : 'DISABLED'));

        return response()->json([
            'success' => true,
            'auto_reply_enabled' => (bool) $enabled,
        ]);
    }

    public function listInboxRules(Request $request): JsonResponse
    {
        $user = $this->resolvedUser();
        $userId = $user->id ?? 1;

        $rules = DB::table('inbox_sender_rules')
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->get(['id', 'pattern', 'action', 'enabled', 'created_at']);

        return response()->json(['rules' => $rules]);
    }

    public function saveInboxRule(Request $request): JsonResponse
    {
        $user = $this->resolvedUser();
        $userId = $user->id ?? 1;
        $input = $request->json()->all();

        $pattern = trim((string) ($input['pattern'] ?? ''));
        if ($pattern === '') {
            return response()->json(['error' => 'pattern required'], 400);
        }

        $action = (string) ($input['action'] ?? 'exclude_anfragen');
        if ($action !== 'exclude_anfragen') {
            return response()->json(['error' => 'unsupported action'], 400);
        }

        $enabled = array_key_exists('enabled', $input) ? !empty($input['enabled']) : true;
        $id = intval($input['id'] ?? 0);

        if ($id > 0) {
            $exists = DB::table('inbox_sender_rules')->where('id', $id)->where('user_id', $userId)->exists();
            if (!$exists) return response()->json(['error' => 'rule not found'], 404);

            DB::table('inbox_sender_rules')
                ->where('id', $id)
                ->where('user_id', $userId)
                ->update([
                    'pattern' => $pattern,
                    'action' => $action,
                    'enabled' => $enabled ? 1 : 0,
                    'updated_at' => now(),
                ]);
        } else {
            DB::table('inbox_sender_rules')->insert([
                'user_id' => $userId,
                'pattern' => $pattern,
                'action' => $action,
                'enabled' => $enabled ? 1 : 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return response()->json(['success' => true]);
    }

    public function deleteInboxRule(Request $request): JsonResponse
    {
        $user = $this->resolvedUser();
        $userId = $user->id ?? 1;
        $input = $request->json()->all();
        $id = intval($input['id'] ?? 0);
        if ($id <= 0) return response()->json(['error' => 'id required'], 400);

        DB::table('inbox_sender_rules')
            ->where('id', $id)
            ->where('user_id', $userId)
            ->delete();

        return response()->json(['success' => true]);
    }
}
