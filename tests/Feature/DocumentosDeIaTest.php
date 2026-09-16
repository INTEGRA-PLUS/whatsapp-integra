<?php

namespace Tests\Feature;

use App\Jobs\ProcesarDocumentoDeIa;
use App\Models\AiDocumento;
use App\Models\AiFragmento;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ZipArchive;

/**
 * Los documentos con los que una empresa entrena a su IA.
 *
 * Lo que se prueba aquí es la extracción contra ficheros de verdad —un XLSX es
 * un zip que el test arma de cero, no un `mock`—, porque es donde esto se va a
 * romper: el formato real trae fechas que son números, fórmulas que guardan dos
 * cosas y CSV exportados por un Excel en español.
 */
class DocumentosDeIaTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('ai_documentos');

        $this->company = Company::create([
            'name' => 'Fibra XYZ',
            'slug' => 'fibra-xyz',
            'active' => true,
            'plan' => 'basico',
            'ia' => 'completa',
        ]);

        $this->user = $this->comoAdmin($this->company);
    }

    // ─── La extracción, que es lo que de verdad rompe ────────────────────────

    /** @test */
    public function un_txt_se_parte_en_fragmentos(): void
    {
        $texto = str_repeat('Nuestro horario de atención es de lunes a viernes. ', 60);

        $documento = $this->subir(UploadedFile::fake()->createWithContent('manual.txt', $texto));

        $this->assertSame('listo', $documento->estado);
        $this->assertGreaterThan(1, $documento->fragmentos, 'Un texto largo tiene que partirse.');

        $primero = AiFragmento::where('ai_documento_id', $documento->id)->orderBy('orden')->first();
        $this->assertStringContainsString('horario de atención', $primero->texto);
        $this->assertSame($this->company->id, $primero->company_id);
    }

    /**
     * Cada fila de una hoja lleva su encabezado pegado.
     *
     * Es la diferencia entre poder contestar «¿cuánto cuesta el de 300 megas?» y
     * mandarle al modelo un «89900» suelto que no sabe de qué es.
     *
     * @test
     */
    public function cada_fila_de_un_csv_lleva_su_encabezado(): void
    {
        $csv = "Plan,Precio mensual,Instalación\n300 megas,89900,50000\n600 megas,109900,50000\n";

        $documento = $this->subir(UploadedFile::fake()->createWithContent('tarifario.csv', $csv));

        $this->assertSame('listo', $documento->estado);
        $this->assertSame(2, $documento->fragmentos, 'Dos filas de datos, dos fragmentos.');

        $textos = AiFragmento::where('ai_documento_id', $documento->id)->pluck('texto')->all();

        $this->assertStringContainsString('Plan: 300 megas', $textos[0]);
        $this->assertStringContainsString('Precio mensual: 89900', $textos[0]);
        $this->assertStringContainsString('Instalación: 50000', $textos[0]);
    }

    /**
     * El CSV que exporta un Excel en español: punto y coma y Windows-1252.
     *
     * Con el separador mal, cada fila sale como una sola columna con todo
     * dentro. Con la codificación mal, «Instalación» llega con un byte suelto
     * que la base rechaza.
     *
     * @test
     */
    public function el_csv_que_exporta_excel_en_espanol_tambien_se_lee(): void
    {
        $csv = mb_convert_encoding(
            "Plan;Precio;Instalación\n300 megas;89.900;Sí\n",
            'Windows-1252',
            'UTF-8'
        );

        $documento = $this->subir(UploadedFile::fake()->createWithContent('tarifario.csv', "\xEF\xBB\xBF".$csv));

        $this->assertSame('listo', $documento->estado);

        $texto = AiFragmento::where('ai_documento_id', $documento->id)->value('texto');

        $this->assertStringContainsString('Plan: 300 megas', $texto);
        $this->assertStringContainsString('Instalación: Sí', $texto);
        // Y la BOM no se quedó pegada al primer título.
        $this->assertStringNotContainsString("\u{FEFF}", $texto);
    }

    /**
     * Un XLSX de verdad: cadenas compartidas, una fecha y una fórmula.
     *
     * Las tres cosas que se olvidan. Sin resolver las cadenas el tarifario sale
     * como una lista de números; sin mirar el estilo, la vigencia empieza «el
     * 45678»; y leyendo la fórmula en vez del valor, al modelo le llega
     * «=B2*1.19».
     *
     * @test
     */
    public function un_xlsx_resuelve_cadenas_fechas_y_formulas(): void
    {
        $ruta = $this->hacerXlsx();

        $documento = $this->subir(new UploadedFile($ruta, 'tarifario.xlsx', null, null, true));

        $this->assertSame('listo', $documento->estado, (string) $documento->motivo);

        $texto = AiFragmento::where('ai_documento_id', $documento->id)->value('texto');

        $this->assertStringContainsString('Plan: 300 megas', $texto);
        $this->assertStringContainsString('Vigencia: 01/01/2026', $texto, 'La fecha es un número de serie.');
        $this->assertStringContainsString('Con IVA: 106981', $texto, 'De la fórmula se lee el valor, no la fórmula.');
        $this->assertStringContainsString('hoja «Planes hogar»', (string) AiFragmento::where('ai_documento_id', $documento->id)->value('origen'));
    }

    /**
     * Una fila con huecos no corre sus valores a la columna de al lado.
     *
     * En un XLSX las celdas vacías no ocupan sitio: la fila salta de A a D. Si
     * no se colocan por su letra, el precio acaba bajo el título «Instalación».
     *
     * @test
     */
    public function una_fila_con_huecos_no_desplaza_los_valores(): void
    {
        $ruta = $this->hacerXlsx();

        $documento = $this->subir(new UploadedFile($ruta, 'tarifario.xlsx', null, null, true));

        $conHueco = AiFragmento::where('ai_documento_id', $documento->id)
            ->where('texto', 'like', '%600 megas%')
            ->value('texto');

        $this->assertNotNull($conHueco);
        $this->assertStringContainsString('Con IVA: 130781', $conHueco);
        // La columna «Vigencia» venía vacía en esa fila: no aparece, y desde
        // luego no con el valor de la de al lado.
        $this->assertStringNotContainsString('Vigencia:', $conHueco);
    }

    /** @test */
    public function un_docx_se_lee_parrafo_a_parrafo(): void
    {
        $ruta = $this->hacerDocx();

        $documento = $this->subir(new UploadedFile($ruta, 'reglamento.docx', null, null, true));

        $this->assertSame('listo', $documento->estado, (string) $documento->motivo);

        $texto = AiFragmento::where('ai_documento_id', $documento->id)->value('texto');

        // Word parte un párrafo en varios <w:t> cada vez que cambia el formato.
        // Si se pegan con espacio, aquí saldría «pre cio».
        $this->assertStringContainsString('El precio no incluye instalación', $texto);
        // Y el tabulador separa las columnas de una tabla.
        $this->assertStringContainsString('300 megas $89.900', $texto);
    }

    /**
     * El PDF escaneado se rechaza diciendo que es un escaneo.
     *
     * Es el caso frecuente —muchos reglamentos son una foto del papel— y desde
     * fuera es idéntico a un PDF normal. Si el aviso no lo nombra, el admin
     * vuelve a subir el mismo fichero convencido de que falló la subida.
     *
     * @test
     */
    public function un_pdf_sin_texto_avisa_de_que_es_un_escaneo(): void
    {
        $documento = $this->subir(UploadedFile::fake()->createWithContent('reglamento.pdf', $this->pdfSinTexto()));

        $this->assertSame('fallido', $documento->estado);
        $this->assertStringContainsString('escaneada', (string) $documento->motivo);
        $this->assertSame(0, $documento->fragmentos);
    }

    // ─── Los candados ────────────────────────────────────────────────────────

    /** @test */
    public function sin_el_complemento_de_ia_no_se_puede_subir(): void
    {
        $this->company->update(['ia' => 'esencial']);

        $this->postJson('/api/settings/ai-flow/documentos', [
            'archivo' => UploadedFile::fake()->createWithContent('manual.txt', str_repeat('hola ', 50)),
        ])->assertStatus(402);

        $this->assertSame(0, AiDocumento::count());
    }

    /** @test */
    public function no_se_puede_pasar_de_cinco_documentos(): void
    {
        for ($i = 0; $i < AiDocumento::MAXIMO_POR_EMPRESA; $i++) {
            $this->subir(UploadedFile::fake()->createWithContent("doc{$i}.txt", str_repeat('contenido de prueba ', 20)));
        }

        $this->postJson('/api/settings/ai-flow/documentos', [
            'archivo' => UploadedFile::fake()->createWithContent('sexto.txt', str_repeat('hola ', 50)),
        ])->assertStatus(422)->assertJsonFragment(['message' => 'Ya tienes 5 documentos. Borra uno para subir otro.']);
    }

    /** @test */
    public function un_ejecutable_disfrazado_no_entra(): void
    {
        $this->postJson('/api/settings/ai-flow/documentos', [
            'archivo' => UploadedFile::fake()->createWithContent('virus.exe', 'MZ'),
        ])->assertStatus(422);
    }

    /**
     * El documento de otra empresa no se descarga ni se borra.
     *
     * Aquí el aislamiento es manual: sin la comprobación en el controlador, una
     * ruta con `{documento}` deja pedir el reglamento interno de cualquier otra.
     *
     * @test
     */
    public function no_se_toca_el_documento_de_otra_empresa(): void
    {
        $otra = Company::create(['name' => 'Otra ISP', 'slug' => 'otra-isp', 'active' => true, 'plan' => 'basico', 'ia' => 'completa']);

        $ajeno = AiDocumento::create([
            'company_id' => $otra->id,
            'nombre' => 'reglamento-ajeno.txt',
            'extension' => 'txt',
            'bytes' => 10,
            'ruta' => "empresa-{$otra->id}/ajeno.txt",
            'estado' => 'listo',
        ]);

        Storage::disk('ai_documentos')->put($ajeno->ruta, 'secreto de la otra empresa');

        $this->getJson("/api/settings/ai-flow/documentos/{$ajeno->id}/descargar")->assertNotFound();
        $this->deleteJson("/api/settings/ai-flow/documentos/{$ajeno->id}")->assertNotFound();
        $this->postJson("/api/settings/ai-flow/documentos/{$ajeno->id}/reprocesar")->assertNotFound();

        $this->assertDatabaseHas('ai_documentos', ['id' => $ajeno->id]);
        Storage::disk('ai_documentos')->assertExists($ajeno->ruta);
    }

    /** @test */
    public function la_lista_solo_trae_los_de_su_empresa(): void
    {
        $otra = Company::create(['name' => 'Otra ISP', 'slug' => 'otra-isp-2', 'active' => true, 'plan' => 'basico', 'ia' => 'completa']);

        AiDocumento::create([
            'company_id' => $otra->id, 'nombre' => 'ajeno.txt', 'extension' => 'txt',
            'bytes' => 10, 'ruta' => 'x/ajeno.txt', 'estado' => 'listo',
        ]);

        $this->subir(UploadedFile::fake()->createWithContent('propio.txt', str_repeat('contenido propio ', 20)));

        $respuesta = $this->getJson('/api/settings/ai-flow/documentos')->assertOk()->json('documentos');

        $this->assertCount(1, $respuesta);
        $this->assertSame('propio.txt', $respuesta[0]['nombre']);
    }

    // ─── El ciclo de vida ────────────────────────────────────────────────────

    /**
     * Borrar el documento se lleva el fichero y los fragmentos.
     *
     * Un documento que la empresa quitó porque tenía precios viejos no puede
     * seguir en disco ni seguir contestando.
     *
     * @test
     */
    public function borrar_se_lleva_el_fichero_y_los_fragmentos(): void
    {
        $documento = $this->subir(UploadedFile::fake()->createWithContent('viejo.txt', str_repeat('tarifas de 2019 ', 30)));

        Storage::disk('ai_documentos')->assertExists($documento->ruta);

        $this->deleteJson("/api/settings/ai-flow/documentos/{$documento->id}")->assertOk();

        $this->assertDatabaseMissing('ai_documentos', ['id' => $documento->id]);
        $this->assertSame(0, AiFragmento::where('ai_documento_id', $documento->id)->count());
        Storage::disk('ai_documentos')->assertMissing($documento->ruta);
    }

    /** Reprocesar no deja el documento con los fragmentos duplicados. */
    public function test_reprocesar_no_duplica_los_fragmentos(): void
    {
        $documento = $this->subir(UploadedFile::fake()->createWithContent('manual.txt', str_repeat('una frase cualquiera. ', 80)));

        $antes = $documento->fragmentos;
        $this->assertGreaterThan(0, $antes);

        $this->postJson("/api/settings/ai-flow/documentos/{$documento->id}/reprocesar")->assertOk();

        $this->assertSame($antes, AiFragmento::where('ai_documento_id', $documento->id)->count());
    }

    /** Mientras se procesa, la pantalla lo dice. */
    public function test_recien_subido_queda_en_procesando(): void
    {
        Queue::fake();

        $respuesta = $this->postJson('/api/settings/ai-flow/documentos', [
            'archivo' => UploadedFile::fake()->createWithContent('manual.txt', str_repeat('hola mundo ', 50)),
        ])->assertStatus(201)->json('documentos');

        $this->assertSame('procesando', $respuesta[0]['estado']);
        Queue::assertPushed(ProcesarDocumentoDeIa::class);
    }

    // ─── Ayudas ──────────────────────────────────────────────────────────────

    private function subir(UploadedFile $archivo): AiDocumento
    {
        $this->postJson('/api/settings/ai-flow/documentos', ['archivo' => $archivo])->assertStatus(201);

        return AiDocumento::where('company_id', $this->company->id)->latest('id')->first()->refresh();
    }

    private function comoAdmin(Company $company): User
    {
        $user = User::create([
            'company_id' => $company->id,
            'name' => 'Admin',
            'email' => 'admin@fibra.test',
            'password' => 'secret',
            'active' => true,
        ]);

        // Los roles de Spatie van por equipos, y el equipo es la empresa: sin
        // el `company_id` en el rol el permiso se concede en el vacío, y
        // `hasPermissionTo` devuelve false sin decir por qué.
        $role = Role::firstOrCreate([
            'name' => 'admin', 'company_id' => $company->id, 'guard_name' => 'web',
        ]);
        $role->givePermissionTo(Permission::firstOrCreate([
            'name' => 'whatsapp_menus.update', 'guard_name' => 'web',
        ]));
        $user->assignRole($role);

        $this->actingAs($user);

        return $user;
    }

    /**
     * Un XLSX de verdad, armado a mano.
     *
     * Se arma en vez de guardar un binario en el repo para que el test diga qué
     * contiene: la fila 3 deja «Vigencia» vacía a propósito, que es el caso de
     * las celdas que no ocupan sitio en el XML.
     */
    private function hacerXlsx(): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        $zip = new ZipArchive;
        $zip->open($ruta, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $zip->addFromString('[Content_Types].xml',
            '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');

        $zip->addFromString('xl/_rels/workbook.xml.rels',
            '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>');

        $zip->addFromString('xl/workbook.xml',
            '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="Planes hogar" sheetId="1" r:id="rId1"/></sheets></workbook>');

        $textos = ['Plan', 'Precio', 'Vigencia', 'Con IVA', '300 megas', '600 megas'];
        $si = implode('', array_map(fn ($t) => "<si><t>{$t}</t></si>", $textos));
        $zip->addFromString('xl/sharedStrings.xml',
            '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'.$si.'</sst>');

        // El estilo 1 es una fecha (numFmtId 14 = dd/mm/yyyy de fábrica).
        $zip->addFromString('xl/styles.xml',
            '<?xml version="1.0"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<cellXfs count="2"><xf numFmtId="0"/><xf numFmtId="14"/></cellXfs></styleSheet>');

        // 46023 = 01/01/2026 en serie de Excel.
        $zip->addFromString('xl/worksheets/sheet1.xml',
            '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            .'<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c>'
            .'<c r="C1" t="s"><v>2</v></c><c r="D1" t="s"><v>3</v></c></row>'
            .'<row r="2"><c r="A2" t="s"><v>4</v></c><c r="B2"><v>89900</v></c>'
            .'<c r="C2" s="1"><v>46023</v></c><c r="D2"><f>B2*1.19</f><v>106981</v></c></row>'
            // Fila con hueco: no hay C3. Si no se coloca por letra, 130781 cae
            // bajo «Vigencia».
            .'<row r="3"><c r="A3" t="s"><v>5</v></c><c r="B3"><v>109900</v></c>'
            .'<c r="D3"><f>B3*1.19</f><v>130781</v></c></row>'
            .'</sheetData></worksheet>');

        $zip->close();

        return $ruta;
    }

    /** Un DOCX con un párrafo partido en varios `<w:t>` y un tabulador. */
    private function hacerDocx(): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'docx').'.docx';
        $zip = new ZipArchive;
        $zip->open($ruta, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $zip->addFromString('[Content_Types].xml',
            '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');

        $zip->addFromString('word/document.xml',
            '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
            .'<w:p><w:r><w:t>Reglamento de servicio de Fibra XYZ, edición 2026.</w:t></w:r></w:p>'
            // «precio» partido en dos runs, como hace Word con una palabra en
            // negrita en mitad de la frase.
            .'<w:p><w:r><w:t>El pre</w:t></w:r><w:r><w:t>cio no incluye instalación ni equipos.</w:t></w:r></w:p>'
            .'<w:p><w:r><w:t>300 megas</w:t></w:r><w:r><w:tab/></w:r><w:r><w:t>$89.900</w:t></w:r></w:p>'
            .'</w:body></w:document>');

        $zip->close();

        return $ruta;
    }

    /**
     * Un PDF válido con una página y sin una sola letra: el escaneo, en pequeño.
     */
    private function pdfSinTexto(): string
    {
        $objetos = [
            "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n",
            "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n",
            "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] >>\nendobj\n",
        ];

        $pdf = "%PDF-1.4\n";
        $posiciones = [];

        foreach ($objetos as $objeto) {
            $posiciones[] = strlen($pdf);
            $pdf .= $objeto;
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objetos) + 1)."\n0000000000 65535 f \n";

        foreach ($posiciones as $posicion) {
            $pdf .= sprintf("%010d 00000 n \n", $posicion);
        }

        return $pdf."trailer\n<< /Size ".(count($objetos) + 1)
            ." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }
}
