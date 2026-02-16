<?php

use App\Models\User;
use App\Models\Instance;
use App\Models\WhatsAppConversation;
use Illuminate\Support\Facades\DB;

// Fetch all users
echo "Users:\n";
foreach (User::all() as $user) {
    echo "ID: {$user->id}, Name: {$user->name}, Role: {$user->role}, Company ID: {$user->company_id}\n";
}

echo "\nInstances:\n";
foreach (Instance::all() as $instance) {
    echo "ID: {$instance->id}, Name: {$instance->name}, Phone: {$instance->phone_number_id}, Company ID: {$instance->company_id}\n";
}

echo "\nConversations:\n";
foreach (WhatsAppConversation::all() as $conv) {
    echo "ID: {$conv->id}, Phone: {$conv->phone_number}, Instance ID: {$conv->instance_id}\n";
}

// Check what the ChatController query would return for the first non-master user
$agentUser = User::where('role', '!=', 'master')->first();
if ($agentUser) {
    echo "\nSimulating ChatController query for User ID {$agentUser->id} (Company {$agentUser->company_id}):\n";
    $instances = Instance::where('company_id', $agentUser->company_id)
        ->where('type', 'meta')
        ->where('active', true)
        ->get();
    
    echo "Found " . $instances->count() . " instances:\n";
    foreach ($instances as $inst) {
        echo "- ID: {$inst->id}, Name: {$inst->name}\n";
    }
}
