<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailAccount;
use App\Services\ImapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EmailAccountController extends Controller
{
    /**
     * email_accounts — List all email accounts (passwords masked).
     */
    public function index(Request $request): JsonResponse
    {
        $brokerId = \Auth::id();
        $user = \Auth::user();
        $isAdmin = $user && $user->user_type === 'admin';
        if (false && $isAdmin) { // Disabled: each user only sees own accounts
            $accounts = DB::select('SELECT * FROM email_accounts ORDER BY id');
        } else if ($brokerId) {
            $accounts = DB::select('SELECT * FROM email_accounts WHERE user_id = ? ORDER BY id', [$brokerId]);
        } else {
            $accounts = DB::select('SELECT * FROM email_accounts ORDER BY id');
        }

        // Mask passwords
        $accounts = array_map(function ($acc) {
            $acc = (array) $acc;
            $acc['imap_password'] = '••••••';
            $acc['smtp_password'] = '••••••';
            return $acc;
        }, $accounts);

        return response()->json(['accounts' => $accounts], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * get_email_accounts_select — Active accounts for dropdown selects.
     */
    public function select(Request $request): JsonResponse
    {
        $brokerId = \Auth::id();
        $user = \Auth::user();
        $isAdmin = $user && $user->user_type === 'admin';
        if (false && $isAdmin) { // Disabled: each user only sees own accounts
            $accounts = DB::select('SELECT id, label, email_address, from_name FROM email_accounts WHERE is_active = 1 ORDER BY label');
        } else if ($brokerId) {
            $accounts = DB::select('SELECT id, label, email_address, from_name FROM email_accounts WHERE is_active = 1 AND user_id = ? ORDER BY label', [$brokerId]);
        } else {
            $accounts = DB::select('SELECT id, label, email_address, from_name FROM email_accounts WHERE is_active = 1 ORDER BY label');
        }

        return response()->json(['accounts' => $accounts], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * save_email_account — Create or update an email account.
     */
    public function save(Request $request): JsonResponse
    {
        $input = $request->json()->all();
        if (empty($input)) {
            return response()->json(['error' => 'Invalid JSON body'], 400);
        }

        $id = $input['id'] ?? null;

        if ($id) {
            // UPDATE existing
            $allowed = [
                'label', 'email_address', 'from_name', 'imap_host', 'imap_port',
                'imap_encryption', 'imap_username', 'smtp_host', 'smtp_port',
                'smtp_encryption', 'smtp_username', 'is_active',
            ];

            $sets = [];
            foreach ($allowed as $f) {
                if (isset($input[$f])) {
                    $sets[$f] = $input[$f];
                }
            }

            // Only update passwords if non-empty and not the masked value
            if (!empty($input['imap_password']) && $input['imap_password'] !== '••••••') {
                $sets['imap_password'] = $input['imap_password'];
            }
            if (!empty($input['smtp_password']) && $input['smtp_password'] !== '••••••') {
                $sets['smtp_password'] = $input['smtp_password'];
            }

            if (empty($sets)) {
                return response()->json(['success' => true, 'message' => 'Nothing to update']);
            }

            DB::table('email_accounts')->where('id', $id)->update($sets);

            return response()->json(['success' => true, 'id' => $id], 200, [], JSON_UNESCAPED_UNICODE);
        }

        // INSERT new
        $newId = DB::table('email_accounts')->insertGetId([
            'label'           => $input['label'] ?? '',
            'email_address'   => $input['email_address'] ?? '',
            'from_name'       => $input['from_name'] ?? 'SR-Homes',
            'imap_host'       => $input['imap_host'] ?? '',
            'imap_port'       => $input['imap_port'] ?? 993,
            'imap_encryption' => $input['imap_encryption'] ?? 'ssl',
            'imap_username'   => $input['imap_username'] ?? '',
            'imap_password'   => $input['imap_password'] ?? '',
            'smtp_host'       => $input['smtp_host'] ?? '',
            'smtp_port'       => $input['smtp_port'] ?? 587,
            'smtp_encryption' => $input['smtp_encryption'] ?? 'tls',
            'smtp_username'   => $input['smtp_username'] ?? '',
            'smtp_password'   => $input['smtp_password'] ?? '',
            'is_active'       => $input['is_active'] ?? 1,
            'created_at'      => now(),
        ]);

        return response()->json(['success' => true, 'id' => $newId], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * delete_email_account — Delete an email account (with force check).
     */
    public function delete(Request $request): JsonResponse
    {
        $input = $request->json()->all();
        $id    = $input['id'] ?? null;
        if (!$id) {
            return response()->json(['error' => 'Missing id'], 400);
        }

        $account = DB::selectOne('SELECT last_uid, label FROM email_accounts WHERE id = ?', [$id]);
        if (!$account) {
            return response()->json(['error' => 'Account not found'], 404);
        }

        $force = ($request->query('force', '') === '1') || !empty($input['force']);

        if ((int) $account->last_uid > 0 && !$force) {
            return response()->json([
                'warning' => true,
                'message' => "Account \"{$account->label}\" has fetched emails (last_uid={$account->last_uid}). Send force=true to delete anyway.",
            ], 200, [], JSON_UNESCAPED_UNICODE);
        }

        DB::table('email_accounts')->where('id', $id)->delete();

        return response()->json(['success' => true, 'deleted_id' => $id], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * test_email_account — Test IMAP and SMTP connections.
     */
    public function test(Request $request): JsonResponse
    {
        $input = $request->json()->all();
        if (empty($input)) {
            return response()->json(['error' => 'Invalid JSON body'], 400);
        }

        // Load account from DB or use direct fields
        if (!empty($input['account_id'])) {
            $acc = DB::selectOne('SELECT * FROM email_accounts WHERE id = ?', [$input['account_id']]);
            if (!$acc) {
                return response()->json(['error' => 'Account not found'], 404);
            }
            $acc = (array) $acc;
        } else {
            $acc = $input;
        }

        $result = [
            'imap' => ['success' => false, 'message' => ''],
            'smtp' => ['success' => false, 'message' => ''],
        ];

        // --- IMAP Test ---
        $imapEnc  = $acc['imap_encryption'] ?? 'ssl';
        $imapFlag = '/imap';
        if ($imapEnc === 'ssl') $imapFlag .= '/ssl';
        if ($imapEnc === 'tls') $imapFlag .= '/tls';
        $imapFlag .= '/novalidate-cert';
        $imapMailbox = '{' . $acc['imap_host'] . ':' . ($acc['imap_port'] ?? 993) . $imapFlag . '}';

        $imapConn = @imap_open($imapMailbox, $acc['imap_username'], $acc['imap_password'], 0, 1);
        if ($imapConn) {
            $folders     = @imap_list($imapConn, $imapMailbox, '*');
            $folderNames = [];
            if ($folders) {
                foreach ($folders as $f) {
                    $folderNames[] = str_replace($imapMailbox, '', $f);
                }
            }
            @imap_close($imapConn);
            $result['imap'] = ['success' => true, 'message' => 'IMAP connection OK', 'folders' => $folderNames];
        } else {
            $err = imap_last_error();
            $result['imap'] = ['success' => false, 'message' => 'IMAP failed: ' . ($err ?: 'Unknown error')];
        }

        // --- SMTP Test ---
        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->SMTPDebug = 0;
            $mail->Host    = $acc['smtp_host'];
            $mail->Port    = $acc['smtp_port'] ?? 587;
            $smtpEnc       = $acc['smtp_encryption'] ?? 'tls';
            if ($smtpEnc === 'tls') {
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            } elseif ($smtpEnc === 'ssl') {
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            } else {
                $mail->SMTPSecure = '';
            }
            $mail->SMTPAuth = true;
            $mail->Username = $acc['smtp_username'];
            $mail->Password = $acc['smtp_password'];
            $mail->Timeout  = 10;

            if ($mail->smtpConnect()) {
                $result['smtp'] = ['success' => true, 'message' => 'SMTP connection OK'];
                $mail->smtpClose();
            } else {
                $result['smtp'] = ['success' => false, 'message' => 'SMTP connection failed'];
            }
        } catch (\Exception $e) {
            $result['smtp'] = ['success' => false, 'message' => 'SMTP error: ' . $e->getMessage()];
        }

        return response()->json($result, 200, [], JSON_UNESCAPED_UNICODE);
    }
}
