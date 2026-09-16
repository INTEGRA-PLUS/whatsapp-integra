<?php

namespace App\Support\Documentos;

use RuntimeException;

/**
 * El documento se subió bien pero no hay nada que leer dentro.
 *
 * El mensaje de esta excepción **se le enseña al admin tal cual**, así que se
 * escribe para él y no para un log: «este PDF es una imagen escaneada» y no
 * «empty text layer». Un documento que se queda en «fallido» sin decir por qué
 * es un documento que alguien sube tres veces antes de preguntar.
 */
class DocumentoIlegible extends RuntimeException {}
