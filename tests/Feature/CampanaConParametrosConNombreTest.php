<?php

namespace Tests\Feature;

use App\Models\WhatsAppCampaign;
use App\Models\WhatsAppCampaignRecipient;
use App\Services\CampaignTemplateBuilder;
use Tests\TestCase;

/**
 * Lo que la campaña manda a Meta cuando la plantilla tiene parámetros con
 * nombre o botones dinámicos, y qué pasa con un destinatario sin nombre.
 *
 * Antes los parámetros salían siempre sin `parameter_name` —una plantilla
 * NAMED no se podía mandar por campaña— y los botones no salían nunca.
 */
class CampanaConParametrosConNombreTest extends TestCase
{
    private function builder(): CampaignTemplateBuilder
    {
        return app(CampaignTemplateBuilder::class);
    }

    private function campana(array $componentes, array $mapa): WhatsAppCampaign
    {
        return new WhatsAppCampaign([
            'template_name' => 'aviso',
            'template_language' => 'es',
            'template_components' => $componentes,
            'variable_map' => $mapa,
        ]);
    }

    public function test_los_parametros_con_nombre_salen_con_su_parameter_name(): void
    {
        $campana = $this->campana(
            [['type' => 'BODY', 'text' => 'Hola {{nombre}}, tu factura {{numero}}. Gracias, {{nombre}}.']],
            ['body' => [
                ['source' => 'field', 'field' => 'name'],
                ['source' => 'fixed', 'value' => "F-100\n"],
            ]]
        );
        $destinatario = new WhatsAppCampaignRecipient(['name' => 'Marta', 'phone_number' => '573001112233']);

        $cuerpo = $this->builder()->components($campana, $destinatario)[0];

        $this->assertSame([
            ['type' => 'text', 'parameter_name' => 'nombre', 'text' => 'Marta'],
            ['type' => 'text', 'parameter_name' => 'numero', 'text' => 'F-100'],
        ], $cuerpo['parameters']);

        // El {{nombre}} repetido es el mismo hueco, no el siguiente.
        $this->assertSame('Hola Marta, tu factura F-100. Gracias, Marta.', $this->builder()->preview($campana, $destinatario));
    }

    public function test_los_botones_dinamicos_salen_como_componente_button(): void
    {
        $campana = $this->campana(
            [
                ['type' => 'BODY', 'text' => 'Tu pedido salió.'],
                ['type' => 'BUTTONS', 'buttons' => [
                    ['type' => 'URL', 'text' => 'Rastrear', 'url' => 'https://envios.test/{{1}}'],
                    ['type' => 'COPY_CODE', 'text' => 'Copiar'],
                ]],
            ],
            ['buttons' => [
                ['index' => 0, 'sub_type' => 'url', 'source' => 'field', 'field' => 'guia'],
                ['index' => 1, 'sub_type' => 'copy_code', 'source' => 'fixed', 'value' => 'DESC10'],
            ]]
        );
        $destinatario = new WhatsAppCampaignRecipient(['name' => 'Marta', 'phone_number' => '573001112233', 'variables' => ['guia' => 'G-77']]);

        $this->assertSame([
            ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => 'G-77']]],
            ['type' => 'button', 'sub_type' => 'copy_code', 'index' => '1', 'parameters' => [['type' => 'coupon_code', 'coupon_code' => 'DESC10']]],
        ], $this->builder()->components($campana, $destinatario));
    }

    /**
     * Un hueco vacío lo rechaza Meta, y saludar por el número —o por un BSUID—
     * es peor que no saludar por el nombre: se queda en «cliente».
     */
    public function test_un_destinatario_sin_nombre_se_saluda_como_cliente(): void
    {
        $campana = $this->campana(
            [['type' => 'BODY', 'text' => 'Hola {{1}}']],
            ['body' => [['source' => 'field', 'field' => 'name']]]
        );

        foreach (['', '573001112233', '+57 300 111 2233', 'CO.1402615141764490'] as $nombre) {
            $destinatario = new WhatsAppCampaignRecipient(['name' => $nombre, 'phone_number' => '573001112233']);

            $this->assertSame('cliente', $this->builder()->resolve(['source' => 'field', 'field' => 'name'], $destinatario), "Con «{$nombre}».");
        }
    }

    /**
     * Al guardar la campaña todavía no hay destinatario: el dato de un campo se
     * marca con su nombre, no vacío, para que el guardarraíl no lo tome por un
     * dato en blanco.
     */
    public function test_sin_destinatario_el_campo_queda_marcado_y_no_vacio(): void
    {
        $campana = $this->campana(
            [['type' => 'BODY', 'text' => 'Hola {{1}}']],
            ['body' => [['source' => 'field', 'field' => 'name']]]
        );

        $this->assertSame('«name»', $this->builder()->components($campana, null)[0]['parameters'][0]['text']);
    }
}
