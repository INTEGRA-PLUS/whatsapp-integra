<?php

use App\Models\Company;
use App\Models\User;

$company = Company::create([
    'name' => 'Empresa Demo',
    'slug' => 'demo',
    'email' => 'demo@empresa.com',
    'active' => true
]);

User::create([
    'company_id' => $company->id,
    'name' => 'Admin',
    'email' => 'admin@empresa.com',
    'password' => bcrypt('password'),
    'role' => 'admin',
    'active' => true
]);

echo "Test data created successfully.\n";
