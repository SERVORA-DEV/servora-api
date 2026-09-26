<?php

namespace App\Http\Resources\Client;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// A published review as a stranger sees it on a spa's page: first name and
// last initial only (or "Anonymous"), never contact details.
class PublicReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $client = $this->client;
        $name = 'Anonymous';

        if (! $this->is_anonymous && $client) {
            $initial = $client->last_name ? mb_substr($client->last_name, 0, 1) . '.' : '';
            $name = trim("{$client->first_name} {$initial}") ?: 'Client';
        }

        return [
            'uuid' => $this->uuid,
            'rating' => (int) $this->rating,
            'comment' => $this->comment,
            'author' => $name,
            'services' => $this->appointment?->services
                ->map(fn ($s) => $s->serviceVariant?->service?->name)->filter()->unique()->values() ?? [],
            'reviewed_at' => optional($this->reviewed_at ?? $this->created_at)->toIso8601String(),
        ];
    }
}
