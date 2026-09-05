<?php

namespace Tests\Feature;

use App\Http\Controllers\WhatsAppWebhookController;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Qué se escribe en el log por cada webhook de Meta.
 *
 * Al suscribir los campos de coexistencia (history, smb_message_echoes,
 * smb_app_state_sync) el callback empezó a poder recibir la mensajería
 * completa de una empresa cliente: `history` trae hasta seis meses de
 * conversaciones. El log volcaba el payload entero de todo lo que llegara, así
 * que el primer onboarding habría escrito en disco, en claro, los chats de un
 * negocio ajeno — sobre un archivo que ya pesa decenas de MB al día.
 *
 * Lo que se protege aquí es el equilibrio: los webhooks de siempre se siguen
 * volcando enteros (es lo que permite diagnosticar entregas), y los de
 * coexistencia dejan rastro de que llegaron sin dejar el contenido.
 */
class CoexistenceWebhookLogTest extends TestCase
{
    public function test_los_webhooks_de_siempre_se_vuelcan_enteros(): void
    {
        $log = $this->payloadForLog([
            'entry' => [['changes' => [[
                'field' => 'messages',
                'value' => ['messages' => [['text' => ['body' => 'hola']]]],
            ]]]],
        ]);

        $this->assertStringContainsString('hola', $log);
    }

    /** El historial no se escribe: sólo que llegó y cuánto pesaba. */
    public function test_el_historial_no_deja_el_contenido_en_el_log(): void
    {
        $log = $this->payloadForLog([
            'entry' => [['changes' => [[
                'field' => 'history',
                'value' => ['messages' => [['text' => ['body' => 'dato privado del cliente']]]],
            ]]]],
        ]);

        $this->assertStringNotContainsString('dato privado del cliente', $log);
        $this->assertStringContainsString('history', $log);
        $this->assertStringContainsString('bytes', $log);
    }

    /** Lo mismo con el eco de lo que el negocio manda desde su celular. */
    public function test_el_eco_de_mensajes_tampoco(): void
    {
        $log = $this->payloadForLog([
            'entry' => [['changes' => [[
                'field' => 'smb_message_echoes',
                'value' => ['messages' => [['text' => ['body' => 'respuesta desde el celular']]]],
            ]]]],
        ]);

        $this->assertStringNotContainsString('respuesta desde el celular', $log);
    }

    /**
     * Un lote mixto se resume entero.
     *
     * Meta agrupa varios changes en una sola petición, así que basta un
     * `history` en el lote para que volcarlo todo filtre el historial.
     */
    public function test_un_lote_mixto_no_filtra_por_la_puerta_de_al_lado(): void
    {
        $log = $this->payloadForLog([
            'entry' => [['changes' => [
                ['field' => 'messages', 'value' => ['messages' => [['text' => ['body' => 'hola']]]]],
                ['field' => 'history',  'value' => ['messages' => [['text' => ['body' => 'seis meses de chats']]]]],
            ]]],
        ]);

        $this->assertStringNotContainsString('seis meses de chats', $log);
    }

    private function payloadForLog(array $data): string
    {
        $m = new ReflectionMethod(WhatsAppWebhookController::class, 'payloadForLog');
        $m->setAccessible(true);

        return $m->invoke(app(WhatsAppWebhookController::class), $data);
    }
}
