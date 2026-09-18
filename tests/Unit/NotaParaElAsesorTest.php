<?php

namespace Tests\Unit;

use App\Support\NotaParaElAsesor;
use PHPUnit\Framework\TestCase;

/**
 * La nota que la IA le deja al asesor, saneada.
 *
 * La escribe un flujo de n8n y se pinta en el hilo de la conversación: es texto
 * de fuera que acaba en la pantalla del equipo.
 */
class NotaParaElAsesorTest extends TestCase
{
    /**
     * Una traza de axios no es una nota para una persona.
     *
     * Lo que llegó el 18-sep-2026: seis líneas con rutas del servidor y la
     * versión de n8n dentro, ocupando más que la conversación.
     *
     * @test
     */
    public function corta_la_traza_y_deja_la_frase(): void
    {
        $nota = 'La IA derivó el chat. · Motivo técnico: Ollama no respondió: '
            .'{"error":{"message":"401 - Unauthorized"},"name":"AxiosError","stack":'
            .'"AxiosError: Request failed with status code 401\n at settle '
            .'(/usr/local/lib/node_modules/n8n/node_modules/axios/dist/node/axios.cjs:2199:12)"}';

        $limpia = NotaParaElAsesor::limpia($nota);

        $this->assertSame('La IA derivó el chat. · Motivo técnico: Ollama no respondió', $limpia);
        $this->assertStringNotContainsString('node_modules', $limpia);
        $this->assertStringNotContainsString('AxiosError', $limpia);
        $this->assertStringNotContainsString('{', $limpia);
    }

    /** Una nota de verdad pasa entera: es justo la que el asesor necesita. */
    public function test_una_nota_util_no_se_toca(): void
    {
        $nota = 'Pregunta por los aportes sociales: quiere montos y condiciones.';

        $this->assertSame($nota, NotaParaElAsesor::limpia($nota));
    }

    /** Sin nota, null: el traspaso usa entonces su aviso genérico. */
    public function test_vacia_es_null(): void
    {
        $this->assertNull(NotaParaElAsesor::limpia(null));
        $this->assertNull(NotaParaElAsesor::limpia('   '));
        $this->assertNull(NotaParaElAsesor::limpia('{"error":"solo json"}'));
    }

    /** Y una nota larguísima se corta donde cabe en una pastilla del hilo. */
    public function test_se_corta_a_lo_que_cabe(): void
    {
        $limpia = NotaParaElAsesor::limpia(str_repeat('palabra ', 80));

        $this->assertLessThanOrEqual(NotaParaElAsesor::MAXIMO, mb_strlen($limpia));
        $this->assertStringEndsWith('…', $limpia);
    }
}
