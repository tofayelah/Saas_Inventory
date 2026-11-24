<?php

namespace Tests\Feature\Security;

use App\Models\Client;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Services\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProjectCodeTenantUniquenessTest extends TestCase
{
    use RefreshDatabase;

    protected Team $tenantA;
    protected Team $tenantB;
    protected User $ownerA;
    protected User $ownerB;
    protected Client $clientA;
    protected Client $clientB;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Setup Tenant Alpha
        $this->ownerA = User::factory()->create([
            'name' => 'Owner Alpha',
            'email' => 'owner.alpha.prj@example.com',
            'phone' => '+8801711000001',
        ]);
        $this->tenantA = Team::forceCreate([
            'user_id' => $this->ownerA->id,
            'name' => 'Tenant Alpha Projects Corp',
            'personal_team' => false,
            'status' => 'active',
        ]);
        $this->ownerA->update(['current_team_id' => $this->tenantA->id]);

        setPermissionsTeamId($this->tenantA->id);
        $roleOwnerA = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'owner', 'team_id' => $this->tenantA->id]);
        $this->ownerA->assignRole($roleOwnerA);

        $this->clientA = Client::forceCreate([
            'team_id' => $this->tenantA->id,
            'name' => 'Client Alpha',
            'company_name' => 'Alpha Holdings',
            'phone' => '+8801711111111',
            'is_active' => true,
        ]);

        // 2. Setup Tenant Beta
        $this->ownerB = User::factory()->create([
            'name' => 'Owner Beta',
            'email' => 'owner.beta.prj@example.com',
            'phone' => '+8801711000002',
        ]);
        $this->tenantB = Team::forceCreate([
            'user_id' => $this->ownerB->id,
            'name' => 'Tenant Beta Engineering Ltd',
            'personal_team' => false,
            'status' => 'active',
        ]);
        $this->ownerB->update(['current_team_id' => $this->tenantB->id]);

        setPermissionsTeamId($this->tenantB->id);
        $roleOwnerB = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'owner', 'team_id' => $this->tenantB->id]);
        $this->ownerB->assignRole($roleOwnerB);

        $this->clientB = Client::forceCreate([
            'team_id' => $this->tenantB->id,
            'name' => 'Client Beta',
            'company_name' => 'Beta Industries',
            'phone' => '+8801722222222',
            'is_active' => true,
        ]);

        app(TenantContext::class)->clear();
    }

    /**
     * TEST 1: Same project code can exist for two different tenants.
     */
    public function test_same_project_code_can_exist_for_two_different_tenants(): void
    {
        $code = 'PRJ-SHARED-2026';

        // 1. Tenant Alpha creates project with code PRJ-SHARED-2026
        $responseA = $this->actingAs($this->ownerA)->post(route('projects.store'), [
            'client_mode' => 'existing',
            'client_id' => $this->clientA->id,
            'project_code' => $code,
            'name' => 'Data Center Alpha Implementation',
            'status' => 'planning',
            'budget' => 500000.00,
        ]);
        $responseA->assertSessionHasNoErrors();

        $projectA = Project::where('team_id', $this->tenantA->id)->where('project_code', $code)->first();
        $this->assertNotNull($projectA);
        $this->assertEquals($this->tenantA->id, $projectA->team_id);
        $this->assertEquals($code, $projectA->project_code);

        // 2. Tenant Beta creates project with identical code PRJ-SHARED-2026
        $responseB = $this->actingAs($this->ownerB)->post(route('projects.store'), [
            'client_mode' => 'existing',
            'client_id' => $this->clientB->id,
            'project_code' => $code,
            'name' => 'Fibre Optic Rollout Beta',
            'status' => 'planning',
            'budget' => 750000.00,
        ]);
        $responseB->assertSessionHasNoErrors();

        $projectB = Project::where('team_id', $this->tenantB->id)->where('project_code', $code)->first();
        $this->assertNotNull($projectB);
        $this->assertEquals($this->tenantB->id, $projectB->team_id);
        $this->assertEquals($code, $projectB->project_code);

        // Verify both coexist with different IDs and different tenants
        $this->assertNotEquals($projectA->id, $projectB->id);
        $this->assertEquals(2, Project::withoutGlobalScopes()->where('project_code', $code)->count());
    }

    /**
     * TEST 2: Duplicate project code is rejected within one tenant.
     */
    public function test_duplicate_project_code_is_rejected_within_one_tenant(): void
    {
        $code = 'PRJ-UNIQUE-ALPHA';

        // 1. First project created successfully
        $this->actingAs($this->ownerA)->post(route('projects.store'), [
            'client_mode' => 'existing',
            'client_id' => $this->clientA->id,
            'project_code' => $code,
            'name' => 'HQ Network Infrastructure',
            'status' => 'planning',
            'budget' => 300000.00,
        ])->assertSessionHasNoErrors();

        // 2. Attempting second project with same code in Tenant Alpha must fail validation
        $responseDupe = $this->actingAs($this->ownerA)->post(route('projects.store'), [
            'client_mode' => 'existing',
            'client_id' => $this->clientA->id,
            'project_code' => $code,
            'name' => 'HQ Backup Network',
            'status' => 'planning',
            'budget' => 150000.00,
        ]);

        $responseDupe->assertSessionHasErrors(['project_code']);
        $this->assertEquals(1, Project::where('team_id', $this->tenantA->id)->where('project_code', $code)->count());
    }

    /**
     * TEST 3: Project update permits retaining its existing code.
     */
    public function test_project_update_permits_retaining_its_existing_code(): void
    {
        $code = 'PRJ-RETAIN-01';

        $project = Project::forceCreate([
            'team_id' => $this->tenantA->id,
            'client_id' => $this->clientA->id,
            'project_code' => $code,
            'name' => 'Original Project Name',
            'status' => 'planning',
            'budget' => 200000.00,
        ]);

        $response = $this->actingAs($this->ownerA)->put(route('projects.update', $project->id), [
            'client_id' => $this->clientA->id,
            'project_code' => $code,
            'name' => 'Updated Project Name',
            'status' => 'in_progress',
            'budget' => 250000.00,
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('projects.show', $project->id));

        $project->refresh();
        $this->assertEquals('Updated Project Name', $project->name);
        $this->assertEquals($code, $project->project_code);
        $this->assertEquals(250000.00, (float) $project->budget);
    }

    /**
     * TEST 4: Project update rejects duplicate code belonging to another project in the same tenant.
     */
    public function test_project_update_rejects_duplicate_code_belonging_to_another_project_in_same_tenant(): void
    {
        $proj1 = Project::forceCreate([
            'team_id' => $this->tenantA->id,
            'client_id' => $this->clientA->id,
            'project_code' => 'PRJ-ALPHA-001',
            'name' => 'Alpha Project One',
            'status' => 'planning',
            'budget' => 100000.00,
        ]);

        $proj2 = Project::forceCreate([
            'team_id' => $this->tenantA->id,
            'client_id' => $this->clientA->id,
            'project_code' => 'PRJ-ALPHA-002',
            'name' => 'Alpha Project Two',
            'status' => 'planning',
            'budget' => 100000.00,
        ]);

        // Attempt to update proj2 with proj1's code
        $response = $this->actingAs($this->ownerA)->put(route('projects.update', $proj2->id), [
            'client_id' => $this->clientA->id,
            'project_code' => 'PRJ-ALPHA-001',
            'name' => 'Alpha Project Two Attempting Steal',
            'status' => 'planning',
            'budget' => 100000.00,
        ]);

        $response->assertSessionHasErrors(['project_code']);

        $proj2->refresh();
        $this->assertEquals('PRJ-ALPHA-002', $proj2->project_code);
    }

    /**
     * TEST 5: Database composite unique constraint rejects duplicate records.
     */
    public function test_database_composite_unique_constraint_rejects_duplicate_records(): void
    {
        DB::table('projects')->insert([
            'team_id' => $this->tenantA->id,
            'client_id' => $this->clientA->id,
            'project_code' => 'RAW-DB-DUPE-01',
            'name' => 'Raw Initial Project',
            'status' => 'planning',
            'budget' => 10000.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('projects')->insert([
            'team_id' => $this->tenantA->id,
            'client_id' => $this->clientA->id,
            'project_code' => 'RAW-DB-DUPE-01',
            'name' => 'Raw Conflicting Duplicate',
            'status' => 'planning',
            'budget' => 10000.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * TEST 6: Existing project records survive migration.
     */
    public function test_existing_project_records_survive_migration(): void
    {
        $projA = Project::forceCreate([
            'team_id' => $this->tenantA->id,
            'client_id' => $this->clientA->id,
            'project_code' => 'SURVIVE-PRJ-A',
            'name' => 'Surviving Project Alpha',
            'status' => 'completed',
            'budget' => 88000.00,
        ]);

        $projB = Project::forceCreate([
            'team_id' => $this->tenantB->id,
            'client_id' => $this->clientB->id,
            'project_code' => 'SURVIVE-PRJ-B',
            'name' => 'Surviving Project Beta',
            'status' => 'in_progress',
            'budget' => 99000.00,
        ]);

        // Verify presence before and after asserting composite unique index
        $indexes = Schema::getIndexes('projects');
        $indexNames = array_column($indexes, 'name');
        $this->assertContains('projects_team_code_unique', $indexNames);

        $this->assertDatabaseHas('projects', [
            'id' => $projA->id,
            'team_id' => $this->tenantA->id,
            'project_code' => 'SURVIVE-PRJ-A',
        ]);

        $this->assertDatabaseHas('projects', [
            'id' => $projB->id,
            'team_id' => $this->tenantB->id,
            'project_code' => 'SURVIVE-PRJ-B',
        ]);
    }

    /**
     * TEST 7: Migration rollback restores the expected previous schema.
     */
    public function test_migration_rollback_restores_expected_previous_schema(): void
    {
        // 1. Verify current composite unique index exists
        $indexesCurrent = Schema::getIndexes('projects');
        $namesCurrent = array_column($indexesCurrent, 'name');
        $this->assertContains('projects_team_code_unique', $namesCurrent);
        $this->assertNotContains('projects_project_code_unique', $namesCurrent);

        // 2. Rollback the migration
        Artisan::call('migrate:rollback', [
            '--path' => 'database/migrations/2026_11_23_162000_make_projects_project_code_composite_unique.php',
            '--realpath' => false,
        ]);

        // 3. Verify rolled back schema restores global unique index
        $indexesRollback = Schema::getIndexes('projects');
        $namesRollback = array_column($indexesRollback, 'name');
        $this->assertContains('projects_project_code_unique', $namesRollback);
        $this->assertNotContains('projects_team_code_unique', $namesRollback);

        // 4. Re-migrate to restore active state
        Artisan::call('migrate', [
            '--path' => 'database/migrations/2026_11_23_162000_make_projects_project_code_composite_unique.php',
            '--realpath' => false,
        ]);
        $indexesRestored = Schema::getIndexes('projects');
        $namesRestored = array_column($indexesRestored, 'name');
        $this->assertContains('projects_team_code_unique', $namesRestored);
        $this->assertNotContains('projects_project_code_unique', $namesRestored);
    }
}
