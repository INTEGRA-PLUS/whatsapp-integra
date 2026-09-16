
// ---------------------------------------------------------------
// Punto unico donde se arma el "job". Si maniana cambias de
// Execute Workflow a Kafka/RabbitMQ, solo cambias el nodo siguiente:
// el contrato del payload se mantiene igual.
// ---------------------------------------------------------------
const base = $('Validar entrada').first().json;

return [{
  json: {
    message_id: base.message_id,
    trace_id: base.trace_id,
    tenant_id: base.tenant_id,
    user_id: base.user_id,
    channel: base.channel,
    session_key: base.session_key,
    message: base.message,
    callback_url: base.callback_url,
    metadata: base.metadata,
    // El perfil de la empresa. Sin esta linea el worker recibe solo un
    // tenant_id —un numero— y no tiene con que nombrar a nadie.
    asistente: base.asistente,
    enqueued_at: new Date().toISOString(),
    attempt: 1,
  },
}];
