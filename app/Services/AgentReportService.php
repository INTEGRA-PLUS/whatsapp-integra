<?php

namespace App\Services;

use App\Models\Instance;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class AgentReportService
{
    public function build(int $companyId, CarbonInterface $from, CarbonInterface $to): array
    {
        $instanceIds = Instance::where('company_id', $companyId)->pluck('id');

        if ($instanceIds->isEmpty()) {
            return $this->emptyReport();
        }

        $perAgent = [];
        $totals = ['inbound' => 0, 'outbound' => 0, 'response_count' => 0, 'response_sum' => 0];

        foreach ($this->porConversacion($instanceIds, $from, $to) as $sorted) {
            $pendingInboundAt = null;

            foreach ($sorted as $m) {
                $when = $m->sent_at ?: $m->created_at;

                if ($m->direction === 'inbound') {
                    $totals['inbound']++;
                    $pendingInboundAt = $pendingInboundAt ?: $when;

                    continue;
                }

                $totals['outbound']++;
                $agentId = $m->sent_by;
                if (! $agentId) {
                    continue;
                }

                $bucket = &$perAgent[$agentId];
                if (! is_array($bucket)) {
                    $bucket = ['sent' => 0, 'conversations' => [], 'response_times' => []];
                }
                $bucket['sent']++;
                $bucket['conversations'][$m->conversation_id] = true;

                if ($pendingInboundAt) {
                    $diff = max(0, $when->diffInSeconds($pendingInboundAt, true));
                    $bucket['response_times'][] = $diff;
                    $totals['response_sum'] += $diff;
                    $totals['response_count']++;
                    $pendingInboundAt = null;
                }
                unset($bucket);
            }
        }

        $openConversations = WhatsAppConversation::whereIn('instance_id', $instanceIds)
            ->where('status', 'open')
            ->get(['id', 'assigned_to']);

        $lastDirections = $this->lastMessageDirections($openConversations->pluck('id'));

        $unansweredByAgent = [];
        $unansweredUnassigned = 0;
        foreach ($openConversations as $conv) {
            $direction = $lastDirections[$conv->id] ?? null;
            if ($direction !== 'inbound') {
                continue;
            }

            if ($conv->assigned_to) {
                $unansweredByAgent[$conv->assigned_to] = ($unansweredByAgent[$conv->assigned_to] ?? 0) + 1;
            } else {
                $unansweredUnassigned++;
            }
        }

        $agentIds = array_unique(array_merge(array_keys($perAgent), array_keys($unansweredByAgent)));
        $users = User::whereIn('id', $agentIds)
            ->where('company_id', $companyId)
            ->get(['id', 'name', 'email'])
            ->keyBy('id');

        $rows = [];
        foreach ($agentIds as $uid) {
            $user = $users[$uid] ?? null;
            if (! $user) {
                continue;
            }

            $rt = $perAgent[$uid]['response_times'] ?? [];
            $rows[] = [
                'user_id' => $uid,
                'name' => $user->name,
                'email' => $user->email,
                'messages_sent' => $perAgent[$uid]['sent'] ?? 0,
                'conversations_handled' => count($perAgent[$uid]['conversations'] ?? []),
                'avg_response_seconds' => count($rt) > 0 ? (int) round(array_sum($rt) / count($rt)) : null,
                'fastest_response_seconds' => count($rt) > 0 ? (int) min($rt) : null,
                'slowest_response_seconds' => count($rt) > 0 ? (int) max($rt) : null,
                'unanswered_count' => $unansweredByAgent[$uid] ?? 0,
            ];
        }

        usort($rows, fn ($a, $b) => $b['messages_sent'] <=> $a['messages_sent']);

        return [
            'totals' => [
                'inbound' => $totals['inbound'],
                'outbound' => $totals['outbound'],
                'avg_response_seconds' => $totals['response_count'] > 0
                    ? (int) round($totals['response_sum'] / $totals['response_count'])
                    : null,
                'response_count' => $totals['response_count'],
                'unanswered_unassigned' => $unansweredUnassigned,
                'open_conversations' => $openConversations->count(),
                'active_agents' => count($rows),
            ],
            'agents' => $rows,
        ];
    }

    public function buildForAgent(int $companyId, int $userId, CarbonInterface $from, CarbonInterface $to): array
    {
        $user = User::where('id', $userId)->where('company_id', $companyId)->first(['id', 'name', 'email']);
        if (! $user) {
            return $this->emptyAgentReport();
        }

        $instanceIds = Instance::where('company_id', $companyId)->pluck('id');
        if ($instanceIds->isEmpty()) {
            return $this->emptyAgentReport($user);
        }

        $perDay = [];
        $perConversation = [];
        $totalSent = 0;
        $responseSum = 0;
        $responseCount = 0;

        foreach ($this->porConversacion($instanceIds, $from, $to) as $convId => $sorted) {
            $pendingInboundAt = null;

            foreach ($sorted as $m) {
                $when = $m->sent_at ?: $m->created_at;

                if ($m->direction === 'inbound') {
                    $pendingInboundAt = $pendingInboundAt ?: $when;

                    continue;
                }

                if ((int) $m->sent_by !== $userId) {
                    $pendingInboundAt = null;

                    continue;
                }

                $totalSent++;
                $day = $when->format('Y-m-d');
                $perDay[$day] = $perDay[$day] ?? ['sent' => 0, 'response_times' => []];
                $perDay[$day]['sent']++;

                $perConversation[$convId] = $perConversation[$convId] ?? ['sent' => 0, 'response_times' => [], 'last_at' => null];
                $perConversation[$convId]['sent']++;
                $perConversation[$convId]['last_at'] = $when;

                if ($pendingInboundAt) {
                    $diff = max(0, $when->diffInSeconds($pendingInboundAt, true));
                    $perDay[$day]['response_times'][] = $diff;
                    $perConversation[$convId]['response_times'][] = $diff;
                    $responseSum += $diff;
                    $responseCount++;
                    $pendingInboundAt = null;
                }
            }
        }

        $assignedOpen = WhatsAppConversation::whereIn('instance_id', $instanceIds)
            ->where('status', 'open')
            ->where('assigned_to', $userId)
            ->get(['id', 'name', 'phone_number', 'last_message_at']);

        $lastDirections = $this->lastMessageDirections($assignedOpen->pluck('id'));

        $unanswered = [];
        foreach ($assignedOpen as $conv) {
            if (($lastDirections[$conv->id] ?? null) !== 'inbound') {
                continue;
            }
            $unanswered[] = [
                'id' => $conv->id,
                'name' => $conv->name,
                'phone' => $conv->phone_number,
                'last_message_at' => optional($conv->last_message_at)->toIso8601String(),
            ];
        }

        $byDay = [];
        foreach ($perDay as $day => $d) {
            $rt = $d['response_times'];
            $byDay[] = [
                'date' => $day,
                'messages_sent' => $d['sent'],
                'response_count' => count($rt),
                'avg_response_seconds' => count($rt) > 0 ? (int) round(array_sum($rt) / count($rt)) : null,
            ];
        }
        usort($byDay, fn ($a, $b) => strcmp($b['date'], $a['date']));

        $convs = WhatsAppConversation::whereIn('id', array_keys($perConversation))
            ->get(['id', 'name', 'phone_number'])
            ->keyBy('id');

        $byConversation = [];
        foreach ($perConversation as $convId => $c) {
            $conv = $convs[$convId] ?? null;
            if (! $conv) {
                continue;
            }
            $rt = $c['response_times'];
            $byConversation[] = [
                'conversation_id' => $convId,
                'name' => $conv->name,
                'phone' => $conv->phone_number,
                'messages_sent' => $c['sent'],
                'response_count' => count($rt),
                'avg_response_seconds' => count($rt) > 0 ? (int) round(array_sum($rt) / count($rt)) : null,
                'last_response_at' => optional($c['last_at'])->toIso8601String(),
            ];
        }
        usort($byConversation, fn ($a, $b) => $b['messages_sent'] <=> $a['messages_sent']);

        return [
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
            'totals' => [
                'messages_sent' => $totalSent,
                'response_count' => $responseCount,
                'avg_response_seconds' => $responseCount > 0 ? (int) round($responseSum / $responseCount) : null,
                'conversations_handled' => count($perConversation),
                'unanswered_count' => count($unanswered),
            ],
            'by_day' => $byDay,
            'by_conversation' => $byConversation,
            'unanswered' => $unanswered,
        ];
    }

    private function emptyAgentReport($user = null): array
    {
        return [
            'user' => $user ? ['id' => $user->id, 'name' => $user->name, 'email' => $user->email] : null,
            'totals' => [
                'messages_sent' => 0,
                'response_count' => 0,
                'avg_response_seconds' => null,
                'conversations_handled' => 0,
                'unanswered_count' => 0,
            ],
            'by_day' => [],
            'by_conversation' => [],
            'unanswered' => [],
        ];
    }

    /**
     * Los mensajes del periodo, conversación a conversación.
     *
     * Antes se traía la lista entera de ids de conversación a memoria y se
     * metía en un `IN (...)`; con doce mil socios eso es una consulta que MySQL
     * corta por tamaño y un plan que degenera. Ahora el filtro por empresa va
     * por `join`, que es lo que el índice sabe resolver.
     *
     * Y se recorre con `cursor()` en vez de `get()`: el informe de un semestre
     * son cientos de miles de filas, y hidratarlas todas antes de empezar a
     * contar es lo que agota la memoria de PHP. Como la consulta llega ordenada
     * por conversación, basta con acumular la que se está leyendo.
     *
     * @return \Generator<int, Collection>
     */
    private function porConversacion(Collection $instanceIds, CarbonInterface $from, CarbonInterface $to): \Generator
    {
        $filas = WhatsAppMessage::query()
            ->join('whatsapp_conversations', 'whatsapp_conversations.id', '=', 'whatsapp_messages.conversation_id')
            ->whereIn('whatsapp_conversations.instance_id', $instanceIds)
            ->where('whatsapp_messages.is_internal', false)
            ->whereBetween('whatsapp_messages.created_at', [$from, $to])
            ->orderBy('whatsapp_messages.conversation_id')
            ->orderBy('whatsapp_messages.id')
            ->select([
                'whatsapp_messages.id',
                'whatsapp_messages.conversation_id',
                'whatsapp_messages.direction',
                'whatsapp_messages.sent_by',
                'whatsapp_messages.sent_at',
                'whatsapp_messages.created_at',
            ])
            ->cursor();

        $actual = null;
        $acumulado = [];

        foreach ($filas as $m) {
            if ($actual !== null && $m->conversation_id !== $actual) {
                yield $actual => $this->ordenarPorEnvio($acumulado);
                $acumulado = [];
            }

            $actual = $m->conversation_id;
            $acumulado[] = $m;
        }

        if ($actual !== null) {
            yield $actual => $this->ordenarPorEnvio($acumulado);
        }
    }

    /**
     * El orden real de la conversación es el de `sent_at`, no el del id.
     *
     * Se ordena aquí y no en SQL porque el historial importado entra con el id
     * de hoy y la fecha de hace meses: por id, las respuestas saldrían antes que
     * las preguntas y los tiempos de respuesta serían negativos.
     */
    private function ordenarPorEnvio(array $mensajes): Collection
    {
        return collect($mensajes)
            ->sortBy(fn ($m) => optional($m->sent_at)->timestamp ?? $m->created_at->timestamp)
            ->values();
    }

    private function lastMessageDirections(Collection $conversationIds): array
    {
        if ($conversationIds->isEmpty()) {
            return [];
        }

        $out = [];

        // Antes se traían TODOS los mensajes de TODAS las conversaciones
        // abiertas para quedarse con el último de cada una. Con una cooperativa
        // eso es medio historial en memoria para leer un campo por conversación.
        // La subconsulta pide sólo el id del último y luego su dirección.
        foreach ($conversationIds->chunk(1000) as $tanda) {
            $ultimos = WhatsAppMessage::query()
                ->selectRaw('MAX(id) as id')
                ->whereIn('conversation_id', $tanda)
                ->where('is_internal', false)
                ->groupBy('conversation_id')
                ->pluck('id');

            if ($ultimos->isEmpty()) {
                continue;
            }

            WhatsAppMessage::whereIn('id', $ultimos)
                ->get(['conversation_id', 'direction'])
                ->each(function ($m) use (&$out) {
                    $out[$m->conversation_id] = $m->direction;
                });
        }

        return $out;
    }

    private function emptyReport(): array
    {
        return [
            'totals' => [
                'inbound' => 0,
                'outbound' => 0,
                'avg_response_seconds' => null,
                'response_count' => 0,
                'unanswered_unassigned' => 0,
                'open_conversations' => 0,
                'active_agents' => 0,
            ],
            'agents' => [],
        ];
    }
}
