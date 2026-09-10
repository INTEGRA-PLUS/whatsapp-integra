<?php

namespace App\Http\Controllers;

use App\Models\InstagramDeletionRequest;
use App\Services\MetaWhatsAppService;
use App\Support\SolicitudFirmadaDeMeta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Las dos obligaciones legales del canal de Instagram: desautorización y
 * eliminación de datos.
 *
 * No son trámite de papel. Meta avisa por aquí cuando alguien quita el acceso a
 * la app o pide que se borren sus datos, y **el revisor del App Review visita
 * las dos URL**: si no contestan 200, la solicitud se rechaza antes de mirar
 * nada más.
 *
 * Ambas aceptan GET además del POST firmado que manda Meta. El POST es el aviso
 * de verdad; el GET existe porque quien las visita con un navegador —el
 * revisor, o el propio usuario siguiendo el enlace— merece una página que
 * explique qué es esto, y no un 405.
 */
class InstagramPrivacidadController extends Controller
{
    public function __construct(private MetaWhatsAppService $metaService) {}

    /**
     * El usuario retiró el acceso a la app desde Instagram.
     *
     * Se responde 200 siempre que la firma cuadre: Meta no espera cuerpo y
     * reintenta si recibe un error, así que devolver 500 porque la cuenta no
     * estaba conectada sólo genera ruido.
     */
    public function desautorizar(Request $request)
    {
        if ($request->isMethod('get')) {
            return $this->pagina(
                'Desautorización de Instagram',
                'Esta dirección la usa Instagram para avisarnos cuando retiras el acceso de tu cuenta a Integra CRM. No hay nada que hacer aquí: el aviso llega solo.'
            );
        }

        $solicitud = $this->abrirSolicitud($request);

        if (! $solicitud) {
            return response()->json(['error' => 'firma_invalida'], 400);
        }

        $usuario = $solicitud->usuario();

        Log::channel('instagram')->info('🔌 Instagram retiró el acceso de una cuenta', [
            'usuario' => $usuario,
        ]);

        // Aquí irá la desconexión de la línea en cuanto el OAuth de Business
        // Login guarde tokens: hoy no hay ninguno que revocar, y escribir una
        // desconexión contra un modelo que todavía no existe sería inventarse
        // el comportamiento.

        return response()->json(['status' => 'ok'], 200);
    }

    /**
     * El usuario pidió que borremos sus datos.
     *
     * Meta exige responder con `url` y `confirmation_code`, y que esa URL sirva
     * para consultar el estado. Por eso la petición se guarda: un código que no
     * se recuerda no se puede consultar después.
     */
    public function eliminarDatos(Request $request)
    {
        if ($request->isMethod('get')) {
            return $this->pagina(
                'Eliminación de datos de Instagram',
                'Esta dirección la usa Instagram para pedirnos el borrado de los datos de una cuenta. Si quieres consultar una petición, abre el enlace con el código de confirmación que te dimos.'
            );
        }

        $solicitud = $this->abrirSolicitud($request);

        if (! $solicitud) {
            return response()->json(['error' => 'firma_invalida'], 400);
        }

        $usuario = $solicitud->usuario();

        if ($usuario === null) {
            return response()->json(['error' => 'sin_usuario'], 400);
        }

        $peticion = InstagramDeletionRequest::abrirPara($usuario);

        Log::channel('instagram')->info('🧹 Petición de borrado de datos de Instagram', [
            'usuario' => $usuario,
            'codigo' => $peticion->confirmation_code,
        ]);

        return response()->json([
            'url' => route('instagram.eliminar-datos.estado', $peticion->confirmation_code),
            'confirmation_code' => $peticion->confirmation_code,
        ], 200);
    }

    /**
     * La página que Meta obliga a ofrecer para consultar cómo va un borrado.
     */
    public function estadoDeBorrado(string $codigo)
    {
        $peticion = InstagramDeletionRequest::where('confirmation_code', $codigo)->first();

        if (! $peticion) {
            return $this->pagina(
                'Petición no encontrada',
                'No tenemos ninguna petición de borrado con ese código de confirmación.',
                404
            );
        }

        $estado = $peticion->estaCompletada()
            ? 'Completada el '.$peticion->completed_at->format('d/m/Y').'.'
            : 'Recibida el '.$peticion->created_at->format('d/m/Y').' y en curso.';

        return $this->pagina('Petición de borrado '.$codigo, $estado);
    }

    /**
     * Abre el `signed_request` con la clave secreta de la app de Instagram.
     *
     * Es la de «Integra CRM-IG», no la de Facebook: son apps distintas y firman
     * distinto. Sale de META_APP_SECRETS por su App ID.
     */
    private function abrirSolicitud(Request $request): ?SolicitudFirmadaDeMeta
    {
        $appId = config('services.meta.instagram.app_id');

        if (! is_string($appId) || $appId === '') {
            Log::channel('instagram')->error('❌ Sin META_IG_APP_ID: no se puede validar la firma de la solicitud');

            return null;
        }

        return SolicitudFirmadaDeMeta::abrir(
            $request->input('signed_request'),
            $this->metaService->appSecretForAppId($appId)
        );
    }

    private function pagina(string $titulo, string $texto, int $estado = 200)
    {
        $titulo = e($titulo);
        $texto = e($texto);

        return response(<<<HTML
        <!doctype html>
        <html lang="es">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>{$titulo} · Integra CRM</title>
            <style>
                body { margin: 0; display: grid; place-items: center; min-height: 100vh;
                       font: 16px/1.6 system-ui, sans-serif; color: #1c2430; background: #f6f7f9; }
                main { max-width: 34rem; padding: 2.5rem; }
                h1 { font-size: 1.35rem; margin: 0 0 .75rem; }
                p { margin: 0; color: #55606f; }
            </style>
        </head>
        <body><main><h1>{$titulo}</h1><p>{$texto}</p></main></body>
        </html>
        HTML, $estado)->header('Content-Type', 'text/html; charset=UTF-8');
    }
}
