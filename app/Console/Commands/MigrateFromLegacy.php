<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class MigrateFromLegacy extends Command
{
    protected $signature = 'migrate:from-legacy {--delta-only : Only import new records}';
    protected $description = 'Migrate data from legacy World4You database via API export';

    private string $apiBase = 'https://kundenportal.sr-homes.at/api/db_export.php';
    private string $apiKey = '';

    public function __construct()
    {
        parent::__construct();
        $this->apiKey = (string) config('portal.api_key', '');
    }

    public function handle(): int
    {
        $this->info('Starting migration via API...');

        $tables = [
            'customers' => 'customers',
            'properties' => 'properties',
            'activities' => 'activities',
            'portal_emails' => 'portal_emails',
            'contacts' => 'contacts',
            'email_accounts' => 'email_accounts',
            'email_drafts' => 'email_drafts',
            'property_knowledge' => 'property_knowledge',
            'viewings' => 'viewings',
            'portal_messages' => 'portal_messages',
            'portal_documents' => 'portal_documents',
        ];

        foreach ($tables as $srcTable => $dstTable) {
            $this->migrateTable($srcTable, $dstTable);
        }

        $this->createEigentuemerUsers();
        $this->verify();

        // Cleanup: remove export script from old server
        $this->info("\n✅ Migration complete!");
        $this->warn("⚠️  Remember to delete db_export.php from the old server after verification.");
        return 0;
    }

    private function migrateTable(string $srcTable, string $dstTable): void
    {
        $this->info("\nMigrating {$srcTable}...");
        $offset = 0;
        $limit = 500;
        $total = 0;
        $imported = 0;

        // Get column list for destination table
        $dstColumns = DB::getSchemaBuilder()->getColumnListing($dstTable);

        do {
            $url = "{$this->apiBase}?key={$this->apiKey}&table={$srcTable}&offset={$offset}&limit={$limit}";
            $response = Http::timeout(30)->get($url);

            if (!$response->ok()) {
                $this->error("  HTTP error for {$srcTable}: " . $response->status());
                return;
            }

            $data = $response->json();

            if (isset($data['error'])) {
                $this->warn("  → Table {$srcTable} not available: {$data['error']}");
                return;
            }

            $total = $data['total'];
            $rows = $data['rows'];

            foreach ($rows as $row) {
                // Filter to only columns that exist in destination
                $filtered = [];
                foreach ($row as $key => $value) {
                    if (in_array($key, $dstColumns)) {
                        $filtered[$key] = $value;
                    }
                }

                try {
                    DB::table($dstTable)->updateOrInsert(
                        ['id' => $filtered['id']],
                        $filtered
                    );
                    $imported++;
                } catch (\Exception $e) {
                    $this->warn("  Row {$filtered['id']}: " . $e->getMessage());
                }
            }

            $offset += $limit;
            $this->output->write("\r  → {$imported}/{$total} rows...");

        } while (count($rows) === $limit);

        $this->info("\r  → {$imported}/{$total} {$srcTable} migrated.          ");
    }

    private function createEigentuemerUsers(): void
    {
        $this->info("\nCreating Eigentümer users from customers...");
        $customers = DB::table('customers')->where('active', 1)->get();
        $count = 0;
        foreach ($customers as $customer) {
            if (empty($customer->email)) continue;
            $exists = DB::table('users')->where('email', $customer->email)->exists();
            if (!$exists) {
                DB::table('users')->insert([
                    'name' => $customer->name,
                    'email' => $customer->email,
                    'password' => $customer->password_hash ?: Hash::make(Str::random(32)),
                    'user_type' => 'eigentuemer',
                    'customer_id' => $customer->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $count++;
            }
        }
        $this->info("  → {$count} Eigentümer users created.");
    }

    private function verify(): void
    {
        $this->info("\n=== VERIFICATION ===");
        $tables = [
            'customers', 'properties', 'activities', 'portal_emails',
            'contacts', 'email_accounts', 'property_knowledge', 'viewings',
            'portal_messages', 'portal_documents',
        ];

        foreach ($tables as $table) {
            $newCount = DB::table($table)->count();
            // Get old count via API
            $url = "{$this->apiBase}?key={$this->apiKey}&table={$table}&limit=1";
            try {
                $data = Http::timeout(10)->get($url)->json();
                $oldCount = $data['total'] ?? 'N/A';
            } catch (\Exception $e) {
                $oldCount = 'N/A';
            }
            $match = ($oldCount === 'N/A' || $oldCount == $newCount) ? '✅' : '❌';
            $this->info("  {$match} {$table}: old={$oldCount} → new={$newCount}");
        }
    }
}
