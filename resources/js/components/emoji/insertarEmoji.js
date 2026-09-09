/**
 * Inserta un emoji donde esté el cursor de un campo controlado por React.
 *
 * Concatenar al final es lo fácil pero está mal: en «Consultar factura» el
 * emoji va delante, porque WhatsApp no pone iconos en las opciones y el único
 * icono posible es el primer carácter del título. Y si el admin ya escribió el
 * texto, mandarle a borrar y reescribir para poner el emoji delante es
 * exactamente la fricción que se quería quitar.
 *
 * Devuelve el texto nuevo; quien llama lo pasa a su `onChange`. Después coloca
 * el cursor detrás del emoji, para poder seguir escribiendo.
 */
export function insertarEnCursor(elemento, valor, emoji) {
    if (!elemento) return `${valor ?? ''}${emoji}`;

    const inicio = elemento.selectionStart ?? (valor ?? '').length;
    const fin = elemento.selectionEnd ?? inicio;
    const texto = valor ?? '';
    const nuevo = texto.slice(0, inicio) + emoji + texto.slice(fin);

    // El cursor se recoloca después de que React repinte el valor.
    requestAnimationFrame(() => {
        const posicion = inicio + emoji.length;
        elemento.focus();
        elemento.setSelectionRange?.(posicion, posicion);
    });

    return nuevo;
}
