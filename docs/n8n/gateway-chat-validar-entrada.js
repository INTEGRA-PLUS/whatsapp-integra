// ---------------------------------------------------------------
// Valida, normaliza y enriquece el mensaje entrante.
// Falla rapido: todo lo que no cumpla contrato se rechaza aqui,
// antes de gastar un solo token de LLM.
// ---------------------------------------------------------------
const MAX_LEN = 4000;
const uuid = () =>
  (typeof crypto !== 'undefined' && crypto.randomUUID)
    ? crypto.randomUUID()
    : `${Date.now().toString(36)}-${Math.random().toString(16).slice(2, 12)}`;

const out = [];

for (const item of $input.all()) {
  const body = item.json.body ?? item.json ?? {};
  const headers = item.json.headers ?? {};
  const errors = [];

  const text = typeof body.message === 'string' ? body.message.trim() : '';
  if (!text) errors.push('message es requerido y debe ser string no vacio');
  if (text.length > MAX_LEN) errors.push(`message excede ${MAX_LEN} caracteres`);

  const userId = String(body.user_id ?? '').trim();
  if (!userId) errors.push('user_id es requerido');
  if (userId.length > 128) errors.push('user_id demasiado largo');

  const tenantId = String(body.tenant_id ?? 'default').trim();
  // El canal se deriva de la ruta que recibio el POST, no del body:
  // asi un llamante no puede colarse en las sesiones de otro canal.
  const fromRoute = String(item.json.webhookUrl ?? '').includes('/chat/wa')
    ? 'whatsapp'
    : null;
  const channel = fromRoute ?? String(body.channel ?? 'api').trim();

  // El callback es como devolvemos la respuesta ya que el HTTP es 202.
  const callbackUrl = String(body.callback_url ?? '').trim();
  if (callbackUrl && !/^https:\/\//i.test(callbackUrl)) {
    errors.push('callback_url debe ser https');
  }

  // Idempotencia: si el cliente no manda message_id lo generamos,
  // pero entonces NO hay proteccion contra reintentos duplicados.
  const messageId = String(body.message_id ?? uuid());

  // Quien es la IA de esta empresa: nombre, tono, conocimiento, limites y
  // el texto entrenable. Lo manda Laravel ya saneado y el worker lo vuelve
  // a recortar por si acaso; aqui solo tiene que SOBREVIVIR.
  //
  // Este nodo reconstruye el objeto campo por campo, asi que todo lo que no
  // se nombre aqui se pierde en silencio. Es lo que pasaba antes: el perfil
  // salia de Laravel, moria en esta linea y las cuarenta empresas acababan
  // presentandose con la misma identidad escrita a mano en el worker.
  const asistente = (body.asistente && typeof body.asistente === 'object')
    ? body.asistente
    : null;

  out.push({
    json: {
      valid: errors.length === 0,
      errors,
      message_id: messageId,
      trace_id: headers['x-request-id'] ?? messageId,
      tenant_id: tenantId,
      user_id: userId,
      channel,
      session_key: `chat:${tenantId}:${channel}:${userId}`,
      message: text,
      callback_url: callbackUrl || null,
      metadata: body.metadata ?? {},
      asistente,
      received_at: new Date().toISOString(),
    },
  });
}

return out;