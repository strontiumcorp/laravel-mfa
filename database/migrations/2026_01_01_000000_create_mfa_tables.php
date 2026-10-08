<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('mfa.tables.factors', 'mfa_factors'), function (Blueprint $table) {
            $table->id();
            $table->morphs('authenticatable');
            $table->string('type', 32);
            $table->string('label')->nullable();
            $table->text('secret')->nullable();          // encrypted (TOTP)
            $table->text('destination')->nullable();     // encrypted (phone / email)
            $table->unsignedBigInteger('last_totp_timestep')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['authenticatable_type', 'authenticatable_id', 'confirmed_at'], 'mfa_factors_owner_confirmed_index');
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

        Schema::create(config('mfa.tables.recovery_codes', 'mfa_recovery_codes'), function (Blueprint $table) {
            $table->id();
            $table->morphs('authenticatable');
            $table->string('code_hash', 64);
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['authenticatable_type', 'authenticatable_id', 'code_hash'], 'mfa_recovery_codes_owner_hash_unique');
        });

        Schema::create(config('mfa.tables.audit_logs', 'mfa_audit_logs'), function (Blueprint $table) {
            $table->id();
            $table->nullableMorphs('authenticatable');
            $table->string('event', 64);
            $table->string('factor_type', 32)->nullable();
            $table->string('reason', 64)->nullable();
            $table->uuid('flow_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->json('context')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['authenticatable_type', 'authenticatable_id', 'created_at'], 'mfa_audit_logs_owner_created_index');
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
