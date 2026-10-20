<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\PersonalAccessToken;
use Illuminate\Database\Eloquent\Model;

// What one business request changed, collected while it runs, so
// RecordBusinessActivity can write the Audit Log's one row for it afterwards.
//
// Every model saved or removed during the request is noted (with what changed,
// read at the moment it changed), and so is every audit row a service wrote
// itself (billing refunds, attendance edits, policy overrides) — those get the
// business and branch filled in, and their details folded into the one row.
//
// Off unless the middleware started it: writes outside a business request
// (the scheduler, the client app, the system admin) are not collected.
final class ActivityRecorder
{
    // Enough for any one action; a bulk change (mark everyone present) only
    // needs the first few to tell what it was.
    private const MAX_TOUCHED = 80;

    private const IGNORED = [AuditLog::class, Notification::class, PersonalAccessToken::class];

    private bool $active = false;

    /** @var array<int, array{kind: string, model: Model, old: array, new: array}> */
    private array $touched = [];

    /** @var AuditLog[] */
    private array $explicit = [];

    public static function register(): void
    {
        foreach (['created', 'updated', 'deleted'] as $kind) {
            \Illuminate\Support\Facades\Event::listen("eloquent.{$kind}: *", function (string $event, array $data) use ($kind) {
                $recorder = app(self::class);
                if ($recorder->active && ($data[0] ?? null) instanceof Model) {
                    $recorder->note($kind, $data[0]);
                }
            });
        }
    }

    public function start(): void
    {
        $this->active = true;
        $this->touched = [];
        $this->explicit = [];
    }

    public function stop(): void
    {
        $this->active = false;
    }

    public function active(): bool
    {
        return $this->active;
    }

    public function note(string $kind, Model $model): void
    {
        if (count($this->touched) >= self::MAX_TOUCHED) {
            return;
        }
        foreach (self::IGNORED as $class) {
            if ($model instanceof $class) {
                return;
            }
        }

        // Read now: by the end of the request the model's "original" values
        // are the new ones.
        $old = [];
        $new = [];
        if ($kind === 'updated') {
            $new = $model->getChanges();
            $old = array_intersect_key($model->getOriginal(), $new);
        } elseif ($kind === 'created') {
            $new = $model->getAttributes();
        } else {
            $old = $model->getAttributes();
        }

        $this->touched[] = ['kind' => $kind, 'model' => $model, 'old' => $old, 'new' => $new];
    }

    public function noteExplicit(AuditLog $row): void
    {
        if ($this->active) {
            $this->explicit[] = $row;
        }
    }

    /** @return array<int, array{kind: string, model: Model, old: array, new: array}> */
    public function touched(): array
    {
        return $this->touched;
    }

    /** @return AuditLog[] */
    public function explicit(): array
    {
        return $this->explicit;
    }
}
