<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\SystemNotification;
use App\Support\AvisosDePlan;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Avisa a quien lleva el negocio de lo que pasa con los planes.
 *
 * El sistema sabía desde el principio quién se había pasado de su plan y a quién
 * se le acababa el crédito de IA, pero había que entrar al panel a mirarlo. Un
 * aviso que exige que alguien se acuerde de abrir una pestaña no es un aviso: es
 * un informe que nadie lee.
 *
 * Va a la campana —`database` + `broadcast`— y no al correo porque es donde el
 * equipo ya mira: llega por websocket sin esperar al poll, y queda en el
 * historial de notificaciones para el que entró más tarde.
 *
 * ## A quién se avisa
 *
 * A los usuarios con rol `master`, que son los que pueden hacer algo al
 * respecto. Avisar al admin de la empresa afectada sería contarle a un cliente
 * que se pasó de lo que paga, que es una conversación que se tiene por teléfono
 * y no por una campanita.
 *
 * ## Por qué no repite
 *
 * Cada aviso lleva una firma de lo que lo causó, y se apunta al mandarlo. Sin
 * eso, «Megastore se pasó de agentes» saldría cada día hasta que alguien lo
 * resolviera — y una campana que repite es una campana que se ignora, arrastrando
 * consigo los avisos que sí son nuevos.
 */
class AvisarDePlanes extends Command
{
    protected $signature = 'planes:avisar
        {--dry : Enseña lo que avisaría y no manda nada}
        {--todos : Avisa también de lo ya avisado (para probar)}';

    protected $description = 'Avisa a los master de las empresas que se pasaron de plan o de crédito de IA';

    public function handle(): int
    {
        $avisos = AvisosDePlan::calcular();

        if (! $this->option('todos')) {
            $avisos = array_values(array_filter(
                $avisos,
                fn (array $a) => ! AvisosDePlan::yaAvisado($a['company'], $a['firma'])
            ));
        }

        if ($avisos === []) {
            $this->info('Nada que avisar.');

            return self::SUCCESS;
        }

        $this->table(
            ['Empresa', 'Motivo', 'Qué pasa'],
            array_map(fn (array $a) => [
                $a['company']->name,
                $a['motivo'],
                Str::limit($a['cuerpo'], 68),
            ], $avisos)
        );

        if ($this->option('dry')) {
            $this->newLine();
            $this->line(count($avisos).' avisos. Sin --dry se mandan a la campana.');

            return self::SUCCESS;
        }

        $masters = User::where('active', true)->get()->filter(fn (User $u) => $u->isMaster());

        if ($masters->isEmpty()) {
            // No es un error del comando: es que no hay a quién avisar. Se dice
            // y no se apunta nada, para que los avisos salgan cuando lo haya.
            $this->warn('No hay ningún master activo a quien avisar. No se ha apuntado nada.');

            return self::SUCCESS;
        }

        foreach ($avisos as $aviso) {
            Notification::send(
                $masters,
                new SystemNotification($aviso['titulo'], $aviso['cuerpo'], 'Planes')
            );

            // Se apunta DESPUÉS de mandarlo: si el envío revienta, el aviso
            // vuelve a salir mañana en vez de perderse en silencio.
            AvisosDePlan::apuntar($aviso['company'], $aviso['firma']);
        }

        $this->newLine();
        $this->info(count($avisos).' avisos mandados a '.$masters->count().' master.');

        return self::SUCCESS;
    }
}
