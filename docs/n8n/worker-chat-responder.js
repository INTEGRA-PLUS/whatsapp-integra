// ---------------------------------------------------------------
// 02 · Chatbot Worker (Ollama), nodo `Responder`
//
// Convierte lo que devolvió el modelo en el cuerpo que lee el CRM, y
// saca del texto el marcador de traspaso.
//
// 17-sep-2026. Por qué existe: `WhatsAppChatAiClient` devolvía siempre
// `MenuActionResult::reply`, así que el chat IA no podía derivar dijera
// lo que dijera su respuesta — y lo decía: «he registrado sus datos y
// procederé a comunicarlo con un asesor», con el cliente dando su
// cédula para nada y sin que nadie quedara asignado. El CRM ya sabe
// derivar; esto es la mitad que faltaba.
//
// El contrato con el CRM (app/Services/WhatsAppChatAiClient.php):
//
//   answer      string   lo que se le manda al cliente. Obligatorio:
//                        si viene vacío, el CRM da el turno por
//                        perdido y el mensaje queda para un agente.
//   handoff     bool     true = pasa el chat a una persona. También
//                        vale `status: "handoff"`.
//   note        string   una frase para el asesor. La lee al abrir el
//                        chat, así que llega sabiendo qué pedían.
//   model       string   } traza. Se guarda en la burbuja para poder
//   latency_ms  number   } abrir el chat meses después y saber con qué
//   degraded    bool     } modelo se contestó eso.
//   trace_id    string   }
//
// El marcador se recorta SIEMPRE, se derive o no: que el cliente lea
// «#ASESOR#» es peor que no derivar.
// ---------------------------------------------------------------

/** La línea que el prompt le pide al modelo cuando hace falta una persona. */
const MARCA = '#ASESOR#';

/**
 * Separa el texto para el cliente de la orden para el sistema.
 *
 * Se busca la marca en cualquier línea y no sólo en la última: el
 * modelo a veces añade una despedida después, y dejarla fuera del
 * recorte cortaría media respuesta.
 */
function separar(texto) {
  const lineas = String(texto || '').split('\n');
  const i = lineas.findIndex((l) => l.trim().toUpperCase().startsWith(MARCA));

  if (i === -1) {
    return { answer: String(texto || '').trim(), handoff: false, note: null };
  }

  const nota = lineas[i].trim().slice(MARCA.length).trim();

  // Todo lo que no sea esa línea sigue siendo del cliente.
  const answer = lineas
    .filter((_, n) => n !== i)
    .join('\n')
    .trim();

  return { answer, handoff: true, note: nota || null };
}

return $input.all().map((item) => {
  const d = item.json || {};

  // De dónde sale el texto del modelo. Se prueban varias claves porque
  // cambia según el nodo que haya delante (Ollama, un Agent, un HTTP
  // Request), y atarse a una sola es lo que deja la IA muda al
  // cambiarlo.
  const crudo =
    d.answer ??
    d.text ??
    d.output ??
    d.message?.content ??
    d.response ??
    '';

  const { answer, handoff, note } = separar(crudo);

  return {
    json: {
      // Sin `answer` el CRM no envía nada y el mensaje queda para un
      // agente: es el comportamiento correcto, pero conviene verlo en
      // las ejecuciones de n8n antes que en un cliente esperando.
      status: handoff ? 'handoff' : 'done',
      answer,
      handoff,
      note,
      // La traza, tal cual venga. Si el nodo de delante no la trae, el
      // CRM guarda la burbuja igual: son campos opcionales.
      model: d.model ?? null,
      latency_ms: d.latency_ms ?? d.total_duration ?? null,
      degraded: d.degraded ?? false,
      trace_id: d.trace_id ?? null,
      // Lo usa el dedupe del gateway: si Meta reintenta el webhook, el
      // flujo reconoce el duplicado y no gasta una segunda inferencia.
      message_id: d.message_id ?? null,
    },
  };
});
