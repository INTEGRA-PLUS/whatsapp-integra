<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Instance;
use App\Models\WhatsAppConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * La frontera entre un número mal escrito y un cliente que oculta el suyo.
 *
 * `whatsapp:fix-duplicate-conversations` reescribe los números guardados para
 * dejarlos en forma canónica, y nació cuando todos los clientes tenían
 * teléfono. Desde que Meta permite ocultarlo, el webhook identifica a esa gente
 * con un BSUID —"CO.1402615141764490"—, que para ese comando parecía un número
 * con basura delante.
 *
 * No es teórico: el 19-sep-2026 la simulación en producción proponía normalizar
 * **618 conversaciones, y las 618 eran BSUID**; ni una era un teléfono con
 * espacios. Con `--apply` habría convertido 618 identificadores válidos en
 * números inexistentes, dejando a esos clientes sin poder recibir nada — y sin
 * vuelta atrás, porque aquí no hay SoftDeletes.
 */
class HilosDuplicadosYBsuidTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Los BSUID de verdad, tal y como llegan de Meta.
     *
     * Este test es el que corre siempre: el del comando necesita MySQL. Si
     * alguien afloja esta frontera, lo de arriba vuelve a pasar.
     */
    public function test_reconoce_los_bsuid_que_manda_meta(): void
    {
        // Vistos en producción.
        $this->assertTrue(WhatsAppConversation::isBsuid('CO.1402615141764490'));
        $this->assertTrue(WhatsAppConversation::isBsuid('CO.1018739207589414'));
        // Los de cartera llevan ENT entre el país y el número.
        $this->assertTrue(WhatsAppConversation::isBsuid('US.ENT.11815799212886844830'));

        // Y un teléfono no lo es, se escriba como se escriba.
        $this->assertFalse(WhatsAppConversation::isBsuid('573177114395'));
        $this->assertFalse(WhatsAppConversation::isBsuid('+57 311 5775385'));
        $this->assertFalse(WhatsAppConversation::isBsuid('57300 825 3303'));
    }

    /** Y normalizar un BSUID lo deja intacto, que es de lo que va todo esto. */
    public function test_normalizar_un_bsuid_no_lo_toca(): void
    {
        $this->assertSame(
            'CO.1402615141764490',
            WhatsAppConversation::normalizeRecipient('CO.1402615141764490')
        );

        $this->assertSame(
            '573008253303',
            WhatsAppConversation::normalizeRecipient('57300 825 3303')
        );
    }

    /** El comando deja los BSUID como están y sí arregla los teléfonos. */
    public function test_el_comando_no_toca_los_bsuid(): void
    {
        $this->soloMysql();

        $instancia = $this->instancia();

        $oculto = $this->conversacion($instancia, 'CO.1402615141764490');
        $conEspacios = $this->conversacion($instancia, '57300 825 3303');

        $this->artisan('whatsapp:fix-duplicate-conversations --apply')->assertExitCode(0);

        $this->assertSame('CO.1402615141764490', $oculto->fresh()->wa_id,
            'Un BSUID no es un número mal escrito: reescribirlo deja al cliente inalcanzable.');

        $this->assertSame('573008253303', $conEspacios->fresh()->wa_id,
            'Y un teléfono con espacios sí hay que dejarlo canónico.');
    }

    /**
     * Un BSUID y un teléfono con los mismos dígitos no son el mismo cliente.
     *
     * El agrupado era por dígitos, así que "CO.573001112233" y "573001112233"
     * caían en el mismo grupo y el comando fundía en un solo hilo a dos
     * personas distintas.
     */
    public function test_no_funde_un_bsuid_con_el_telefono_de_sus_digitos(): void
    {
        $this->soloMysql();

        $instancia = $this->instancia();

        $oculto = $this->conversacion($instancia, 'CO.573001112233');
        $conNumero = $this->conversacion($instancia, '573001112233');

        $this->artisan('whatsapp:fix-duplicate-conversations --apply')->assertExitCode(0);

        $this->assertNotNull($oculto->fresh(), 'El hilo del cliente oculto se fusionó con otro.');
        $this->assertNotNull($conNumero->fresh());
    }

    private function soloMysql(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped(
                'El comando usa REGEXP y REGEXP_REPLACE de MySQL, y la suite corre en sqlite. '
                .'La frontera que protege sí se comprueba arriba, sin base de datos.'
            );
        }
    }

    private function instancia(): Instance
    {
        $empresa = Company::create(['name' => 'Fibra Sur', 'slug' => 'fibra-'.Str::random(5), 'active' => true]);

        return Instance::create([
            'company_id' => $empresa->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Principal',
            'phone_number_id' => '11779625154'.random_int(10000, 99999),
            'waba_id' => 'waba-'.Str::random(6),
            'type' => 'meta',
            'active' => true,
            'access_token' => 'token',
        ]);
    }

    private function conversacion(Instance $instancia, string $waId): WhatsAppConversation
    {
        return WhatsAppConversation::create([
            'instance_id' => $instancia->id,
            'wa_id' => $waId,
            'phone_number' => $waId,
            'name' => 'Cliente',
            'status' => 'open',
            'last_message_at' => now(),
        ]);
    }
}
