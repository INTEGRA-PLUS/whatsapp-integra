<?php

namespace App\Support;

use App\Models\Company;
use App\Models\User;
use App\Services\AgentAssignmentService;
use Illuminate\Support\Facades\Log;

/**
 * A quién le llega el chat cuando la IA se rinde o el cliente pide un humano.
 *
 * Hasta ahora esto no se elegía: el traspaso iba **siempre** al asesor menos
 * cargado, escrito a fuego en `ProcessWhatsAppMenu`. Funciona para un equipo de
 * cinco personas que hacen lo mismo, y no para una empresa donde soporte técnico
 * y cartera son dos mundos — ahí el chat de una factura acaba en manos del que
 * instala antenas, sólo porque tenía un hueco.
 *
 * Las opciones de menú sí se podían configurar desde el principio. Esto pone el
 * traspaso de la IA al mismo nivel, y con una más: repartir dentro de un equipo.
 *
 * ## Las cuatro
 *
 * - **`menos_cargado`** — el que menos conversaciones abiertas tiene. Es lo que
 *   se hacía antes y sigue siendo el valor por defecto.
 * - **`equipo`** — el menos cargado, pero sólo entre los que se elijan. Es la
 *   que faltaba, y probablemente la que más se va a usar.
 * - **`fijo`** — una persona concreta.
 * - **`bandeja`** — nadie. El chat queda sin dueño, visible para todos.
 *
 * ## Por qué `fijo` y `equipo` caen al menos cargado si no hay nadie
 *
 * Porque la alternativa es el silencio, que es exactamente lo que este
 * mecanismo existe para evitar. Si la persona elegida se dio de baja, o el
 * equipo entero quedó inactivo, el chat tiene que llegarle **a alguien**: un
 * cliente esperando no se entera de que había un error de configuración.
 *
 * Se apunta en el log cuando pasa, porque es un síntoma de que alguien tiene
 * que ir a la pantalla a arreglarlo.
 *
 * **`bandeja` no cae a ningún sitio**: ahí no hay error que salvar, es una
 * decisión — hay empresas donde nadie quiere chats asignados y todos miran la
 * misma lista.
 */
class TraspasoAUnAsesor
{
    public const MENOS_CARGADO = 'menos_cargado';

    public const EQUIPO = 'equipo';

    public const FIJO = 'fijo';

    public const BANDEJA = 'bandeja';

    public const ESTRATEGIAS = [self::MENOS_CARGADO, self::EQUIPO, self::FIJO, self::BANDEJA];

    /** Tope de personas en un equipo. Más que eso es «todos», y para eso está `menos_cargado`. */
    public const MAX_EQUIPO = 25;

    /**
     * Cómo lo tiene configurado esta empresa, ya saneado.
     *
     * @return array{estrategia: string, usuario_id: ?int, equipo: list<int>}
     */
    public static function de(Company $company): array
    {
        $guardado = (array) (($company->settings ?? [])['traspaso'] ?? []);

        $estrategia = (string) ($guardado['estrategia'] ?? self::MENOS_CARGADO);

        if (! in_array($estrategia, self::ESTRATEGIAS, true)) {
            $estrategia = self::MENOS_CARGADO;
        }

        return [
            'estrategia' => $estrategia,
            'usuario_id' => isset($guardado['usuario_id']) ? (int) $guardado['usuario_id'] : null,
            'equipo' => collect((array) ($guardado['equipo'] ?? []))
                ->map(fn ($id) => (int) $id)
                ->filter()
                ->unique()
                ->take(self::MAX_EQUIPO)
                ->values()
                ->all(),
        ];
    }

    /**
     * Guarda la preferencia sin pisar el resto de `settings`.
     *
     * `settings` es un cajón compartido —ahí viven también los avisos de plan y
     * la instancia del ERP— así que asignarlo entero borraría lo de al lado.
     */
    public static function guardar(Company $company, array $datos): array
    {
        $limpio = self::de(new Company(['settings' => ['traspaso' => $datos]]));

        // Sólo se guarda lo que la estrategia elegida usa: dejar el equipo
        // escrito cuando se pasó a `fijo` hace que la pantalla enseñe al volver
        // una selección que no se está aplicando.
        $guardar = ['estrategia' => $limpio['estrategia']];

        if ($limpio['estrategia'] === self::FIJO) {
            $guardar['usuario_id'] = $limpio['usuario_id'];
        }

        if ($limpio['estrategia'] === self::EQUIPO) {
            $guardar['equipo'] = $limpio['equipo'];
        }

        $company->settings = array_merge($company->settings ?? [], ['traspaso' => $guardar]);
        $company->save();

        return self::de($company);
    }

    /**
     * El asesor que debe recibir este chat, o `null` para dejarlo sin dueño.
     *
     * Se le pasa el servicio de reparto en vez de resolverlo aquí porque el job
     * que llama ya lo tiene inyectado, y porque así esto se puede probar sin
     * tocar el contenedor.
     */
    public static function asesorPara(Company $company, AgentAssignmentService $reparto): ?User
    {
        $config = self::de($company);

        return match ($config['estrategia']) {
            self::BANDEJA => null,
            self::FIJO => self::elElegido($company, $config['usuario_id'], $reparto),
            self::EQUIPO => self::delEquipo($company, $config['equipo'], $reparto),
            default => $reparto->leastBusy($company->id),
        };
    }

    private static function elElegido(Company $company, ?int $usuarioId, AgentAssignmentService $reparto): ?User
    {
        $elegido = $usuarioId
            ? User::where('company_id', $company->id)
                ->where('id', $usuarioId)
                ->where('active', true)
                ->first()
            : null;

        if ($elegido) {
            return $elegido;
        }

        Log::channel('whatsapp')->warning('⚠️ El asesor fijo del traspaso no puede recibir chats', [
            'empresa' => $company->id,
            'usuario' => $usuarioId,
            'motivo' => 'no existe, no es de la empresa o está inactivo',
        ]);

        return $reparto->leastBusy($company->id);
    }

    private static function delEquipo(Company $company, array $equipo, AgentAssignmentService $reparto): ?User
    {
        if ($equipo !== [] && $asesor = $reparto->leastBusy($company->id, $equipo)) {
            return $asesor;
        }

        Log::channel('whatsapp')->warning('⚠️ El equipo del traspaso no tiene a nadie disponible', [
            'empresa' => $company->id,
            'equipo' => $equipo,
        ]);

        return $reparto->leastBusy($company->id);
    }

    /** Cómo se lee en una pantalla o en una nota de sistema. */
    public static function comoTexto(array $config): string
    {
        return match ($config['estrategia']) {
            self::BANDEJA => 'nadie: queda en la bandeja general',
            self::FIJO => 'una persona concreta',
            self::EQUIPO => 'el menos cargado de un equipo',
            default => 'el asesor menos cargado',
        };
    }
}
