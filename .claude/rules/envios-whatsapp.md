---
description: Reglas de la Cloud API de Meta que fallan en silencio al enviar mensajes
paths:
  - "app/Services/**"
  - "app/Jobs/**"
  - "app/Http/Controllers/**"
---

# Enviar mensajes por WhatsApp

## La regla que lo condiciona todo

**La Cloud API responde 200 con `wamid` a envíos que después fallan.** El fallo llega minutos más tarde por
webhook. Quien lanzó el envío ve éxito; el cliente nunca recibe nada. Los dos guardarraíles de abajo existen
por eso, y saltárselos reintroduce el fallo silencioso.

## Todo envío de plantilla pasa por `TemplateParameterGuard`

Antes de `MetaWhatsAppService::sendTemplate()`, pasar los componentes por `check()` / `normalize()`. Armar el
array `components` a mano y llamar directo al servicio **salta el guardarraíl entero**.

Lo que valida, y que es fácil de romper escribiendo el JSON a mano:
- Los tipos de parámetro van **en minúscula**: `"type": "image"`, no `"IMAGE"`. Un `"IMAGE"` produce el error
  132012 de Meta: *"header: Format mismatch, expected IMAGE, received UNKNOWN"*.
- El media va como `{"link": "..."}`, no `{"image": "..."}`.
- Y como **id** de medio, no como handle de creación.

Código de rechazo estable: `template_parameter_invalid`. La política del guard es **ante la duda, dejar pasar**:
si Graph no responde o la plantilla no está en el catálogo, el envío sigue.

## Fuera de la ventana de 24 h solo se aceptan plantillas aprobadas

Cualquier código que mande **texto libre** — una notificación, un aviso del ERP, una respuesta de bot — debe
comprobar `isWindowOpen()` primero y, si está cerrada, pasar por `WhatsAppFallbackTemplateService`.

Esto **parece funcionar en pruebas** y falla en producción: en pruebas la ventana siempre está abierta porque
acabas de escribir tú. Rompe justo con los clientes que llevan un día sin escribir.

El estado de la plantilla de respaldo está cacheado en `instances.meta.fallback_template`. **No sobrescribir
`$instance->meta` entero**: borra ese caché y dispara llamadas extra a Graph que agotan el rate limit del tenant.

El guardarraíl es configurable: `WHATSAPP_WINDOW_GUARD=shadow` (por defecto: deja pasar marcando) o `enforce`
(rechaza con 422 y code `window_closed`), y `WHATSAPP_WINDOW_GUARD_COMPANIES` para activarlo empresa a empresa.

## Quién habla con Meta

`ProcessWhatsAppMenu` es el único job que envía. `ProcessWhatsAppAi`, `ProcessWhatsAppChatAi` y
`WhatsAppMenuActionService` **deciden y devuelven texto, no envían**. Mantener esa separación: es lo que permite
reevaluar agente asignado, hilo cerrado y ventana de 24 h *después* de la espera del modelo.

`WhatsAppMenuService` decide en caliente dentro del webhook (no en cola) porque el webhook necesita saber ya si
el menú se hace cargo; si no, despacharía además la autorespuesta y el cliente recibiría dos respuestas al mismo
mensaje. Se calla si `assigned_to !== null` o `status === 'closed'`.
