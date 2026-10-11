<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds user_agent to an mfa_trusted_browsers table created before the
     * create migration had it. Fresh installs get the column from that
     * migration, so this does nothing there. Temporary: it goes once every
     * environment that ran the old create migration has run this one.
     */
    public function up(): void
    {
        $table = config('mfa.tables.trusted_browsers', 'mfa_trusted_browsers');

        if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'user_agent')) {
            Schema::table($table, function (Blueprint $table) {
                $table->string('user_agent', 1024)->nullable()->after('label');
            });
        }
    }

    /** The column belongs to the create migration (fresh installs), so it stays. */
    public function down(): void {}
};
