# Plan: sacar «Flujo IA» de Configuración y ponerla detrás del plan

*Escrito el 15-sep-2026 para repartir el trabajo entre dos agentes.* Este
documento es para **quien se encargue de la pantalla**. El modelo de planes lo
está tocando otra sesión en paralelo; la frontera entre los dos está en
«[El contrato entre las dos sesiones](#el-contrato-entre-las-dos-sesiones)» y es
lo primero que hay que leer.

## Qué se quiere

Que un admin entre por el menú lateral a una pantalla donde configura que la IA
responda los chats sola. Referencia de enfoque: `app.tecnochat.com/ai-settings`.

Tres cosas, en este orden:

1. **Sale del cajón de Configuración** y pasa a ser su propia entrada del menú
   lateral. Hoy está enterrada en `/settings` como una pestaña más entre
   «Apariencia» y «Horarios», y es la función que se va a vender.
2. **Se cierra por plan.** Si la empresa tiene el complemento de IA contratado,
   configura. Si no, ve la pantalla —con lo que hace, para que la quiera— y un
   aviso de que tiene que contratarlo.
3. **Pagar desde ahí, con IntegraPay.** *Esto no se hace ahora.* Por ahora el
   aviso dice que contacte con un administrador. Queda anotado abajo.

## Lo que ya existe y NO hay que rehacer

El backend está hecho y funciona. Es lo que hay que mover, no lo que hay que
escribir.

| Pieza | Dónde | Qué hace |
|---|---|---|
| `AiFlowSettingsController` | `app/Http/Controllers/` | `show` · `unlock` · `lock` · `update` |
| Rutas | `routes/web.php:563-572` | `api/settings/ai-flow`, con `permission:whatsapp_menus.update` |
| `TabFlujoIA()` | `resources/js/pages/Settings/Index.jsx:2806` hasta el final (~336 líneas) | La pantalla entera |
| `AsistenteCard()` | `Settings/Index.jsx:2474` | Identidad del asistente por empresa |
| `PromptCard()` | `Settings/Index.jsx:2675` | El prompt entrenable |
| `AiSwitch()` · `AI_PERMISSION_LABELS` | `Settings/Index.jsx:2448` y `:2442` | Interruptores y etiquetas |
| `Company::aiFlowUnlocked()` | `app/Models/Company.php:84` | Si la empresa pasó el secreto |

Documentación relacionada: `docs/prompt-entrenable-por-empresa.md`.

## El candado que ya hay, y por qué NO se quita

Hoy el apartado está detrás de un **secreto** que escribe el equipo
(`services.ai_activation.secret`, del `.env`). No es una credencial —no da
acceso a nada— es un freno: encender esto pone a un modelo a hablar con clientes
reales, y el desbloqueo queda registrado con quién lo hizo.

**Ese freno se queda.** Lo que se añade delante es el candado comercial:

```
¿tiene el complemento de IA contratado?
        │
        ├── NO  →  pantalla de venta + «contacta con un administrador»
        │          (ni se enseña el formulario del secreto)
        │
        └── SÍ  →  ¿ya pasó el secreto?
                        ├── NO  →  formulario del secreto, como hoy
                        └── SÍ  →  la configuración, como hoy
```

Son dos preguntas distintas —«¿lo pagó?» y «¿está seguro?»— y juntarlas en una
es lo que haría que el día que alguien contrate el plan, la IA se encienda sola
sin que nadie del equipo lo sepa.

## El contrato entre las dos sesiones

**No toques `config/planes.php` ni `app/Support/PlanDeLaEmpresa.php`.** Los está
reescribiendo la otra sesión: los tres planes actuales (`esencial`,
`automatizacion`, `inteligente`) se van a partir en un plan de CRM por tamaño
más un complemento de IA aparte, y cualquier cambio ahí va a chocar.

Lo único que necesitas de ese lado es **una pregunta**, y ya existe:

```php
App\Support\PlanDeLaEmpresa::de($company)->tieneIa()   // bool
```

Hoy devuelve `true` si el plan tiene crédito de IA. Después de la reescritura va
a seguir devolviendo lo mismo con la lógica nueva. **Programa contra esa
llamada** y no contra `$company->plan`, ni contra `config('planes.disponibles')`,
ni contra el nombre de un plan: todo eso cambia esta semana.

Si necesitas algo que `PlanDeLaEmpresa` no responda —por ejemplo «¿qué
complemento tiene contratado?» para enseñarlo en el aviso— **pídelo, no lo
escribas**: el sitio único por donde pasan las preguntas de plan es a propósito,
porque un candado repartido por cinco controladores es un candado que alguien se
deja abierto al añadir el sexto.

## El trabajo, paso a paso

### 1. La ruta y la pantalla

Una página Inertia propia, no una pestaña. Sugerencia de nombres, para que
encaje con lo que ya hay:

- Ruta: `/ia` → `name('ia.index')`
- Controlador: `app/Http/Controllers/FlujoIaController.php`
- Pantalla: `resources/js/pages/FlujoIa/Index.jsx`

El controlador manda a la pantalla el estado que hoy devuelve
`AiFlowSettingsController::show()` **más** la respuesta del plan. Las rutas
`api/settings/ai-flow/*` se quedan donde están: la pantalla nueva las sigue
llamando igual.

### 2. Sacarlo de Settings

Mover `TabFlujoIA` y sus tres componentes auxiliares a
`resources/js/pages/FlujoIa/`. `Settings/Index.jsx` tiene 3.142 líneas y el
flujo IA son 336 — sacarlo es tan útil para ese fichero como para esta función.

Quitar la pestaña `{ id: 'flujo-ia', … }` de `Settings/Index.jsx:26`.

**Ojo:** comprobar si alguien enlaza a `/settings?tab=flujo-ia`. Si lo hace,
dejar una redirección: el enlace está en manuales y en WhatsApps del equipo.

### 3. El menú lateral

En `resources/js/components/app-sidebar.jsx`, en el grupo de «Respuestas
automáticas» (líneas 95-98), junto a «Menús de WhatsApp» y «Respuestas
Automáticas» — es donde el admin va a buscar esto.

```js
{ title: 'IA que responde', href: route('ia.index'), icon: Sparkles,
  show: hasPermission('whatsapp_menus.update') },
```

**Se ve siempre**, tenga o no el plan. Esconder lo que no se ha comprado es la
forma más segura de que nadie lo compre: si no aparece, nadie pregunta. Lo que
cambia es lo que hay dentro.

Si lleva distintivo, que diga lo que es: un «PRO» junto al título cuando no lo
tiene contratado. No un candado gris, que se lee como «no tienes permiso» y
manda al admin a pelearse con sus roles.

### 4. La pantalla cuando NO lo tiene contratado

Es una pantalla de venta, y es la parte que más importa. No un error.

- **Qué hace la función**, en concreto y con el vocabulario del cliente: que la
  IA conteste sola los chats, con la identidad de su empresa y las reglas que él
  le ponga. Las maquetas de `resources/js/pages/Extensions/maquetas.jsx` son el
  precedente de cómo se enseña una función apagada en este proyecto — misma idea.
- **El aviso:** «Este paquete no está incluido en tu plan. Contacta con un
  administrador para habilitarlo.» Texto exacto por acordar con Alejandro.
- **Nada que pulsar que no lleve a ningún sitio.** Sin botón «Contratar» que no
  haga nada: mientras no exista el pago, un botón muerto es peor que ninguno.

### 5. Los guardarraíles del backend

La pantalla no es el candado. `AiFlowSettingsController::update()` y `unlock()`
tienen que rechazar a quien no tiene el plan, aunque llegue por `curl`.

Sigue el precedente que ya hay en `ExtensionController.php:81`:

```php
if (! $this->plan()->permiteExtension($slug)) {
    abort(402, 'Esta extensión no está incluida en tu plan.');
}
```

**402 y no 403**, y el comentario de ahí explica por qué: no es un problema de
permisos sino de plan, y el frontend tiene que poder distinguirlos para decir
«mejora tu plan» en vez de «no tienes acceso». Haz lo mismo aquí.

### 6. Tests

Lo que hay que proteger, en orden de lo que más caro sale:

1. **Sin el complemento contratado, `update` responde 402** aunque el usuario
   tenga todos los permisos de su empresa.
2. **Sin el complemento, `unlock` responde 402** aunque el secreto sea correcto.
   Es el que se olvida: el secreto correcto no puede saltarse el plan.
3. **Con el complemento y sin el secreto**, la pantalla enseña el formulario del
   secreto y no la configuración.
4. **El aislamiento de siempre:** desbloquear una empresa no desbloquea otra.

Mira `tests/Feature/CobroDelMesTest.php` para el estilo de tests de este
proyecto: cada uno lleva escrito en su docblock **qué incidente evita**, no qué
método llama.

## Lo que queda anotado para después

**Pagar el complemento desde la propia pantalla, con IntegraPay.** Alejandro lo
va a explicar; no empieces por ahí y no dejes el botón puesto «para cuando
esté». Cuando llegue, lo que hoy dice «contacta con un administrador» pasa a ser
el flujo de pago, y el resto de la pantalla no se toca.

## Antes de empezar

- La rama viva es `master`. Ramas nuevas: `tipo/descripcion-en-espanol`.
- Hay **dos sesiones trabajando en este repo a la vez**. Ya pasó hoy: una edición
  hecha directamente en el servidor estuvo a punto de perderse con un `git pull`.
  Trabaja en tu rama, haz `git fetch` antes de cada push, y no des por hecho que
  `master` está donde lo dejaste.
- Léete `CLAUDE.md` entero antes de tocar nada, sobre todo lo del aislamiento
  entre empresas: no hay global scopes, son ~315 `where('company_id', …)` a mano,
  y olvidarlo no lanza ningún error — devuelve datos de otra empresa en silencio.
