<?php

namespace App\Http\Controllers;

use App\Models\AutoResponse;
use App\Models\BusinessHour;
use App\Models\Contact;
use App\Models\Instance;
use App\Models\QuickReply;
use App\Models\Tag;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

/**
 * La portada del producto.
 *
 * Antes de esto, entrar a la aplicación dejaba al cliente en el chat: una lista
 * de conversaciones sin contexto, sin decirle qué le falta por configurar ni
 * cómo se supone que se usa el resto. Quien no había conectado su WhatsApp veía
 * una pantalla vacía y llamaba a soporte a preguntar si estaba roto.
 *
 * La regla que gobierna todo lo que se pinta aquí: **sólo se muestra lo que se
 * puede calcular**. El tablero del CRM llegó a enseñar un «pipeline» que era el
 * número de conversaciones por 150.000 pesos inventados; una cifra falsa con
 * pinta de dato de negocio es peor que no mostrar nada, porque alguien la lee
 * en una reunión. Aquí no hay ni un número que no salga de la base de datos.
 */
class DashboardController extends Controller
{
    public function index()
    {
        $user = auth()->user();

        // El maestro tiene su propio panel: el de la empresa no le sirve, y
        // además no tiene company_id con el que filtrar nada.
        if ($user->isMaster() && ! session('impersonated_by')) {
            return redirect()->route('master.index');
        }

        $companyId = $user->company_id;

        $instancias = Instance::where('company_id', $companyId)->get();
        $idsActivas = $instancias->where('active', true)->pluck('id');

        return Inertia::render('Dashboard/Index', [
            'metricas' => $this->metricas($idsActivas, $companyId),
            'actividad' => $this->actividad($idsActivas),
            'canales' => $this->canales($instancias),
            'puestaEnMarcha' => $this->puestaEnMarcha($companyId, $instancias),
        ]);
    }

    /**
     * Las cifras de la fila superior.
     *
     * Todas se acotan por fecha antes de cruzar con las conversaciones, para
     * que la consulta entre por `msg_created_direction_idx` en vez de recorrer
     * la tabla de mensajes entera: hay empresas con cientos de miles.
     */
    private function metricas($idsActivas, int $companyId): array
    {
        if ($idsActivas->isEmpty()) {
            return [
                'abiertas' => 0,
                'sinAsignar' => 0,
                'sinLeer' => 0,
                'estancadas' => 0,
                'recibidosHoy' => 0,
                'enviadosHoy' => 0,
                'contactos' => 0,
            ];
        }

        $conversaciones = WhatsAppConversation::whereIn('instance_id', $idsActivas);

        $hoy = Carbon::today();

        $mensajesHoy = WhatsAppMessage::where('whatsapp_messages.created_at', '>=', $hoy)
            ->whereIn(
                'conversation_id',
                WhatsAppConversation::whereIn('instance_id', $idsActivas)->select('id')
            )
            ->where('is_internal', false)
            ->selectRaw('direction, COUNT(*) as total')
            ->groupBy('direction')
            ->pluck('total', 'direction');

        return [
            'abiertas' => (clone $conversaciones)->where('status', 'open')->count(),

            // Sin asignar es la cifra que de verdad duele: son clientes
            // escribiendo a nadie en concreto.
            'sinAsignar' => (clone $conversaciones)
                ->where('status', 'open')
                ->whereNull('assigned_to')
                ->count(),

            'sinLeer' => (clone $conversaciones)->where('unread_count', '>', 0)->count(),

            // Una semana en la misma etapa sin que nadie escriba. Misma
            // definición que usa el tablero, para que las dos pantallas no
            // digan cosas distintas del mismo dato.
            'estancadas' => (clone $conversaciones)
                ->whereNotNull('kanban_column_id')
                ->where(function ($q) {
                    $q->where('last_message_at', '<', now()->subDays(7))
                        ->orWhereNull('last_message_at');
                })
                ->count(),

            'recibidosHoy' => (int) ($mensajesHoy['inbound'] ?? 0),
            'enviadosHoy' => (int) ($mensajesHoy['outbound'] ?? 0),
            'contactos' => Contact::where('company_id', $companyId)->count(),
        ];
    }

    /**
     * Mensajes por día de las últimas dos semanas, para la gráfica.
     *
     * Se devuelven los catorce días siempre, incluidos los que no tuvieron
     * ningún mensaje: si sólo vinieran los días con datos, un fin de semana sin
     * actividad se dibujaría como una línea continua y taparía justo lo que se
     * quiere ver.
     */
    private function actividad($idsActivas): array
    {
        $desde = Carbon::today()->subDays(13);

        $dias = collect(range(0, 13))->mapWithKeys(fn ($i) => [
            $desde->copy()->addDays($i)->format('Y-m-d') => ['entrantes' => 0, 'salientes' => 0],
        ]);

        if ($idsActivas->isEmpty()) {
            return $this->formatearActividad($dias);
        }

        $filas = WhatsAppMessage::where('whatsapp_messages.created_at', '>=', $desde)
            ->whereIn(
                'conversation_id',
                WhatsAppConversation::whereIn('instance_id', $idsActivas)->select('id')
            )
            ->where('is_internal', false)
            ->selectRaw('DATE(whatsapp_messages.created_at) as dia, direction, COUNT(*) as total')
            ->groupBy('dia', 'direction')
            ->get();

        foreach ($filas as $fila) {
            $dia = (string) $fila->dia;

            if (! $dias->has($dia)) {
                continue;
            }

            $valores = $dias[$dia];
            $clave = $fila->direction === 'inbound' ? 'entrantes' : 'salientes';
            $valores[$clave] = (int) $fila->total;
            $dias[$dia] = $valores;
        }

        return $this->formatearActividad($dias);
    }

    private function formatearActividad($dias): array
    {
        return $dias->map(fn ($valores, $dia) => [
            'dia' => $dia,
            'etiqueta' => Carbon::parse($dia)->translatedFormat('D j'),
            'entrantes' => $valores['entrantes'],
            'salientes' => $valores['salientes'],
        ])->values()->all();
    }

    /**
     * El estado de cada número conectado.
     *
     * Va en la portada porque una instancia caída no se anuncia sola: hasta que
     * existió el aviso de `account_update`, la única forma de enterarse era que
     * un cliente llamara a preguntar por qué lleva semanas sin recibir nada.
     */
    private function canales($instancias): array
    {
        return $instancias->map(fn (Instance $i) => [
            'id' => $i->id,
            'nombre' => $i->name,
            'numero' => $i->display_phone_number,
            'activa' => (bool) $i->active,
            'salud' => $i->health_status,
            'revisada' => $i->health_checked_at?->diffForHumans(),
        ])->values()->all();
    }

    /**
     * Qué le falta a la empresa para tener el CRM funcionando.
     *
     * Cada paso se comprueba contra la base de datos, no contra una casilla que
     * alguien marcó: un cliente que borra sus etiquetas vuelve a ver ese paso
     * pendiente, que es la verdad. El orden importa —es el que sigue la guía de
     * conexión— y el primero que esté sin hacer es el que la pantalla destaca.
     */
    private function puestaEnMarcha(int $companyId, $instancias): array
    {
        $conectado = $instancias->where('active', true)->isNotEmpty();

        $pasos = [
            [
                'clave' => 'whatsapp',
                'titulo' => 'Conecta tu WhatsApp',
                'detalle' => 'Sin esto no entra ni sale ningún mensaje. Es el único paso que no se puede saltar.',
                'hecho' => $conectado,
                'ruta' => 'instances.index',
                'accion' => 'Conectar',
            ],
            [
                // Etiquetas y tablero son un solo paso y no dos: al crear una
                // etiqueta, `TagObserver` le monta su columna. Separarlos daba
                // un paso que se marcaba solo sin que el cliente hiciera nada,
                // y una lista así enseña a no fiarse de la lista.
                'clave' => 'etiquetas',
                'titulo' => 'Crea tus etiquetas',
                'detalle' => 'Cada etiqueta que creas arma su columna en el tablero: son las etapas por las que pasa un cliente, de la primera consulta al cierre.',
                'hecho' => Tag::where('company_id', $companyId)->exists(),
                'ruta' => 'chat.kanban',
                'accion' => 'Crear etiquetas',
            ],
            [
                'clave' => 'equipo',
                'titulo' => 'Suma a tu equipo',
                'detalle' => 'Cada agente con su usuario: así se puede asignar una conversación y saber quién la atendió.',
                'hecho' => User::where('company_id', $companyId)->count() > 1,
                'ruta' => 'users.index',
                'accion' => 'Invitar usuarios',
            ],
            [
                'clave' => 'horario',
                'titulo' => 'Define tu horario de atención',
                'detalle' => 'Para que fuera de horas el cliente reciba una respuesta y no un silencio.',
                'hecho' => BusinessHour::where('company_id', $companyId)->exists(),
                'ruta' => 'settings.index',
                'accion' => 'Definir horario',
            ],
            [
                'clave' => 'automatico',
                'titulo' => 'Automatiza lo que se repite',
                'detalle' => 'Respuestas rápidas para lo que tu equipo escribe cada día, y automáticas para lo que puede contestarse solo.',
                'hecho' => AutoResponse::where('company_id', $companyId)->exists()
                    || QuickReply::where('company_id', $companyId)->exists(),
                'ruta' => 'auto-responses.index',
                'accion' => 'Automatizar',
            ],
        ];

        $hechos = collect($pasos)->where('hecho', true)->count();

        return [
            'pasos' => $pasos,
            'hechos' => $hechos,
            'total' => count($pasos),
            'completo' => $hechos === count($pasos),
        ];
    }
}
