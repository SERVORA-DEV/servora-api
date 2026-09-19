<?php

namespace App\Repository\System;

use App\Models\Service;

// A "service template" is an admin-authored catalog row in the services table:
// is_template = true and no owning business. Every query here pins both, so a
// business's own service can never be reached through the admin catalog — and,
// conversely, templates stay out of every business-scoped query because those
// all filter on spa_business_id (see ServiceRepository).
class ServiceTemplateRepository
{
    private function query()
    {
        return Service::query()
            ->where('is_template', true)
            ->whereNull('spa_business_id');
    }

    // withCount('adoptions') gives the admin's "adopted by N" column in the
    // same query rather than one count per row.
    public function paginate(?string $category = null, int $perPage = 15)
    {
        return $this->query()
            ->with('variants')
            ->withCount('adoptions')
            ->when($category, fn ($q) => $q->where('category', $category))
            ->latest()
            ->paginate($perPage);
    }

    // The owner-facing catalog: active templates only, and no adoption counts —
    // how many other businesses copied something isn't an owner's concern.
    public function activeForOwners(?string $category = null)
    {
        return $this->query()
            ->with('variants')
            ->where('is_active', true)
            ->when($category, fn ($q) => $q->where('category', $category))
            ->latest()
            ->get();
    }

    public function create(array $payload): Service
    {
        return Service::create($payload);
    }

    public function findByUuid(string $uuid): Service
    {
        return $this->query()
            ->with('variants')
            ->withCount('adoptions')
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    public function update(Service $template, array $payload): Service
    {
        $template->update($payload);

        return $template;
    }

    // Soft delete — services uses SoftDeletes, and adopted copies keep working
    // either way since source_template_id is nullOnDelete and never read back.
    public function delete(Service $template): void
    {
        $template->delete();
    }
}
