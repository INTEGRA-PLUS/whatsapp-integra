/**
 * Rehace las capturas de la guía «Cómo crear una plantilla».
 *
 * Captura el formulario real con Chrome sin ventana y marca en naranja lo que
 * hay que tocar en cada paso. Hay que volver a pasarlo cuando cambie el
 * formulario de plantillas, o la guía enseñará botones que ya no existen.
 *
 *   1. php artisan demo:montar --slug=guias-demo --nombre="Mi Empresa"
 *      y dejar su línea con nombre «Mi Empresa», número +57 300 000 0000 y
 *      WABA 100000000000000, y el admin llamado «Administrador»: las capturas
 *      se publican y no pueden llevar el nombre de un cliente.
 *   2. npx vite build && php artisan serve --port=8010
 *   3. npm i --no-save puppeteer-core@23
 *   4. node scripts/guias/capturar-crear-plantilla.mjs /tmp/capturas
 *   5. Pasarlas a JPEG en public/img/guias/crear-plantilla/
 *      (sips -s format jpeg -s formatOptions 82) y actualizar `ancho`/`alto`
 *      en contenido.jsx: son la mitad de los píxeles, porque salen al doble.
 */
import puppeteer from 'puppeteer-core';
const B = 'http://127.0.0.1:8010';
const OUT = process.argv[2];
const browser = await puppeteer.launch({ executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', headless: 'new', defaultViewport: { width: 1440, height: 900, deviceScaleFactor: 2 } });
const p = await browser.newPage();
const espera = ms => new Promise(r => setTimeout(r, ms));
await p.goto(B + '/login', { waitUntil: 'networkidle0' });
await p.type('#email', 'admin@guias-demo.demo');
await p.type('#password', 'demo1234');
await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle0' }), p.click('button[type=submit]')]);

// Marca con data-g el elemento cuyo texto visible coincide, subiendo `sube` niveles.
const marcar = (sel, texto, g, sube = 0) => p.evaluate((sel, texto, g, sube) => {
  const el = [...document.querySelectorAll(sel)].find(e => e.textContent.trim().startsWith(texto) || e.placeholder === texto);
  if (!el) throw new Error('no encontré ' + texto);
  let t = el; for (let i = 0; i < sube; i++) t = t.parentElement;
  t.setAttribute('data-g', g);
}, sel, texto, g, sube);
const resaltar = g => p.evaluate(g => {
  document.querySelectorAll('[data-resaltado]').forEach(e => { e.style.outline = ''; e.removeAttribute('data-resaltado'); });
  for (const x of [].concat(g)) { const e = document.querySelector(`[data-g="${x}"]`); e.style.outline = '3px solid #f59e0b'; e.style.outlineOffset = '4px'; e.setAttribute('data-resaltado', '1'); }
}, g);
const limpiar = () => p.evaluate(() => document.querySelectorAll('[data-resaltado]').forEach(e => { e.style.outline = ''; e.removeAttribute('data-resaltado'); }));
// Captura la unión de los rectángulos de varios elementos, con margen.
const foto = async (nombre, gs, m = 16) => {
  const r = await p.evaluate(gs => {
    const rs = gs.map(g => document.querySelector(`[data-g="${g}"]`).getBoundingClientRect());
    return { x: Math.min(...rs.map(r => r.left)), y: Math.min(...rs.map(r => r.top)) + window.scrollY, r: Math.max(...rs.map(r => r.right)), b: Math.max(...rs.map(r => r.bottom)) + window.scrollY };
  }, [].concat(gs));
  await p.screenshot({ path: `${OUT}/${nombre}.png`, captureBeyondViewport: true, clip: { x: Math.max(0, r.x - m), y: Math.max(0, r.y - m), width: r.r - r.x + 2 * m, height: r.b - r.y + 2 * m } });
};
const escribir = async (g, texto) => { await p.click(`[data-g="${g}"]`, { clickCount: 3 }); await p.type(`[data-g="${g}"]`, texto); };

// 1. Listado: la cabecera con «Nueva plantilla».
await p.goto(B + '/templates', { waitUntil: 'networkidle0' }); await espera(1200);
await p.evaluate(() => document.querySelector('h1').closest('header').setAttribute('data-g', 'cabecera'));
await marcar('button, a', 'Nueva plantilla', 'nueva');
await resaltar('nueva'); await foto('01-nueva-plantilla', 'cabecera', 12);

// 2-5. Paso 1 del formulario.
await p.goto(B + '/templates/create', { waitUntil: 'networkidle0' }); await espera(1200);
await p.evaluate(() => [...document.querySelectorAll('h2')].find(h => h.textContent.includes('Configura tu plantilla')).closest('.rounded-xl').setAttribute('data-g', 'config'));
await marcar('button', 'Utilidad', 'utilidad');
await marcar('label', 'Permitir que Meta', 'reclasificar');
await p.click('[data-g="utilidad"]');
await resaltar(['utilidad', 'reclasificar']); await foto('02-categoria', 'config');
await limpiar();
await marcar('input', 'cortes_servicio', 'nombre');
await p.evaluate(() => { const s = [...document.querySelectorAll('select')].find(s => [...s.options].some(o => o.value === 'es_MX')); s.setAttribute('data-g', 'idioma'); });
await escribir('nombre', 'recordatorio_pago');
await p.select('[data-g="idioma"]', 'es');
await resaltar(['nombre', 'idioma']); await foto('04-nombre-idioma', 'config');
await limpiar();
// Barra inferior con «Siguiente».
await marcar('button', 'Siguiente', 'siguiente');
await p.evaluate(() => document.querySelector('[data-g="siguiente"]').closest('.sticky').setAttribute('data-g', 'barra'));
await resaltar('siguiente'); await foto('05-siguiente', 'siguiente', 28);
await limpiar();
await p.click('[data-g="siguiente"]'); await espera(800);

// 6-10. Paso 2.
await p.evaluate(() => {
  const sel = [...document.querySelectorAll('select')];
  sel.find(s => [...s.options].some(o => o.value === 'POSITIONAL')).setAttribute('data-g', 'tipovar');
  sel.find(s => [...s.options].some(o => o.value === 'DOCUMENT')).setAttribute('data-g', 'media');
});
await marcar('h2', 'Contenido', 'contenido', 1);
await p.evaluate(() => document.querySelector('[data-g="tipovar"]').parentElement.setAttribute('data-g', 'tipovar-bloque'));
await resaltar('tipovar'); await foto('06-tipo-variable', 'tipovar-bloque', 14);
await limpiar();
await p.select('[data-g="media"]', 'DOCUMENT'); await espera(400);
await p.evaluate(() => document.querySelector('[data-g="media"]').parentElement.setAttribute('data-g', 'media-bloque'));
await p.evaluate(() => document.querySelector('input[type=file]').parentElement.setAttribute('data-g', 'archivo'));
await resaltar(['media', 'archivo']); await foto('07-encabezado-documento', ['media-bloque', 'archivo']);
await limpiar();
const cuerpo = 'Hola {{1}}, te recordamos que tu factura del mes por valor de {{2}} vence el {{3}}. Puedes pagarla en nuestros puntos autorizados o por transferencia. Gracias por confiar en nosotros.';
await p.evaluate(() => document.querySelector('textarea').setAttribute('data-g', 'cuerpo'));
await p.click('[data-g="cuerpo"]'); await p.type('[data-g="cuerpo"]', cuerpo); await espera(400);
await marcar('button', 'Agregar variable', 'agregar-var');
await p.evaluate(() => document.querySelector('[data-g="cuerpo"]').closest('.space-y-1\\.5, .space-y-2')?.setAttribute('data-g', 'cuerpo-bloque'));
await resaltar(['cuerpo', 'agregar-var']); await foto('08-cuerpo', ['cuerpo', 'agregar-var'], 40);
await limpiar();
const ejemplos = await p.$$('input[placeholder^="Ejemplo para"]');
const valores = ['Juan Pérez', '$85.000', '15 de octubre'];
for (let i = 0; i < ejemplos.length; i++) { await ejemplos[i].type(valores[i] ?? 'ejemplo'); await ejemplos[i].evaluate((e, i) => e.setAttribute('data-g', 'ej' + i), i); }
await espera(400);
await p.evaluate(() => { const t=[...document.querySelectorAll('p, span, div, h3, h4, label')].find(e => e.childElementCount===0 && e.textContent.trim().startsWith('Ejemplos para las variables')); t.closest('.rounded-md, .rounded-lg, .rounded-xl').setAttribute('data-g','ejemplos'); });
await resaltar(ejemplos.map((_, i) => 'ej' + i)); await foto('09-ejemplos', 'ejemplos', 14);
await limpiar();
await marcar('input', 'Agrega una breve línea de texto en la parte inferior del mensaje', 'pie');
await escribir('pie', 'Mi Empresa · Servicio al cliente');
await marcar('button', 'Agregar botón', 'agregar-boton');
await p.click('[data-g="agregar-boton"]'); await espera(300);
await marcar('input', 'Texto del botón', 'boton-texto');
await escribir('boton-texto', 'Ya pagué');
await marcar('h2', 'Botones', 'botones', 2);
await p.evaluate(() => document.querySelector('[data-g="pie"]').closest('.space-y-1\\.5').setAttribute('data-g','pie-bloque'));
await resaltar(['pie', 'agregar-boton']); await foto('10-pie-y-botones', ['pie-bloque', 'botones']);
await limpiar();
// 11. Vista previa + barra final.
await p.evaluate(() => { const h = [...document.querySelectorAll('h3')].find(h => h.textContent.includes('Vista previa')); h.closest('.rounded-xl').setAttribute('data-g', 'vista'); });
await marcar('button', 'Enviar para revisión', 'enviar');
await p.evaluate(() => window.scrollTo(0, 0));
await foto('11a-vista-previa', 'vista', 12);
await p.evaluate(() => document.querySelector('[data-g="enviar"]').closest('.sticky').setAttribute('data-g', 'barra2'));
await p.evaluate(() => { const b=[...document.querySelectorAll('button')].find(b=>b.textContent.trim()==='Anterior'); b.setAttribute('data-g','anterior'); });
await resaltar('enviar'); await foto('11b-enviar', ['anterior','enviar'], 28);
await browser.close();
