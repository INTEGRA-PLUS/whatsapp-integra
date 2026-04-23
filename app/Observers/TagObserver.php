<?php

namespace App\Observers;

use App\Models\Tag;
use App\Models\KanbanColumn;

class TagObserver
{
    /**
     * Handle the Tag "created" event.
     */
    public function created(Tag $tag): void
    {
        KanbanColumn::create([
            'company_id' => $tag->company_id,
            'tag_id' => $tag->id,
            'name' => $tag->name,
            'color' => $this->mapHexToTailwind($tag->color),
            'icon' => 'Zap',
            'subtitle' => 'Estado',
            'position' => KanbanColumn::where('company_id', $tag->company_id)->max('position') + 1,
        ]);
    }

    /**
     * Handle the Tag "updated" event.
     */
    public function updated(Tag $tag): void
    {
        $column = KanbanColumn::where('tag_id', $tag->id)->first();
        if ($column) {
            $column->update([
                'name' => $tag->name,
                'color' => $this->mapHexToTailwind($tag->color),
            ]);
        }
    }

    /**
     * Handle the Tag "deleted" event.
     */
    public function deleted(Tag $tag): void
    {
        $column = KanbanColumn::where('tag_id', $tag->id)->first();
        if ($column) {
            $column->delete();
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
}
