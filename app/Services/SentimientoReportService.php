<?php

namespace App\Services;

use App\Models\CompanyExtension;
use App\Models\Instance;
use App\Models\SentimentEvent;
use App\Models\WhatsAppConversation;
use App\Support\Sentimiento\Lectura;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Los números del semáforo de emociones para el panel de informes.
 *
 * Dos cosas distintas, y conviene no confundirlas al leerlas:
 *
 * - **Ahora**: cómo está la bandeja en este momento. Se cuenta sobre las
 *   conversaciones abiertas, no sobre el histórico.
 * - **El periodo**: cuántas veces se torció una conversación entre dos fechas.
 *   Se cuenta sobre `conversation_sentiment_events`, que sólo guarda los
 *   cambios.
 *
 * Un mes con pocos rojos y una bandeja roja hoy es una empresa que acaba de
 * tener un mal día; al revés, una que lleva un mes malo y hoy lo tiene
 * resuelto. Con un solo número no se distinguen.
 *
 * Aislamiento: todo filtra por `company_id` a mano, incluido el histórico, que
 * lleva la columna propia justamente para esto.
 */
class SentimientoReportService
{
    /**
     * @return ?array Null si la empresa no tiene la extensión: el panel se
     *                calla en vez de enseñar una sección a cero, que parecería
     *                que todo va bien cuando lo que pasa es que nadie mide.
     */
    public function build(int $companyId, Carbon $from, Carbon $to): ?array
    {
        $instalada = CompanyExtension::where('company_id', $companyId)
            ->where('slug', 'sentiment_traffic_light')
            ->exists();

        if (! $instalada) {
            return null;
        }

        return [
            'ahora' => $this->ahora($companyId),
            'evolucion' => $this->evolucion($companyId, $from, $to),
            'agentes' => $this->agentes($companyId, $from, $to),
            'cambios' => SentimentEvent::where('company_id', $companyId)
                ->whereBetween('created_at', [$from, $to])
                ->count(),
        ];
    }

    /**
     * Cómo está la bandeja ahora mismo.
     *
     * `sin_analizar` se cuenta y se enseña aparte en vez de sumarse a los
     * verdes. Es la diferencia entre "lo miré y está bien" y "no lo he mirado",
     * y meterlas en el mismo cubo convierte el panel en el tipo de informe
     * tranquilizador que no sirve para decidir nada.
     *
     * @return array<string, int>
     */
    private function ahora(int $companyId): array
    {
        $instanceIds = Instance::where('company_id', $companyId)->pluck('id');

        $porNivel = WhatsAppConversation::whereIn('instance_id', $instanceIds)
            ->where('status', 'open')
            ->groupBy('sentiment_level')
            ->select('sentiment_level', DB::raw('COUNT(*) as total'))
            ->pluck('total', 'sentiment_level');

        return [
            'verde' => (int) ($porNivel[Lectura::VERDE] ?? 0),
            'amarillo' => (int) ($porNivel[Lectura::AMARILLO] ?? 0),
            'rojo' => (int) ($porNivel[Lectura::ROJO] ?? 0),
            'sin_analizar' => (int) ($porNivel[''] ?? 0) + (int) ($porNivel[null] ?? 0),
        ];
    }

    /**
     * Cuántas conversaciones se torcieron cada día.
     *
     * Se devuelven TODOS los días del rango, incluidos los de cero. Una gráfica
     * que se salta los días vacíos comprime el tiempo y hace que tres rojos en
     * tres semanas parezcan una racha de tres días seguidos.
     *
     * @return list<array<string, mixed>>
     */
    private function evolucion(int $companyId, Carbon $from, Carbon $to): array
    {
        $filas = SentimentEvent::where('company_id', $companyId)
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('dia', 'nivel')
            ->select(
                DB::raw('DATE(created_at) as dia'),
                'nivel',
                DB::raw('COUNT(*) as total')
            )
            ->get()
            ->groupBy('dia');

        $dias = [];

        for ($fecha = $from->copy()->startOfDay(); $fecha->lte($to); $fecha->addDay()) {
            $clave = $fecha->format('Y-m-d');
            $delDia = $filas[$clave] ?? collect();

            $dias[] = [
                'dia' => $clave,
                'verde' => (int) ($delDia->firstWhere('nivel', Lectura::VERDE)->total ?? 0),
                'amarillo' => (int) ($delDia->firstWhere('nivel', Lectura::AMARILLO)->total ?? 0),
                'rojo' => (int) ($delDia->firstWhere('nivel', Lectura::ROJO)->total ?? 0),
            ];
        }

        return $dias;
    }

    /**
     * Cuántas conversaciones se pusieron en rojo con cada agente delante.
     *
     * **Esto no mide la calidad del agente**, y el panel lo dice en voz alta:
     * al mejor agente se le asignan los casos difíciles, así que leer esta
     * tabla como un ranking premia a quien atiende lo fácil. Sirve para
     * repartir carga y para saber a quién hay que echarle una mano, no para
     * evaluar a nadie.
     *
     * El agente sale del histórico y no de la conversación: si el chat se
     * reasignó después, atribuirlo a quien lo tiene hoy sería colgarle enfados
     * que ocurrieron con otro delante.
     *
     * @return list<array<string, mixed>>
     */
    private function agentes(int $companyId, Carbon $from, Carbon $to): array
    {
        return SentimentEvent::where('conversation_sentiment_events.company_id', $companyId)
            ->whereBetween('conversation_sentiment_events.created_at', [$from, $to])
            ->where('nivel', Lectura::ROJO)
            ->whereNotNull('assigned_to')
            ->join('users', 'users.id', '=', 'conversation_sentiment_events.assigned_to')
            ->groupBy('users.id', 'users.name')
            ->select('users.id', 'users.name', DB::raw('COUNT(*) as rojos'))
            ->orderByDesc('rojos')
            ->limit(10)
            ->get()
            ->map(fn ($fila) => [
                'id' => (int) $fila->id,
                'nombre' => (string) $fila->name,
                'rojos' => (int) $fila->rojos,
            ])
            ->all();
    }
}
