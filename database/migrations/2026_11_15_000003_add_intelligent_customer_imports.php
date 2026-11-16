<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // Imported address notes, secondary contacts and other approved
            // source columns are tenant-owned profile data, not discarded
            // just because they are not part of the nine standard fields.
            $table->json('custom_fields')->nullable()->after('sex');
        });

        Schema::table('customer_imports', function (Blueprint $table) {
            $table->boolean('automatic')->default(false)->after('stage');
            $table->json('additional_mapping')->nullable()->after('mapping');
            $table->json('mapping_diagnostics')->nullable()->after('additional_mapping');
        });

        Schema::table('customer_import_rows', function (Blueprint $table) {
            $table->json('custom_fields')->nullable()->after('payload');
        });
    }

    public function down(): void
    {
        Schema::table('customer_import_rows', function (Blueprint $table) {
            $table->dropColumn('custom_fields');
        });
        Schema::table('customer_imports', function (Blueprint $table) {
            $table->dropColumn(['automatic', 'additional_mapping', 'mapping_diagnostics']);
        });
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('custom_fields');
        });
    }
};
