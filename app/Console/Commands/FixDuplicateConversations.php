<?php

namespace App\Console\Commands;

use App\Models\WhatsAppCall;
use App\Models\WhatsAppCallPermission;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Une los hilos que quedaron partidos por la forma de escribir el número y deja
 * todos los `wa_id` en su forma canónica (solo dígitos).
 *
 * El síntoma que repara: un aviso saliente creó el hilo con el número tal como
 * lo tenía el sistema externo ("57300 825 3303"), el cliente respondió, y el
 * webhook —que recibe el número limpio— abrió un segundo hilo. El agente seguía
 * mirando el primero y no veía nunca la respuesta.
 *
 * **No toca los BSUID.** Desde que Meta permite ocultar el número, un cliente
 * puede identificarse con "CO.1402615141764490", y ese identificador no es un
 * teléfono mal escrito: quitarle el prefijo lo convierte en un número que no
 * existe y deja al cliente inalcanzable. Este comando nació antes que los
 * BSUID y los trataba como formato sucio: el 19-sep-2026 una simulación en
 * producción proponía "normalizar" 618 conversaciones, y las 618 eran BSUID
 * —ni una sola era un número con espacios—. Con `--apply` habría roto las 618.
 *
 * Solo simula, salvo que se pase --apply.
 */
class FixDuplicateConversations extends Command
{
    protected $signature = 'whatsapp:fix-duplicate-conversations
        {--apply : Aplica los cambios (sin esta opción solo se simula)}
        {--instance= : Limita la revisión a una instancia}';

    protected $description = 'Une hilos duplicados del mismo cliente y normaliza los números guardados';

    /**
     * Un teléfono no lleva letras y un BSUID siempre las lleva: el prefijo de
     * país va delante del punto ("CO.", "US.ENT."). Es la frontera entre lo que
     * este comando puede reescribir y lo que no debe tocar jamás.
     */
    private const SOLO_TELEFONOS = "wa_id NOT REGEXP '[A-Za-z]'";

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $this->line($apply
            ? '<comment>MODO REAL: se van a modificar datos.</comment>'
            : '<info>Simulación. Añade --apply para ejecutar los cambios.</info>');
        $this->newLine();

        $merged = $this->mergeDuplicates($apply);
        $normalized = $this->normalizeRemaining($apply);

        $this->newLine();
        $this->info(sprintf(
            '%s: %d hilos unidos, %d números normalizados.',
            $apply ? 'Aplicado' : 'Se aplicaría',
            $merged,
            $normalized
        ));

        if (! $apply && ($merged || $normalized)) {
            $this->newLine();
            $this->line('Para ejecutarlo: <comment>php artisan whatsapp:fix-duplicate-conversations --apply</comment>');
        }

        return self::SUCCESS;
    }

    /**
     * Grupos de hilos de una misma instancia cuyos números, ya normalizados,
     * son el mismo. Se conserva uno y los demás se vuelcan en él.
     */
    private function mergeDuplicates(bool $apply): int
    {
        $groups = DB::table('whatsapp_conversations')
            ->selectRaw("instance_id, REGEXP_REPLACE(wa_id, '[^0-9]', '') as digits, COUNT(*) as total")
            // Agrupar por dígitos junta cosas que no son la misma: "CO.573001"
            // y el teléfono "573001" dan el mismo grupo, y fusionarlos mezcla
            // dos clientes distintos en un hilo. Los BSUID quedan fuera.
            ->whereRaw(self::SOLO_TELEFONOS)
            ->when($this->option('instance'), fn ($q, $id) => $q->where('instance_id', $id))
            ->groupBy('instance_id', 'digits')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($groups->isEmpty()) {
            $this->line('No hay hilos duplicados por variantes del número.');
            return 0;
        }

        $this->line("Hilos duplicados: {$groups->count()} grupo(s).");
        $mergedCount = 0;

        foreach ($groups as $group) {
            $conversations = WhatsAppConversation::where('instance_id', $group->instance_id)
                ->whereRaw("REGEXP_REPLACE(wa_id, '[^0-9]', '') = ?", [$group->digits])
                ->whereRaw(self::SOLO_TELEFONOS)
                ->withCount('messages')
                ->get();

            $survivor = $this->pickSurvivor($conversations);
            $losers = $conversations->reject(fn ($c) => $c->id === $survivor->id);

            $this->line(sprintf(
                '  instancia %s · %s → se conserva #%d (%d mensajes, CRM %s)',
                $group->instance_id,
                $group->digits,
                $survivor->id,
                $survivor->messages_count,
                $survivor->kanban_column_id ?? 'sin columna'
            ));

            foreach ($losers as $loser) {
                $this->line(sprintf(
                    '      absorbe #%d ("%s", %d mensajes)',
                    $loser->id,
                    $loser->wa_id,
                    $loser->messages_count
                ));

                if ($apply) {
                    $this->absorb($survivor, $loser);
                }

                $mergedCount++;
            }

            if ($apply) {
                $this->refreshSurvivor($survivor, $group->digits);
            }
        }

        return $mergedCount;
    }

    /**
     * Se queda el hilo que más contexto tiene: primero el que está en una columna
     * del CRM (es el que el equipo está mirando), luego el de más mensajes y, a
     * igualdad, el más antiguo.
     */
    private function pickSurvivor($conversations): WhatsAppConversation
    {
        return $conversations
            ->sortByDesc(fn ($c) => [
                $c->kanban_column_id ? 1 : 0,
                $c->messages_count,
                -$c->id,
            ])
            ->first();
    }

    private function absorb(WhatsAppConversation $survivor, WhatsAppConversation $loser): void
    {
        DB::transaction(function () use ($survivor, $loser) {
            WhatsAppMessage::where('conversation_id', $loser->id)
                ->update(['conversation_id' => $survivor->id]);

            // El historial de llamadas cuelga del hilo con ON DELETE SET NULL:
            // si no se reasigna antes de borrar, las llamadas no dan error pero
            // quedan sin conversación y desaparecen del chat para siempre.
            WhatsAppCall::where('conversation_id', $loser->id)
                ->update(['conversation_id' => $survivor->id]);

            // Las etiquetas del hilo absorbido se conservan, sin duplicar las que
            // el superviviente ya tenía. Se hace por la relación para no depender
            // del nombre de las columnas de la tabla pivote.
            $tagIds = $loser->tags()->pluck('tags.id')->all();

            if ($tagIds) {
                $survivor->tags()->syncWithoutDetaching($tagIds);
            }

            $loser->tags()->detach();

            $survivor->unread_count += $loser->unread_count;

            // El hilo absorbido puede traer datos que al superviviente le faltan.
            $survivor->assigned_to ??= $loser->assigned_to;
            $survivor->contact_id ??= $loser->contact_id;
            $survivor->kanban_column_id ??= $loser->kanban_column_id;

            if ($loser->last_message_at && (! $survivor->last_message_at || $loser->last_message_at->gt($survivor->last_message_at))) {
                $survivor->last_message_at = $loser->last_message_at;
                $survivor->last_message = $loser->last_message;
            }

            // Si el cliente escribió en cualquiera de los dos, el hilo está vivo.
            if ($loser->status === 'open') {
                $survivor->status = 'open';
            }

            $survivor->save();

            $loser->delete();
        });
    }

    /**
     * Deja el superviviente con el número canónico y su último mensaje al día.
     */
    private function refreshSurvivor(WhatsAppConversation $survivor, string $digits): void
    {
        // El último que vio el cliente, no el último aviso del hilo: esto va a
        // parar al `last_message` que se lee en la lista de chats.
        $last = WhatsAppMessage::where('conversation_id', $survivor->id)
            ->visiblesParaElCliente()
            ->orderByDesc('created_at')
            ->first();

        $survivor->forceFill([
            'wa_id'           => $digits,
            'phone_number'    => $digits,
            'last_message'    => $last?->content ?: $survivor->last_message,
            'last_message_at' => $last?->created_at ?? $survivor->last_message_at,
        ])->save();

        $this->syncCallPermissions($survivor->instance_id, $digits, $survivor->id);
    }

    /**
     * Deja un único permiso de llamada para el número, con el wa_id canónico.
     *
     * Los permisos se buscan por `(instance_id, wa_id)`, no por conversación:
     * al reescribir el número del hilo, un permiso guardado bajo la forma con
     * espacios dejaría de encontrarse y el botón de llamar desaparecería aunque
     * el cliente sí hubiera dado permiso.
     */
    private function syncCallPermissions(int $instanceId, string $digits, ?int $conversationId): void
    {
        $permissions = WhatsAppCallPermission::where('instance_id', $instanceId)
            ->whereRaw("REGEXP_REPLACE(wa_id, '[^0-9]', '') = ?", [$digits])
            ->get();

        if ($permissions->isEmpty()) {
            return;
        }

        // Se conserva el permiso más útil: uno vigente manda sobre uno caducado
        // y, a igualdad, el más reciente. Los demás sobran y además chocarían
        // con el índice único (instance_id, wa_id).
        $keep = $permissions
            ->sortByDesc(fn ($p) => [$p->isActive() ? 1 : 0, $p->requested_at?->getTimestamp() ?? 0])
            ->first();

        $permissions->reject(fn ($p) => $p->id === $keep->id)->each->delete();

        $keep->forceFill([
            'wa_id'           => $digits,
            'conversation_id' => $conversationId ?? $keep->conversation_id,
        ])->save();
    }

    /**
     * Los hilos que no chocan con nadie solo necesitan que su número quede en
     * forma canónica, para que el webhook los encuentre a la primera.
     */
    private function normalizeRemaining(bool $apply): int
    {
        $pending = WhatsAppConversation::whereRaw("wa_id REGEXP '[^0-9]' OR phone_number REGEXP '[^0-9]'")
            ->whereRaw(self::SOLO_TELEFONOS)
            ->when($this->option('instance'), fn ($q, $id) => $q->where('instance_id', $id))
            ->get(['id', 'instance_id', 'wa_id', 'phone_number'])
            // Y una segunda vuelta con el criterio del modelo, que es el que
            // manda: la condición SQL es un atajo para no traerse miles de
            // filas, no la definición de lo que es un BSUID.
            ->reject(fn ($c) => WhatsAppConversation::isBsuid($c->wa_id)
                || WhatsAppConversation::isBsuid($c->phone_number))
            ->values();

        if ($pending->isEmpty()) {
            $this->line('Todos los números guardados ya están en forma canónica.');
            return 0;
        }

        $this->newLine();
        $this->line("Números por normalizar: {$pending->count()}");

        foreach ($pending->take(10) as $conversation) {
            $this->line(sprintf(
                '  #%d "%s" → "%s"',
                $conversation->id,
                $conversation->wa_id,
                WhatsAppConversation::normalizePhone($conversation->wa_id)
            ));
        }

        if ($pending->count() > 10) {
            $this->line('  … y ' . ($pending->count() - 10) . ' más.');
        }

        if (! $apply) {
            return $pending->count();
        }

        $count = 0;

        foreach ($pending as $conversation) {
            $digits = WhatsAppConversation::normalizePhone($conversation->wa_id);

            if ($digits === '') {
                $this->warn("  #{$conversation->id} no tiene ningún dígito, se deja como está.");
                continue;
            }

            // El merge previo debería haber despejado los choques, pero si
            // aparece uno nuevo se salta en vez de romper el índice único.
            $clash = WhatsAppConversation::where('instance_id', $conversation->instance_id)
                ->where('wa_id', $digits)
                ->where('id', '!=', $conversation->id)
                ->exists();

            if ($clash) {
                $this->warn("  #{$conversation->id} choca con otro hilo ya normalizado, se salta.");
                continue;
            }

            DB::table('whatsapp_conversations')
                ->where('id', $conversation->id)
                ->update([
                    'wa_id'        => $digits,
                    'phone_number' => WhatsAppConversation::normalizePhone($conversation->phone_number) ?: $digits,
                ]);

            // El permiso de llamada se busca por wa_id, así que tiene que seguir
            // al hilo cuando le cambia el número.
            $this->syncCallPermissions($conversation->instance_id, $digits, $conversation->id);

            $count++;
        }

        return $count;
    }
}
