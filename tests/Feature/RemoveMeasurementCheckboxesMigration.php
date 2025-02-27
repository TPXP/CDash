<?php

namespace Tests\Feature;

use Artisan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RemoveMeasurementCheckboxesMigration extends TestCase
{
    use RefreshDatabase;

    /**
     * Test case for the migration that removes the `testpage` and `summarypage`
     * columns from the `measurement` table.
     *
     * @return void
     */
    public function testRemoveMeasurementCheckboxesMigration()
    {
        // Rollback the relevant migration.
        Artisan::call('migrate:rollback', [
            '--path' => 'database/migrations/2021_10_29_151020_remove_test_checkboxes.php',
            '--force' => true]);

        // Verify that worked.
        $this->assertTrue(\Schema::hasColumn('measurement', 'testpage'));
        $this->assertTrue(\Schema::hasColumn('measurement', 'summarypage'));

        // Run the migrations under test.
        Artisan::call('migrate', [
            '--path' => 'database/migrations/2021_10_29_151020_remove_test_checkboxes.php',
            '--force' => true]);

        // Verify that worked.
        $this->assertFalse(\Schema::hasColumn('measurement', 'testpage'));
        $this->assertFalse(\Schema::hasColumn('measurement', 'summarypage'));
    }
}
