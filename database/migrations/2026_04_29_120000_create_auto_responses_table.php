<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auto_responses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('instance_id')->nullable();
            $table->string('name');
            $table->string('trigger_text');
            $table->enum('match_type', ['exact', 'contains', 'starts_with'])->default('contains');
            $table->text('response_message');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->foreign('instance_id')->references('id')->on('instances')->onDelete('cascade');
            $table->index(['company_id', 'active']);
        });

        $permissions = ['auto_responses.view', 'auto_responses.create', 'auto_responses.update', 'auto_responses.delete'];
        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $newPermissions = Permission::whereIn('name', $permissions)->get();
        if (Schema::hasTable('companies')) {
            DB::table('companies')->orderBy('id')->each(function ($company) use ($newPermissions) {
                setPermissionsTeamId($company->id);
                $adminRole = Role::where('name', 'admin')
                    ->where('company_id', $company->id)
                    ->where('guard_name', 'web')
                    ->first();
                if ($adminRole) {
                    $adminRole->givePermissionTo($newPermissions);
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_responses');
    }
};
