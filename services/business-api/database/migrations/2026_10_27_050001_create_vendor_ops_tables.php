<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_companies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('owner_id')->constrained('users');
            $t->string('legal_name', 150);
            $t->string('country', 2);
            $t->text('trade_license_number');
            $t->date('license_expires_on');
            $t->string('status')->default('draft');
            $t->text('review_reason')->nullable();
            $t->foreignId('reviewed_by')->nullable()->constrained('users');
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamps();
        });
        Schema::create('vendor_kyc_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('vendor_companies');
            $t->foreignId('uploaded_by')->constrained('users');
            $t->string('kind');
            $t->string('storage_path');
            $t->string('mime_type');
            $t->unsignedBigInteger('size_bytes');
            $t->string('sha256', 64);
            $t->timestamps();
        });
        Schema::create('vendor_company_staff', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('vendor_companies');
            $t->foreignId('user_id')->constrained('users');
            $t->json('permissions');
            $t->string('status')->default('invited');
            $t->unique(['company_id', 'user_id']);
            $t->timestamps();
        });
        Schema::create('vendor_sites', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('vendor_companies');
            $t->foreignId('station_id')->nullable()->unique()->constrained('stations');
            $t->string('name', 150);
            $t->string('country', 2);
            $t->string('timezone');
            $t->string('address', 300);
            $t->decimal('latitude', 10, 7);
            $t->decimal('longitude', 10, 7);
            $t->timestamps();
        });
        Schema::create('vendor_chargers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('vendor_companies');
            $t->foreignId('site_id')->constrained('vendor_sites');
            $t->string('asset_reference', 80);
            $t->string('manufacturer', 100);
            $t->string('model', 100);
            $t->string('serial_number', 100)->nullable();
            $t->string('ocpp_protocol')->nullable();
            $t->string('source');
            $t->string('integration_status')->default('unverified');
            $t->unique(['company_id', 'asset_reference']);
            $t->timestamps();
        });
        Schema::create('vendor_connectors', function (Blueprint $t) {
            $t->id();
            $t->foreignId('charger_id')->constrained('vendor_chargers');
            $t->unsignedInteger('connector_number');
            $t->string('connector_type');
            $t->unsignedInteger('max_power_kw');
            $t->boolean('maintenance_blocked')->default(false);
            $t->text('maintenance_reason')->nullable();
            $t->unique(['charger_id', 'connector_number']);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['vendor_connectors', 'vendor_chargers', 'vendor_sites', 'vendor_company_staff', 'vendor_kyc_documents', 'vendor_companies'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
