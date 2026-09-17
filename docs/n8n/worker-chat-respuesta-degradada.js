// ---------------------------------------------------------------
// 02 · Chatbot Worker (Ollama), nodo `Respuesta degradada`
//
// Ultimo recurso: los dos modelos fallaron. NO se pierde el
// mensaje: se responde algo util y se deja rastro para alertar.
//
// 17-sep-2026: **y ahora se deriva de verdad.** Este nodo decía «Un
// asesor te contactara en breve» y no pasaba el chat a nadie, así que
// prometía en el peor momento posible —los dos modelos caídos— algo
// que no iba a ocurrir. Es la misma mentira que se acaba de quitar del
// prompt, escrita a mano aquí.
//
// De todas las ramas ésta es la que MÁS tiene que derivar: si el chat
// IA no puede ni responder, lo único que queda es una persona.
// ---------------------------------------------------------------
const ctx = $('Preparar contexto').first().json;
const err = $input.first().json.error ?? $input.first().json;

return [{
  json: {
    message_id: ctx.message_id,
    trace_id: ctx.trace_id,
    tenant_id: ctx.tenant_id,
    user_id: ctx.user_id,
    channel: ctx.channel,
    session_key: ctx.session_key,
    // `failed` describe al modelo, no al turno: el CRM mira `handoff`.
    status: 'failed',
    handoff: true,
    // Lo que el asesor lee al abrir el chat. Que sepa que no es un
    // cliente enfadado con la IA: es que la IA no funcionó.
    note: 'La IA no pudo responder (los dos modelos fallaron). El cliente espera respuesta.',
    degraded: true,
    model: 'none',
    answer: 'Estamos con alta demanda en este momento y no pude procesar tu mensaje. Un asesor te contactara en breve.',
    error_detail: typeof err === 'string' ? err : (err?.message ?? JSON.stringify(err).slice(0, 500)),
    latency_ms: Date.now() - (ctx.started_ms ?? Date.now()),
    finished_at: new Date().toISOString(),
    callback_url: ctx.callback_url,
  },
}];
