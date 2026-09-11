<?php

namespace App\Extensions;

use App\Models\CompanyExtension;
use Illuminate\Support\Collection;

/**
 * El catálogo: qué extensiones existen en la plataforma.
 *
 * Se instancia una vez por petición (singleton en AppServiceProvider) porque
 * las clases del catálogo no tienen estado propio y construirlas en cada
 * llamada del webhook no aporta nada.
 *
 * Una clase listada en config/extensions.php que no exista o que no herede de
 * Extension se descarta con un aviso en vez de tumbar la aplicación: un typo en
 * la configuración no debe dejar sin catálogo —ni sin ganchos— a las extensiones
 * que sí están bien.
 */
class ExtensionRegistry
{
    /** @var array<string, Extension>|null */
    private ?array $extensions = null;

    /** @return array<string, Extension> Indexadas por slug. */
    public function all(): array
    {
        if ($this->extensions !== null) {
            return $this->extensions;
        }

        $this->extensions = [];

        foreach ((array) config('extensions.available', []) as $class) {
            if (! is_string($class) || ! class_exists($class) || ! is_subclass_of($class, Extension::class)) {
                logger()->warning('Extensión del catálogo inválida, se omite', ['class' => $class]);

                continue;
            }

            /** @var Extension $extension */
            $extension = app($class);
            $this->extensions[$extension->slug()] = $extension;
        }

        return $this->extensions;
    }

    public function find(?string $slug): ?Extension
    {
        return $this->all()[$slug] ?? null;
    }

    public function has(?string $slug): bool
    {
        return $this->find($slug) !== null;
    }

    /**
     * Las filas instaladas por una empresa, indexadas por slug.
     *
     * Se descartan las de slugs que ya no están en el catálogo: una extensión
     * retirada deja filas huérfanas, y devolverlas obligaría a cada consumidor
     * a comprobar si la clase existe.
     *
     * @return Collection<string, CompanyExtension>
     */
    public function installedFor(int $companyId): Collection
    {
        return CompanyExtension::where('company_id', $companyId)
            ->get()
            ->filter(fn (CompanyExtension $row) => $this->has($row->slug))
            ->keyBy('slug');
    }

    /**
     * Las extensiones instaladas Y encendidas de una empresa que implementan
     * una interfaz de gancho concreta.
     *
     * Devuelve las filas —no las clases— porque el gancho necesita los ajustes,
     * y el orden es el del catálogo para que encadenar filtros de salida dé
     * siempre el mismo resultado.
     *
     * @return Collection<int, CompanyExtension>
     */
    public function activeForHook(int $companyId, string $contract): Collection
    {
        $orden = array_flip(array_keys($this->all()));

        return CompanyExtension::where('company_id', $companyId)
            ->where('enabled', true)
            ->get()
            ->filter(fn (CompanyExtension $row) => $this->find($row->slug) instanceof $contract)
            ->sortBy(fn (CompanyExtension $row) => $orden[$row->slug] ?? PHP_INT_MAX)
            ->values();
    }
}
