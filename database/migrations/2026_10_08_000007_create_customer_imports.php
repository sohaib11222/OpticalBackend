<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('private_files', fn (Blueprint $t) => $t->unique(['shop_id', 'id']));
        Schema::create('customer_imports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('shop_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('file_id');
            $t->foreign(['shop_id', 'file_id'])->references(['shop_id', 'id'])->on('private_files')->restrictOnDelete();
            $t->char('source_hash', 64);
            $t->string('status', 30)->default('parsing');
            $t->string('stage', 20)->default('parse');
            $t->unsignedInteger('version')->default(1);
            $t->string('sheet_name')->nullable();
            $t->string('delimiter', 1)->default(',');
            $t->string('date_format', 30)->default('Y-m-d');
            $t->unsignedInteger('date_system')->default(1900);
            $t->json('headers')->nullable();
            $t->json('sheets')->nullable();
            $t->json('mapping')->nullable();
            $t->unsignedInteger('total_rows')->default(0);
            $t->unsignedInteger('processed_rows')->default(0);
            $t->text('last_error')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['shop_id', 'id']);
            $t->unique(['shop_id', 'source_hash']);
        });
        Schema::create('customer_import_rows', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('shop_id');
            $t->unsignedBigInteger('customer_import_id');
            $t->foreign(['shop_id', 'customer_import_id'])->references(['shop_id', 'id'])->on('customer_imports')->restrictOnDelete();
            $t->unsignedInteger('row_number');
            $t->uuid('creation_key');
            $t->json('raw_values');
            $t->json('parse_errors')->nullable();
            $t->json('payload')->nullable();
            $t->json('errors')->nullable();
            $t->json('matches')->nullable();
            $t->boolean('file_duplicate')->default(false);
            $t->string('decision', 30)->default('pending');
            $t->unsignedBigInteger('target_customer_id')->nullable();
            $t->unsignedInteger('target_version')->nullable();
            $t->boolean('overwrite_blanks')->default(false);
            $t->text('reason')->nullable();
            $t->string('status', 30)->default('pending');
            $t->unsignedBigInteger('result_customer_id')->nullable();
            $t->text('result_error')->nullable();
            $t->timestamps();
            $t->unique(['shop_id', 'customer_import_id', 'row_number']);
            $t->foreign(['shop_id', 'target_customer_id'])->references(['shop_id', 'id'])->on('customers')->restrictOnDelete();
            $t->foreign(['shop_id', 'result_customer_id'])->references(['shop_id', 'id'])->on('customers')->restrictOnDelete();
        });
        $owner = DB::table('roles')->where('name', 'owner')->value('id');
        foreach (['imports.read', 'imports.write'] as $name) {
            $id = DB::table('permissions')->insertGetId(['name' => $name]);
            DB::table('permission_role')->insert(['role_id' => $owner, 'permission_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_import_rows');
        Schema::dropIfExists('customer_imports');
        Schema::table('private_files', fn (Blueprint $t) => $t->dropUnique(['shop_id', 'id']));
        DB::table('permissions')->whereIn('name', ['imports.read', 'imports.write'])->delete();
    }
};
