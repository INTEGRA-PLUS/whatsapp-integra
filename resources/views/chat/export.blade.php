@php
    use Illuminate\Support\Carbon;

    $contactName = $conversation->contact?->name
        ?: ($conversation->name ?: $conversation->phone_number);

    // Autor de una salida sin agente: la mandó la plataforma (plantilla del ERP,
    // automatismo). En el chat esa línea va vacía, pero en una transcripción
    // impresa "Sistema" haría pasar por automático lo que escribió alguien; el
    // nombre de la empresa dice lo único que consta: salió por esta línea.
    $autorSaliente = $conversation->instance?->company?->name
        ?: ($conversation->instance?->name ?: 'Nuestra línea');

    $tituloArchivo = trim('Conversacion - ' . $contactName . ' - ' . now()->format('Y-m-d'));

    $estados = [
        'open'    => 'Abierta',
        'closed'  => 'Cerrada',
        'pending' => 'Pendiente',
    ];

    $diasSemana = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
    $meses = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
              'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    /** Separador de día tal y como se lee en el chat: "Hoy", "Ayer" o la fecha larga. */
    $etiquetaDia = function (?Carbon $fecha) use ($diasSemana, $meses) {
        if (! $fecha) {
            return 'Sin fecha';
        }
        if ($fecha->isToday()) {
            return 'Hoy';
        }
        if ($fecha->isYesterday()) {
            return 'Ayer';
        }

        return $diasSemana[(int) $fecha->format('w')] . ', '
            . $fecha->format('j') . ' de ' . $meses[(int) $fecha->format('n')]
            . ' de ' . $fecha->format('Y');
    };

    /**
     * Descripción textual de lo que no es texto plano.
     *
     * El PDF no lleva los adjuntos incrustados, así que cada uno deja constancia
     * de qué se envió y con qué nombre: sin esta línea, un hilo lleno de fotos
     * y documentos sale como una sucesión de burbujas vacías.
     */
    $adjunto = function ($msg) {
        $nombre = $msg->filename ?: null;

        $etiquetas = [
            'image'    => 'Imagen',
            'video'    => 'Video',
            'audio'    => 'Nota de voz / audio',
            'sticker'  => 'Sticker',
            'document' => 'Documento',
        ];

        if (isset($etiquetas[$msg->type])) {
            return $etiquetas[$msg->type] . ($nombre ? ' — ' . $nombre : '');
        }

        if ($msg->type === 'location') {
            $loc = $msg->metadata['location'] ?? [];
            $partes = array_filter([
                $loc['name'] ?? null,
                $loc['address'] ?? null,
                isset($loc['latitude'], $loc['longitude']) ? $loc['latitude'] . ', ' . $loc['longitude'] : null,
            ]);

            return 'Ubicación' . ($partes ? ' — ' . implode(' · ', $partes) : ' compartida');
        }

        if ($msg->type === 'contacts') {
            $contactos = collect($msg->metadata['contacts'] ?? [])
                ->map(function ($c) {
                    $nom = $c['name']['formatted_name'] ?? 'Contacto';
                    $tels = collect($c['phones'] ?? [])->pluck('phone')->filter()->implode(', ');

                    return $tels ? $nom . ' (' . $tels . ')' : $nom;
                })
                ->filter()
                ->implode(' · ');

            return 'Contacto' . ($contactos ? ' — ' . $contactos : ' compartido');
        }

        if ($msg->type === 'template') {
            return 'Plantilla de WhatsApp' . ($nombre ? ' — ' . $nombre : '');
        }

        if ($msg->type === 'unsupported') {
            return 'Mensaje no soportado (WhatsApp no entrega su contenido)';
        }

        return null;
    };
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $tituloArchivo }}</title>
    <style>
        @page {
            size: A4;
            margin: 14mm 12mm;
        }

        * { box-sizing: border-box; }

        html, body {
            margin: 0;
            padding: 0;
            background: #e9e4dd;
            color: #111b21;
            font-family: "Segoe UI", "Helvetica Neue", Helvetica, Arial,
                         "Apple Color Emoji", "Segoe UI Emoji", "Noto Color Emoji", sans-serif;
            font-size: 12px;
            line-height: 1.45;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .hoja {
            max-width: 820px;
            margin: 0 auto;
            padding: 24px 20px 48px;
        }

        /* --- Barra de acción: sólo en pantalla --- */
        .barra {
            position: sticky;
            top: 0;
            z-index: 10;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            background: #111b21;
            color: #fff;
            padding: 10px 18px;
            font-size: 12.5px;
        }
        .barra button {
            font: inherit;
            font-weight: 700;
            color: #fff;
            background: #128c7e;
            border: 0;
            border-radius: 6px;
            padding: 8px 16px;
            cursor: pointer;
        }
        .barra button:hover { background: #0f7a6d; }

        /* --- Encabezado --- */
        .cabecera {
            background: #fff;
            border: 1px solid #d9d3cb;
            border-radius: 10px;
            padding: 16px 18px;
            margin-bottom: 18px;
        }
        .cabecera h1 {
            margin: 0 0 2px;
            font-size: 18px;
            line-height: 1.25;
        }
        .cabecera .telefono {
            margin: 0 0 12px;
            font-size: 12.5px;
            color: #667781;
        }

        .datos {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 10px 18px;
            border-top: 1px solid #eee;
            padding-top: 12px;
        }
        .datos .campo .rotulo {
            display: block;
            font-size: 9px;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: #8696a0;
            margin-bottom: 2px;
        }
        .datos .campo .valor { font-size: 12px; }

        .pastilla {
            display: inline-block;
            border-radius: 999px;
            padding: 1px 9px;
            font-size: 10.5px;
            font-weight: 700;
        }
        .pastilla.abierta { background: #d8f3e3; color: #0b6b4f; }
        .pastilla.cerrada { background: #e4e7ea; color: #4a5459; }

        .etiquetas { display: flex; flex-wrap: wrap; gap: 5px; }
        .etiqueta {
            border-radius: 4px;
            padding: 1px 8px;
            font-size: 10.5px;
            font-weight: 600;
            color: #fff;
        }

        /* --- Hilo --- */
        .dia {
            display: flex;
            justify-content: center;
            margin: 18px 0 12px;
        }
        .dia span {
            background: #d5dbdc;
            color: #43555c;
            border-radius: 8px;
            padding: 3px 12px;
            font-size: 10.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        .fila { display: flex; margin-bottom: 8px; }
        .fila.entrante { justify-content: flex-start; }
        .fila.saliente { justify-content: flex-end; }
        .fila.centrada  { justify-content: center; }

        .burbuja {
            max-width: 74%;
            border-radius: 8px;
            padding: 7px 11px;
            border: 1px solid rgba(0, 0, 0, .07);
            page-break-inside: avoid;
            break-inside: avoid;
        }
        .fila.entrante .burbuja { background: #fff; border-top-left-radius: 0; }
        .fila.saliente .burbuja { background: #dcf8c6; border-top-right-radius: 0; }

        .autor {
            display: block;
            font-size: 10.5px;
            font-weight: 700;
            color: #0b7a6a;
            margin-bottom: 2px;
        }
        .fila.entrante .autor { color: #7a4fbf; }

        .texto {
            margin: 0;
            white-space: pre-wrap;
            word-wrap: break-word;
            overflow-wrap: anywhere;
            font-size: 12.5px;
        }

        .adjunto {
            display: inline-block;
            margin: 0 0 3px;
            padding: 4px 9px;
            border-radius: 5px;
            background: rgba(0, 0, 0, .055);
            font-size: 11.5px;
            font-weight: 600;
            color: #3b4a51;
        }

        .hora {
            display: block;
            text-align: right;
            margin-top: 2px;
            font-size: 9.5px;
            color: #667781;
            white-space: nowrap;
        }

        /* Nota interna: nunca salió a WhatsApp, y en papel eso tiene que
           notarse a simple vista. */
        .burbuja.nota {
            background: #fdf3d3;
            border: 1px solid #e6cf8a;
            border-radius: 8px;
        }
        .nota .marca {
            display: block;
            font-size: 9px;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: #9a7411;
            margin-bottom: 3px;
        }

        .aviso {
            max-width: 78%;
            background: #fdf4d8;
            border: 1px solid #ecd9a4;
            border-radius: 7px;
            padding: 5px 12px;
            font-size: 11px;
            color: #54656f;
            text-align: center;
            page-break-inside: avoid;
            break-inside: avoid;
        }

        .vacio {
            text-align: center;
            color: #667781;
            padding: 40px 0;
            font-size: 12.5px;
        }

        .pie {
            margin-top: 24px;
            padding-top: 10px;
            border-top: 1px solid #d9d3cb;
            text-align: center;
            font-size: 10px;
            color: #8696a0;
        }

        @media print {
            html, body { background: #fff; }
            .barra { display: none !important; }
            .hoja { max-width: none; margin: 0; padding: 0; }
            .cabecera { border-color: #ccc; }
        }
    </style>
</head>
<body>
    <div class="barra">
        <span>Vista para imprimir · elige <strong>Guardar como PDF</strong> en el destino de impresión.</span>
        <button type="button" onclick="window.print()">Guardar como PDF</button>
    </div>

    <div class="hoja">
        <div class="cabecera">
            <h1>{{ $contactName }}</h1>
            <p class="telefono">{{ $conversation->phone_number ?: $conversation->wa_id }}</p>

            <div class="datos">
                <div class="campo">
                    <span class="rotulo">Estado</span>
                    <span class="valor">
                        <span class="pastilla {{ $conversation->status === 'closed' ? 'cerrada' : 'abierta' }}">
                            {{ $estados[$conversation->status] ?? ucfirst($conversation->status) }}
                        </span>
                    </span>
                </div>
                <div class="campo">
                    <span class="rotulo">Agente asignado</span>
                    <span class="valor">{{ $conversation->assignedAgent?->name ?: 'Sin asignar' }}</span>
                </div>
                <div class="campo">
                    <span class="rotulo">Línea</span>
                    <span class="valor">{{ $conversation->instance?->name ?: '—' }}</span>
                </div>
                <div class="campo">
                    <span class="rotulo">Mensajes</span>
                    <span class="valor">{{ $total }}</span>
                </div>
                <div class="campo">
                    <span class="rotulo">Periodo</span>
                    <span class="valor">
                        @if ($total > 0)
                            {{ $days->first()->first()->created_at?->format('d/m/Y') }}
                            —
                            {{ $days->last()->last()->created_at?->format('d/m/Y') }}
                        @else
                            —
                        @endif
                    </span>
                </div>
                <div class="campo">
                    <span class="rotulo">Etiquetas</span>
                    <span class="valor">
                        @if ($conversation->tags->isNotEmpty())
                            <span class="etiquetas">
                                @foreach ($conversation->tags as $tag)
                                    <span class="etiqueta" style="background: {{ $tag->color ?: '#667781' }}">{{ $tag->name }}</span>
                                @endforeach
                            </span>
                        @else
                            Sin etiquetas
                        @endif
                    </span>
                </div>
            </div>
        </div>

        @forelse ($days as $dia => $mensajes)
            <div class="dia"><span>{{ $etiquetaDia($mensajes->first()->created_at) }}</span></div>

            @foreach ($mensajes as $msg)
                @php
                    $esSaliente = $msg->direction === 'outbound';
                    $descripcion = $adjunto($msg);
                    $hora = $msg->created_at?->format('H:i') ?? '';
                @endphp

                @if ($msg->type === 'system')
                    <div class="fila centrada">
                        <div class="aviso">{{ $msg->content }} <span style="opacity:.6">· {{ $hora }}</span></div>
                    </div>
                @elseif ($msg->is_internal)
                    <div class="fila centrada">
                        <div class="burbuja nota" style="max-width: 78%">
                            <span class="marca">Nota interna · {{ $msg->sender?->name ?: $autorSaliente }}</span>
                            @if ($descripcion)
                                <span class="adjunto">{{ $descripcion }}</span>
                            @endif
                            @if ($msg->content)
                                <p class="texto">{{ $msg->content }}</p>
                            @endif
                            <span class="hora">{{ $hora }}</span>
                        </div>
                    </div>
                @else
                    <div class="fila {{ $esSaliente ? 'saliente' : 'entrante' }}">
                        <div class="burbuja">
                            <span class="autor">
                                {{ $esSaliente ? ($msg->sender?->name ?: $autorSaliente) : $contactName }}
                            </span>
                            @if ($descripcion)
                                <span class="adjunto">{{ $descripcion }}</span>
                            @endif
                            @if ($msg->content)
                                <p class="texto">{{ $msg->content }}</p>
                            @elseif (! $descripcion)
                                <p class="texto" style="opacity:.55; font-style: italic">(Sin contenido)</p>
                            @endif
                            <span class="hora">{{ $hora }}</span>
                        </div>
                    </div>
                @endif
            @endforeach
        @empty
            <p class="vacio">Esta conversación todavía no tiene mensajes.</p>
        @endforelse

        <p class="pie">
            Exportado por {{ $exportedBy }} el {{ now()->format('d/m/Y \a \l\a\s H:i') }} · {{ config('app.name') }}
        </p>
    </div>

    <script>
        // El diálogo se abre solo: quien pulsa "Exportar a PDF" ya pidió el PDF,
        // hacerle buscar el botón otra vez sobra. Si lo cancela, la vista queda
        // en pantalla con el botón para reintentar.
        window.addEventListener('load', function () {
            window.setTimeout(function () { window.print(); }, 250);
        });
    </script>
</body>
</html>
