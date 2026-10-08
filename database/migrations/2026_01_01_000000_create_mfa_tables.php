<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use StrontiumCorp\LaravelMfa\Mfa;

return new class extends Migration
{
    public function up(): void
    {
        // Factors and codes belong to the user and go with them; audit rows
        // outlive the user (user_id → null) until the retention prune.
        $model = Mfa::userModel();
        $users = (new $model)->getTable();
        $key = (new $model)->getKeyName();

        Schema::create(config('mfa.tables.factors', 'mfa_factors'), function (Blueprint $table) use ($users, $key) {
            $table->id();
            $table->foreignId('user_id')->constrained($users, $key)->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('label')->nullable();
            $table->text('secret')->nullable();          // encrypted (TOTP)
            $table->text('destination')->nullable();     // encrypted (phone / email)
            $table->unsignedBigInteger('last_totp_timestep')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'confirmed_at']);
        });

        Schema::create(config('mfa.tables.otp_codes', 'mfa_otp_codes'), function (Blueprint $table) {
            $table->id();
            $table->foreignId('factor_id')->constrained(config('mfa.tables.factors', 'mfa_factors'))->cascadeOnDelete();
            $table->string('code_hash', 64);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['factor_id', 'consumed_at', 'expires_at']);
            $table->index('expires_at');
        });

        Schema::create(config('mfa.tables.recovery_codes', 'mfa_recovery_codes'), function (Blueprint $table) use ($users, $key) {
            $table->id();
            $table->foreignId('user_id')->constrained($users, $key)->cascadeOnDelete();
            $table->string('code_hash', 64);
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['user_id', 'code_hash']);
        });

        Schema::create(config('mfa.tables.audit_logs', 'mfa_audit_logs'), function (Blueprint $table) use ($users, $key) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained($users, $key)->nullOnDelete();
            $table->string('event', 64);
            $table->string('factor_type', 32)->nullable();
            $table->string('reason', 64)->nullable();
            $table->uuid('flow_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->json('context')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'created_at']);
            $table->index('flow_id');
            $table->index(['event', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('mfa.tables.audit_logs', 'mfa_audit_logs'));
        Schema::dropIfExists(config('mfa.tables.recovery_codes', 'mfa_recovery_codes'));
        Schema::dropIfExists(config('mfa.tables.otp_codes', 'mfa_otp_codes'));
        Schema::dropIfExists(config('mfa.tables.factors', 'mfa_factors'));
    }
};
