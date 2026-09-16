<?php

namespace App\Models;

use App\Casts\Vector;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un trozo de un documento: lo que de verdad se le manda al modelo.
 *
 * Al modelo no le llega el documento entero sino los cuatro o cinco fragmentos
 * que responden a lo que preguntó el cliente. Pegar los cinco documentos en cada
 * mensaje serían unos 60.000 tokens **por mensaje**, sobre el flujo más caro que
 * hay; los fragmentos elegidos son unos 1.500.
 *
 * `company_id` está aquí repetido —se podría deducir por el documento— porque
 * esta tabla se consulta en cada mensaje entrante y el aislamiento entre
 * empresas en este proyecto es manual. Con la columna, el filtro es un `where`;
 * sin ella, un `join` que alguien puede quitar sin notar que acaba de mezclar
 * dos empresas.
 *
 * `vector` llega nulo en la primera entrega: lo rellena la siguiente, que es la
 * que añade la búsqueda.
 */
class AiFragmento extends Model
{
    protected $table = 'ai_fragmentos';

    protected $fillable = [
        'ai_documento_id',
        'company_id',
        'origen',
        'orden',
        'texto',
        'vector',
    ];

    protected $casts = [
        'orden' => 'integer',
        'vector' => Vector::class,
    ];

    public function documento(): BelongsTo
    {
        return $this->belongsTo(AiDocumento::class, 'ai_documento_id');
    }
}
