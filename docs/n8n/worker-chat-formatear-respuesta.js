// ---------------------------------------------------------------
// 02 · Chatbot Worker (Ollama), nodo `Formatear respuesta`
//
// Normaliza la salida del agente a un contrato estable.
// El consumidor (WhatsApp, widget web, app) nunca ve el formato
// crudo del LLM: si cambias de modelo, el contrato no se rompe.
//
// 17-sep-2026: la IA puede PEDIR que el chat pase a un asesor.
// Hasta hoy lo decía en su texto —«procedo a comunicarlo con un
// asesor»— y no pasaba nada: el CRM devolvía siempre `reply`, nadie
// quedaba asignado, y el cliente había dado su cédula para nada.
//
// Este nodo es el sitio, y no otro: es donde el objeto se arma campo
// por campo. De aquí en adelante `Guardar resultado` y `Publicar en
// canal` serializan `$json` entero, así que lo que se añada aquí llega
// al CRM sin tocar nada más. Lo que NO se nombre aquí, se pierde — es
// exactamente el incidente del 16-sep con `asistente`.
// ---------------------------------------------------------------

/** La línea que el prompt de `Preparar contexto` le pide al modelo. */
const MARCA = '#ASESOR#';

/**
 * Separa el texto para el cliente de la orden para el sistema.
 *
 * Se busca la marca en cualquier línea y no sólo en la última: el
 * modelo a veces añade una despedida después, y recortar desde la
 * marca hasta el final se comería media respuesta.
 *
 * El marcador se quita SIEMPRE, se derive o no: que el cliente lea
 * «#ASESOR#» es peor que no derivar.
 */
function separar(texto) {
  const lineas = String(texto || '').split('\n');
  const i = lineas.findIndex((l) => l.trim().toUpperCase().startsWith(MARCA));

  if (i === -1) {
    return { answer: String(texto || '').trim(), handoff: false, note: null };
  }

  return {
    answer: lineas.filter((_, n) => n !== i).join('\n').trim(),
    handoff: true,
    note: lineas[i].trim().slice(MARCA.length).trim() || null,
  };
}

const ctx = $('Preparar contexto').first().json;

return $input.all().map((item) => {
  const raw = item.json.output ?? item.json.text ?? item.json.response ?? '';
  const { answer, handoff, note } = separar(raw);

  return {
    json: {
      message_id: ctx.message_id,
      trace_id: ctx.trace_id,
      tenant_id: ctx.tenant_id,
      user_id: ctx.user_id,
      channel: ctx.channel,
      session_key: ctx.session_key,
      // `handoff` es lo que lee el CRM; `status` va en el mismo sentido
      // para que las ejecuciones de n8n se puedan filtrar de un vistazo.
      status: handoff ? 'handoff' : 'done',
      handoff,
      // Lo que el asesor lee al abrir el chat. Llega sabiendo qué
      // pedían, que es mucho más útil que el aviso genérico.
      note,
      degraded: false,
      model: 'primary',
      // Si el modelo se quedó sin texto y sólo mandó el marcador, el
      // traspaso sigue valiendo: se deriva con un aviso en vez de
      // callarse.
      answer: answer || (handoff
        ? 'Te comunico con una persona del equipo.'
        : 'No pude generar una respuesta. Intenta reformular la pregunta.'),
      latency_ms: Date.now() - (ctx.started_ms ?? Date.now()),
      finished_at: new Date().toISOString(),
      callback_url: ctx.callback_url,
    },
  };
});
