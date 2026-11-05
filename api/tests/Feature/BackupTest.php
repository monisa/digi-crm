<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Lead;
use App\Models\User;
use App\Services\Backup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PDO;
use Tests\TestCase;

class BackupTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_database_backup_restores_into_an_empty_database(): void
    {
        Storage::fake('local');
        Lead::create(['full_name' => "O'Brien \"Quotes\" ; DROP", 'phone' => '+91 98470 00001']);
        Client::create(['company_name' => 'Green Leaf', 'notes' => "line1\nline2 ₹ മലയാളം"]);

        Artisan::call('crm:backup', ['--files' => true]);
        $list = app(Backup::class)->list();
        $db = collect($list)->firstWhere('kind', 'database');
        $this->assertNotNull($db);
        $this->assertNotNull(collect($list)->firstWhere('kind', 'files'));

        // Restore into a brand-new SQLite database and compare.
        $sql = gzdecode(file_get_contents(app(Backup::class)->path($db['name'])));
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec($sql);
        $this->assertSame("O'Brien \"Quotes\" ; DROP", $pdo->query('SELECT full_name FROM leads')->fetchColumn());
        $this->assertSame("line1\nline2 ₹ മലയാളം", $pdo->query('SELECT notes FROM clients')->fetchColumn());
        $this->assertSame((int) \DB::table('permissions')->count(), (int) $pdo->query('SELECT COUNT(*) FROM permissions')->fetchColumn());
    }

    public function test_only_admins_see_and_download_backups_and_old_ones_are_pruned(): void
    {
        Storage::fake('local');
        $admin = User::factory()->admin()->create();
        $emp = User::factory()->create(['role' => 'employee', 'status' => 'active']);
        foreach (range(1, 16) as $i) {
            Storage::disk('local')->put(sprintf('backups/database-2026-10-%02d-020000.sql.gz', $i), 'x');
            touch(Storage::disk('local')->path(sprintf('backups/database-2026-10-%02d-020000.sql.gz', $i)), strtotime("2026-10-$i"));
        }

        Sanctum::actingAs($emp);
        $this->getJson('/v1/settings/backups')->assertForbidden();

        Sanctum::actingAs($admin);
        $name = $this->postJson('/v1/settings/backups')->assertCreated()->json('name');
        $this->assertCount(14, collect($this->getJson('/v1/settings/backups')->json('data'))->where('kind', 'database'));
        $this->get('/v1/settings/backups/'.$name)->assertOk();
        $this->get('/v1/settings/backups/..%2F.env')->assertNotFound();
        $this->get('/v1/settings/backups/database-2026-10-01-020000.sql.gz')->assertNotFound(); // pruned
    }
}
