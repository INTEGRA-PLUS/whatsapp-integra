<?php

namespace App\Console\Commands;

use App\Models\Instance;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Services\MetaWhatsAppService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Le pregunta a Meta, una vez al día, si cada instancia sigue viva.
 *
 * El 2026-09-05 aparecieron cinco empresas cuyo token o número ya no existían
 * del lado de Meta. La más antigua llevaba seis meses así. Las cinco se veían
 * "Activa" en verde en el panel, porque `active` es una casilla nuestra que
 * alguien marcó una vez, no una comprobación. Se descubrieron por casualidad,
 * revisando otra cosa.
 *
 * Esto convierte ese hallazgo en un aviso del mismo día.
 *
 * Se consulta el `phone_number_id` y no el WABA a propósito: es el objeto que
 * se usa para enviar, así que si responde, la instancia puede trabajar. Un
 * WABA legible con un número muerto seguiría sin poder mandar un mensaje.
 */
class CheckInstanceHealth extends Command
{
    /** Cómo se llama en castellano cada entidad de las que responde Meta. */
    private const QUIEN = [
        'WABA' => 'La cuenta de WhatsApp',
        'BUSINESS' => 'El portafolio del negocio',
        'APP' => 'La app de Meta',
        'PHONE_NUMBER' => 'El número',
    ];

    protected $signature = 'whatsapp:health-check
        {--instance= : Revisa solo esta instancia (id)}
        {--quiet-notifications : No notifica; sólo actualiza el estado}';

    protected $description = 'Comprueba contra Meta que cada instancia siga viva y avisa cuando una se cae';

    public function __construct(private MetaWhatsAppService $meta)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $instances = Instance::with('company')
            ->where('active', true)
            ->when($this->option('instance'), fn ($q, $id) => $q->where('id', $id))
            ->orderBy('id')
            ->get();

        if ($instances->isEmpty()) {
            $this->warn('No hay instancias activas que revisar.');

            return self::SUCCESS;
        }

        $caidas = 0;
        $recuperadas = 0;
        $bloqueadas = 0;

        foreach ($instances as $instance) {
            $anterior = $instance->health_status;
            [$estado, $error] = $this->revisar($instance);

            $instance->update([
                'health_status' => $estado,
                'health_checked_at' => now(),
                'health_error' => $error,
            ]);

            $etiqueta = $instance->company->name ?? "instancia #{$instance->id}";

            // Y la otra mitad de la pregunta: conectado no es lo mismo que
            // poder enviar. Una cuenta sana a la que se le venció la tarjeta
            // responde a todo y no entrega nada.
            $antesPodia = $instance->puede_enviar;
            $podra = $estado === 'ok' ? $this->puedeEnviar($instance) : null;

            if ($podra !== null) {
                $instance->update([
                    'puede_enviar' => $podra['estado'],
                    'puede_enviar_motivo' => $podra['motivo'],
                    'puede_enviar_visto_at' => now(),
                ]);

                if ($podra['estado'] !== 'AVAILABLE') {
                    $this->line("    <fg=yellow>envío {$podra['estado']}:</> ".$podra['motivo']);
                }

                // Sólo en el cambio: repetirlo a diario convierte el aviso en
                // ruido, y el ruido es lo que hace que nadie mire.
                //
                // «Cambio» incluye la primera vez que se mira, no sólo pasar de
                // disponible a bloqueado: una cuenta que ya estaba bloqueada
                // antes de que existiera esta comprobación es justo la que hay
                // que descubrir, y exigir un AVAILABLE previo la dejaría muda
                // para siempre.
                if ($podra['estado'] !== 'AVAILABLE' && $antesPodia !== $podra['estado']) {
                    $bloqueadas++;
                    $this->avisarDelBloqueo($instance, $podra['motivo']);
                }
            }

            if ($estado === 'unreachable') {
                $this->line("  ✗ {$etiqueta}: {$error}");
            } else {
                $this->line("  ✓ {$etiqueta}");
            }

            // Sólo se avisa en el cambio de estado. Repetirlo cada día
            // convertiría la alerta en ruido, y el ruido es exactamente lo que
            // hizo que nadie mirara las cinco que ya estaban caídas.
            if ($estado === 'unreachable' && $anterior !== 'unreachable') {
                $caidas++;
                $this->avisar($instance, $error);
            }

            if ($estado === 'ok' && $anterior === 'unreachable') {
                $recuperadas++;
                Log::channel('whatsapp')->info('✅ Instancia recuperada', [
                    'instance_id' => $instance->id,
                    'company' => $etiqueta,
                ]);
            }
        }

        $this->newLine();
        $this->info("Revisadas: {$instances->count()} · Caídas nuevas: {$caidas} · Recuperadas: {$recuperadas}"
            ." · Bloqueadas para enviar: {$bloqueadas}");

        return self::SUCCESS;
    }

    /**
     * @return array{0: string, 1: string|null} estado y motivo
     */
    private function revisar(Instance $instance): array
    {
        // Instagram no tiene `phone_number_id` y nunca lo tendrá: se identifica
        // por la cuenta profesional. Exigírselo marcaba `unreachable` a toda
        // línea de Instagram sana y pintaba en su tarjeta «Meta no responde por
        // esta cuenta. No entran ni salen mensajes», mintiendo: el 14-sep-2026
        // la cuenta @integracolombiasas recibía mensajes con ese cartel rojo
        // encima. Se descubrió preparando el screencast del App Review, que es
        // justo donde peor podía verse.
        //
        // No se consulta a Graph para comprobarlo, a diferencia de WhatsApp.
        // `graph.instagram.com` responde «Unsupported request - method type:
        // get» por causas que no son la salud de la línea —el rol de evaluador
        // sin aceptar, entre otras— y un health-check que se cree eso apagaría
        // en verde líneas que funcionan. Con la configuración completa basta:
        // lo que de verdad falla, el token caducado, ya tiene su propia tarea.
        if ($instance->esInstagram()) {
            return $instance->isMetaConfigured()
                ? ['ok', null]
                : ['unreachable', 'La cuenta de Instagram no tiene identificador o token configurado.'];
        }

        if (! $instance->access_token || ! $instance->phone_number_id) {
            return ['unreachable', 'La instancia no tiene token o phone_number_id configurado.'];
        }

        $res = $this->meta->getPhoneNumber($instance->phone_number_id, $instance->access_token);

        if ($res['success'] ?? false) {
            return ['ok', null];
        }

        $motivo = $res['error']['error']['message']
            ?? $res['error']['message']
            ?? (is_string($res['error'] ?? null) ? $res['error'] : 'Meta no reconoce el número o el token.');

        return ['unreachable', mb_substr($motivo, 0, 240)];
    }

    /**
     * ¿Meta deja enviar por esta cuenta?
     *
     * Pregunta por el WABA, el portafolio del negocio y la app a la vez. Se
     * devuelve el primero que no esté disponible, porque es el que hay que
     * arreglar; si son varios, el resto sale en el motivo.
     *
     * `null` cuando no se puede saber —una línea sin WABA, o Meta que no
     * contesta—: eso no es un bloqueo y pintarlo como tal sería inventarse una
     * alarma.
     *
     * @return array{estado: string, motivo: ?string}|null
     */
    private function puedeEnviar(Instance $instance): ?array
    {
        if ($instance->esInstagram() || ! $instance->waba_id || ! $instance->access_token) {
            return null;
        }

        $res = $this->meta->healthStatus($instance->waba_id, $instance->access_token);

        if (! ($res['success'] ?? false)) {
            return null;
        }

        $salud = $res['data']['health_status'] ?? [];
        $general = $salud['can_send_message'] ?? null;

        if ($general === null) {
            return null;
        }

        if ($general === 'AVAILABLE') {
            return ['estado' => 'AVAILABLE', 'motivo' => null];
        }

        $motivos = [];

        foreach ($salud['entities'] ?? [] as $entidad) {
            if (($entidad['can_send_message'] ?? 'AVAILABLE') === 'AVAILABLE') {
                continue;
            }

            $quien = self::QUIEN[$entidad['entity_type'] ?? ''] ?? ($entidad['entity_type'] ?? 'algo');
            $detalle = collect($entidad['errors'] ?? [])
                ->map(fn ($e) => $e['description'] ?? $e['error_description'] ?? $e['message'] ?? null)
                ->filter()
                ->implode('. ');

            $motivos[] = trim($quien.($detalle !== '' ? ': '.$detalle : ''));
        }

        return [
            'estado' => (string) $general,
            'motivo' => $motivos === [] ? null : mb_substr(implode(' · ', $motivos), 0, 480),
        ];
    }

    /**
     * Avisa de que la cuenta dejó de poder enviar.
     *
     * Va a los admins de la empresa y no sólo al log por lo mismo que el aviso
     * de caída: la información ya existía y nadie la miraba. Y se nombra al
     * portafolio cuando es él quien falla, porque ahí el arreglo no está en el
     * CRM: está en la facturación de Meta.
     */
    private function avisarDelBloqueo(Instance $instance, ?string $motivo): void
    {
        Log::channel('whatsapp')->error('🚫 La cuenta no puede enviar', [
            'instance_id' => $instance->id,
            'company' => $instance->company->name ?? null,
            'waba_id' => $instance->waba_id,
            'motivo' => $motivo,
        ]);

        if ($this->option('quiet-notifications')) {
            return;
        }

        $admins = User::where('company_id', $instance->company_id)
            ->where('role', 'admin')
            ->where('active', true)
            ->get();

        if ($admins->isEmpty()) {
            return;
        }

        Notification::send($admins, new SystemNotification(
            'WhatsApp no está dejando enviar',
            "Meta bloqueó el envío por «{$instance->name}» ({$instance->display_phone_number}). "
                .($motivo ?: 'No dio un motivo.')
                .' La causa más común es el medio de pago del portafolio: revísalo en el Administrador comercial de Meta.',
            'Sistema'
        ));
    }

    /**
     * Avisa a los admins de la empresa dueña, que son quienes pueden
     * reconectar. Mandarlo sólo al log repetiría el problema que esto arregla:
     * la información existía y nadie la miraba.
     */
    private function avisar(Instance $instance, ?string $error): void
    {
        Log::channel('whatsapp')->error('❌ Instancia caída contra Meta', [
            'instance_id' => $instance->id,
            'company' => $instance->company->name ?? null,
            'phone_number_id' => $instance->phone_number_id,
            'error' => $error,
        ]);

        if ($this->option('quiet-notifications')) {
            return;
        }

        $admins = User::where('company_id', $instance->company_id)
            ->where('role', 'admin')
            ->where('active', true)
            ->get();

        if ($admins->isEmpty()) {
            return;
        }

        Notification::send($admins, new SystemNotification(
            'WhatsApp desconectado',
            "La conexión de «{$instance->name}» ({$instance->display_phone_number}) dejó de responder en Meta. "
                .'No se están recibiendo ni enviando mensajes. Reconecta la cuenta desde Instancias.',
            'Sistema'
        ));
    }
}
