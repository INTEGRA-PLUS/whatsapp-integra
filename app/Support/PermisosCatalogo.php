<?php

namespace App\Support;

use Spatie\Permission\Models\Permission;

/**
 * Catálogo de permisos en español.
 *
 * La pantalla de roles mostraba el nombre crudo del permiso: el prefijo como
 * título de módulo («campaigns») y lo que sigue al punto como nombre de la
 * casilla («update»). El resultado era una interfaz en español salpicada de
 * inglés, donde nadie podía saber qué habilitaba cada casilla.
 *
 * Aquí se traduce una sola vez y se agrupa EXACTAMENTE igual que el menú
 * lateral (app-sidebar.jsx). Esa es la parte importante: quien reparte
 * permisos ya conoce el menú, así que reconoce lo que está autorizando sin
 * tener que aprender un vocabulario nuevo.
 *
 * Cualquier permiso que exista en la base y no esté aquí NO se descarta: sale
 * en el grupo «Otros permisos». Si se ocultara, al guardar un rol que lo
 * tuviera `syncPermissions()` se lo quitaría en silencio.
 */
class PermisosCatalogo
{
    /**
     * Acciones estándar. El texto va en segunda persona porque describe lo que
     * la persona con el rol va a poder hacer, no lo que hace el sistema.
     */
    public const ACCIONES = [
        'view'   => ['etiqueta' => 'Ver',       'ayuda' => 'Entrar y consultar'],
        'create' => ['etiqueta' => 'Crear',     'ayuda' => 'Agregar registros nuevos'],
        'update' => ['etiqueta' => 'Editar',    'ayuda' => 'Modificar lo que ya existe'],
        'delete' => ['etiqueta' => 'Eliminar',  'ayuda' => 'Borrar registros'],
        'run'    => ['etiqueta' => 'Ejecutar',  'ayuda' => 'Correr la acción sobre un chat'],
        'send'   => ['etiqueta' => 'Enviar',    'ayuda' => 'Emitir el envío'],
    ];

    /**
     * Niveles de acceso. Son la entrada rápida: en la mayoría de los casos
     * quien crea un rol piensa «que vea nomás» o «que trabaje pero no borre»,
     * no en cuatro casillas sueltas.
     */
    public const NIVELES = [
        'ninguno' => ['etiqueta' => 'Sin acceso', 'ayuda' => 'No aparece en el menú'],
        'ver'     => ['etiqueta' => 'Solo ver',   'ayuda' => 'Puede entrar y consultar, sin tocar nada'],
        'operar'  => ['etiqueta' => 'Operar',     'ayuda' => 'Puede consultar, crear y editar, pero no borrar'],
        'total'   => ['etiqueta' => 'Control total', 'ayuda' => 'Todo lo anterior y además eliminar'],
    ];

    /**
     * Módulos agrupados como en el menú lateral.
     *
     * `acciones` lista solo las que la aplicación realmente valida. Ejemplo:
     * el chat no se «crea» desde un botón, así que ofrecer `chat.create` sería
     * prometer un permiso que no gobierna nada.
     */
    public static function grupos(): array
    {
        return [
            [
                'clave'  => 'conversaciones',
                'titulo' => 'Conversaciones',
                'ayuda'  => 'El día a día: atender clientes y llevar su ficha.',
                'modulos' => [
                    [
                        'clave' => 'chat',
                        'nombre' => 'Chat',
                        'ayuda' => 'Atender y responder las conversaciones de WhatsApp.',
                        'icono' => 'chat',
                        'acciones' => ['view', 'update', 'delete'],
                        'textos' => [
                            'view'   => 'Abrir el chat y leer las conversaciones',
                            'update' => 'Responder, asignar y cambiar el estado de un chat',
                            'delete' => 'Borrar mensajes y conversaciones',
                        ],
                    ],
                    [
                        'clave' => 'crm',
                        'nombre' => 'CRM',
                        'ayuda' => 'El tablero por etapas donde avanza cada cliente.',
                        'icono' => 'crm',
                        'acciones' => ['view', 'create', 'update', 'delete'],
                        'textos' => [
                            'view'   => 'Ver el tablero y las tarjetas',
                            'create' => 'Crear tarjetas y etapas',
                            'update' => 'Mover tarjetas entre etapas y editarlas',
                            'delete' => 'Eliminar tarjetas y etapas',
                        ],
                    ],
                    [
                        'clave' => 'contacts',
                        'nombre' => 'Contactos',
                        'ayuda' => 'La libreta de clientes: datos, etiquetas y notas.',
                        'icono' => 'contacts',
                        'acciones' => ['view', 'create', 'update', 'delete'],
                        'textos' => [
                            'view'   => 'Consultar la lista de contactos y su ficha',
                            'create' => 'Agregar contactos e importarlos',
                            'update' => 'Editar datos, etiquetas y notas',
                            'delete' => 'Eliminar contactos',
                        ],
                    ],
                ],
            ],
            [
                'clave'  => 'automaticas',
                'titulo' => 'Respuestas automáticas',
                'ayuda'  => 'Lo que el sistema contesta solo cuando nadie está.',
                'modulos' => [
                    [
                        'clave' => 'whatsapp_menus',
                        'nombre' => 'Menús de WhatsApp',
                        'ayuda' => 'Los menús de opciones que ve el cliente al escribir.',
                        'icono' => 'menus',
                        'acciones' => ['view', 'create', 'update', 'delete'],
                    ],
                    [
                        'clave' => 'auto_responses',
                        'nombre' => 'Respuestas automáticas',
                        'ayuda' => 'Contestaciones que se disparan por palabra clave.',
                        'icono' => 'bot',
                        'acciones' => ['view', 'create', 'update', 'delete'],
                    ],
                    [
                        'clave' => 'quick_replies',
                        'nombre' => 'Respuestas rápidas',
                        'ayuda' => 'Textos guardados que el agente inserta con un clic.',
                        'icono' => 'rayo',
                        'acciones' => ['view', 'create', 'update', 'delete'],
                    ],
                    [
                        'clave' => 'macros',
                        'nombre' => 'Macros',
                        'ayuda' => 'Secuencias que hacen varias cosas de una sola vez.',
                        'icono' => 'varita',
                        'acciones' => ['view', 'create', 'update', 'delete', 'run'],
                        'textos' => [
                            'run' => 'Ejecutar macros sobre una conversación',
                        ],
                    ],
                    [
                        'clave' => 'business_hours',
                        'nombre' => 'Horarios de atención',
                        'ayuda' => 'Las franjas en que hay gente para atender.',
                        'icono' => 'reloj',
                        'acciones' => ['view', 'create', 'update', 'delete'],
                    ],
                ],
            ],
            [
                'clave'  => 'envios',
                'titulo' => 'Envíos',
                'ayuda'  => 'Mensajería masiva. Conviene darlo con cuidado: sale a nombre de la empresa y cuesta plata.',
                'modulos' => [
                    [
                        'clave' => 'campaigns',
                        'nombre' => 'Campañas',
                        'ayuda' => 'Envíos masivos de WhatsApp a listas de contactos.',
                        'icono' => 'megafono',
                        'acciones' => ['view', 'create', 'update', 'delete'],
                        'textos' => [
                            'create' => 'Crear y lanzar campañas masivas',
                        ],
                    ],
                    [
                        'clave' => 'templates',
                        'nombre' => 'Plantillas',
                        'ayuda' => 'Las plantillas aprobadas por Meta que usan las campañas.',
                        'icono' => 'plantilla',
                        'acciones' => ['view', 'create', 'update', 'delete'],
                    ],
                ],
            ],
            [
                'clave'  => 'analisis',
                'titulo' => 'Análisis',
                'ayuda'  => 'Cifras de la operación.',
                'modulos' => [
                    [
                        'clave' => 'reports',
                        'nombre' => 'Reportes',
                        'ayuda' => 'Indicadores de atención, tiempos y volumen de mensajes.',
                        'icono' => 'grafico',
                        'acciones' => ['view'],
                    ],
                ],
            ],
            [
                'clave'  => 'configuracion',
                'titulo' => 'Configuración',
                'ayuda'  => 'Lo que se toca una vez y afecta a toda la empresa.',
                'modulos' => [
                    [
                        'clave' => 'instances',
                        'nombre' => 'Instancias',
                        'ayuda' => 'Las líneas de WhatsApp conectadas.',
                        'icono' => 'engranaje',
                        'acciones' => ['view', 'create', 'update', 'delete'],
                    ],
                    [
                        'clave' => 'integrations',
                        'nombre' => 'Integraciones',
                        'ayuda' => 'Conexiones con otros sistemas y webhooks.',
                        'icono' => 'webhook',
                        'acciones' => ['view', 'create', 'update', 'delete'],
                    ],
                    [
                        'clave' => 'users',
                        'nombre' => 'Usuarios',
                        'ayuda' => 'Las personas del equipo que entran al sistema.',
                        'icono' => 'usuarios',
                        'acciones' => ['view', 'create', 'update', 'delete'],
                    ],
                    [
                        'clave' => 'roles',
                        'nombre' => 'Roles y permisos',
                        'ayuda' => 'Esta misma pantalla. Quien lo tenga puede ampliarse el acceso a sí mismo.',
                        'icono' => 'escudo',
                        'acciones' => ['view', 'create', 'update', 'delete'],
                        'delicado' => true,
                    ],
                    [
                        'clave' => 'notifications',
                        'nombre' => 'Notificaciones',
                        'ayuda' => 'Avisos internos para todo el equipo.',
                        'icono' => 'campana',
                        'acciones' => ['send'],
                    ],
                ],
            ],
        ];
    }

    /**
     * Une el catálogo con los permisos que existen de verdad en la base.
     *
     * Devuelve solo lo que está creado: si un módulo del catálogo todavía no
     * tiene permisos sembrados, no se ofrece una casilla que al guardar no
     * haría nada. Y al final agrega el grupo «Otros permisos» con lo que hay
     * en la base y no está catalogado, para que editar un rol nunca le quite
     * en silencio un permiso que la pantalla no supo mostrar.
     */
    public static function paraPantalla(): array
    {
        $existentes = Permission::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->keyBy('name');

        $usados = [];
        $grupos = [];

        foreach (self::grupos() as $grupo) {
            $modulos = [];

            foreach ($grupo['modulos'] as $modulo) {
                $permisos = [];

                // Primero las acciones que el catálogo declara, en su orden.
                foreach ($modulo['acciones'] as $accion) {
                    $nombre = $modulo['clave'].'.'.$accion;

                    if (! isset($existentes[$nombre])) {
                        continue;
                    }

                    $usados[$nombre] = true;

                    $permisos[] = [
                        'id'       => $existentes[$nombre]->id,
                        'accion'   => $accion,
                        'etiqueta' => self::ACCIONES[$accion]['etiqueta'] ?? ucfirst($accion),
                        'ayuda'    => $modulo['textos'][$accion]
                            ?? (self::ACCIONES[$accion]['ayuda'] ?? ''),
                    ];
                }

                // Y después cualquier otra acción de ESTE módulo que exista en
                // la base y el catálogo no contemple. Se muestra igual: sigue
                // perteneciendo a un módulo real, y esconderla haría que
                // `syncPermissions()` se la quitara al rol sin avisar.
                $prefijo = $modulo['clave'].'.';

                foreach ($existentes as $nombre => $permiso) {
                    if (isset($usados[$nombre]) || ! str_starts_with($nombre, $prefijo)) {
                        continue;
                    }

                    $accion = substr($nombre, strlen($prefijo));
                    $usados[$nombre] = true;

                    $permisos[] = [
                        'id'       => $permiso->id,
                        'accion'   => $accion,
                        'etiqueta' => self::ACCIONES[$accion]['etiqueta'] ?? ucfirst($accion),
                        'ayuda'    => self::ACCIONES[$accion]['ayuda'] ?? '',
                    ];
                }

                if (empty($permisos)) {
                    continue;
                }

                $modulos[] = [
                    'clave'    => $modulo['clave'],
                    'nombre'   => $modulo['nombre'],
                    'ayuda'    => $modulo['ayuda'],
                    'icono'    => $modulo['icono'] ?? 'escudo',
                    'delicado' => $modulo['delicado'] ?? false,
                    'permisos' => $permisos,
                ];
            }

            if (! empty($modulos)) {
                $grupos[] = [
                    'clave'   => $grupo['clave'],
                    'titulo'  => $grupo['titulo'],
                    'ayuda'   => $grupo['ayuda'],
                    'modulos' => $modulos,
                ];
            }
        }

        $sobrantes = $existentes->reject(fn ($p) => isset($usados[$p->name]));

        if ($sobrantes->isNotEmpty()) {
            $grupos[] = [
                'clave'  => 'otros',
                'titulo' => 'Otros permisos',
                'ayuda'  => 'Permisos que existen en la base y no pertenecen a ningún módulo del sistema. '
                    .'Casi siempre son restos de roles viejos y no habilitan nada.',
                'sobrante' => true,
                'modulos' => [[
                    'clave'    => 'otros',
                    'nombre'   => 'Sin módulo',
                    'ayuda'    => 'Se muestran para no quitártelos sin avisar al guardar.',
                    'icono'    => 'interrogacion',
                    'delicado' => false,
                    'permisos' => $sobrantes->values()->map(fn ($p) => [
                        'id'       => $p->id,
                        'accion'   => 'otro',
                        'etiqueta' => $p->name,
                        'ayuda'    => '',
                    ])->all(),
                ]],
            ];
        }

        return $grupos;
    }

    /**
     * Prefijos de los módulos que existen de verdad en la aplicación.
     *
     * El comando de limpieza decide por MÓDULO, no por acción: conserva todo
     * lo que empiece por un módulo real (`chat.*`, `crm.*`…) aunque el catálogo
     * no describa esa acción en particular, y borra solo lo que cuelga de un
     * módulo inexistente, que es lo que dejaban los roles al crearse
     * (`ventas.view`, `soporte.delete`…). Distinguir a nivel de acción sería
     * apostar a que ningún `can()` del sistema se me escapó en la lectura.
     */
    public static function modulosReales(): array
    {
        $claves = [];

        foreach (self::grupos() as $grupo) {
            foreach ($grupo['modulos'] as $modulo) {
                $claves[] = $modulo['clave'];
            }
        }

        return $claves;
    }
}
