<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class WebhookEndpoint extends Model
{
    use HasFactory;

    protected $table = 'webhook_endpoints';

    protected $fillable = [
        'company_id',
        'name',
        'url',
        'secret',
        'events',
        'headers',
        'active',
        'created_by',
    ];

    protected $casts = [
        'events'  => 'array',
        'headers' => 'array',
        'active'  => 'boolean',
    ];

    /**
     * La salud viaja siempre con el webhook: la pantalla la necesita tanto al
     * listar como después de crear o editar uno, y calcularla sólo en el
     * listado dejaba la tarjeta recién guardada sin ella.
     */
    protected $appends = ['health'];

    public function getHealthAttribute(): array
    {
        return $this->deliveryHealth();
    }

    protected static function booted(): void
    {
        static::creating(function (WebhookEndpoint $endpoint) {
            if (empty($endpoint->secret)) {
                $endpoint->secret = Str::random(48);
            }
        });
    }

    public function deliveries()
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function scopeForCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function subscribesTo(string $event): bool
    {
        return in_array($event, $this->events ?? [], true);
    }

    /**
     * Si de verdad está entregando, no si está encendido.
     *
     * "ACTIVO" en verde sólo dice que el interruptor está puesto, y con eso un
     * webhook puede llevar meses mandando avisos a una puerta que no abre sin
     * que nadie se entere. Pasó: uno apuntaba a la web de Integra en vez de a
     * una ruta que recibiera POSTs y acumuló 111 entregas, todas con 405,
     * mientras la pantalla lo mostraba en verde.
     *
     * @return array{state: string, total: int, ok: int, failed: int, last_code: ?int,
     *               last_at: ?string, last_error: ?string, says: string, fix: ?string}
     */
    public function deliveryHealth(): array
    {
        $stats = $this->deliveries()
            ->selectRaw('count(*) as total, sum(success) as ok')
            ->first();

        $total = (int) ($stats->total ?? 0);
        $ok = (int) ($stats->ok ?? 0);
        $last = $this->deliveries()->latest('id')->first();

        // Sin entregas no se puede opinar: puede estar recién creado, o
        // suscrito a un evento que todavía no ha ocurrido nunca.
        if ($total === 0) {
            return [
                'state' => 'idle',
                'total' => 0, 'ok' => 0, 'failed' => 0,
                'last_code' => null, 'last_at' => null, 'last_error' => null,
                'says' => 'Todavía no se ha disparado ninguna vez.',
                'fix' => 'Ocurrirá cuando pase por primera vez el evento al que está suscrito. Puedes probarlo ahora con el botón de enviar.',
            ];
        }

        $failed = $total - $ok;
        $healthy = (bool) ($last->success ?? false);

        return [
            'state' => $healthy ? 'ok' : 'failing',
            'total' => $total,
            'ok' => $ok,
            'failed' => $failed,
            'last_code' => $last->status_code,
            'last_at' => optional($last->created_at)->toIso8601String(),
            'last_error' => $last->error,
            'says' => $healthy
                ? 'La última entrega salió bien.'
                : 'La última entrega falló' . ($last->status_code ? ' con HTTP ' . $last->status_code : ' sin respuesta') . '.',
            'fix' => $healthy ? null : self::hintForStatus($last->status_code),
        ];
    }

    /**
     * Qué significa el código que devolvió su servidor, en su idioma.
     *
     * El 405 se lleva la explicación más larga a propósito: es el que sale
     * cuando alguien pega la URL de una web en vez de la de un endpoint, y es
     * con diferencia el error más común de los que hemos visto.
     */
    public static function hintForStatus(?int $code): string
    {
        return match (true) {
            $code === 405 => 'Esa URL existe pero no acepta POST: casi siempre significa que apunta a una página web y no a una ruta preparada para recibir eventos. Pide a tu proveedor la ruta que escucha los webhooks.',
            $code === 404 => 'La ruta no existe en tu servidor. Revisa la URL, o pide que la creen.',
            $code === 401 || $code === 403 => 'Tu servidor rechazó la petición. Si exige autenticación, añade la cabecera que necesite; la firma X-Webhook-Signature va siempre.',
            $code === 410 => 'Tu servidor dice que esa ruta ya no existe.',
            $code !== null && $code >= 500 => 'Tu servidor recibió el aviso pero falló al procesarlo. El error es de tu lado; lo reintentamos igualmente.',
            $code !== null && $code >= 300 && $code < 400 => 'Tu servidor redirige a otra dirección. Pon la definitiva aquí: no seguimos redirecciones.',
            default => 'No hubo respuesta: el servidor no contestó a tiempo o la dirección no es alcanzable desde internet.',
        };
    }
}
