<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * La importación de contactos e historial de una instancia en coexistencia.
 *
 * Una fila por instancia, y sólo una: ver la migración para por qué el candado
 * importa tanto.
 */
class CoexistenceSync extends Model
{
    protected $table = 'coexistence_syncs';

    public const PENDIENTE  = 'pendiente';
    public const SOLICITADA = 'solicitada';
    public const IMPORTANDO = 'importando';
    public const COMPLETADA = 'completada';
    public const RECHAZADA  = 'rechazada';
    public const FALLIDA    = 'fallida';

    /** Meta avisa así de que el negocio eligió no compartir sus chats. */
    public const ERROR_SIN_HISTORIAL = '2593109';

    protected $fillable = [
        'instance_id',
        'status',
        'contacts_request_id',
        'history_request_id',
        'phase',
        'progress',
        'contacts_imported',
        'messages_imported',
        'conversations_touched',
        'requested_at',
        'first_chunk_at',
        'last_chunk_at',
        'completed_at',
        'error_code',
        'error_message',
    ];

    /**
     * Los valores por defecto viven aquí y no sólo en la migración.
     *
     * `firstOrCreate` construye el modelo con lo que se le pasa: las columnas
     * con DEFAULT en la base quedan como **null** en el objeto recién creado,
     * no como 0. Comparar `0 === null` es falso, y con eso la barra de progreso
     * se quedaba clavada en cero durante toda la importación.
     */
    protected $attributes = [
        'status'                => self::PENDIENTE,
        'phase'                 => 0,
        'progress'              => 0,
        'contacts_imported'     => 0,
        'messages_imported'     => 0,
        'conversations_touched' => 0,
    ];

    protected $casts = [
        'phase'                 => 'integer',
        'progress'              => 'integer',
        'contacts_imported'     => 'integer',
        'messages_imported'     => 'integer',
        'conversations_touched' => 'integer',
        'requested_at'          => 'datetime',
        'first_chunk_at'        => 'datetime',
        'last_chunk_at'         => 'datetime',
        'completed_at'          => 'datetime',
    ];

    public function instance(): BelongsTo
    {
        return $this->belongsTo(Instance::class);
    }

    /**
     * ¿Sigue viva la ventana en la que Meta acepta la importación?
     *
     * Son 24 horas desde que termina el registro insertado. Pasadas, no hay
     * segundo intento: hay que desconectar el número y rehacer el proceso.
     */
    public function ventanaAbierta(): bool
    {
        $inicio = $this->requested_at ?? $this->created_at;

        return $inicio !== null && $inicio->diffInHours(now()) < 24;
    }

    public function terminada(): bool
    {
        return in_array($this->status, [self::COMPLETADA, self::RECHAZADA, self::FALLIDA], true);
    }

    /**
     * Porcentaje que se le enseña al cliente.
     *
     * Meta reporta el progreso **por fase**, así que un 100 en la fase 0 no es
     * el final de nada. Se reparte el total entre las tres fases para que la
     * barra avance de forma monótona en vez de saltar a 100 y volver a 0.
     */
    public function porcentajeGlobal(): int
    {
        if ($this->status === self::COMPLETADA) {
            return 100;
        }

        if (in_array($this->status, [self::PENDIENTE, self::SOLICITADA], true)) {
            return 0;
        }

        $porFase = 100 / 3;

        return (int) min(99, round($this->phase * $porFase + ($this->progress / 100) * $porFase));
    }

    /**
     * Lo que la pantalla necesita para pintar la tarjeta de progreso.
     *
     * Es la misma forma por websocket y por la consulta de respaldo, para que el
     * cliente no tenga que traducir según por dónde llegó: si el websocket no
     * conecta —proxies, redes de oficina—, la barra sigue avanzando igual.
     */
    public function paraPantalla(): array
    {
        return [
            'instance_id'   => $this->instance_id,
            'status'        => $this->status,
            'porcentaje'    => $this->porcentajeGlobal(),
            'etapa'         => $this->etiquetaFase(),
            'fase'          => $this->phase,
            'contactos'     => $this->contacts_imported,
            'mensajes'      => $this->messages_imported,
            'conversaciones' => $this->conversations_touched,
            'terminada'     => $this->terminada(),
            'error'         => $this->status === self::RECHAZADA
                // El cliente eligió no compartir sus chats. No es un fallo, y
                // decírselo como error rojo lo manda a soporte sin motivo.
                ? 'No se compartió el historial de chats desde el celular. Las conversaciones nuevas sí entrarán con normalidad.'
                : ($this->status === self::FALLIDA ? $this->error_message : null),
        ];
    }

    /** Etiqueta legible de la fase, para la pantalla del cliente. */
    public function etiquetaFase(): string
    {
        return match ($this->status) {
            self::PENDIENTE, self::SOLICITADA => 'Preparando la importación',
            self::COMPLETADA => 'Importación terminada',
            self::RECHAZADA  => 'El historial no se compartió',
            self::FALLIDA    => 'La importación falló',
            default => match ($this->phase) {
                0 => 'Chats de hoy',
                1 => 'Últimos 3 meses',
                default => 'Hasta 6 meses atrás',
            },
        };
    }
}
