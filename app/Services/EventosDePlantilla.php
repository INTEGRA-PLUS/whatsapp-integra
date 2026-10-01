<?php

namespace App\Services;

use App\Models\Instance;
use App\Models\User;
use App\Models\WhatsAppCampaign;
use App\Notifications\PlantillaDeMetaNotification;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Lo que Meta hace con las plantillas de una WABA, contado por webhook.
 *
 * ## Por qué existe
 *
 * Hasta el 1-oct-2026 el webhook descartaba sin leerlos los cuatro campos de
 * plantillas. Una plantilla que Meta pausaba por quejas seguía figurando como
 * aprobada en tres sitios —la caché del guardarraíl, el estado del respaldo
 * fuera de ventana y las campañas— y lo único que veía la empresa era una
 * campaña entera fallando con 132015, destinatario a destinatario, sin que
 * nadie le dijera por qué ni hasta cuándo. Y la pantalla de Ajustes prometía
 * «Necesario para recibir aprobación/rechazo de plantillas» de un webhook que
 * no hacía nada con ellos.
 *
 * ## Qué hace con cada aviso
 *
 * - Siempre: tira la caché del catálogo que usa `TemplateParameterGuard`, para
 *   que el siguiente envío valide contra la plantilla que hay ahora.
 * - Si es la plantilla de respaldo de alguna instancia: apunta el estado nuevo.
 * - PAUSED / DISABLED / REJECTED: pausa las campañas que estaban enviándola y
 *   avisa a los administradores con el motivo en español.
 * - Cambio de categoría: avisa, porque marketing cuesta más que utilidad.
 *
 * ## Reglas que no hay que romper
 *
 * `entry.id` es la WABA, no el número: el aviso vale para todas las
 * instancias con ese `waba_id`, de todas las empresas que la tengan conectada.
 * Y un aviso de una plantilla que no conocemos no puede lanzar excepción: el
 * webhook respondería 500 y Meta lo reintentaría durante siete días.
 */
class EventosDePlantilla
{
    /** Los campos del webhook que trae Meta sobre plantillas. */
    public const CAMPOS = [
        'message_template_status_update',
        'message_template_quality_update',
        'template_category_update',
        'message_template_components_update',
    ];

    /** Estados que dejan la plantilla sin poder enviarse. */
    public const ESTADOS_QUE_BLOQUEAN = ['PAUSED', 'DISABLED', 'REJECTED'];

    /**
     * Meta reintenta un webhook hasta siete días: la marca de «ya lo procesé»
     * tiene que durar más que eso.
     */
    private const DIAS_DE_MEMORIA = 8;

    private const MOTIVOS = [
        'ABUSIVE_CONTENT' => 'Meta considera que el contenido es abusivo o infringe sus políticas.',
        'CATEGORY_NOT_AVAILABLE' => 'La categoría elegida no está disponible para esta cuenta.',
        'INCORRECT_CATEGORY' => 'La categoría no corresponde al contenido (por ejemplo, un texto promocional marcado como utilidad).',
        'INVALID_FORMAT' => 'El formato de la plantilla no es válido (variables mal puestas, ejemplos que faltan o texto que no cumple las reglas).',
        'PROMOTIONAL' => 'El contenido es promocional y la categoría elegida no lo admite.',
        'SCAM' => 'Meta la marcó como posible estafa.',
        'TAG_CONTENT_MISMATCH' => 'El contenido no coincide con la categoría o etiqueta elegida.',
    ];

    private const CATEGORIAS = [
        'MARKETING' => 'marketing',
        'UTILITY' => 'utilidad',
        'AUTHENTICATION' => 'autenticación',
    ];

    public function __construct(private WhatsAppFallbackTemplateService $respaldo) {}

    /**
     * Punto de entrada desde el webhook. Idempotente: el mismo aviso
     * reintentado por Meta no vuelve a pausar ni a notificar.
     */
    public function procesar(string $campo, ?string $wabaId, $time, array $value): void
    {
        $plantilla = $value['message_template_name'] ?? null;
        $idioma = $value['message_template_language'] ?? null;

        Log::channel('whatsapp')->info('🧾 Aviso de plantilla de Meta', [
            'campo' => $campo,
            'waba_id' => $wabaId,
            'template' => $plantilla,
            'template_id' => $value['message_template_id'] ?? null,
            'language' => $idioma,
            'event' => $value['event'] ?? null,
            'reason' => $value['reason'] ?? null,
            'new_category' => $value['new_category'] ?? null,
            'new_quality_score' => $value['new_quality_score'] ?? null,
        ]);

        if (! $wabaId) {
            return;
        }

        // La caché del guardarraíl se tira aunque el aviso no sea de nadie
        // conocido o ya se haya procesado: tirarla dos veces no cuesta nada, y
        // es lo que hace que el siguiente envío no valide contra un catálogo
        // de hace diez minutos.
        Cache::forget("wa:templates:{$wabaId}");

        if (! $plantilla) {
            return;
        }

        $clave = 'wa:evento-plantilla:'.sha1(implode('|', [
            $campo,
            $wabaId,
            $value['message_template_id'] ?? $plantilla,
            $idioma,
            $this->discriminante($campo, $value),
            $time,
        ]));

        if (! Cache::add($clave, true, now()->addDays(self::DIAS_DE_MEMORIA))) {
            Log::channel('whatsapp')->info('ℹ️ Aviso de plantilla repetido, ya se procesó', [
                'campo' => $campo,
                'template' => $plantilla,
            ]);

            return;
        }

        try {
            $instancias = Instance::with('company')->where('waba_id', $wabaId)->get();

            if ($instancias->isEmpty()) {
                Log::channel('whatsapp')->info('ℹ️ Aviso de plantilla de una WABA que no tiene ninguna instancia', [
                    'waba_id' => $wabaId,
                ]);

                return;
            }

            match ($campo) {
                'message_template_status_update' => $this->cambioDeEstado($instancias, $plantilla, $idioma, $value),
                'template_category_update' => $this->cambioDeCategoria($instancias, $plantilla, $idioma, $value),
                'message_template_quality_update' => $this->cambioDeCalidad($instancias, $plantilla, $idioma, $value),
                'message_template_components_update' => $this->cambioDeContenido($instancias, $plantilla, $idioma),
                default => null,
            };
        } catch (\Throwable $e) {
            // Si algo falla a medias, que el reintento de Meta lo vuelva a
            // intentar entero: sin esto la marca impediría reprocesarlo.
            Cache::forget($clave);

            throw $e;
        }
    }

    /* ------------------------------ Estado ------------------------------ */

    private function cambioDeEstado(Collection $instancias, string $plantilla, ?string $idioma, array $value): void
    {
        $evento = strtoupper((string) ($value['event'] ?? ''));

        if ($evento === '') {
            return;
        }

        $detalle = $this->explicacion($evento, $value);

        $respaldo = $this->actualizarRespaldo($instancias, $plantilla, $idioma, $evento, $detalle);
        $reanudacion = $this->instanciasConReanudacion($instancias, $plantilla, $idioma);

        $bloquea = in_array($evento, self::ESTADOS_QUE_BLOQUEAN, true);
        $pausadas = $bloquea ? $this->pausarCampanas($instancias, $plantilla, $idioma, $evento) : collect();

        if (! $bloquea && $evento !== 'FLAGGED') {
            return;
        }

        $titulo = match ($evento) {
            'PAUSED' => "Meta pausó la plantilla «{$plantilla}»",
            'DISABLED' => "Meta desactivó la plantilla «{$plantilla}»",
            'REJECTED' => "Meta rechazó la plantilla «{$plantilla}»",
            'FLAGGED' => "La plantilla «{$plantilla}» está en riesgo",
        };

        foreach ($instancias->groupBy('company_id') as $companyId => $deLaEmpresa) {
            $partes = [$detalle];

            $campanas = $pausadas->whereIn('instance_id', $deLaEmpresa->pluck('id'));
            if ($campanas->isNotEmpty()) {
                $partes[] = 'Se pausaron las campañas que la estaban enviando: '
                    .$campanas->pluck('name')->map(fn ($n) => "«{$n}»")->implode(', ')
                    .'. Reanúdalas cuando la plantilla vuelva a estar aprobada, o cámbiales la plantilla.';
            }

            if ($respaldo->whereIn('id', $deLaEmpresa->pluck('id'))->isNotEmpty()) {
                $partes[] = 'Es la plantilla de respaldo de los avisos fuera de la ventana de 24 h: '
                    .'mientras no esté aprobada, esos avisos no se entregan.';
            }

            if ($reanudacion->whereIn('id', $deLaEmpresa->pluck('id'))->isNotEmpty()) {
                $partes[] = 'Es la plantilla con la que se reabren los chats: mientras no esté aprobada, no se puede retomar a un cliente que lleve más de 24 h sin escribir.';
            }

            $this->avisar($companyId, $deLaEmpresa, $titulo, implode(' ', array_filter($partes)), $plantilla);
        }
    }

    /**
     * El motivo en español, con lo que Meta añada de su puño y letra. El texto
     * de Meta va tal cual (viene en inglés) porque es lo que hay que leer para
     * corregir la plantilla o apelar.
     */
    private function explicacion(string $evento, array $value): string
    {
        $reason = strtoupper((string) ($value['reason'] ?? ''));
        $motivo = self::MOTIVOS[$reason] ?? null;

        $rechazo = $value['rejection_info'] ?? [];
        $otro = $value['other_info'] ?? [];

        $extra = trim(implode(' ', array_filter([
            $rechazo['reason'] ?? null,
            isset($rechazo['recommendation']) ? 'Recomendación: '.$rechazo['recommendation'] : null,
            $otro['description'] ?? null,
        ])));

        $texto = match ($evento) {
            'PAUSED' => 'Los clientes la marcaron como spam o la bloquearon y Meta la pausó '
                .$this->duracionDePausa($otro).'. Mientras tanto no se puede enviar.',
            'DISABLED' => 'Meta la desactivó por baja calidad después de varias pausas. Ya no se puede enviar: hay que crear otra.'
                .$this->fechaDeDesactivacion($value),
            'REJECTED' => 'Meta no la aprobó.'.($motivo ? ' Motivo: '.$motivo : '')
                .' Puedes corregirla y volver a enviarla, o apelar desde el Administrador de WhatsApp.',
            'FLAGGED' => 'Su calidad bajó a roja. Si no mejora en los próximos días, Meta la desactivará.',
            default => '',
        };

        if ($evento !== 'REJECTED' && $motivo) {
            $texto .= ' Motivo: '.$motivo;
        }

        return trim($texto.($extra !== '' ? " (Meta: {$extra})" : ''));
    }

    /**
     * La primera pausa dura 3 horas y la segunda 6; una tercera ya es la
     * desactivación. Meta lo dice en `other_info.title`.
     */
    private function duracionDePausa(array $otro): string
    {
        return match (strtoupper((string) ($otro['title'] ?? ''))) {
            'FIRST_PAUSE' => 'durante 3 horas',
            'SECOND_PAUSE' => 'durante 6 horas (es la segunda pausa: a la tercera la desactiva)',
            default => 'durante unas horas',
        };
    }

    private function fechaDeDesactivacion(array $value): string
    {
        $fecha = $value['disable_info']['disable_date'] ?? null;

        if (! is_numeric($fecha)) {
            return '';
        }

        return ' Fecha: '.Carbon::createFromTimestamp((int) $fecha, config('app.timezone'))->format('d/m/Y H:i').'.';
    }

    /**
     * Pausa con el mismo estado que el botón «Pausar» de la campaña: los
     * destinatarios pendientes se quedan pendientes, y `SendCampaignMessage`
     * deja de enviar en cuanto ve `paused_at`. Seguir enviando sólo convertía
     * cada destinatario en un 132015 cobrado como intento.
     */
    private function pausarCampanas(Collection $instancias, string $plantilla, ?string $idioma, string $evento): Collection
    {
        $campanas = WhatsAppCampaign::whereIn('instance_id', $instancias->pluck('id'))
            ->where('template_name', $plantilla)
            ->whereIn('status', ['queued', 'sending'])
            ->get()
            ->filter(fn ($c) => ! $idioma || ! $c->template_language
                || WhatsAppFallbackTemplateService::idiomaComparable($c->template_language)
                    === WhatsAppFallbackTemplateService::idiomaComparable($idioma));

        foreach ($campanas as $campana) {
            $campana->update(['status' => 'paused', 'paused_at' => now()]);

            Log::channel('whatsapp')->warning('⏸️ Campaña pausada: Meta bloqueó su plantilla', [
                'campaign_id' => $campana->id,
                'company_id' => $campana->company_id,
                'template' => $plantilla,
                'event' => $evento,
            ]);
        }

        return $campanas->values();
    }

    /* ---------------------- Respaldo y reanudación ---------------------- */

    /**
     * Apunta el estado nuevo en las instancias cuya plantilla de respaldo es
     * esta. Devuelve las instancias afectadas.
     */
    private function actualizarRespaldo(Collection $instancias, string $plantilla, ?string $idioma, string $evento, ?string $detalle): Collection
    {
        return $instancias->filter(function (Instance $instancia) use ($plantilla, $idioma, $evento, $detalle) {
            if (! $this->respaldo->esLaDeRespaldo($instancia, $plantilla, $idioma)) {
                return false;
            }

            $this->respaldo->registrarEventoDeMeta($instancia, $evento, $detalle);

            return true;
        })->values();
    }

    /**
     * La plantilla de reanudación no guarda estado: se elige por nombre y se
     * envía cuando el agente la pide. Aquí sólo se identifica para decirlo en
     * el aviso.
     */
    private function instanciasConReanudacion(Collection $instancias, string $plantilla, ?string $idioma): Collection
    {
        return $instancias->filter(fn (Instance $i) => $i->resumeTemplateName() === $plantilla
            && (! $idioma || ! $i->resumeTemplateLanguage()
                || WhatsAppFallbackTemplateService::idiomaComparable($i->resumeTemplateLanguage())
                    === WhatsAppFallbackTemplateService::idiomaComparable($idioma)))
            ->values();
    }

    /* ---------------------------- Categoría ----------------------------- */

    private function cambioDeCategoria(Collection $instancias, string $plantilla, ?string $idioma, array $value): void
    {
        $nueva = strtoupper((string) ($value['new_category'] ?? ''));
        $correcta = strtoupper((string) ($value['correct_category'] ?? ''));
        $anterior = strtoupper((string) ($value['previous_category'] ?? ''));

        // Hay dos avisos: el que anuncia el cambio (trae `correct_category` y
        // la fecha) y el que confirma que ya se hizo (trae `previous_category`).
        $inminente = $correcta !== '';
        $destino = $inminente ? $correcta : $nueva;
        $origen = $inminente ? $nueva : $anterior;

        foreach ($instancias as $instancia) {
            if ($this->respaldo->esLaDeRespaldo($instancia, $plantilla, $idioma)) {
                // Que la próxima consulta traiga la categoría nueva: una de
                // respaldo que pasa a marketing deja de servir para avisos.
                $this->respaldo->invalidar($instancia);
            }
        }

        if ($destino === '' || $destino === $origen) {
            return;
        }

        $cuando = '';
        if ($inminente && is_numeric($value['category_update_timestamp'] ?? null)) {
            $cuando = ' el '.Carbon::createFromTimestamp((int) $value['category_update_timestamp'], config('app.timezone'))
                ->format('d/m/Y H:i');
        }

        $de = self::CATEGORIAS[$origen] ?? strtolower($origen);
        $a = self::CATEGORIAS[$destino] ?? strtolower($destino);

        $cuerpo = $inminente
            ? "Meta revisó «{$plantilla}» y va a cambiar su categoría de {$de} a {$a}{$cuando}."
            : "Meta cambió la categoría de «{$plantilla}» de {$de} a {$a}.";

        if ($destino === 'MARKETING') {
            $cuerpo .= ' Las plantillas de marketing cuestan más por mensaje que las de utilidad, Meta limita cuántas recibe '
                .'cada cliente, y no sirven como plantilla de respaldo de los avisos automáticos.';
        }

        foreach ($instancias->groupBy('company_id') as $companyId => $deLaEmpresa) {
            $this->avisar($companyId, $deLaEmpresa, "Cambio de categoría de «{$plantilla}»", $cuerpo, $plantilla);
        }
    }

    /* ------------------------ Calidad y contenido ------------------------ */

    private function cambioDeCalidad(Collection $instancias, string $plantilla, ?string $idioma, array $value): void
    {
        $nueva = strtoupper((string) ($value['new_quality_score'] ?? ''));

        if ($nueva !== 'RED') {
            return;
        }

        $cuerpo = "La calidad de «{$plantilla}» bajó a roja: muchos clientes la están bloqueando o reportando. "
            .'Si sigue así Meta la pausará y, a la tercera pausa, la desactivará. Revisa a quién se la envías y con qué frecuencia.';

        foreach ($instancias->groupBy('company_id') as $companyId => $deLaEmpresa) {
            $this->avisar($companyId, $deLaEmpresa, "Calidad baja en «{$plantilla}»", $cuerpo, $plantilla);
        }
    }

    /**
     * Alguien editó la plantilla en Meta. No se avisa a nadie (lo hizo la
     * propia empresa), pero el cuerpo guardado del respaldo ya no es el que
     * Meta va a renderizar.
     */
    private function cambioDeContenido(Collection $instancias, string $plantilla, ?string $idioma): void
    {
        foreach ($instancias as $instancia) {
            if ($this->respaldo->esLaDeRespaldo($instancia, $plantilla, $idioma)) {
                $this->respaldo->invalidar($instancia);
            }
        }
    }

    /* ------------------------------ Avisos ------------------------------ */

    private function avisar($companyId, Collection $instancias, string $titulo, string $cuerpo, string $plantilla): void
    {
        $lineas = $instancias->map(fn ($i) => $i->display_phone_number ?: $i->name)->filter()->unique()->implode(', ');

        Log::channel('whatsapp')->warning('📣 Aviso de plantilla a los administradores', [
            'company_id' => $companyId,
            'template' => $plantilla,
            'titulo' => $titulo,
        ]);

        $admins = User::where('company_id', $companyId)
            ->where('role', 'admin')
            ->where('active', true)
            ->get();

        if ($admins->isEmpty()) {
            return;
        }

        Notification::send($admins, new PlantillaDeMetaNotification(
            $titulo,
            $cuerpo.($lineas !== '' ? " Línea: {$lineas}." : ''),
            $plantilla,
            $instancias->first()?->id
        ));
    }

    /** Lo que distingue dos avisos distintos de la misma plantilla en el mismo segundo. */
    private function discriminante(string $campo, array $value): string
    {
        return match ($campo) {
            'message_template_status_update' => (string) ($value['event'] ?? ''),
            'message_template_quality_update' => (string) ($value['new_quality_score'] ?? ''),
            'template_category_update' => ($value['correct_category'] ?? '').'>'.($value['new_category'] ?? ''),
            default => sha1(json_encode($value)),
        };
    }
}
