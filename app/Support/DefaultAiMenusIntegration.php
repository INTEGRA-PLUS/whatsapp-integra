<?php

namespace App\Support;

use App\Models\Company;
use App\Models\CompanyIntegration;

/**
 * El interruptor de IA con el que arranca toda empresa.
 *
 * La fila se siembra para que el interruptor exista desde el primer día en el
 * módulo de menús: sin ella, la pantalla tendría que aprender a crearla al
 * primer clic y toda empresa nueva pasaría por ese caso raro.
 *
 * Nace **apagada**, a propósito. Encenderla por su cuenta significaría que los
 * clientes de la empresa empiezan a hablar con un modelo que nadie de esa
 * empresa revisó, y un mensaje enviado a un cliente ya no se puede recoger.
 *
 * No guarda ajustes: el servidor de Ollama, el modelo y los permisos son los
 * mismos para toda la plataforma y viven en el flujo de n8n.
 */
class DefaultAiMenusIntegration
{
    /**
     * Siembra la fila de una empresa.
     *
     * Devuelve null si ya la tiene, para no pisarle el interruptor a quien ya
     * lo encendió. Es además lo que deja correr la migración dos veces sin
     * duplicar nada.
     */
    public static function createFor(Company $company): ?CompanyIntegration
    {
        $existing = CompanyIntegration::where('company_id', $company->id)
            ->where('key', CompanyIntegration::KEY_AI_MENUS)
            ->first();

        if ($existing) {
            return null;
        }

        return CompanyIntegration::create([
            'company_id' => $company->id,
            'key' => CompanyIntegration::KEY_AI_MENUS,
            'enabled' => false,
            // Las demás columnas de la tabla son de las integraciones que sí
            // guardan credenciales (Integra). Aquí no aplican: se dejan en su
            // valor por defecto y nadie las lee.
        ]);
    }
}
