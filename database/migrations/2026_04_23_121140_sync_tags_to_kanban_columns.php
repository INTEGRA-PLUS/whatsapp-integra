<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\Tag;
use App\Models\KanbanColumn;
use App\Models\WhatsAppConversation;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $tags = Tag::all();
        
        foreach ($tags as $tag) {
            // Check if column exists for this tag
            $column = KanbanColumn::where('tag_id', $tag->id)->first();
            
            if (!$column) {
                // Check if a column with the same name exists (might have been created manually)
                $column = KanbanColumn::where('company_id', $tag->company_id)
                    ->where('name', $tag->name)
                    ->first();
                
                if ($column) {
                    $column->update(['tag_id' => $tag->id]);
                } else {
                    $maxPos = KanbanColumn::where('company_id', $tag->company_id)->max('position') ?? -1;
                    $column = KanbanColumn::create([
                        'company_id' => $tag->company_id,
                        'tag_id' => $tag->id,
                        'name' => $tag->name,
                        'color' => $this->mapHexToTailwind($tag->color),
                        'icon' => 'Zap',
                        'subtitle' => 'Estado',
                        'position' => $maxPos + 1,
                    ]);
                }
            }
            
            // For each conversation that has this tag, update its kanban_column_id
            $conversationIds = \DB::table('whatsapp_conversation_tag')
                ->where('tag_id', $tag->id)
                ->pluck('whatsapp_conversation_id');
            
            WhatsAppConversation::whereIn('id', $conversationIds)
                ->update(['kanban_column_id' => $column->id]);
        }
    }

    private function mapHexToTailwind($hex)
    {
        $colors = [
            '#ef4444' => 'bg-red-500',
            '#f59e0b' => 'bg-amber-500',
            '#10b981' => 'bg-emerald-500',
            '#3b82f6' => 'bg-blue-500',
            '#6366f1' => 'bg-indigo-500',
            '#8b5cf6' => 'bg-purple-500',
            '#ec4899' => 'bg-pink-500',
            '#0d9488' => 'bg-teal-600',
        ];

        return $colors[strtolower($hex ?? '')] ?? 'bg-slate-500';
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No need to reverse for this sync migration
    }
};
