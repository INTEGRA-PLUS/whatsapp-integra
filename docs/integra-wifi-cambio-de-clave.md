# Cambio de clave WiFi con Integra (API v1): cómo funciona en el CRM

> Documento de traspaso desde **Integra 2.0** hacia el CRM (wpp.integracolombia.com).
> Estado al **8 de octubre de 2026**. Integra en producción: commits `649659e3` (código) y `457fc1cd` (docs).

## 1. En una frase

El CRM cambia la clave del WiFi del cliente por la API de Integra. Desde el 8 de octubre de 2026 Integra además **devuelve todas las redes WiFi de la ONU** y **acepta que se elija en cuáles cambiar la clave**, y el panel del CRM ya lo usa: si el equipo tiene más redes que las dos principales, el asesor marca en cuáles va la clave (sección 6).

Si no se eligen redes, la clave va a las dos principales (2,4 y 5 GHz), como siempre.

## 2. Cómo funciona de punta a punta

```
Cliente (WhatsApp) ──pide──▶ Asesor en el CRM ──POST /contratos/{nro}/wifi──▶ Integra
                                                                              │
                         ┌────────────────────────────────────────────────────┤
                         ▼                                                    ▼
          ONU en el ACS (TR-069, GenieACS)                       ONU sin ACS / modelo sin mapear
          Integra le manda la clave al equipo                     Queda en la bandeja de WiFi
          estado: en_cola → aplicada (≤ 5 min)                    estado: pendiente_manual
          TODOS los dispositivos se desconectan                   La aplica una persona del ISP
```

- Integra decide solo si el cambio es automático. El CRM no habla con el ACS.
- La clave **nunca** se devuelve ni se puede leer: la ONU la reporta vacía.
- Una sola solicitud en curso por cliente. Si hay otra pendiente, Integra responde 422.
- Las mismas reglas valen para la app de clientes, el MCP y el panel de Integra, porque todos usan el mismo servicio (`CambiarClaveWifi`).

## 3. Autenticación y permisos

- Base: `https://{dominio-del-isp}/software/api/v1` (sin `/software` responde 404).
- Header: `Authorization: Bearer {token}` (el token `wpp-integraciones` de cada empresa).

| Acción | Permiso del token |
|---|---|
| `GET /contratos/{nro}/wifi` | `contratos.leer` |
| `POST /contratos/{nro}/wifi` | `contratos.wifi` (no viene con `contratos.leer`) |

**Estado de los tokens:** hoy (08-10-2026) los **19 tokens `wpp-integraciones` activos** ya tienen `contratos.wifi`. Antes solo 2 lo tenían (intercaldas y linktelecom); a los otros 17 se les agregó desde Integra. Nadie tiene que reconectar.

Al conectar una empresa nueva, el CRM ya lo pide como permiso **opcional** (`IntegraClient::ABILITY_WIFI` está en `ABILITIES_OPTIONAL`). Hoy todos los Integra en producción lo reconocen, así que podría pasar a `ABILITIES` si se quiere que sea obligatorio.

## 4. `GET /api/v1/contratos/{nro}/wifi`

Query opcional: `identificacion` (documento del titular; si no coincide → 404, el mismo 404 que «no existe», a propósito).

Respuesta `200` (ejemplo real de producción, con los nombres de red cambiados):

```json
{
  "success": true,
  "data": {
    "contrato": "16117",
    "automatico": true,
    "motivo_manual": null,
    "redes": [
      { "banda": "5",   "ssid": "CASA-5G" },
      { "banda": "2.4", "ssid": "CASA" }
    ],
    "todas_las_redes": [
      { "instancia": 1, "ssid": "CASA-5G",   "banda": "5",   "principal": true,  "activa": true,  "de_fabrica": false },
      { "instancia": 5, "ssid": "CASA",      "banda": "2.4", "principal": true,  "activa": true,  "de_fabrica": false },
      { "instancia": 2, "ssid": "FTTH-1111", "banda": "5",   "principal": false, "activa": false, "de_fabrica": true },
      { "instancia": 6, "ssid": "AP-1",      "banda": "2.4", "principal": false, "activa": false, "de_fabrica": true }
    ],
    "solicitudes": [
      { "id": 812, "estado": "aplicada", "automatico": true, "redes": null,
        "creada_en": "2026-10-08T10:12:00-05:00", "aplicada_en": "2026-10-08T10:14:31-05:00" }
    ]
  }
}
```

| Campo | Qué es |
|---|---|
| `automatico` | `true`: la ONU está en el ACS y la clave llega sola. `false`: la aplica una persona. |
| `motivo_manual` | Por qué no es automático (texto para el asesor). `null` si lo es. |
| `redes` | **Nuevo significado, mismo formato:** las dos redes principales. Es a las que va la clave si no se eligen redes. |
| `todas_las_redes` | **NUEVO.** Todas las redes de la ONU, primero las principales. Vacío si `automatico` es `false`. |
| `todas_las_redes[].instancia` | El número que se manda en el POST para elegir esa red. |
| `todas_las_redes[].principal` | Es una de las dos principales. |
| `todas_las_redes[].de_fabrica` | Apagada o con nombre de fábrica (`AP-1`, `FTTH-3333`, `SSID2`…). **No ofrecerla por defecto.** |
| `todas_las_redes[].activa` | Si la red está encendida (puede venir `null` si la ONU no lo reporta). |
| `solicitudes[].redes` | **NUEVO.** Nombres de las redes elegidas en esa solicitud; `null` = las principales. |

## 5. `POST /api/v1/contratos/{nro}/wifi`

Cuerpo:

```json
{
  "clave": "MiCasa2026",
  "identificacion": "1080427586",
  "instancias": [2]
}
```

| Campo | Obligatorio | Regla |
|---|---|---|
| `clave` | Sí | 8 a 63 caracteres ASCII imprimibles: sin tildes ni ñ. La dicta el cliente. |
| `identificacion` | Recomendado | Documento del titular; si no coincide → 404. |
| `instancias` | **NUEVO**, opcional | Lista de `instancia` de `todas_las_redes` (máx. 16). Sin este campo → las dos principales. Solo funciona si `automatico` es `true`. |

Respuesta `201`:

```json
{
  "success": true,
  "message": "Clave enviada al equipo del cliente.",
  "data": {
    "solicitud": {
      "id": 813,
      "contrato": "16117",
      "automatico": true,
      "estado": "en_cola",
      "redes": "Invitados",
      "mensaje_cliente": "Recibimos tu solicitud. Tu equipo aplicará la clave nueva en unos minutos; cuando pase, tus dispositivos se desconectarán y tendrás que conectarlos con la clave nueva."
    }
  }
}
```

- `estado`: `en_cola` (la ONU la aplica en su próximo reporte, ≤ 5 min), `aplicada` o `pendiente_manual`.
- `redes`: **nuevo**. Nombres de las redes elegidas, o `null` si fueron las principales.
- `mensaje_cliente`: texto listo para pegarle al cliente (el CRM ya lo muestra con botón de copiar).
- `motivo_manual`: solo cuando `automatico` es `false`.

Errores:

| Código | Cuándo | `message` (ya viene redactado para el asesor) |
|---|---|---|
| 403 | Al token le falta `contratos.wifi` | (genérico; el CRM ya lo traduce) |
| 404 | El contrato no existe o el documento no es del titular | `Contrato no encontrado.` |
| 422 | Clave inválida | `La clave debe tener entre 8 y 63 caracteres, sin tildes ni ñ.` |
| 422 | Ya hay un cambio en curso | `El cliente ya tiene un cambio de WiFi en curso. Hay que esperar a que se aplique.` |
| 422 | **Nuevo:** una `instancia` no existe en la ONU | `Alguna de las redes elegidas ya no aparece en el equipo del cliente. Consulta de nuevo las redes y vuelve a elegir.` |
| 422 | **Nuevo:** se mandaron `instancias` y la ONU no está en el ACS | `Solo se pueden elegir redes cuando el equipo del cliente está conectado al ACS. Sin elegir redes, la solicitud queda para una persona.` |
| 422 | **Nuevo:** el ACS no respondió al validar las redes | `No se pudo consultar el equipo en este momento. Intenta de nuevo en unos minutos.` |

En ningún 422 Integra crea la solicitud: no queda nada a medias y se puede reintentar.

**Por qué Integra valida las redes:** GenieACS descarta en silencio una escritura a una red que la ONU no tiene. Sin esa validación, la solicitud quedaría «aplicada» sin haber cambiado nada.

## 6. Cómo quedó implementado en el CRM (rama `feat/wifi-elegir-redes`)

### 6.1 `app/Services/IntegraClient.php`

`changeWifiPassword()` acepta las redes y las manda solo si vienen (sin repetidas, como enteros):

```php
public function changeWifiPassword(string $nro, string $clave, ?string $identificacion = null, ?array $instancias = null): array
{
    $res = $this->call('post', '/api/v1/contratos/'.rawurlencode($nro).'/wifi', array_filter([
        'clave' => $clave,
        'identificacion' => $identificacion,
        'instancias' => $instancias ? array_values(array_map('intval', $instancias)) : null,
    ], fn ($v) => $v !== null && $v !== '' && $v !== []));
    // … el manejo del 403 queda igual
}
```

`contractWifi()` no cambia: ya devuelve `data` completo, que ahora trae `todas_las_redes`.

### 6.2 `app/Http/Controllers/IntegrationController.php` → `cambiarClaveWifi()`

Valida `instancias` y se lo pasa al cliente:

```php
'instancias'   => ['nullable', 'array', 'max:16'],
'instancias.*' => ['integer', 'min:1', 'max:64'],
// …
$client->changeWifiPassword($nro, $data['clave'], $identificacion, $data['instancias'] ?? null);
```

El `Log::channel('whatsapp')` registra `instancias` (solo los números). **Nunca** la clave.

### 6.3 Panel del contrato (`resources/js/components/FichaIntegra.jsx`, `WifiDelContrato`)

Mismo comportamiento que la ficha del contrato en Integra:

1. Si `automatico` es `false`: no mostrar redes. Mostrar `motivo_manual` y el campo de clave. La solicitud queda para una persona.
2. Si `automatico` es `true` y `todas_las_redes` trae **solo las principales**: no mostrar casillas, la experiencia de siempre.
3. Si hay **más redes**: mostrar casillas.
   - Las **principales marcadas** por defecto, con su banda («5 GHz», «2,4 GHz»).
   - Las demás activas y no de fábrica, desmarcadas.
   - Las `de_fabrica`, **plegadas** en «Otras redes del equipo (apagadas o de fábrica)».
4. Si el asesor **no cambia la selección** (las principales marcadas): **no mandar `instancias`**. Así se conserva el comportamiento de siempre.
5. Exigir al menos una red marcada.
6. Al confirmar, repetir el aviso de siempre: todos los dispositivos conectados a esas redes se desconectan.
7. En el resultado y el historial, mostrar `redes` cuando venga: «Clave enviada a: Invitados».
8. Si llega el 422 de «redes que ya no aparecen», volver a pedir el `GET` y repintar las casillas.

Además, el listado del WiFi muestra las otras redes activas («Otra red · 2,4 GHz»), y el historial de «Últimos cambios» dice a qué redes fue cada uno.

### 6.4 Pruebas (`tests/Feature/WifiYProrrogaDelContratoTest.php`)

- `test_sin_redes_elegidas_no_viaja_instancias`: sin redes, el cuerpo hacia Integra no lleva el campo.
- `test_las_redes_elegidas_viajan_a_integra`: viajan sin repetir y como enteros; vuelven los nombres en `redes`.
- `test_redes_que_no_son_numeros_no_salen_del_crm`: texto, 0, 65 o más de 16 redes → 422 sin llamar a Integra.
- `test_una_red_que_ya_no_esta_en_la_onu_llega_con_su_motivo`: el 422 de Integra llega tal cual.
- Siguen en verde los 20 de antes, incluido `test_la_clave_no_aparece_en_ningun_log`, y los 359 de las suites de Integra, Ficha y Extensiones.

## 7. Referencias en Integra

- Servicio compartido: `app/Services/Escritura/CambiarClaveWifi.php`
- Endpoint: `app/Http/Controllers/Api/V1/ContratosApiController.php` (`wifi`, `cambiarClaveWifi`)
- ACS: `app/Services/Acs/WifiAcs.php` (`redes`, `todasLasRedes`, `aplicar`)
- OpenAPI: `docs/openapi.yaml`, ruta `/contratos/{nro}/wifi`; también en `https://{isp}/software/api/docs`
- Tests: `tests/Feature/Mcp/WifiApiYMcpTest.php` (14 casos, incluidas las redes elegidas)
- Respaldo de los permisos anteriores de los tokens: `/root/tokens-wpp-antes-wifi-20261008.tsv` en el VPS de tenants
