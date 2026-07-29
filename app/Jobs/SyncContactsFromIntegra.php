<?php

namespace App\Jobs;

use App\Models\CompanyIntegration;
use App\Models\Contact;
use App\Services\IntegraClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Trae TODO el maestro de clientes de Integra (GET /api/v1/contactos,
 * paginado) y lo guarda en la tabla `contacts` del CRM.
 *
 * Dedup por teléfono: si ya existe un contacto de la misma empresa con ese
 * número (comparando por los últimos 10 dígitos, ya que Integra guarda el
 * celular en formato local y WhatsApp con indicativo), NO se duplica ni se
 * sobrescribe el nombre — solo se etiqueta con los datos de Integra en
 * `metadata.integra_contactos`. Si el teléfono es nuevo, se crea el contacto.
 *
 * Sincronización incremental: la primera corrida trae todo (`estado=todos`,
 * sin `actualizado_desde`); las siguientes solo piden lo que cambió desde
 * `last_synced_at`. No se pide `incluir` (contratos/facturas no se persisten
 * en esta versión), así la página puede ser de 100 en vez de 25.
 */
class SyncContactsFromIntegra implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;
    public int $tries = 1;

    private const PER_PAGE = 100;

    public function __construct(public int $companyIntegrationId)
    {
    }

    public function handle(): void
    {
        $integration = CompanyIntegration::find($this->companyIntegrationId);

        if (! $integration || ! $integration->isConnected()) {
            return;
        }

        $client = new IntegraClient($integration->base_url, $integration->access_token);
        $actualizadoDesde = $integration->last_synced_at?->toIso8601String();

        $processed = 0;
        $created = 0;
        $matched = 0;
        $page = 0;
        $totalPages = null;

        try {
            do {
                $page++;
                $res = $client->listContacts([
                    'page'              => $page,
                    'por_pagina'        => self::PER_PAGE,
                    'estado'            => 'todos',
                    'actualizado_desde' => $actualizadoDesde,
                ]);

                $rows = $res['data'];
                $totalPages = $res['meta']['total_paginas'] ?? $res['meta']['last_page'] ?? $totalPages;

                foreach ($rows as $row) {
                    $processed++;
                    if ($this->upsertContact($integration->company_id, $row)) {
                        $created++;
                    } else {
                        $matched++;
                    }
                }

                $this->reportProgress($integration, $page, $totalPages, $processed, $created, $matched);
            } while (count($rows) === self::PER_PAGE && (! $totalPages || $page < $totalPages));

            $integration->update([
                'last_synced_at' => now(),
                'sync_status' => [
                    'state'       => 'done',
                    'page'        => $page,
                    'total_pages' => $totalPages,
                    'processed'   => $processed,
                    'created'     => $created,
                    'matched'     => $matched,
                    'error'       => null,
                    'started_at'  => $integration->sync_status['started_at'] ?? now()->toIso8601String(),
                    'finished_at' => now()->toIso8601String(),
                ],
            ]);
        } catch (\RuntimeException $e) {
            Log::warning('Contactos: error sincronizando desde Integra', [
                'company_integration_id' => $integration->id,
                'page' => $page,
                'error' => $e->getMessage(),
            ]);

            $integration->update([
                'sync_status' => [
                    'state'       => 'error',
                    'page'        => $page,
                    'total_pages' => $totalPages,
                    'processed'   => $processed,
                    'created'     => $created,
                    'matched'     => $matched,
                    'error'       => $e->getMessage(),
                    'started_at'  => $integration->sync_status['started_at'] ?? now()->toIso8601String(),
                    'finished_at' => now()->toIso8601String(),
                ],
            ]);
        }
    }

    private function reportProgress(CompanyIntegration $integration, int $page, ?int $totalPages, int $processed, int $created, int $matched): void
    {
        $integration->update([
            'sync_status' => array_merge($integration->sync_status ?? [], [
                'state'       => 'running',
                'page'        => $page,
                'total_pages' => $totalPages,
                'processed'   => $processed,
                'created'     => $created,
                'matched'     => $matched,
            ]),
        ]);
    }

    /**
     * Soporta las dos formas del payload de Integra (ver
     * IntegrationController::presentClient): la plana original y la
     * segmentada con `contacto`/`resumen`.
     *
     * @return bool true si se creó un contacto nuevo, false si hizo match con uno existente.
     */
    private function upsertContact(int $companyId, array $row): bool
    {
        $contacto = is_array($row['contacto'] ?? null) ? $row['contacto'] : [];

        $nombre = $row['nombre_completo'] ?? $row['nombre'] ?? $contacto['nombre'] ?? '';
        $celular = $row['celular'] ?? $contacto['celular'] ?? $row['telefono1'] ?? $contacto['telefono1'] ?? null;
        $identificacion = $row['identificacion'] ?? $contacto['identificacion'] ?? null;
        $estado = $row['estado'] ?? $contacto['estado'] ?? null;
        $externalId = $row['id'] ?? null;

        $digits = preg_replace('/\D+/', '', (string) $celular);
        if ($digits === '') {
            return false; // sin teléfono no hay forma de vincular ni de crear (phone_number es requerido).
        }
        $last10 = strlen($digits) > 10 ? substr($digits, -10) : $digits;

        $tag = [
            'external_id'    => $externalId,
            'nombre_api'     => $nombre,
            'identificacion' => $identificacion,
            'estado'         => $estado,
            'synced_at'      => now()->toIso8601String(),
        ];

        $existing = Contact::where('company_id', $companyId)
            ->where(function ($q) use ($last10) {
                $q->where('phone_number', 'like', "%{$last10}")
                    ->orWhere('phone_numbers', 'like', '%"%'.$last10.'"%');
            })
            ->first();

        if ($existing) {
            // No se toca name/phone_number: solo se etiqueta con los datos de
            // Integra para que el frontend muestre el badge sin duplicar el contacto.
            $existing->update([
                'metadata' => array_merge($existing->metadata ?? [], ['integra_contactos' => $tag]),
            ]);

            return false;
        }

        Contact::create([
            'company_id'     => $companyId,
            'name'           => $nombre ?: 'Sin nombre',
            'phone_number'   => $last10,
            'email'          => $row['email'] ?? $contacto['email'] ?? null,
            'source'         => 'integra_contactos',
            'identificacion' => $identificacion,
            'external_id'    => $externalId,
            'metadata'       => ['integra_contactos' => $tag],
        ]);

        return true;
    }
}
