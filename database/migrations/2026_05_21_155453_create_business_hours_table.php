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
        Schema::create('business_hours', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('instance_id')->nullable();
            $table->string('name')->default('Horario laboral');
            $table->boolean('active')->default(true);
            $table->string('timezone', 64)->default('America/Bogota');
            $table->time('start_time');
            $table->time('end_time');
            $table->json('days_of_week')->nullable();
            $table->text('out_of_hours_message');
            $table->unsignedInteger('cooldown_minutes')->default(60);
            $table->unsignedBigInteger('fires_count')->default(0);
            $table->timestamp('last_fired_at')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->foreign('instance_id')->references('id')->on('instances')->onDelete('cascade');
            $table->index(['company_id', 'active']);
        });

        $permissions = ['business_hours.view', 'business_hours.create', 'business_hours.update', 'business_hours.delete'];
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
        Schema::dropIfExists('business_hours');
    }
};
