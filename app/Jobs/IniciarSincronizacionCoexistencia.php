<?php

namespace App\Jobs;

use App\Events\CoexistenceSyncEvent;
use App\Models\CoexistenceSync;
use App\Models\Instance;
use App\Services\MetaWhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pide a Meta los contactos y el historial de un número recién conectado por
 * coexistencia.
 *
 * Antes esto se lanzaba a mano desde el servidor. El problema no era la
 * comodidad: la importación tiene **24 horas y un solo intento**, así que
 * depender de que alguien se acuerde de correr dos comandos es depender de que
 * nadie se distraiga el día que un cliente se conecta a las once de la noche.
 *
 * Todo el diseño gira alrededor de que esto **no se puede repetir**:
 *
 *  - `ShouldBeUnique` evita dos copias en la cola a la vez.
 *  - La fila de `coexistence_syncs` tiene índice único por instancia, y se crea
 *    dentro de una transacción con bloqueo: si ya existe, el job se retira.
 *  - Se marca como solicitada **antes** de llamar a Meta. Si el proceso muere
 *    entre la llamada y el guardado, preferimos perder el `request_id` a
 *    volver a pedirlo y gastar el intento de verdad.
 */
class IniciarSincronizacionCoexistencia implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    /**
     * Un solo intento a propósito.
     *
     * Un reintento automático sobre una llamada que Meta pudo haber aceptado
     * quemaría la única oportunidad del cliente. Si falla, queda `fallida` en la
     * base y se decide a mano, con la ventana de 24 horas todavía abierta.
     */
    public int $tries = 1;

    public function __construct(public int $instanceId)
    {
    }

    public function uniqueId(): string
    {
        return (string) $this->instanceId;
    }

    public function handle(MetaWhatsAppService $meta): void
    {
        $instance = Instance::find($this->instanceId);

        if (!$instance || !$instance->access_token) {
            return;
        }

        // Sólo hay historial que importar si el número sigue vivo en la app del
        // celular. En un registro normal esta llamada no tendría nada que traer
        // y gastaría el intento igual.
        $detalle = $meta->getPhoneNumber($instance->phone_number_id, $instance->access_token);

        if (! ($detalle['data']['is_on_biz_app'] ?? false)) {
            Log::channel('whatsapp')->info('ℹ️ Instancia sin coexistencia: no hay nada que importar', [
                'instance_id' => $instance->id,
            ]);
            return;
        }

        $sync = $this->reservar($instance);

        if (!$sync) {
            Log::channel('whatsapp')->warning('🔒 La importación de esta instancia ya se había solicitado', [
                'instance_id' => $instance->id,
            ]);
            return;
        }

        // La pantalla se entera ya de que la importación arrancó, sin esperar
        // al primer lote: entre la petición y el primer webhook pueden pasar
        // minutos, y una tarjeta que no aparece parece que no pasó nada.
        CoexistenceSyncEvent::dispatch($sync);

        // Primero los contactos: así, cuando empiecen a entrar las
        // conversaciones del historial, ya tienen a quién colgarse y el cliente
        // ve nombres en vez de números.
        $contactos = $meta->startSmbDataSync(
            $instance->phone_number_id,
            $instance->access_token,
            'smb_app_state_sync'
        );

        $historial = $meta->startSmbDataSync(
            $instance->phone_number_id,
            $instance->access_token,
            'history'
        );

        $sync->update([
            'contacts_request_id' => $contactos['request_id'] ?? null,
            'history_request_id'  => $historial['request_id'] ?? null,
        ]);

        // Que fallen las dos es un problema real y hay que verlo. Que falle una
        // no: la otra sigue su curso y el cliente recibe media importación, que
        // es mejor que ninguna.
        if (! ($contactos['success'] ?? false) && ! ($historial['success'] ?? false)) {
            $sync->update([
                'status'        => CoexistenceSync::FALLIDA,
                'error_message' => 'Meta rechazó las dos peticiones de sincronización',
                'completed_at'  => now(),
            ]);

            Log::channel('whatsapp')->error('❌ No se pudo iniciar la importación de coexistencia', [
                'instance_id' => $instance->id,
                'contactos'   => $contactos['error'] ?? null,
                'historial'   => $historial['error'] ?? null,
            ]);

            CoexistenceSyncEvent::dispatch($sync->fresh());

            return;
        }

        Log::channel('whatsapp')->info('📥 Importación de coexistencia solicitada', [
            'instance_id'         => $instance->id,
            'contacts_request_id' => $contactos['request_id'] ?? null,
            'history_request_id'  => $historial['request_id'] ?? null,
        ]);
    }

    /**
     * Reserva el único intento de esta instancia.
     *
     * Devuelve la fila si la reserva es nuestra, o null si alguien se adelantó.
     * El bloqueo de fila cierra la carrera entre dos workers que despachen a la
     * vez; el índice único de la tabla es la última red por si el bloqueo no
     * llegara a tiempo.
     */
    private function reservar(Instance $instance): ?CoexistenceSync
    {
        return DB::transaction(function () use ($instance) {
            $existente = CoexistenceSync::where('instance_id', $instance->id)
                ->lockForUpdate()
                ->first();

            // Lo que marca el intento gastado es `requested_at`, no que la fila
            // exista. La fila puede haberla creado antes el volcado de un eco
            // del celular, que llega en cuanto el negocio responde y no tiene
            // nada que ver con la importación; bastaba eso para que el job se
            // retirase y el cliente se quedara sin historial para siempre.
            if ($existente?->requested_at) {
                return null;
            }

            if ($existente) {
                $existente->update([
                    'status'       => CoexistenceSync::SOLICITADA,
                    'requested_at' => now(),
                ]);

                return $existente;
            }

            return CoexistenceSync::create([
                'instance_id'  => $instance->id,
                'status'       => CoexistenceSync::SOLICITADA,
                'requested_at' => now(),
            ]);
        });
    }
}
