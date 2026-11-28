<?php

namespace App\Service\Business;

use App\Exceptions\ActionRefused;
use App\Exceptions\ChangedElsewhere;
use App\Models\Client;
use App\Models\SpaBusiness;
use App\Models\User;
use App\Repository\Business\ClientRepository;
use App\Repository\SpaBusinessRepository;
use App\Support\LivePulse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ClientDuplicateService
{
    public function __construct(
        private ClientRepository $clients,
        private ClientService $profiles,
        private SpaBusinessRepository $businesses,
    ) {}

    public function suggestions(User $actor, string $uuid): array
    {
        $business = $this->businessFor($actor);
        $branches = $this->profiles->branchScope($actor);
        $profiles = $this->clients->forBusiness($business->id, $branches)->where('is_active', true);
        $client = (clone $profiles)->where('uuid', $uuid)->firstOrFail();

        // Compare the business's eligible profiles in chunks, keeping only the best matches.
        // Spa clients can match each other. Participant profiles can also match mobile profiles.
        $matches = [];
        $identity = $this->identity($client);
        $reviewed = DB::table('client_duplicate_resolutions')
            ->selectRaw('case when first_client_id = ? then second_client_id else first_client_id end', [$client->id])
            ->where(fn ($query) => $query->where('first_client_id', $client->id)->orWhere('second_client_id', $client->id));
        $profiles->where('clients.id', '<>', $client->id)
            ->whereNotIn('clients.id', $reviewed)
            ->select(['id', 'first_name', 'last_name', 'phone_number', 'email', 'user_id', 'created_as_participant'])
            ->chunkById(250, function ($rows) use ($client, $identity, &$matches) {
                foreach ($rows as $row) {
                    if (! $this->canReviewPair($client, $row)) {
                        continue;
                    }
                    $score = $this->matchScore($identity, $this->identity($row));
                    if ($score) {
                        $matches[] = ['id' => $row->id, 'score' => $score];
                    }
                }
                usort($matches, fn ($left, $right) => ($right['score'] <=> $left['score']) ?: ($left['id'] <=> $right['id']));
                $matches = array_slice($matches, 0, 5);
            });

        if (! $matches) {
            return ['profile' => null, 'candidates' => []];
        }

        $candidates = $this->clients->forBusiness($business->id, $branches)
            ->where('is_active', true)->whereKey(array_column($matches, 'id'))->get()->keyBy('id');
        $reasons = [5 => 'Same mobile account', 4 => 'Matching email', 3 => 'Matching phone number', 2 => 'Matching name', 1 => 'Similar name'];

        return [
            'profile' => $this->preview($client, $branches),
            'candidates' => collect($matches)->filter(fn ($match) => $candidates->has($match['id']))
                ->map(function ($match) use ($client, $candidates, $branches, $reasons) {
                    $other = $candidates->get($match['id']);

                    return [
                        'profile' => $this->preview($other, $branches),
                        'reason' => $reasons[$match['score']],
                        'keep_uuid' => ($client->usesApp() !== $other->usesApp())
                            ? ($client->usesApp() ? $client->uuid : $other->uuid)
                            : ($client->id < $other->id ? $client->uuid : $other->uuid),
                        'require_mobile_profile' => $client->usesApp() !== $other->usesApp(),
                        'merge_block' => $this->mergeBlock($client, $other, $branches),
                    ];
                })->values()->all(),
        ];
    }

    public function resolve(User $actor, string $uuid, string $otherUuid, string $decision, ?string $keepUuid): array
    {
        $business = $this->businessFor($actor);
        $branches = $this->profiles->branchScope($actor);

        return DB::transaction(function () use ($actor, $business, $branches, $uuid, $otherUuid, $decision, $keepUuid) {
            // Mobile bookings already hold this lock while linking or creating a client.
            SpaBusiness::whereKey($business->id)->lockForUpdate()->firstOrFail();
            $pair = $this->clients->forBusiness($business->id, $branches)
                ->where('is_active', true)->whereIn('uuid', [$uuid, $otherUuid])
                ->orderBy('id')->lockForUpdate()->get();
            abort_unless($pair->count() === 2, 404);
            [$first, $second] = $pair->all();
            $key = ['first_client_id' => $first->id, 'second_client_id' => $second->id];
            if (DB::table('client_duplicate_resolutions')->where($key)->exists()) {
                throw new ChangedElsewhere('This suggestion has already been reviewed. Refresh the profile to see the latest suggestions.');
            }
            if (! $this->canReviewPair($first, $second)
                || ! $this->matchScore($this->identity($first), $this->identity($second))) {
                throw new ChangedElsewhere('These profiles have changed. Refresh the profile to review the latest suggestions.');
            }

            $kept = null;
            if ($decision === 'merge') {
                $kept = $pair->firstWhere('uuid', $keepUuid);
                $removed = $pair->first(fn (Client $profile) => $profile->id !== $kept?->id);
                throw_if(! $kept, ActionRefused::class, 'Choose which of the two profiles to keep.');
                throw_if($removed->usesApp() && ! $kept->usesApp(), ActionRefused::class, 'Keep the profile linked to the mobile account.');
                if ($message = $this->mergeBlock($kept, $removed, $branches)) {
                    throw new ActionRefused($message);
                }
                $this->merge($kept, $removed);
                DB::afterCommit(fn () => LivePulse::bump($business->id, 'clients', 'visits', 'billing'));
            }

            DB::table('client_duplicate_resolutions')->insert($key + [
                'kept_client_id' => $kept?->id,
                'resolved_by' => $actor->id,
                'resolved_at' => now(),
            ]);

            return ['kept_uuid' => $kept?->uuid];
        }, 3);
    }

    private function businessFor(User $actor): SpaBusiness
    {
        return $this->businesses->findForUser($actor)
            ?? throw new ActionRefused('No spa business found for this account.');
    }

    private function canReviewPair(Client $first, Client $second): bool
    {
        return $first->created_as_participant || $second->created_as_participant
            || (! $first->usesApp() && ! $second->usesApp());
    }

    private function identity(Client $client): array
    {
        $name = fn (string $value) => (string) preg_replace('/[^a-z0-9]/', '', Str::lower(Str::ascii($value)));
        $phone = (string) preg_replace('/\D/', '', (string) $client->phone_number);
        if (strlen($phone) === 12 && str_starts_with($phone, '639')) {
            $phone = '0'.substr($phone, 2);
        }

        return [$name($client->first_name), $name($client->last_name), strlen($phone) >= 7 ? $phone : '',
            Str::lower(trim((string) $client->email)), $client->user_id];
    }

    private function matchScore(array $first, array $second): int
    {
        [$given, $family, $phone, $email, $user] = $first;
        [$otherGiven, $otherFamily, $otherPhone, $otherEmail, $otherUser] = $second;

        return match (true) {
            $user !== null && $user === $otherUser => 5,
            $email !== '' && $email === $otherEmail => 4,
            $phone !== '' && $phone === $otherPhone => 3,
            $given !== '' && $family !== '' && $given === $otherGiven && $family === $otherFamily => 2,
            ($family !== '' && $family === $otherFamily && $this->similar($given, $otherGiven))
                || ($given !== '' && $given === $otherGiven && $this->similar($family, $otherFamily)) => 1,
            default => 0,
        };
    }

    private function similar(string $first, string $second): bool
    {
        $length = min(strlen($first), strlen($second));

        return $length >= 3 && levenshtein($first, $second) <= ($length >= 7 ? 2 : 1);
    }

    private function preview(Client $client, ?array $branches): array
    {
        $visits = $client->appointments()->when($branches !== null, fn ($query) => $query->whereIn('spa_branch_id', $branches));

        return [
            'uuid' => $client->uuid,
            'full_name' => trim("{$client->first_name} {$client->last_name}"),
            'email' => $client->email,
            'phone_number' => $client->phone_number,
            'uses_app' => $client->usesApp(),
            'created_as_participant' => (bool) $client->created_as_participant,
            'created_at' => $client->created_at?->toDateString(),
            'visit_count' => (clone $visits)->count(),
            'visits' => $visits->orderByDesc('appointment_date')->orderByDesc('appointment_time')->limit(3)
                ->get(['appointment_number', 'appointment_date', 'status'])->map(fn ($visit) => [
                    'number' => $visit->appointment_number,
                    'date' => $visit->appointment_date?->toDateString(),
                    'status' => $visit->getRawOriginal('status'),
                ])->all(),
        ];
    }

    private function mergeBlock(Client $first, Client $second, ?array $branches): ?string
    {
        if ($first->usesApp() && $second->usesApp() && $first->user_id !== $second->user_id) {
            return 'These profiles are linked to different mobile accounts.';
        }

        $ids = [$first->id, $second->id];
        if ($branches !== null && (
            collect([$first->spa_branch_id, $second->spa_branch_id])->filter()->diff($branches)->isNotEmpty()
            || DB::table('appointments')->whereIn('client_id', $ids)->whereNotIn('spa_branch_id', $branches)->exists()
            || DB::table('client_memberships')->whereIn('client_id', $ids)->whereNotIn('spa_branch_id', $branches)->exists()
        )) {
            return 'An owner needs to review this merge because the profiles include records from another branch.';
        }

        if ($first->activeMembership()->exists() && $second->activeMembership()->exists()) {
            return 'Review the two active memberships before merging these profiles.';
        }

        return null;
    }

    private function merge(Client $kept, Client $removed): void
    {
        // Bills and the original booker stay attached to their visits and shared package.
        foreach (['appointments', 'reviews', 'client_memberships', 'client_point_entries', 'client_vouchers'] as $table) {
            DB::table($table)->where('client_id', $removed->id)->update(['client_id' => $kept->id]);
        }

        $kept->fill([
            'created_as_participant' => $kept->created_as_participant || $removed->created_as_participant,
            'current_points' => $kept->current_points + $removed->current_points,
            'lifetime_points' => $kept->lifetime_points + $removed->lifetime_points,
            'notes' => collect([$kept->notes, $removed->notes])->filter(fn ($note) => filled($note))->unique()->join("\n\n") ?: null,
        ]);
        foreach (['phone_number', 'email', 'gender', 'birth_year', 'preferred_staff_id', 'spa_branch_id'] as $column) {
            if (blank($kept->{$column})) {
                $kept->{$column} = $removed->{$column};
            }
        }
        $kept->save();
        $removed->delete();
    }
}
