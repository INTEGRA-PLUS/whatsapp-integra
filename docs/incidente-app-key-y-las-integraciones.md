# «Se me borró la integración»: dos APP_KEY distintas en el mismo servidor

**El síntoma (10-sep-2026).** Transinternet conectó Integra por Complementos **tres veces en una mañana**.
Cada vez la conexión funcionaba, y al rato la pantalla volvía a enseñar el formulario de conectar, con la
URL y el token en blanco, como si nunca se hubiera conectado nadie.

**Lo que no era.** La fila no se borraba. `company_integrations` tenía las dos filas de la empresa intactas,
en `status = connected`, con su `connected_at` y su token guardado. Tampoco había ningún `delete` ni ningún
`disconnect` en el código que pudiera dispararse solo.

**Lo que era.** `access_token` está cifrado con la `APP_KEY`. En el servidor había **dos llaves distintas**:

| archivo | APP_KEY |
|---|---|
| `.env.docker` | `base64:11g2XVL…` — la que cifró los tokens |
| `.env` | `base64:nE5eFDv…` — otra |

`docker-compose.yml` resuelve `APP_KEY: ${APP_KEY:?...}` **contra el archivo de entorno con el que se
invoque compose**. Desplegando con `docker compose --env-file .env.docker up -d` el contenedor arrancaba con
la primera; desplegando con `docker compose up -d` a secas, compose leía el `.env` del directorio y arrancaba
con la segunda. Cada despliegue podía cambiar la llave según cómo lo hubiera escrito quien lo hiciera.

Con la llave equivocada, `isConnected()` no devuelve `false`: **lanza** `DecryptException`. Eso hacía dos
cosas a la vez, y ninguna decía la verdad:

- Al pintar Complementos, la excepción salía desde el listado y devolvía `{"message":"Server Error"}` para
  toda la empresa (arreglado aparte: una credencial ilegible ahora vale lo mismo que no tener credencial).
- Con el listado ya blindado, la integración pasaba a verse **desconectada**, que es lo que el cliente lee
  como «se me borró» — y reconectar *parecía* arreglarlo, porque el token nuevo se cifraba con la llave viva
  en ese momento. Hasta el siguiente despliegue con el otro comando.

En el log no quedaba casi nada: un `production.ERROR: The MAC is invalid.` suelto por cada visita a la
pantalla, sin nombre de empresa ni de integración.

## El arreglo

1. **Las dos llaves iguales.** `.env` ahora lleva la misma `APP_KEY` que `.env.docker` —la que cifró los
   datos—, así que ya da igual cómo se invoque compose. Copia de seguridad en `.env.bak-llave-20260910-1545`.
   Los tokens volvieron a leerse solos: **no hizo falta reconectar a nadie**.
2. **Una credencial ilegible ya no tumba la pantalla** ni se confunde con no tener credencial. La tarjeta
   dice lo único que la arregla en ese caso, en vez de invitar a pulsar «Verificar» contra algo que nunca va
   a responder.
3. **`integraciones:credenciales`**, en el planificador a las 07:05. Cuenta cuántas credenciales no se pueden
   descifrar y de quién son, y nombra la causa probable. Si esto vuelve a pasar se sabrá al día siguiente y
   no cuando un cliente lo reporte por tercera vez.

## Lo que hay que recordar al desplegar

**Siempre `--env-file .env.docker`.** El `Makefile` ya lo pone en todos sus objetivos; el peligro es escribir
`docker compose` a mano. Ahora que las dos llaves coinciden, olvidarlo ya no rompe el cifrado — pero sí
seguiría cogiendo el resto de variables del archivo equivocado.

Y la regla general: **cambiar la `APP_KEY` de una instalación con datos cifrados es una migración, no un
ajuste.** Deja atrás todo lo que hubiera cifrado con la anterior, en silencio y de golpe.
