<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\SuscripcionCobro;
use App\Services\OnePayClient;
use App\Support\Suscripcion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Emitir cobros de suscripción y darlos por pagados, desde el panel maestro.
 *
 * Es la mano humana sobre el mismo mecanismo que usará IntegraPay. Cuando llegue
 * la pasarela, el pago lo confirmará un webhook en vez de una persona — y por
 * eso quien alarga el periodo es `Suscripcion::pagar()` y no este controlador:
 * la lógica está donde los dos caminos la comparten.
 *
 * Mientras tanto esto no es un apaño temporal: sigue haciendo falta el día que
 * haya pasarela, para el cliente que paga por transferencia, el que negocia un
 * importe distinto y el cobro que hay que anular porque se emitió mal.
 */
class SuscripcionController extends Controller
{
    /**
     * POST /master/companies/{company}/suscripcion/emitir
     *
     * Emite el cobro del próximo periodo y lo pone delante del cliente en
     * OnePay. Queda **pendiente**: la suscripción no se alarga hasta que la
     * pasarela confirme el pago, o hasta que alguien lo marque a mano.
     *
     * Si no hay pasarela configurada, el cobro se emite igual y sólo vive en el
     * CRM — que es como se operaba antes y como se sigue operando con el cliente
     * que paga por transferencia.
     */
    public function emitir(Request $request, Company $company)
    {
        $this->authorizeMaster();

        $datos = $request->validate([
            'nota' => 'nullable|string|max:300',
        ]);

        // Un pendiente sin pagar ya cubre este periodo. Emitir otro encima deja
        // dos cobros por lo mismo, y el día que se paguen los dos la suscripción
        // se alarga el doble — que es un error que se descubre tarde y en
        // contra.
        $pendiente = SuscripcionCobro::where('company_id', $company->id)
            ->where('estado', 'pendiente')
            ->exists();

        if ($pendiente) {
            return back()->with('error', 'Ya tiene un cobro pendiente. Págalo o anúlalo antes de emitir otro.');
        }

        $cobro = Suscripcion::emitir($company, $request->user()->id, $datos['nota'] ?? null);

        // Y se pone delante del cliente, si hay pasarela. Va aquí y no dentro de
        // `Suscripcion::emitir()` para que el servicio siga sin saber de HTTP:
        // emitir tiene que funcionar igual sin token, que es como se operaba
        // antes de OnePay y como se seguirá operando para el que paga por
        // transferencia.
        $enOnePay = OnePayClient::crearFactura($cobro);

        Log::channel('whatsapp')->info('💳 Cobro de suscripción emitido', [
            'empresa' => $company->id,
            'cobro' => $cobro->id,
            'importe' => $cobro->importe_usd,
            'por' => $request->user()->id,
        ]);

        return back()->with('success', "Cobro de \${$cobro->importe_usd} emitido"
            .($enOnePay ? ' y enviado a OnePay.' : '. No se envió a OnePay: revisa el log.'));
    }

    /**
     * POST /master/cobros/{cobro}/pagar
     *
     * Da el cobro por pagado y alarga la suscripción.
     */
    public function pagar(Request $request, SuscripcionCobro $cobro)
    {
        $this->authorizeMaster();

        $datos = $request->validate([
            // La referencia del pago fuera del sistema: el número de la
            // transferencia, el comprobante de IntegraPay. No es obligatoria
            // —hay pagos que llegan sin número— pero es única, así que el mismo
            // comprobante no se puede aplicar dos veces.
            'referencia' => 'nullable|string|max:120',
        ]);

        if (! Suscripcion::pagar($cobro, $datos['referencia'] ?? null)) {
            return back()->with('error', 'Ese cobro ya estaba pagado, o esa referencia ya se usó.');
        }

        Log::channel('whatsapp')->info('💳 Cobro de suscripción pagado', [
            'empresa' => $cobro->company_id,
            'cobro' => $cobro->id,
            'hasta' => $cobro->periodo_hasta->toDateString(),
            'por' => $request->user()->id,
        ]);

        return back()->with('success', 'Pagado. La suscripción llega hasta el '
            .$cobro->periodo_hasta->format('d/m/Y').'.');
    }

    /**
     * POST /master/cobros/{cobro}/anular
     *
     * Para el cobro emitido por error. No se borra: un cobro que desaparece es
     * un cobro que nadie puede explicar tres meses después.
     */
    public function anular(Request $request, SuscripcionCobro $cobro)
    {
        $this->authorizeMaster();

        if ($cobro->estaPagado()) {
            return back()->with('error', 'Un cobro pagado no se anula: eso es una devolución y se hace fuera.');
        }

        $cobro->update(['estado' => 'anulado']);

        return back()->with('success', 'Cobro anulado.');
    }

    /** Sólo el master toca esto: es dinero. */
    private function authorizeMaster(): void
    {
        abort_unless(auth()->user()?->isMaster(), 403);
    }
}
