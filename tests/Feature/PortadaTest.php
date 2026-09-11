<?php

namespace Tests\Feature;

use Tests\TestCase;

class PortadaTest extends TestCase
{
    /**
     * La raíz dejó de ser una página pública cuando se puso ahí el resumen del
     * cliente: ahora es el tablero de entrada y exige sesión. Antes de eso este
     * test venía del andamiaje de Laravel y esperaba un 200.
     */
    public function test_la_raiz_manda_al_login_a_quien_no_ha_entrado(): void
    {
        $this->get('/')->assertRedirect('/login');
    }
}
