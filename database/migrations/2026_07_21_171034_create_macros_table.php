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
        Schema::create('macros', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->json('actions');
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->index(['company_id', 'active']);
        });

        $permissions = ['macros.view', 'macros.create', 'macros.update', 'macros.delete', 'macros.run'];
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
        Schema::dropIfExists('macros');
    }
};
