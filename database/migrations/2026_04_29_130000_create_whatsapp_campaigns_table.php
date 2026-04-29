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
        Schema::create('whatsapp_campaigns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('instance_id');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('name');
            $table->text('message');
            $table->string('message_type')->default('text');
            $table->enum('status', ['draft', 'queued', 'sending', 'completed', 'cancelled', 'failed'])->default('draft');
            $table->unsignedInteger('total_recipients')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->foreign('instance_id')->references('id')->on('instances')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
            $table->index(['company_id', 'status']);
        });

        Schema::create('whatsapp_campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('campaign_id');
            $table->string('phone_number', 32);
            $table->string('name')->nullable();
            $table->enum('status', ['pending', 'sent', 'failed'])->default('pending');
            $table->string('wamid')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->foreign('campaign_id')->references('id')->on('whatsapp_campaigns')->onDelete('cascade');
            $table->index(['campaign_id', 'status']);
        });

        $permissions = ['campaigns.view', 'campaigns.create', 'campaigns.update', 'campaigns.delete'];
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
        Schema::dropIfExists('whatsapp_campaign_recipients');
        Schema::dropIfExists('whatsapp_campaigns');
    }
};
