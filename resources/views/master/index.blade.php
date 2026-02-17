@extends('layouts.app')

@section('title', 'Panel Master')

@section('content')
<div class="max-w-7xl mx-auto py-6 sm:px-6 lg:px-8">
    <div class="px-4 py-6 sm:px-0">
        <div class="flex flex-col sm:flex-row justify-between items-center mb-6">
            <h2 class="text-2xl font-bold text-gray-900 mb-4 sm:mb-0">Panel de Administración Master</h2>
            <button onclick="document.getElementById('createCompanyModal').classList.remove('hidden')" 
                class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg transition duration-200">
                + Nueva Empresa
            </button>
        </div>

        @if(session('success'))
        <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded mb-4">
            {{ session('success') }}
        </div>
        @endif

        @if(session('error'))
        <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded mb-4">
            {{ session('error') }}
        </div>
        @endif

        <!-- Search & Filter Form -->
        <div class="bg-white p-4 rounded-lg shadow mb-6">
            <form action="{{ route('master.index') }}" method="GET" class="flex flex-col sm:flex-row gap-4">
                <div class="flex-1">
                    <label for="search" class="sr-only">Buscar</label>
                    <input type="text" name="search" id="search" placeholder="Buscar por empresa, email o admin..." 
                        value="{{ request('search') }}"
                        class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500">
                </div>
                <div class="w-full sm:w-48">
                    <label for="status" class="sr-only">Estado</label>
                    <select name="status" id="status" class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500">
                        <option value="">Todos los estados</option>
                        <option value="active" {{ request('status') == 'active' ? 'selected' : '' }}>Activos</option>
                        <option value="inactive" {{ request('status') == 'inactive' ? 'selected' : '' }}>Inactivos</option>
                    </select>
                </div>
                <button type="submit" class="bg-gray-800 text-white px-6 py-2 rounded-md hover:bg-gray-700 transition duration-200">
                    Buscar
                </button>
                @if(request()->has('search') || request()->has('status'))
                    <a href="{{ route('master.index') }}" class="bg-gray-200 text-gray-700 px-6 py-2 rounded-md hover:bg-gray-300 transition duration-200 flex items-center justify-center">
                        Limpiar
                    </a>
                @endif
            </form>
        </div>

        <!-- Companies Table Container -->
        <div id="companies-table-container">
            @include('master.partials.companies-table')
        </div>
    </div>
</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('search');
    const statusSelect = document.getElementById('status');
    const tableContainer = document.getElementById('companies-table-container');
    let timeout = null;

    function fetchCompanies() {
        const query = searchInput.value;
        const status = statusSelect.value;
        
        // Build URL with params
        const url = new URL("{{ route('master.index') }}");
        if (query) url.searchParams.set('search', query);
        if (status) url.searchParams.set('status', status);

        fetch(url, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.text())
        .then(html => {
            tableContainer.innerHTML = html;
        })
        .catch(error => console.error('Error:', error));
    }

    searchInput.addEventListener('input', function() {
        clearTimeout(timeout);
        
        // Debounce search
        timeout = setTimeout(() => {
            if (this.value.length > 3 || this.value.length === 0) {
                fetchCompanies();
            }
        }, 500);
    });

    statusSelect.addEventListener('change', function() {
        fetchCompanies();
    });
});
</script>
@endpush
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
