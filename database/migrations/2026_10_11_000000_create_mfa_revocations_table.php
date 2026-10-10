<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use StrontiumCorp\LaravelMfa\Mfa;

return new class extends Migration
{
    public function up(): void
    {
        // When a user's verifications were last revoked (Mfa::reset(),
        // Mfa::revokeVerifications()): sessions verified at or before it are
        // challenged again; sessions logged in at or before logged_out_at
        // (an administrator's reset) are logged out. One row per user; it
        // goes with the user.
        $model = Mfa::userModel();
        $users = (new $model)->getTable();
        $key = (new $model)->getKeyName();

        Schema::create(config('mfa.tables.revocations', 'mfa_revocations'), function (Blueprint $table) use ($users, $key) {
            $table->foreignId('user_id')->primary()->constrained($users, $key)->cascadeOnDelete();
            $table->unsignedBigInteger('revoked_at');                // unix timestamp, compared with the session's verified-at
            $table->unsignedBigInteger('logged_out_at')->nullable(); // unix timestamp, compared with the session's login time
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('mfa.tables.revocations', 'mfa_revocations'));
    }
};
