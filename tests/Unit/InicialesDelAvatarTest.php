<?php

namespace Tests\Unit;

use App\Models\WhatsAppConversation;
use PHPUnit\Framework\TestCase;

/**
 * Las dos letras del avatar de la bandeja.
 *
 * Se escribían con `substr`, que corta **bytes**. En UTF-8 una vocal con tilde
 * ocupa dos, así que «Óscar Iván Bedoya» se quedaba con medio carácter y el
 * navegador pintaba `?!` en la lista de conversaciones. Se descubrió montando
 * la cuenta de demostración, la víspera de una presentación.
 *
 * No es un caso raro: Óscar, Úsuga, Ángela, Íngrid y Álvaro son nombres
 * corrientes en Colombia, así que el avatar roto salía en cualquier bandeja
 * real con unas decenas de contactos.
 */
class InicialesDelAvatarTest extends TestCase
{
    /** @dataProvider nombres */
    public function test_las_iniciales_salen_enteras(string $nombre, string $esperado): void
    {
        $conversacion = new WhatsAppConversation(['name' => $nombre]);

        $this->assertSame($esperado, $conversacion->initials);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function nombres(): array
    {
        return [
            'con tilde en la primera letra' => ['Óscar Iván Bedoya', 'ÓI'],
            'con tilde en el apellido' => ['Wilson Alberto Úsuga', 'WA'],
            'un solo nombre con tilde' => ['Ángela', 'ÁN'],
            'con eñe' => ['Ñuño Peña', 'ÑP'],
            'normal de dos palabras' => ['Beatriz Elena Ospina', 'BE'],
            'un solo nombre' => ['Beatriz', 'BE'],

            // Los espacios de más venían de nombres pegados del webhook de
            // Meta: `explode(' ')` devolvía una cadena vacía como segunda
            // palabra y la inicial salía a medias.
            'con espacios de más' => ['  Luz   Dary  ', 'LD'],
        ];
    }

    /** Sin nombre se cae al teléfono, que es lo que se ve en el hilo. */
    public function test_sin_nombre_usa_el_telefono(): void
    {
        $conversacion = new WhatsAppConversation([
            'name' => null,
            'phone_number' => '573104458821',
        ]);

        $this->assertSame('57', $conversacion->initials);
    }
}
