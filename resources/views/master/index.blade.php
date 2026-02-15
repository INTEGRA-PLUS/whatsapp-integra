@extends('layouts.app')

@section('title', 'Panel Master')

@section('content')
<div class="max-w-7xl mx-auto py-6 sm:px-6 lg:px-8">
    <div class="px-4 py-6 sm:px-0">
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-2xl font-bold text-gray-900">Panel de Administración Master</h2>
            <button onclick="document.getElementById('createCompanyModal').classList.remove('hidden')" 
                class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg">
                + Nueva Empresa
            </button>
        </div>

        @if(session('success'))
        <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded mb-4">
            {{ session('success') }}
        </div>
        @endif

        <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
            @foreach($companies as $company)
            <div class="bg-white overflow-hidden shadow rounded-lg transform transition hover:scale-105">
                <div class="px-4 py-5 sm:p-6">
                    <div class="flex items-center">
                        <div class="flex-shrink-0 bg-indigo-500 rounded-md p-3">
                            <svg class="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                            </svg>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dt class="text-sm font-medium text-gray-500 truncate">
                                {{ $company->name }}
                            </dt>
                            <dd class="flex items-baseline">
                                <div class="text-2xl font-semibold text-gray-900">
                                    {{ $company->users_count }}
                                </div>
                                <div class="ml-2 flex items-baseline text-sm font-semibold text-green-600">
                                    <span class="sr-only">Usuarios</span>
                                    Usuarios
                                </div>
                            </dd>
                        </div>
                    </div>
                </div>
                <div class="bg-gray-50 px-4 py-4 sm:px-6">
                    <div class="text-sm flex justify-between items-center space-x-2">
                        <span class="text-gray-500">{{ $company->instances_count }} Instancias</span>
                        <div class="flex space-x-2">
                            <button onclick='openEditModal(@json($company))' class="font-medium text-yellow-600 hover:text-yellow-500">
                                Editar
                            </button>
                            <form action="{{ route('master.impersonate', $company->id) }}" method="POST" class="inline">
                                @csrf
                                <button type="submit" class="font-medium text-indigo-600 hover:text-indigo-500">
                                    Administrar <span aria-hidden="true">&rarr;</span>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            @endforeach
        </div>
    </div>
</div>

<!-- Modal Crear Empresa -->
<div id="createCompanyModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="mt-3">
            <h3 class="text-lg font-medium text-gray-900 mb-4">Nueva Empresa</h3>
            <form action="{{ route('master.companies.store') }}" method="POST">
                @csrf
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nombre de Empresa</label>
                    <input type="text" name="name" required 
                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email de Empresa</label>
                    <input type="email" name="email" required 
                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                </div>
                
                <hr class="my-4 border-gray-200">
                <p class="text-xs text-gray-500 mb-3 uppercase font-bold">Datos del Administrador</p>

                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nombre Admin</label>
                    <input type="text" name="admin_name" required 
                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email Admin</label>
                    <input type="email" name="admin_email" required 
                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Contraseña</label>
                    <input type="password" name="password" required minlength="8"
                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                </div>

                <div class="flex gap-2 mt-6">
                    <button type="submit" class="flex-1 bg-indigo-600 text-white px-4 py-2 rounded-md hover:bg-indigo-700">
                        Crear Empresa
                    </button>
                    <button type="button" onclick="document.getElementById('createCompanyModal').classList.add('hidden')" 
                        class="flex-1 bg-gray-300 text-gray-700 px-4 py-2 rounded-md hover:bg-gray-400">
                        Cancelar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Editar Empresa -->
<div id="editCompanyModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="mt-3">
            <h3 class="text-lg font-medium text-gray-900 mb-4">Editar Empresa</h3>
            <form id="editCompanyForm" method="POST">
                @csrf
                @method('PUT')
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nombre de Empresa</label>
                    <input type="text" id="editName" name="name" required 
                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email de Empresa</label>
                    <input type="email" id="editEmail" name="email" required 
                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                </div>
                
                <hr class="my-4 border-gray-200">
                <p class="text-xs text-gray-500 mb-3 uppercase font-bold">Datos del Administrador</p>

                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nombre Admin</label>
                    <input type="text" id="editAdminName" name="admin_name" required 
                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email Admin</label>
                    <input type="email" id="editAdminEmail" name="admin_email" required 
                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nueva Contraseña (Opcional)</label>
                    <input type="password" name="password" minlength="8" placeholder="Dejar en blanco para mantener actual"
                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                </div>

                <div class="mb-4 flex items-center">
                    <input type="checkbox" id="editActive" name="active" value="1"
                        class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded">
                    <label for="editActive" class="ml-2 block text-sm text-gray-900">
                        Empresa Activa
                    </label>
                </div>

                <div class="flex gap-2 mt-6">
                    <button type="submit" class="flex-1 bg-yellow-600 text-white px-4 py-2 rounded-md hover:bg-yellow-700">
                        Actualizar
                    </button>
                    <button type="button" onclick="document.getElementById('editCompanyModal').classList.add('hidden')" 
                        class="flex-1 bg-gray-300 text-gray-700 px-4 py-2 rounded-md hover:bg-gray-400">
                        Cancelar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openEditModal(company) {
    document.getElementById('editName').value = company.name;
    document.getElementById('editEmail').value = company.email;
    document.getElementById('editActive').checked = company.active;
    
    // Populate admin fields if available
    if (company.users && company.users.length > 0) {
        const admin = company.users[0];
        document.getElementById('editAdminName').value = admin.name;
        document.getElementById('editAdminEmail').value = admin.email;
    } else {
        document.getElementById('editAdminName').value = '';
        document.getElementById('editAdminEmail').value = '';
    }
    
    // Configurar la ruta del formulario
    const form = document.getElementById('editCompanyForm');
    form.action = `/master/companies/${company.id}`;
    
    document.getElementById('editCompanyModal').classList.remove('hidden');
}
</script>
@endsection
