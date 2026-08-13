<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Instance;
use App\Models\WhatsAppConversation;
use App\Services\MetaWhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Responder a un cliente que oculta su teléfono.
 *
 * Meta exige el BSUID en `recipient`; `to` es sólo para teléfonos. Comprobado
 * contra la API real: enviando únicamente `recipient` deja de reclamar `to`.
 */
class OutboundBsuidTest extends TestCase
{
    use RefreshDatabase;

    private const BSUID = 'CO.1402615141764490';

    private function metaInstance(): Instance
    {
        $company = Company::create(['name' => 'Cmnet', 'slug' => 'cmnet', 'active' => true]);

        return Instance::create([
            'company_id'      => $company->id,
            'uuid'            => (string) Str::uuid(),
            'name'            => 'Principal',
            'phone_number_id' => '1177962515404155',
            'type'            => 'meta',
            'active'          => true,
            'access_token'    => 'token-meta',
        ]);
    }

    private function conversation(Instance $instance, string $waId, string $phone): WhatsAppConversation
    {
        return WhatsAppConversation::create([
            'instance_id'  => $instance->id,
            'wa_id'        => $waId,
            'phone_number' => $phone,
            'name'         => 'Cliente',
            'status'       => 'open',
        ]);
    }

    public function test_a_un_bsuid_se_le_responde_en_recipient_y_nunca_en_to(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.X']]], 200)]);

        $instance = $this->metaInstance();
        $conversation = $this->conversation($instance, self::BSUID, '');

        app(MetaWhatsAppService::class)->sendMessage(
            $instance->phone_number_id,
            $conversation->recipientId(),
            'Buenas, ya revisamos su servicio.'
        );

        Http::assertSent(function ($request) {
            return ($request['recipient'] ?? null) === self::BSUID
                && !array_key_exists('to', $request->data());
        });
    }

    public function test_a_un_telefono_se_le_sigue_respondiendo_en_to(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.X']]], 200)]);

        $instance = $this->metaInstance();
        $conversation = $this->conversation($instance, '573007852081', '573007852081');

        app(MetaWhatsAppService::class)->sendMessage(
            $instance->phone_number_id,
            $conversation->recipientId(),
            'Buenas, ya revisamos su servicio.'
        );

        Http::assertSent(function ($request) {
            return ($request['to'] ?? null) === '573007852081'
                && !array_key_exists('recipient', $request->data());
        });
    }

    public function test_el_destinatario_de_un_hilo_con_bsuid_no_es_el_telefono_vacio(): void
    {
        $instance = $this->metaInstance();

        $bsuid = $this->conversation($instance, self::BSUID, '');
        $phone = $this->conversation($instance, '573007852081', '573007852081');

        $this->assertSame(self::BSUID, $bsuid->recipientId());
        $this->assertSame('573007852081', $phone->recipientId());
    }
}
