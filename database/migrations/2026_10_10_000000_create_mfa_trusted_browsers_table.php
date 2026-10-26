<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use StrontiumCorp\LaravelMfa\Mfa;

return new class extends Migration
{
    public function up(): void
    {
        // Browsers a user chose to trust after a challenge (trusted_browsers).
        // Only a keyed hash of the cookie's token is stored; rows go with the user.
        $model = Mfa::userModel();
        $users = (new $model)->getTable();
        $key = (new $model)->getKeyName();

        Schema::create(config('mfa.tables.trusted_browsers', 'mfa_trusted_browsers'), function (Blueprint $table) use ($users, $key) {
            $table->id();
            $table->foreignId('user_id')->constrained($users, $key)->cascadeOnDelete();
            $table->string('guard', 64);
            $table->string('token_hash', 64)->unique();
            $table->string('password_hash', 64);        // keyed hash of the password hash: a password change revokes
            $table->string('label', 100)->nullable();   // e.g. "Chrome on Mac"
            $table->string('user_agent', 1024)->nullable(); // the User-Agent it was trusted with
            // An explicit default: without one, MySQL 5.7 and MariaDB < 10.10 make it ON UPDATE CURRENT_TIMESTAMP.
            $table->timestamp('expires_at')->useCurrent();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'expires_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('mfa.tables.trusted_browsers', 'mfa_trusted_browsers'));
    }
};
