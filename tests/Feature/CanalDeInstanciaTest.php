<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Instance;
use App\Models\WhatsAppConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El canal de una línea: por dónde escribe el cliente final.
 *
 * Primer cimiento para sumar Messenger e Instagram a la misma bandeja. Lo que
 * más importa de estas pruebas no es lo que habilita sino lo que **protege**:
 * hoy sólo WhatsApp sabe hablar con Meta, y una línea de otro canal no puede
 * colarse por los caminos de envío que existen.
 */
class CanalDeInstanciaTest extends TestCase
{
    use RefreshDatabase;

    public function test_una_linea_nueva_es_de_whatsapp_sin_tener_que_decirlo(): void
    {
        $instancia = $this->instancia();

        $this->assertSame(Instance::CANAL_WHATSAPP, $instancia->fresh()->channel);
        $this->assertTrue($instancia->esWhatsApp());
        $this->assertFalse($instancia->esMessenger());
        $this->assertFalse($instancia->esInstagram());
    }

    /**
     * Una instancia recién construida, todavía sin pasar por la base, no tiene
     * el valor por defecto: aun así tiene que contar como WhatsApp o el código
     * de envío la trataría como un canal desconocido.
     */
    public function test_una_instancia_en_memoria_sin_canal_cuenta_como_whatsapp(): void
    {
        $this->assertTrue((new Instance)->esWhatsApp());
        $this->assertSame('WhatsApp', (new Instance)->nombreDelCanal());
    }

    public function test_reconoce_los_tres_canales(): void
    {
        foreach ([
            Instance::CANAL_WHATSAPP => 'WhatsApp',
            Instance::CANAL_MESSENGER => 'Messenger',
            Instance::CANAL_INSTAGRAM => 'Instagram',
        ] as $canal => $nombre) {
            $instancia = $this->instancia(['channel' => $canal]);

            $this->assertSame($canal, $instancia->channel);
            $this->assertSame($nombre, $instancia->nombreDelCanal());
        }
    }

    public function test_se_pueden_pedir_las_lineas_de_un_canal(): void
    {
        $this->instancia();
        $this->instancia(['channel' => Instance::CANAL_INSTAGRAM]);
        $this->instancia(['channel' => Instance::CANAL_INSTAGRAM]);

        $this->assertSame(1, Instance::canal(Instance::CANAL_WHATSAPP)->count());
        $this->assertSame(2, Instance::canal(Instance::CANAL_INSTAGRAM)->count());
        $this->assertSame(0, Instance::canal(Instance::CANAL_MESSENGER)->count());
    }

    /**
     * La protección que de verdad importa mientras los canales nuevos no estén
     * construidos: nada intenta enviar por ellos.
     */
    public function test_una_linea_que_no_es_whatsapp_no_se_da_por_configurada(): void
    {
        $completa = [
            'phone_number_id' => '1177962515404155',
            'waba_id' => 'waba-1',
            'access_token' => 'un-token-que-parece-bueno',
        ];

        $this->assertTrue($this->instancia($completa)->isMetaConfigured());

        foreach ([Instance::CANAL_MESSENGER, Instance::CANAL_INSTAGRAM] as $canal) {
            $ajena = $this->instancia($completa + ['channel' => $canal]);

            $this->assertFalse(
                $ajena->isMetaConfigured(),
                "Una línea de {$canal} se dio por lista para enviar, y ese camino todavía no existe."
            );
        }
    }

    public function test_la_conversacion_sabe_por_donde_llego(): void
    {
        $instancia = $this->instancia(['channel' => Instance::CANAL_INSTAGRAM]);

        $conversacion = WhatsAppConversation::create([
            'instance_id' => $instancia->id,
            'wa_id' => 'igsid-1234567890',
            'name' => 'Alguien por Instagram',
            'status' => 'open',
            'last_message_at' => now(),
        ]);

        $this->assertSame(Instance::CANAL_INSTAGRAM, $conversacion->canal());
        $this->assertSame('Instagram', $conversacion->nombreDelCanal());
    }

    /**
     * Sin instancia cargada tampoco puede reventar: la bandeja pinta el canal en
     * cada fila y un null ahí sería un 500 en la pantalla más usada.
     */
    public function test_una_conversacion_sin_instancia_no_revienta(): void
    {
        $conversacion = new WhatsAppConversation;

        $this->assertSame(Instance::CANAL_WHATSAPP, $conversacion->canal());
        $this->assertSame('WhatsApp', $conversacion->nombreDelCanal());
    }

    private function instancia(array $extra = []): Instance
    {
        $company = Company::create([
            'name' => 'Empresa',
            'slug' => 'e-'.Str::random(8),
            'active' => true,
        ]);

        return Instance::create(array_merge([
            'company_id' => $company->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Línea',
            'phone_number_id' => (string) random_int(1000000000000, 9999999999999),
            'waba_id' => 'waba-'.Str::random(5),
            'type' => 'meta',
            'active' => true,
            'access_token' => 'token',
        ], $extra));
    }
}
