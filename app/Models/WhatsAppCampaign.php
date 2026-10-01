<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WhatsAppCampaign extends Model
{
    use HasFactory;

    protected $table = 'whatsapp_campaigns';

    public const DAY_KEYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    protected $fillable = [
        'company_id',
        'instance_id',
        'created_by',
        'name',
        'message',
        'message_type',
        'template_name',
        'template_language',
        'template_components',
        'variable_map',
        'header_media_id',
        'header_media_url',
        'header_media_path',
        'header_media_mime',
        'header_filename',
        'status',
        'schedule_type',
        'schedule_days',
        'schedule_time',
        'schedule_timezone',
        'total_recipients',
        'rate_per_minute',
        'sent_count',
        'failed_count',
        'started_at',
        'completed_at',
        'last_run_at',
        'next_run_at',
        'paused_at',
        'cancelled_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'last_run_at' => 'datetime',
        'next_run_at' => 'datetime',
        'paused_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'schedule_days' => 'array',
        'template_components' => 'array',
        'variable_map' => 'array',
    ];

    public function instance()
    {
        return $this->belongsTo(Instance::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function recipients()
    {
        return $this->hasMany(WhatsAppCampaignRecipient::class, 'campaign_id');
    }

    public function pendingRecipients()
    {
        return $this->recipients()->where('status', 'pending');
    }

    public function isLaunchable(): bool
    {
        return $this->schedule_type === 'manual'
            && in_array($this->status, ['draft', 'failed', 'paused'], true)
            && $this->total_recipients > 0
            && $this->usesTemplate();
    }

    /**
     * Una campaña por plantilla es la única que WhatsApp entrega fuera de la
     * ventana de 24h. Las de texto libre creadas antes de este cambio se quedan
     * en borrador hasta que alguien les asigne una plantilla.
     */
    public function usesTemplate(): bool
    {
        return $this->message_type === 'template' && !empty($this->template_name);
    }

    /**
     * Estados que cuentan como "ya no hay nada que enviar aquí".
     */
    public const CLOSED_RECIPIENT_STATUSES = ['sent', 'delivered', 'read', 'failed', 'skipped'];

    /**
     * Recalcula los contadores desde las filas de los destinatarios.
     *
     * Se hacía con `increment()` a medida que se enviaba, y bastaba un job
     * reintentado para que el total dijera más envíos de los que hubo. La verdad
     * está en los destinatarios; los contadores son solo una copia rápida.
     */
    public function refreshCounters(): void
    {
        $counts = $this->recipients()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $sent = (int) ($counts['sent'] ?? 0) + (int) ($counts['delivered'] ?? 0) + (int) ($counts['read'] ?? 0);

        $this->forceFill([
            'sent_count'       => $sent,
            'failed_count'     => (int) ($counts['failed'] ?? 0),
            'total_recipients' => (int) $counts->sum(),
        ])->save();
    }

    /**
     * Cuántos destinatarios siguen sin resolverse.
     */
    public function outstandingCount(): int
    {
        return $this->recipients()->whereIn('status', ['pending', 'sending'])->count();
    }

    /**
     * La campaña termina cuando no queda nadie pendiente. Se decide en el
     * último job que acaba, y no en el que reparte: repartir es instantáneo,
     * enviar puede durar horas.
     *
     * Una pausada no se cierra aunque no quede nadie en cola: la pausa puede
     * venir de una plantilla que Meta paró (SendCampaignMessage), y cerrarla
     * borraría el único aviso de que hay que mirar la plantilla y reanudar.
     */
    public function cerrarSiTermino(): void
    {
        $this->refreshCounters();

        if ($this->outstandingCount() > 0 || in_array($this->status, ['cancelled', 'paused'], true)) {
            return;
        }

        $this->update([
            // Todo fallido es un fallo de la campaña; con entregas parciales el
            // detalle ya dice cuántas y por qué.
            'status'       => $this->sent_count === 0 ? 'failed' : 'completed',
            'completed_at' => now(),
            'last_run_at'  => now(),
        ]);
    }

    /**
     * Minutos que un destinatario puede pasar en "sending" sin wamid antes de
     * darlo por perdido. Un envío normal está ahí segundos; el job tiene
     * 120 s de tope. Quince minutos es que el worker murió con él en la mano.
     */
    public const MINUTOS_ATASCADO = 15;

    /**
     * Rescata los destinatarios que se quedaron en "sending" porque el worker
     * murió a medio envío (un despliegue, un OOM, un `queue:restart`).
     *
     * Antes no los miraba nadie: contaban como pendientes para siempre, la
     * campaña no se cerraba nunca y, si era recurrente, `campaigns:run-scheduled`
     * no la volvía a lanzar porque seguía "enviando".
     *
     * Si la burbuja del chat tiene wamid, Meta sí lo aceptó y sólo faltó
     * apuntarlo: se marca enviado. Si no, no se sabe si salió, y se marca
     * fallido con ese motivo en vez de reenviarlo solo: un "fallido" se puede
     * reintentar a mano; un mensaje duplicado no se puede retirar.
     *
     * @return int cuántos se rescataron
     */
    public static function rescatarEnviosAtascados(): int
    {
        $limite = now()->subMinutes(self::MINUTOS_ATASCADO);
        $campanas = [];
        $rescatados = 0;

        WhatsAppCampaignRecipient::where('status', 'sending')
            ->whereNull('wamid')
            ->where('updated_at', '<', $limite)
            ->orderBy('id')
            ->chunkById(200, function ($filas) use (&$campanas, &$rescatados, $limite) {
                foreach ($filas as $fila) {
                    $wamid = $fila->message_id
                        ? WhatsAppMessage::whereKey($fila->message_id)->value('wamid')
                        : null;

                    $cambio = $wamid
                        ? ['status' => 'sent', 'wamid' => $wamid, 'sent_at' => $fila->sent_at ?: now()]
                        : [
                            'status' => 'failed',
                            'error_message' => 'El envío se cortó a medias (se reinició el servidor de envíos) y no consta '
                                .'que WhatsApp lo recibiera. Revisa el chat del cliente antes de reintentarlo.',
                        ];

                    // Condicional otra vez: si el job seguía vivo y acaba de
                    // terminar, su resultado gana.
                    $hecho = WhatsAppCampaignRecipient::whereKey($fila->id)
                        ->where('status', 'sending')
                        ->whereNull('wamid')
                        ->where('updated_at', '<', $limite)
                        ->update($cambio + ['updated_at' => now()]);

                    if ($hecho) {
                        if (! $wamid && $fila->message_id) {
                            WhatsAppMessage::whereKey($fila->message_id)
                                ->where('status', 'pending')
                                ->whereNull('wamid')
                                ->update([
                                    'status' => 'failed',
                                    'failed_at' => now(),
                                    'error_message' => $cambio['error_message'],
                                ]);
                        }

                        $rescatados++;
                        $campanas[$fila->campaign_id] = true;
                    }
                }
            });

        foreach (array_keys($campanas) as $id) {
            static::find($id)?->cerrarSiTermino();
        }

        // Y las que se quedaron "enviando" sin nadie en cola: el último job
        // murió justo antes de cerrarla.
        static::where('status', 'sending')
            ->where('updated_at', '<', $limite)
            ->whereDoesntHave('recipients', fn ($q) => $q->whereIn('status', ['pending', 'sending']))
            ->get()
            ->each(fn (self $c) => $c->cerrarSiTermino());

        return $rescatados;
    }

    /**
     * Fallidos que tiene sentido volver a enviar.
     *
     * Fuera quedan:
     * - 131050: el cliente se dio de baja de los mensajes de marketing. Volver a
     *   intentarlo es escribir a quien pidió que no, y Meta lo vuelve a rechazar.
     * - 131049: Meta frenó el envío para no saturar al cliente con marketing.
     *   Reintentarlo antes de 24 h da el mismo rechazo y empeora la calidad del
     *   número; pasado ese plazo sí puede entrar.
     */
    public function fallidosReintentables()
    {
        return $this->recipients()
            ->where('status', 'failed')
            ->where(function ($q) {
                $q->whereNull('error_code')
                    ->orWhereNotIn('error_code', ['131050', '131049'])
                    ->orWhere(fn ($q) => $q->where('error_code', '131049')
                        ->where('updated_at', '<', now()->subDay()));
            });
    }

    public function isRecurring(): bool
    {
        return $this->schedule_type === 'recurring';
    }

    public function computeNextRun(?\Carbon\Carbon $from = null): ?\Carbon\Carbon
    {
        $days = $this->schedule_days ?: [];
        if (!$this->isRecurring() || empty($days) || !$this->schedule_time) {
            return null;
        }

        $tz = $this->schedule_timezone ?: config('app.timezone');
        $base = ($from ?? now())->copy()->setTimezone($tz);

        [$h, $m] = array_pad(explode(':', (string) $this->schedule_time), 2, 0);

        $dayMap = ['sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6];
        $allowed = [];
        foreach ($days as $d) {
            if (isset($dayMap[$d])) $allowed[] = $dayMap[$d];
        }
        if (empty($allowed)) return null;

        for ($i = 0; $i < 8; $i++) {
            $candidate = $base->copy()->addDays($i)->setTime((int) $h, (int) $m, 0);
            if (in_array($candidate->dayOfWeek, $allowed, true) && $candidate->gt($base)) {
                return $candidate->setTimezone(config('app.timezone'));
            }
        }
        return null;
    }
}
