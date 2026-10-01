<?php

namespace App\Services;

use App\Models\WhatsAppCampaign;
use App\Models\WhatsAppCampaignRecipient;

/**
 * Convierte una campaña y un destinatario concretos en los `components` que
 * espera Meta, y en el texto que de verdad va a leer esa persona.
 *
 * La personalización vive en `variable_map`: por cada `{{n}}` de la plantilla se
 * guarda de dónde sale el dato —un texto fijo igual para todos, o un campo del
 * destinatario (su nombre, su teléfono, una columna del CSV)—. Resolverlo aquí,
 * y no en el job, permite que la vista previa del asistente muestre exactamente
 * el mismo mensaje que se enviará, con el primer destinatario real dentro.
 *
 * Forma de `variable_map`:
 *
 *   {
 *     "header":  [{"source": "fixed", "value": "Septiembre"}],
 *     "body":    [{"source": "field", "field": "name"}, {"source": "fixed", "value": "$120.000"}],
 *     "buttons": [{"index": 0, "sub_type": "url", "source": "field", "field": "identificacion"}]
 *   }
 *
 * El hueco n-ésimo corresponde a la n-ésima variable distinta del texto, por
 * orden de aparición: `{{1}}`, `{{2}}`… o, en las plantillas con nombre,
 * `{{nombre}}`, `{{factura}}`… En estas últimas cada parámetro sale además con
 * su `parameter_name`, que Meta exige para saber dónde va cada uno.
 */
class CampaignTemplateBuilder
{
    /** Campos del destinatario que se pueden insertar en una variable. */
    public const FIELDS = ['name', 'phone', 'identificacion'];

    public function components(WhatsAppCampaign $campaign, ?WhatsAppCampaignRecipient $recipient = null): array
    {
        $components = [];
        $map = $campaign->variable_map ?? [];

        $headerFormat = $this->headerFormat($campaign);

        if (in_array($headerFormat, ['IMAGE', 'VIDEO', 'DOCUMENT'], true) && $campaign->header_media_id) {
            $kind = strtolower($headerFormat);
            $media = ['id' => $campaign->header_media_id];

            if ($kind === 'document') {
                $media['filename'] = $campaign->header_filename ?: 'documento.pdf';
            }

            $components[] = [
                'type' => 'header',
                'parameters' => [['type' => $kind, $kind => $media]],
            ];
        } elseif ($headerFormat === 'TEXT' && !empty($map['header'])) {
            $components[] = [
                'type' => 'header',
                'parameters' => $this->parameters($map['header'], $recipient, $this->names($campaign, 'HEADER')),
            ];
        }

        if (!empty($map['body'])) {
            $components[] = [
                'type' => 'body',
                'parameters' => $this->parameters($map['body'], $recipient, $this->names($campaign, 'BODY')),
            ];
        }

        // Botones con dato: la URL que termina en {{1}} o el código para copiar.
        // Sin este componente Meta rechaza el envío entero.
        foreach ($map['buttons'] ?? [] as $slot) {
            if (!is_array($slot) || !isset($slot['index'])) {
                continue;
            }

            $subType = ($slot['sub_type'] ?? 'url') === 'copy_code' ? 'copy_code' : 'url';
            $value = $this->resolve($slot, $recipient);

            $components[] = [
                'type' => 'button',
                'sub_type' => $subType,
                'index' => (string) (int) $slot['index'],
                'parameters' => [$subType === 'copy_code'
                    ? ['type' => 'coupon_code', 'coupon_code' => $value]
                    : ['type' => 'text', 'text' => $value]],
            ];
        }

        return $components;
    }

    /**
     * Las variables distintas de un componente de la plantilla, en orden de
     * primera aparición. Es el mismo orden en que el asistente pinta los huecos.
     */
    private function names(WhatsAppCampaign $campaign, string $type): array
    {
        foreach ($campaign->template_components ?? [] as $component) {
            if (strtoupper($component['type'] ?? '') === $type) {
                preg_match_all('/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/', (string) ($component['text'] ?? ''), $matches);

                return array_values(array_unique($matches[1] ?? []));
            }
        }

        return [];
    }

    /**
     * El cuerpo ya resuelto, que es lo que se guarda como contenido de la burbuja
     * del chat: el agente debe leer lo mismo que le llegó al cliente.
     */
    public function preview(WhatsAppCampaign $campaign, ?WhatsAppCampaignRecipient $recipient = null): string
    {
        $body = null;
        foreach ($campaign->template_components ?? [] as $component) {
            if (strtoupper($component['type'] ?? '') === 'BODY') {
                $body = (string) ($component['text'] ?? '');
                break;
            }
        }

        if ($body === null || $body === '') {
            return "[Plantilla: {$campaign->template_name}]";
        }

        return $this->fill($body, $campaign->variable_map['body'] ?? [], $recipient);
    }

    /**
     * Sustituye los {{n}} de un texto por los valores de este destinatario.
     */
    private function fill(string $texto, array $slots, ?WhatsAppCampaignRecipient $recipient): string
    {
        $values = array_map(fn ($slot) => $this->resolve($slot, $recipient), $slots);

        // Un {{nombre}} repetido es el mismo hueco: se busca por su posición
        // entre las variables distintas, no por cuántas veces apareció ya. Con
        // un contador, la segunda aparición tomaba el valor del hueco siguiente.
        preg_match_all('/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/', $texto, $todas);
        $orden = array_flip(array_values(array_unique($todas[1] ?? [])));

        return preg_replace_callback(
            '/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/',
            function ($matches) use ($values, $orden) {
                $key = $matches[1];
                $value = ctype_digit($key)
                    ? ($values[((int) $key) - 1] ?? null)
                    : ($values[$orden[$key] ?? -1] ?? null);

                return ($value === null || $value === '') ? $matches[0] : $value;
            },
            $texto
        );
    }

    /**
     * El encabezado de texto, ya resuelto. Se pintaba crudo en el detalle de la
     * campaña —«Tu factura de {{1}}»—, que es justo lo que nadie recibió.
     */
    public function previewHeader(WhatsAppCampaign $campaign, ?WhatsAppCampaignRecipient $recipient = null): ?string
    {
        foreach ($campaign->template_components ?? [] as $component) {
            if (strtoupper($component['type'] ?? '') !== 'HEADER') {
                continue;
            }

            if (strtoupper($component['format'] ?? 'TEXT') !== 'TEXT') {
                return null;
            }

            return $this->fill((string) ($component['text'] ?? ''), $campaign->variable_map['header'] ?? [], $recipient);
        }

        return null;
    }

    /**
     * Qué valor le toca a este destinatario en una variable.
     */
    public function resolve(array $slot, ?WhatsAppCampaignRecipient $recipient): string
    {
        $source = $slot['source'] ?? 'fixed';

        if ($source === 'fixed') {
            return $this->clean((string) ($slot['value'] ?? ''));
        }

        $field = (string) ($slot['field'] ?? '');

        // Sin destinatario —la comprobación al guardar la campaña, o la vista
        // previa sin nadie elegido— el dato todavía no existe. Se marca con el
        // nombre del campo, como hace el asistente, en vez de dejarlo vacío: un
        // vacío aquí es un «dato en blanco» que el guardarraíl rechazaría por
        // algo que en el envío real sí va a tener valor.
        if ($recipient === null) {
            return '«' . ($field ?: 'dato') . '»';
        }

        $variables = $recipient->variables ?? [];

        // Lo que trajo el CSV manda sobre lo derivado del contacto: si alguien se
        // molestó en escribir el dato para esta campaña, es el bueno.
        if (array_key_exists($field, $variables)) {
            return $this->clean((string) $variables[$field]);
        }

        $value = match ($field) {
            'name' => $this->customerName($recipient),
            'phone' => $recipient->phone_number ?: '',
            'identificacion' => $recipient->contact?->identificacion ?: '',
            default => '',
        };

        return $this->clean((string) $value);
    }

    /**
     * El nombre con el que saludar. Un destinatario sin nombre se queda en
     * «cliente», igual que en la plantilla de respaldo: el hueco vacío lo
     * rechaza Meta, y saludar por el número de teléfono —o por un BSUID como
     * «CO.1402615141764490»— es peor que no saludar por el nombre.
     */
    private function customerName(WhatsAppCampaignRecipient $recipient): string
    {
        $phone = trim((string) $recipient->phone_number);

        foreach ([$recipient->name, $recipient->contact?->name] as $candidato) {
            $candidato = $this->clean((string) $candidato);

            if ($candidato === ''
                || $candidato === $phone
                || preg_match('/^[\d\s+.\-()]+$/', $candidato)
                || \App\Models\WhatsAppConversation::isBsuid($candidato)) {
                continue;
            }

            return $candidato;
        }

        return 'cliente';
    }

    public function headerFormat(WhatsAppCampaign $campaign): ?string
    {
        foreach ($campaign->template_components ?? [] as $component) {
            if (strtoupper($component['type'] ?? '') === 'HEADER') {
                return strtoupper($component['format'] ?? 'TEXT');
            }
        }

        return null;
    }

    /**
     * Cuántas variables distintas declara el cuerpo de la plantilla.
     */
    public function bodyVariableCount(array $templateComponents): int
    {
        foreach ($templateComponents as $component) {
            if (strtoupper($component['type'] ?? '') === 'BODY') {
                preg_match_all('/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/', (string) ($component['text'] ?? ''), $matches);
                return count(array_unique($matches[1] ?? []));
            }
        }

        return 0;
    }

    private function parameters(array $slots, ?WhatsAppCampaignRecipient $recipient, array $names = []): array
    {
        $named = $names !== [] && !collect($names)->every(fn ($n) => ctype_digit((string) $n));

        return array_values(array_map(
            function ($slot, $i) use ($recipient, $names, $named) {
                $parameter = ['type' => 'text', 'text' => $this->resolve($slot, $recipient)];

                if ($named && isset($names[$i])) {
                    $parameter = ['type' => 'text', 'parameter_name' => $names[$i], 'text' => $parameter['text']];
                }

                return $parameter;
            },
            $slots,
            array_keys($slots)
        ));
    }

    /**
     * WhatsApp rechaza los parámetros con saltos de línea, tabuladores o más de
     * cuatro espacios seguidos (132018). Se limpian aquí y no en el formulario:
     * el dato puede venir de un CSV o del CRM, no solo de alguien escribiendo.
     * La regla es la del guardarraíl, para que no haya dos versiones de ella.
     */
    private function clean(string $value): string
    {
        return TemplateParameterGuard::cleanText($value);
    }
}
