<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Instance;
use App\Models\WhatsAppConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El teléfono que manda el ERP, con los dos números del cliente en el mismo campo.
 *
 * Nova Partners, 28-sep-2026: «3004012143 3004012143» se limpiaba a dígitos y
 * quedaba en un número de 24 cifras. Meta rechazó 39 facturas con «(#131009)
 * Parameter value is not valid», y como la conversación se creaba antes de
 * enviar, el chat se llenó de 39 clientes sin un solo mensaje.
 */
class DestinatarioDelApiTest extends TestCase
{
    use RefreshDatabase;

    /** @dataProvider casos */
    public function test_interpreta_el_destinatario(string $entrada, string $esperado): void
    {
        $this->assertSame($esperado, WhatsAppConversation::destinatarioDelApi($entrada));
    }

    public static function casos(): array
    {
        return [
            'número normal con espacios' => ['+57 300 401 2143', '573004012143'],
            'el mismo pegado dos veces' => ['573004012143573004012143', '573004012143'],
            'el mismo separado por espacio' => ['3004012143 3004012143', '3004012143'],
            'con y sin el 57' => ['3004012143, 573004012143', '573004012143'],
            'dos números distintos' => ['3004012143 3115551234', ''],
            'dos distintos pegados' => ['30040121433115551234', ''],
            'un BSUID pasa intacto' => ['CO.1402615141764490', 'CO.1402615141764490'],
        ];
    }

    public function test_un_numero_repetido_envia_a_uno_solo(): void
    {
        $linea = $this->linea();
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.OK'.Str::random(6)]]], 200)]);

        $this->plantilla($linea, '573004012143573004012143')->assertOk();

        Http::assertSent(fn ($r) => str_contains($r->url(), '/messages') && ($r['to'] ?? null) === '573004012143');
        $this->assertSame(['573004012143'], WhatsAppConversation::pluck('wa_id')->all());
    }

    /** Dos números distintos no se adivinan: se rechaza sin dejar un chat vacío. */
    public function test_dos_numeros_distintos_se_rechazan_sin_crear_conversacion(): void
    {
        $linea = $this->linea();
        Http::fake();

        $this->plantilla($linea, '3004012143 3115551234')
            ->assertStatus(422)
            ->assertJsonPath('errors.to.0', fn ($m) => str_contains($m, 'parecen dos teléfonos distintos'));

        $this->assertSame(0, WhatsAppConversation::count());
        Http::assertNothingSent();
    }

    private function plantilla(Instance $linea, string $to)
    {
        return $this->withHeader('X-Instance-Token', $linea->phone_number_id)
            ->postJson('/api/v1/messages/template', [
                'to' => $to,
                'template_name' => 'facturacion',
                'language_code' => 'es_CO',
            ]);
    }

    private function linea(): Instance
    {
        $empresa = Company::create(['name' => 'Nova', 'slug' => 'nova-'.Str::random(4), 'active' => true]);

        return Instance::create([
            'company_id' => $empresa->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Nova partners',
            'phone_number_id' => '1096840791112194',
            'waba_id' => '920906875228084',
            'type' => 'meta',
            'active' => true,
            'access_token' => 'token-meta',
        ]);
    }
}
