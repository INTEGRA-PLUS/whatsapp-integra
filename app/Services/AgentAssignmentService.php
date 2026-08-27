<?php

namespace App\Services;

use App\Models\User;
use App\Models\WhatsAppConversation;
use Illuminate\Support\Facades\DB;

/**
 * A quién le toca el siguiente chat.
 *
 * El criterio es la carga real, no un turno rotativo: un round-robin puro le
 * entrega el chat al asesor que lleva media hora atascado con una reclamación
 * sólo porque le tocaba, mientras el de al lado está libre. Aquí gana siempre
 * el que menos conversaciones abiertas tiene encima.
 */
class AgentAssignmentService
{
    /**
     * El asesor con menos conversaciones abiertas asignadas, o null si la
     * empresa no tiene a nadie disponible.
     *
     * @param int[] $onlyUserIds Restringe el reparto a estos asesores (lista
     *                           blanca de la opción del menú). Vacío = todos.
     */
    public function leastBusy(int $companyId, array $onlyUserIds = []): ?User
    {
        $candidates = $this->candidates($companyId, $onlyUserIds);

        if ($candidates->isEmpty()) {
            return null;
        }

        $load = $this->openConversationsPerAgent($candidates->pluck('id')->all());

        return $candidates
            // A igual carga gana quien lleva más tiempo sin recibir un chat.
            // Sin este desempate, con todo el equipo a cero el primer asesor de
            // la lista se llevaría todos los chats de la mañana.
            ->sortBy(fn (User $u) => [
                $load[$u->id]['open'] ?? 0,
                $load[$u->id]['last'] ?? 0,
                $u->id,
            ])
            ->first();
    }

    /**
     * Asesores que pueden recibir un chat: activos, de la empresa y con rol de
     * atención. El admin entra porque en la mayoría de estas empresas es quien
     * más atiende, y excluirlo dejaría el reparto vacío.
     *
     * @param int[] $onlyUserIds
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function candidates(int $companyId, array $onlyUserIds = [])
    {
        // Los roles de Spatie están particionados por empresa; sin fijar el
        // equipo, hasRole() no encuentra nada y el reparto se queda sin nadie.
        setPermissionsTeamId($companyId);

        return User::where('company_id', $companyId)
            ->where('active', true)
            ->when($onlyUserIds, fn ($q) => $q->whereIn('id', $onlyUserIds))
            ->get()
            ->filter(fn (User $u) => $u->hasRole(['admin', 'agent']))
            ->values();
    }

    /**
     * Carga actual de cada asesor: cuántos chats sin cerrar tiene y cuál fue el
     * último que se le asignó (por id, que crece con el tiempo; `updated_at`
     * no serviría porque cambia con cada mensaje del chat).
     *
     * @param int[] $userIds
     * @return array<int, array{open: int, last: int}>
     */
    private function openConversationsPerAgent(array $userIds): array
    {
        if (!$userIds) {
            return [];
        }

        return WhatsAppConversation::whereIn('assigned_to', $userIds)
            ->where('status', '!=', 'closed')
            ->groupBy('assigned_to')
            ->select('assigned_to', DB::raw('COUNT(*) as open_count'), DB::raw('MAX(id) as last_id'))
            ->get()
            ->mapWithKeys(fn ($row) => [
                (int) $row->assigned_to => [
                    'open' => (int) $row->open_count,
                    'last' => (int) $row->last_id,
                ],
            ])
            ->all();
    }
}
