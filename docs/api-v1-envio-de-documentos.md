# `POST /api/v1/messages/document` — enviar un PDF por la línea de la empresa

Es la pieza que faltaba para que **haya un solo emisor de WhatsApp**.

## Por qué existe

Hasta el 9-sep-2026, Integra 2.0 mandaba las tirillas y las facturas
**directamente** a `graph.facebook.com` y después llamaba a
`/api/v1/messages/register` para que el CRM se enterara. Lo dice su propio
código:

> «Registrar el mensaje saliente en el microservicio central para que aparezca
> en el chat CRM. **Sin esto, el envío va directo a graph.facebook.com y el
> microservicio nunca se entera.**»
> — `integra2.0/app/Services/IngresoWhatsAppService.php:224`

No era un capricho: este API sólo sabía enviar **texto** (`/messages/send`) y
**plantillas** (`/messages/template`). Para un PDF no había camino, así que
Integra 2.0 subía el archivo a Meta por su cuenta y enviaba un documento suelto.

Dos emisores obligan a dos configuraciones, dos sitios donde mirar cuando un
recibo no llega, y un hilo de chat que se entera a posteriori y **sin el
archivo**: el asesor veía que se mandó algo, pero no podía abrir el recibo.

## Cómo se llama

Cabecera `X-Instance-Token: <token de la instancia>`, igual que el resto del API.

Con el archivo (multipart):

```bash
curl -X POST https://wpp.integracolombia.online/api/v1/messages/document \
  -H "X-Instance-Token: $TOKEN" \
  -F "to=+57 300 111 2233" \
  -F "file=@/tmp/Recibo_15979.pdf" \
  -F "filename=Recibo_15979.pdf" \
  -F "caption=Su soporte de pago ha sido generado bajo el Nro. 15979" \
  -F "incoming_payment_id=15979"
```

O con una URL ya publicada:

```json
{
  "to": "573001112233",
  "document_url": "https://.../facturas/F-100.pdf",
  "filename": "Factura_100.pdf",
  "caption": "Su factura de septiembre",
  "incoming_invoice_id": 100
}
```

| Campo | |
|---|---|
| `to` | requerido. Se normaliza aquí: da igual cómo lo tenga guardado el ERP. Un BSUID (`CO.1402…`) pasa intacto. |
| `file` / `document_url` | uno de los dos. El archivo hasta 20 MB. |
| `filename` | opcional; es el nombre que ve el cliente. |
| `caption` | opcional, máximo 1024 (límite de Meta). |
| `incoming_invoice_id`, `incoming_payment_id`, `incoming_contract_id`, `incoming_company_nit` | opcionales, para poder cruzar el mensaje con el documento del ERP. |
| `template_name`, `language_code`, `components` | opcionales. Ver «fuera de la ventana». |

Responde `{"success": true, "message_id": …, "wamid": …, "media_url": …}`.

## Dos decisiones que conviene conocer

**El PDF se guarda en el CRM y se envía por URL**, en vez de subirlo a Meta. Así
queda también en el hilo, que es lo que permite al asesor **abrir el recibo que
se le mandó al cliente**. Meta sólo aloja lo que le subes unos treinta días.

**Fuera de la ventana de 24 h no se usa la plantilla de respaldo de texto.**
`/messages/send` sí lo hace: envuelve el texto del ERP en la plantilla aprobada
de la empresa y así el aviso no se pierde. Aquí no vale, porque entregaría el
aviso **sin el PDF**, que es justo el documento que el cliente esperaba. Perder
el recibo en silencio es peor que decir que no se pudo enviar.

Lo que sí funciona pasadas las 24 h es una plantilla con **encabezado de tipo
documento**: el PDF viaja dentro de ella. Si la llamada trae `template_name`, el
endpoint la usa; si no, responde `422` con `code: window_closed` explicándolo
—siempre que el guardarraíl esté en `enforce` para esa empresa
(`WHATSAPP_WINDOW_GUARD`); en `shadow` deja pasar el envío como hasta hoy.

**La URL del encabezado la pone el CRM.** Quien llama no puede conocerla antes
de subir el archivo, así que pedírsela sería pedirle que adivine: manda el PDF y
el nombre de la plantilla en la misma llamada, y el endpoint construye el
encabezado. El resto de componentes —el cuerpo con sus variables— llega intacto,
y si venía un encabezado se sustituye, porque dos documentos en una plantilla no
es algo que Meta acepte.

Es el caso de la facturación mensual:

```bash
curl -X POST .../api/v1/messages/document \
  -H "X-Instance-Token: $TOKEN" \
  -F "to=573001112233" \
  -F "file=@/tmp/Factura_100.pdf" \
  -F "filename=Factura_100.pdf" \
  -F "template_name=factura_mensual" \
  -F "language_code=es" \
  -F 'components=[{"type":"body","parameters":[{"type":"text","text":"Pedro"}]}]' \
  -F "incoming_invoice_id=100"
```

## Lo que queda por hacer

Este endpoint es la mitad del CRM de la fase 1. La otra mitad es cambiar
`IngresoWhatsAppService` y el cron de facturación de Integra 2.0 para que llamen
aquí en vez de a `graph.facebook.com`, y quitar el `registerMessage` posterior,
que deja de hacer falta.

Después vienen los interruptores de envío automático y el mapeo de plantillas,
que deben vivir en el CRM porque es quien habla con WhatsApp. El botón manual
«Enviar tirilla por WhatsApp» se queda en Integra 2.0: la acción va donde está
el documento.
