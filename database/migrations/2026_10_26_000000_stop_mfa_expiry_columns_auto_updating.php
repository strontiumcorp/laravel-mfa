<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * On MySQL 5.7 and MariaDB before 10.10 (explicit_defaults_for_timestamp
     * off), the create migrations' bare NOT NULL `timestamp('expires_at')`
     * got ON UPDATE CURRENT_TIMESTAMP: any update of the row (a trusted
     * browser's last_used_at, a wrong guess at a code) moved its expiry to
     * now. This gives those columns an explicit default, which drops the
     * auto-update. Only where the column has it; nothing elsewhere.
     */
    public function up(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach ([config('mfa.tables.trusted_browsers', 'mfa_trusted_browsers'), config('mfa.tables.otp_codes', 'mfa_otp_codes')] as $table) {
            $extra = DB::selectOne(
                'select extra from information_schema.columns where table_schema = database() and table_name = ? and column_name = ?',
                [DB::connection()->getTablePrefix().$table, 'expires_at'],
            )?->extra;

            if (is_string($extra) && str_contains(strtolower($extra), 'on update')) {
                Schema::table($table, function (Blueprint $table) {
                    $table->timestamp('expires_at')->useCurrent()->change();
                });
            }
        }
    }

    /** Putting the auto-update back would bring the bug back. */
    public function down(): void {}
};
