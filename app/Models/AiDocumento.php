<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * Un documento que una empresa subió para que su IA sepa de qué habla.
 *
 * El fichero vive en el disco `ai_documentos`, que es privado: aquí entran
 * reglamentos y tarifarios internos, y `s3_media` —el disco de las imágenes de
 * WhatsApp— se sirve desde una URL pública.
 *
 * **Borrar la fila borra el fichero.** Va en `booted()` y no en el controlador
 * porque hay más de un camino que borra —el botón de la pantalla, el borrado en
 * cascada al eliminar una empresa, un `delete()` desde tinker— y un fichero
 * huérfano en disco no lo echa nadie de menos hasta que el disco se llena.
 */
class AiDocumento extends Model
{
    protected $table = 'ai_documentos';

    /** Lo que se acepta subir. Ver `App\Support\Documentos\ExtraerTexto`. */
    public const EXTENSIONES = ['pdf', 'docx', 'xlsx', 'csv', 'txt'];

    /** Cinco por empresa, que es lo que se pidió. */
    public const MAXIMO_POR_EMPRESA = 5;

    /** 10 MB. Por encima, el que sufre es el worker que lo procesa. */
    public const MAXIMO_KB = 10240;

    protected $fillable = [
        'company_id',
        'nombre',
        'extension',
        'bytes',
        'ruta',
        'estado',
        'motivo',
        'fragmentos',
        'usos',
        'ultimo_uso_at',
        'subido_por',
    ];

    protected $casts = [
        'bytes' => 'integer',
        'fragmentos' => 'integer',
        'usos' => 'integer',
        'ultimo_uso_at' => 'datetime',
    ];

    /**
     * A partir de cuándo conviene que alguien lo mire otra vez.
     *
     * Seis meses. Un tarifario caducado que nadie borró es **peor que no tener
     * nada**: la IA va a citar precios que ya no existen, y con la misma
     * seguridad con la que cita los buenos. No se bloquea ni se deja de usar
     * —eso sería decidir por el cliente— pero se dice.
     */
    public const MESES_HASTA_REVISAR = 6;

    public function esAntiguo(): bool
    {
        return $this->created_at !== null
            && $this->created_at->lt(now()->subMonths(self::MESES_HASTA_REVISAR));
    }

    protected static function booted(): void
    {
        static::deleting(function (self $documento) {
            Storage::disk('ai_documentos')->delete($documento->ruta);
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function fragmentosDelDocumento(): HasMany
    {
        return $this->hasMany(AiFragmento::class, 'ai_documento_id');
    }

    public function subidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subido_por');
    }

    public function estaListo(): bool
    {
        return $this->estado === 'listo';
    }

    /**
     * Cómo se lee el tamaño en la pantalla.
     *
     * En la pantalla y no en el frontend porque el mismo número aparece en el
     * log y en los mensajes de error, y tres formateos distintos del mismo dato
     * es cómo acaban sin cuadrar entre sí.
     */
    public function tamano(): string
    {
        return $this->bytes >= 1048576
            ? round($this->bytes / 1048576, 1).' MB'
            : max(1, (int) round($this->bytes / 1024)).' KB';
    }
}
