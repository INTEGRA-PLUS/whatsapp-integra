# Campañas de WhatsApp

Cómo está montado el envío masivo, para quien tenga que tocarlo.

## La regla que lo condiciona todo

WhatsApp solo entrega **texto libre** dentro de las 24 horas siguientes al último
mensaje del cliente. Una campaña va, por definición, a gente que no acaba de
escribir, así que **una campaña solo puede enviarse como plantilla aprobada**.

Esto no es una preferencia de producto: hasta septiembre de 2026 las campañas se
enviaban con `sendMessage()` (texto libre) y Meta las aceptaba con un 200 y un
`wamid`. El rechazo llegaba minutos después por webhook (131047 *Re-engagement*)
y no lo veía nadie, porque la campaña no creaba filas en `whatsapp_messages` y
`WhatsAppWebhookController::updateMessageStatus()` salía por un `return` silencioso
al no encontrar el wamid. Una campaña podía reportar «100 % enviada» habiendo
entregado cero.

## Piezas

| Pieza | Responsabilidad |
| --- | --- |
| `WhatsAppCampaignController` | Asistente, creación, acciones (pausar, reanudar, cancelar, reintentar), buscadores de destinatarios y export CSV. |
| `CampaignTemplateBuilder` | Convierte campaña + destinatario en los `components` de Meta y en el texto ya resuelto. |
| `TemplateParameterGuard` | Revisa —y arregla cuando puede— los parámetros antes de que salgan hacia Meta. |
| `ProcessWhatsAppCampaign` | Reparte: encola un job por destinatario, escalonado según `rate_per_minute`. |
| `SendCampaignMessage` | Envía a **un** destinatario, crea su burbuja en el chat y guarda el resultado. |
| `RunScheduledCampaigns` | Comando de cada minuto que relanza las campañas recurrentes vencidas. |

## Ciclo de vida

```
draft ──enviar──▶ queued ──▶ sending ──┬──▶ completed   (quedó alguien entregado)
                    ▲                   └──▶ failed      (no salió ni uno)
                    │
   paused ◀──pausar─┤
      └──reanudar───┘                        cancelled   (lo pendiente pasa a skipped)
```

Cada destinatario tiene su propio estado, que **no** es el de la campaña:
`pending → sending → sent → delivered → read`, o `failed` / `skipped`.
`sent` lo decide la respuesta de Meta; `delivered` y `read` llegan después por
webhook y los escribe `updateCampaignRecipientStatus()`, buscando por `wamid`.

## Personalización

`whatsapp_campaigns.variable_map` guarda, por cada `{{n}}` de la plantilla, de
dónde sale el dato:

```json
{
  "header": [{"source": "fixed", "value": "Septiembre"}],
  "body":   [{"source": "field", "field": "name"}, {"source": "fixed", "value": "$120.000"}]
}
```

`source: field` busca primero en `whatsapp_campaign_recipients.variables` (lo que
trajo el CSV) y después en el propio destinatario (`name`, `phone`,
`identificacion` del contacto). Resolverlo en `CampaignTemplateBuilder` —y no en
el job— permite que la vista previa del asistente muestre exactamente el mismo
mensaje que se enviará.

## El guardarraíl de parámetros

`TemplateParameterGuard::check()` es la única puerta por la que pasan **todos** los
envíos de plantilla: campañas, chat (`ChatController::sendTemplate`),
`DeliverWhatsAppMessage` y la API del ERP (`MessageApiController::sendTemplate`).

Hace dos cosas:

1. **Normaliza** lo que se puede arreglar solo. Meta exige los tipos en minúscula
   (`"type": "image"`); un `"IMAGE"` produce un parámetro que no reconoce y el
   error literal `header: Format mismatch, expected IMAGE, received UNKNOWN`.
   También acepta `{"image": "https://…"}` en vez de `{"image": {"link": …}}`.
2. **Valida** contra la definición aprobada: encabezado ausente, de otro tipo, un
   handle `h:` de creación usado como media id, un media id que Meta ya borró, o un
   `link` que no devuelve una imagen. Un `link` válido se descarga y se **sube** a
   Meta, y el envío pasa a referenciar el media id.

Regla de oro: **ante la duda, deja pasar**. Si Graph no responde o la plantilla no
aparece en el catálogo, el envío sigue su curso. Un guardarraíl que bloquea envíos
buenos porque Meta tuvo un mal minuto es peor que el problema que resuelve.

El catálogo se cachea 10 minutos por WABA (`wa:templates:{waba_id}`), así que una
campaña de 500 destinatarios hace **una** llamada a Graph, no 500.

Los códigos que devuelve (`code` en la respuesta 422) son estables y los puede
registrar el ERP: `template_header_missing`, `template_header_type_mismatch`,
`template_header_empty`, `template_header_handle`, `template_header_media_gone`,
`template_header_link_invalid`, `template_header_link_unreachable`,
`template_header_link_wrong_type`, `template_header_too_big`,
`template_header_upload_failed`, `template_body_parameters`.

## Ritmo de envío

`ProcessWhatsAppCampaign` no llama a Meta: encola un `SendCampaignMessage` por
destinatario con `->delay(i * 60 / rate_per_minute)`. Un job por destinatario es lo
que permite reintentar solo lo que falló, pausar en seco y que un fallo a mitad no
se lleve por delante el resto.

## Destinatarios

`resolveRecipients()` acepta cuatro orígenes (conversaciones, contactos del CRM,
lista pegada/CSV y segmentos guardados), deduplica por teléfono normalizado y
descarta lo que no es un destinatario válido. Normaliza con
`WhatsAppConversation::normalizeRecipient()`, **no** con un `preg_replace` de
dígitos: un BSUID (`CO.1402615141764490`) es un destinatario legítimo y quitarle
las letras lo convertiría en un teléfono inventado.

Los segmentos (`whatsapp_campaign_segments`) guardan el **criterio**, no la lista:
«los contactos con etiqueta morosos» debe significar quienes lo sean el día que se
lance la campaña.

## Baja de campañas (opt-out)

`contacts.opted_out_at` marca a quien pidió no recibir campañas. Es la baja de
los **envíos masivos**, no del servicio: al cliente se le sigue respondiendo en
el chat y le siguen llegando los avisos del ERP.

`resolveRecipients()` los cruza por teléfono normalizado y crea su fila como
`skipped` con el motivo, en vez de descartarlos en silencio: así el total de la
campaña sigue cuadrando con lo que se seleccionó y se ve cuánta gente quedó
fuera. Si **todos** los seleccionados están dados de baja, la campaña no se crea.

Se marca a mano desde Contactos (`POST /api/contacts/{id}/opt-out`).

## Lo que falta

- La baja hay que marcarla a mano. Detectar automáticamente un «BAJA» o un
  «STOP» del cliente y marcarlo desde el webhook está por hacer, y es lo que
  haría que la lista se mantenga sola.
- Las etiquetas cuelgan de las conversaciones, no de los contactos, así que los
  segmentos por etiqueta solo funcionan sobre conversaciones.
