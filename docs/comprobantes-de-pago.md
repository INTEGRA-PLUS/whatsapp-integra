# Comprobantes de pago por WhatsApp

Cuando un cliente manda la captura de un pago, el CRM la lee sola y la deja **pendiente de aprobación**. El pago
en Integra lo registra una persona. Nada de aquí registra un pago por su cuenta.

## Por qué existe

Verificado el 30-sep-2026: con una captura no pasaba nada.

- La visión (`ImagenDelCliente`) la describía en prosa, y sólo si el chat IA estaba encendido, la opción
  «ver imágenes» también y no había asesor asignado. La descripción no se guardaba.
- La IA de chat no tiene herramientas. Aun así, si el modelo contestaba «tu pago quedó registrado», **eso le
  llegaba al cliente** sin que nadie hubiera registrado nada.
- El pago lo seguía escribiendo un asesor en el modal del chat. Un doble clic registraba **dos** pagos,
  con dos recibos de caja distintos, y lo podía hacer un usuario sin ningún permiso. Esto se comprobó en vivo.

## Cómo funciona

1. **Se enciende por empresa** en *Integraciones → Pagos a facturas → «Leer las capturas de pago»*
   (`settings.leer_comprobantes`). Nace apagado: cada foto que entra cuesta una llamada al modelo de visión,
   sea o no un comprobante. Hace falta además `VISION_TOKEN` en el servidor.
2. **El webhook sólo encola** `LeerComprobanteDePago`. El job descarga la foto y le pide al modelo JSON con
   claves fijas: `es_comprobante`, `monto`, `fecha`, `referencia`, `banco`, `destino` y `estado`
   (`LectorDeComprobante`). Corre aunque haya un asesor asignado, porque es justo él quien lo aprueba.
3. Si es un comprobante, se crea un registro en `comprobantes_de_pago` en estado `pendiente`, y bajo la foto
   del chat aparece una tarjeta con lo leído.
4. **Aprobar** pide el permiso `pagos.aprobar` (lo tienen los admin; el resto, desde Roles). El modal de pago
   se abre ya precargado: busca al cliente por el teléfono, elige la factura si sólo una cuadra con el monto
   leído, y trae el valor, la fecha y la referencia.
5. **Rechazar** pide un motivo. Aprobar y rechazar dejan una nota interna en el hilo, con quién lo hizo.
6. La bandeja **Pagos por aprobar** (`/pagos-por-aprobar`) reúne todos los de la empresa. Desde ahí se
   abre el chat; se aprueba allí, al lado de la foto y de la conversación.

## Decisiones que no hay que «arreglar»

- **Siempre lo aprueba una persona.** Registrar un pago en Integra reconecta al cliente en el router en el
  acto, y un comprobante editado se lee igual que uno real. El modelo confunde un 8 con un 3.
  La tarjeta y el modal lo dicen: confirmar en el banco antes de aprobar.
- **Reserva atómica** `pendiente → aprobando` en un solo `UPDATE`. Es lo que impide pagar dos veces por un
  doble clic o por dos asesores a la vez. Si actualiza cero filas, se responde 409.
- **La referencia viaja como `comprobante_pago`.** Integra rechaza una referencia repetida, así que
  reintentar tras un corte no duplica el pago. Sin referencia leída se manda `WA-{id}`, que es estable.
- **Referencia normalizada** (`referencia_normalizada`: sin espacios, guiones ni ceros a la izquierda).
  «00123-456» y «123456» son el mismo pago. Una referencia, o una imagen idéntica (`imagen_sha256`), que ya
  se aprobó en otro comprobante no se vuelve a aprobar. Al leer sólo se marca como repetida
  (`duplicado_de_id`), porque puede ser el cliente reenviándola porque nadie le contestó.
- **Nunca por encima del saldo.** Integra aplica sólo el saldo y **pierde la diferencia sin avisar**: no la
  guarda como saldo a favor. Aquí se responde 422 antes de llamar.
- **Si Integra no contesta, el estado es `revisar` y no `pendiente`.** El pago pudo entrar: Integra confirma
  antes de hablar con MikroTik, y sólo el login ya tarda 20 s. El tiempo de espera del pago es de 60 s.
- **Sin claves foráneas en `comprobantes_de_pago`, a propósito.** Con cualquier FK hacia `whatsapp_messages`
  —aunque fuera la única—, MySQL dejaba a medias el borrado en cascada de una instancia entera, sin error:
  sobrevivían mensajes de su historial. Lo detectó `BorradoDeInstanciaTest` el 30-sep-2026: con la FK falla
  siempre, sin ella pasa. Los comprobantes se borran a mano donde se borra historial:
  `InstanceController::destroy`, el borrado aprobado de una conversación y `MontarEmpresaDemo`.
  Quien añada otro borrado de historial tiene que añadirlos.
- La visión de la IA de chat lleva la regla *«NO digas que el pago quedó registrado»*. El modelo no ha
  registrado nada.

## Lo que falta

- **El modal de pago manual sigue abierto**: `/api/integrations/invoice-payments/pay` sólo pide sesión, no
  tiene idempotencia y no ata la factura al cliente del hilo. Esto afecta al pago escrito a mano, no a los
  comprobantes.
- **Avisarle al cliente** cuando se aprueba o se rechaza. Hoy no se le manda nada; hay que pasar por la
  ventana de 24 h (ver `.claude/rules/envios-whatsapp.md`).
- **La imagen no llega a Integra**: su API no acepta adjunto (`ingresos.adjunto_pago` sólo se llena desde la
  web), así que el recibo queda sin soporte.
- **Del estado `revisar` sólo se sale aprobando de nuevo o rechazando.** Si Integra responde que la
  referencia ya está registrada, significa que el primer intento sí entró. No hay un botón de «marcar como ya
  registrado»: hoy se rechaza con ese motivo.
- **`s3_media` sube las fotos con ACL pública**, y sus credenciales de producción están escritas en
  `config/filesystems.php`. Los comprobantes son datos bancarios de clientes.
