<?php

namespace App\Service\System;

use App\Http\Resources\System\ServiceTemplateResource;
use App\Models\User;
use App\Repository\AuditLogRepository;
use App\Repository\Business\ServiceVariantRepository;
use App\Repository\System\ServiceTemplateRepository;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

// Admin CRUD over the global service catalog. A template is stored as a
// Service row with is_template = true and no spa_business_id, so its
// duration/price options reuse service_variants unchanged — which is what lets
// an owner adopt one without any translation step. Audit-logged under the
// 'service_templates' module (the rows themselves live in `services`).
class ServiceTemplateService
{
    private ServiceTemplateRepository $serviceTemplateRepository;
    private ServiceVariantRepository $serviceVariantRepository;
    private AuditLogRepository $auditLogRepository;

    public function __construct(
        ServiceTemplateRepository $serviceTemplateRepository,
        ServiceVariantRepository $serviceVariantRepository,
        AuditLogRepository $auditLogRepository,
    ) {
        $this->serviceTemplateRepository = $serviceTemplateRepository;
        $this->serviceVariantRepository = $serviceVariantRepository;
        $this->auditLogRepository = $auditLogRepository;
    }

    public function listTemplates(?string $category = null, int $perPage = 15)
    {
        return ServiceTemplateResource::collection(
            $this->serviceTemplateRepository->paginate($category, $perPage)
        );
    }

    // The catalog an owner picks from on the Add Service flow — active only,
    // and without the adoption counts the admin listing carries.
    public function listForOwners(?string $category = null)
    {
        return ServiceTemplateResource::collection(
            $this->serviceTemplateRepository->activeForOwners($category)
        );
    }

    public function getTemplate(string $uuid)
    {
        return new ServiceTemplateResource($this->serviceTemplateRepository->findByUuid($uuid));
    }

    /**
     * The template row and its variants are two writes, so both paths are
     * wrapped in a transaction — a half-created template (no duration options)
     * would show up in the owner's picker as an unusable entry.
     */
    public function createTemplate(User $user, array $payload)
    {
        $variants = $payload['variants'] ?? [];
        unset($payload['variants']);

        // Never client-settable: what makes this row a template rather than
        // some business's service.
        $payload['spa_business_id'] = null;
        $payload['is_template'] = true;
        $payload['source_template_id'] = null;
        $payload['created_by'] = $user->id;
        $payload['is_active'] = $payload['is_active'] ?? true;

        $template = DB::transaction(function () use ($payload, $variants) {
            $template = $this->serviceTemplateRepository->create($payload);
            $this->serviceVariantRepository->syncForService($template->id, $variants);

            return $template;
        });

        $this->auditLogRepository->recordAdminAction('service_templates', $template->id, 'Create', null, [
            'name' => $template->name,
            'duration_options' => count($variants),
        ]);

        return new ServiceTemplateResource($template->load('variants'));
    }

    public function updateTemplate(string $uuid, array $payload)
    {
        $template = $this->serviceTemplateRepository->findByUuid($uuid);

        $variants = $payload['variants'] ?? null;
        unset($payload['variants'], $payload['spa_business_id'], $payload['is_template'], $payload['source_template_id']);
        [$old, $new] = $this->auditLogRepository->diff($template->getAttributes(), $payload);

        try {
            DB::transaction(function () use ($template, $payload, $variants) {
                $this->serviceTemplateRepository->update($template, $payload);

                // Omitted entirely means "leave the options alone"; an empty array
                // would be rejected by ServiceTemplateRequest's min:1.
                if ($variants !== null) {
                    $this->serviceVariantRepository->syncForService($template->id, $variants);
                }
            });
        } catch (UniqueConstraintViolationException $e) {
            // syncForService treats a variant with no uuid as a brand-new row
            // by design (it never infers identity from duration), so editing an
            // existing option without sending its uuid collides with
            // unique(service_id, duration_minutes). That's a malformed payload,
            // not a server fault — say so instead of surfacing a 500.
            return response()->json([
                'message' => 'Each existing duration option must be sent with its uuid. Reload the template and try again.',
            ], 422);
        }

        $updated = $this->serviceTemplateRepository->findByUuid($uuid);

        $this->auditLogRepository->recordAdminAction('service_templates', $updated->id, 'Update', $old, array_merge(
            $new ?? [],
            ['name' => $updated->name],
            $variants !== null ? ['duration_options' => count($variants)] : [],
        ));

        // Editing a template deliberately touches nothing that was adopted from
        // it — source_template_id is provenance, never a sync link.
        return new ServiceTemplateResource($updated);
    }

    public function deleteTemplate(string $uuid): void
    {
        $template = $this->serviceTemplateRepository->findByUuid($uuid);
        $this->serviceTemplateRepository->delete($template);

        $this->auditLogRepository->recordAdminAction('service_templates', $template->id, 'Delete', null, ['name' => $template->name]);
    }
}
