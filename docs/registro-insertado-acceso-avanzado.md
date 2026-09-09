# «Función no disponible»: el registro insertado sólo funcionaba con cuentas con rol en la app

**El incidente (8-sep-2026).** Con un cliente delante, el diálogo de registro insertado devolvía
*"Función no disponible — En este momento, el inicio de sesión con Facebook no está disponible debido a
que estamos actualizando otros detalles de la app"* para toda cuenta de Facebook que no fuera la de Juan
José Tuirán. Con la suya, el flujo iba entero.

**La causa, y no es ninguna de las dos que parecían.** El permiso `public_profile` estaba en **acceso
estándar**. La documentación de Facebook Login for Business no deja margen:

> "advanced access to the `public_profile` permission **is required** for Facebook Login for Business apps
> before they go live. This requirement is crucial to ensure that the app can support authorization from
> **users who do not have an app role**, commonly referred to as external users."

Y la de registro insertado remata:

> "once you switch your app to live mode, **only permissions that have been approved for advanced access**
> through the App Review process will appear in the flow."

App en `live_mode` + `public_profile` en estándar = el diálogo no puede autorizar a nadie sin rol. Eso es
exactamente lo que se veía, y explica el patrón "sólo funciona con una cuenta".

**El arreglo fueron dos clics**, sin App Review ni vídeo, porque `public_profile` es de concesión
automática: *Casos de uso → Conectar en WhatsApp → Permisos y funciones → `public_profile` → Actions →
Aumentar acceso*. Meta pide reintroducir la contraseña de la cuenta para aplicarlo. Comprobado después
por API: `access_level: advanced`, `grant_status: DEVOPS_APPROVED`.

**Confirmado el 9-sep-2026**: con el acceso avanzado concedido, una cuenta de Facebook **sin rol en la
app** completa el registro insertado. El caso queda cerrado.

Ojo: el diálogo de confirmación avisa de que `public_profile` también pertenece al caso de uso de la API
de marketing —que esta app tiene sin usar— y de que el cambio "might mean new requirements and reviews".
Las apps con acceso avanzado quedan sujetas a *Ongoing Review*, con requisitos reducidos para las de
Facebook Login for Business.

## Los dos callejones sin salida, para no repetirlos

**No era la configuración de inicio de sesión.** `1686339276244548` ("Coexistencia v4", la que usa
producción) pide exactamente dos permisos —`whatsapp_business_management` y
`whatsapp_business_messaging`—, los dos con acceso avanzado y al máximo concedido: su menú sólo ofrece
"Reducir acceso". Se revisó pantalla por pantalla.

**No era `business_management`, aunque acumule 68 llamadas en 30 días y esté rechazado.** La
documentación de App Review para proveedores de WhatsApp lista los permisos que hay que pedir con acceso
avanzado y ése no está: sólo los dos de WhatsApp y, opcional, `ads_read` para Marketing Messages.
`business_management` aparece en un único contexto:

> "the system user who the token represents must have granted your app the `business_management`
> permission... **in order to be able to share your credit line**"

Eso es de **Solution Partners**, que tienen línea de crédito. Integra es **Tech Provider**: los clientes
ponen su propio método de pago. Pedirlo fue probablemente la razón de que App Review lo rechazara, y se
puede quitar del caso de uso en vez de volver a pedirlo. Lo mismo con
`whatsapp_business_manage_events` (32 llamadas, rechazado), que sólo hace falta con la Marketing
Messages API y la Conversions API.

## Lo que se descartó de camino, todo comprobado el 8-sep

App en `live_mode` y publicada · cumplimiento sin acciones requeridas ni infracciones · sin
restricciones de ubicación · App Review aprobado (5-sep) · registro de Tech Provider completo, 2 de 2
pasos · política de privacidad y verificación del negocio en orden (`can_submit: true`).

Y un falso positivo que costó tiempo: en la consola del navegador aparecía
`Uncaught Error: Corruption: block checksum mismatch` al abrir el diálogo. Es un error de LevelDB del
perfil de Chrome —no hay IndexedDB en este frontend—, no tiene nada que ver.

## Material que quedó hecho, por si vuelve a hacer falta

En `docs/app-review-video-rotulos.md` y `docs/app-review-video.srt` están los rótulos en inglés de un
screencast del flujo completo, cuadrados a una grabación real de 2:33. No se llegó a enviar ninguna
solicitud —no hizo falta—, pero la documentación de App Review pide, si algún día toca renovar:

- `whatsapp_business_management`: vídeo creando una plantilla de mensaje.
- `whatsapp_business_messaging`: vídeo enviando un mensaje y recibiéndolo en el cliente de WhatsApp.
- **Un vídeo distinto por permiso.** Un solo vídeo que muestre varios permisos es motivo de rechazo.
