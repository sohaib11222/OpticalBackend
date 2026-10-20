<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('private_files', function (Blueprint $t) {
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->unsignedBigInteger('optical_record_id')->nullable();
            $t->foreign(['shop_id', 'customer_id'])->references(['shop_id', 'id'])->on('customers')->restrictOnDelete();
            $t->foreign(['shop_id', 'customer_id', 'optical_record_id'])->references(['shop_id', 'customer_id', 'id'])->on('optical_records')->restrictOnDelete();
            $t->string('title')->nullable();
            $t->string('category', 40)->default('document');
            $t->text('description')->nullable();
            $t->char('sha256', 64)->nullable();
            $t->uuid('upload_key')->nullable();
            $t->foreignId('uploaded_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('scan_error', 40)->nullable();
            $t->timestamp('scanned_at')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamp('archived_at')->nullable();
            $t->text('archive_reason')->nullable();
            $t->unique(['shop_id', 'upload_key']);
            $t->index(['shop_id', 'customer_id', 'category']);
        });
        $owner = DB::table('roles')->where('name', 'owner')->value('id');
        $id = DB::table('permissions')->insertGetId(['name' => 'files.write']);
        DB::table('permission_role')->insert(['role_id' => $owner, 'permission_id' => $id]);
    }

    public function down(): void
    {
        Schema::table('private_files', function (Blueprint $t) {
            $t->dropForeign(['shop_id', 'customer_id', 'optical_record_id']);
            $t->dropForeign(['shop_id', 'customer_id']);
            $t->dropForeign(['uploaded_by']);
            $t->dropUnique(['shop_id', 'upload_key']);
            $t->dropIndex(['shop_id', 'customer_id', 'category']);
            $t->dropColumn(['customer_id', 'optical_record_id', 'title', 'category', 'description', 'sha256', 'upload_key', 'uploaded_by', 'scan_error', 'scanned_at', 'version', 'archived_at', 'archive_reason']);
        });
        DB::table('permissions')->where('name', 'files.write')->delete();
    }
};
