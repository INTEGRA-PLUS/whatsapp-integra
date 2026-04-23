<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\KanbanColumn;
use App\Models\WhatsAppConversation;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $defaultNames = [
            'Nuevo Prospecto',
            'En Atención',
            'Seguimiento',
            'Venta / Cerrado'
        ];

        // Find columns with these names that ARE NOT linked to a tag
        $columnsToDelete = KanbanColumn::whereIn('name', $defaultNames)
            ->whereNull('tag_id')
            ->get();

        foreach ($columnsToDelete as $column) {
            // Find a fallback column for this company that IS linked to a tag
            $fallback = KanbanColumn::where('company_id', $column->company_id)
                ->whereNotNull('tag_id')
                ->orderBy('position')
                ->first();

            // Reassign conversations
            WhatsAppConversation::where('kanban_column_id', $column->id)
                ->update(['kanban_column_id' => $fallback?->id]);

            $column->delete();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No reverse needed
    }
};
