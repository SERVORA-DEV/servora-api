<?php

namespace App\Console\Commands;

use App\Models\Announcement;
use App\Repository\NotificationRepository;
use App\Service\System\AnnouncementAudience;
use App\Support\ClientNotificationLink;
use App\Support\Platform\MobileRelease;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

// Tells every client when a newer mobile app is published. The version is still set in .env
// (MOBILE_LATEST_VERSION / MOBILE_LATEST_BUILD / MOBILE_APK_URL, read by MobileRelease); this
// notices a higher build and sends one notification per client account, the same way an
// announcement does (feed, in-app pop-up and phone push). Tapping it opens the download.
//
// Each build is sent once: it is recorded as an Announcement with request_id
// "app-release-{build}", whose unique index stops a repeat. The first run on a server only
// records the build it finds (status "baseline") and sends nothing, so a deploy never tells
// everyone about the version they already have.
class AnnounceMobileRelease extends Command
{
    protected $signature = 'mobile:announce-release';

    protected $description = 'Notify clients once when MOBILE_LATEST_BUILD goes up';

    public const REQUEST_PREFIX = 'app-release-';

    public function handle(MobileRelease $releases, AnnouncementAudience $audience, NotificationRepository $notifications): int
    {
        $release = $releases->current();
        $build = (int) $release['latest_build'];
        if ($build <= 0 || empty($release['apk_url'])) {
            $this->info('No release published: nothing to announce.');

            return self::SUCCESS;
        }

        $requestId = self::REQUEST_PREFIX.$build;
        if (Announcement::where('request_id', $requestId)->exists()) {
            $this->info("Build {$build} was already announced.");

            return self::SUCCESS;
        }

        $version = trim((string) ($release['latest_version'] ?? ''));
        $title = ClientNotificationLink::APP_UPDATE_TITLE;
        $body = ($version !== '' ? "Version {$version} is ready." : 'A new version is ready.').' Tap to update.';

        $first = ! Announcement::where('request_id', 'like', self::REQUEST_PREFIX.'%')->exists();
        // An older build than one already announced (a rollback in .env) is not news.
        $newest = Announcement::where('request_id', 'like', self::REQUEST_PREFIX.'%')
            ->pluck('request_id')
            ->map(fn ($id) => (int) substr($id, strlen(self::REQUEST_PREFIX)))
            ->max() ?? 0;
        $baseline = $first || $build < $newest;

        $recipients = $baseline ? collect() : $audience->clients()->get(['id']);

        try {
            DB::transaction(function () use ($requestId, $title, $body, $baseline, $recipients, $notifications) {
                $announcement = Announcement::create([
                    'sender_id' => null,
                    'audience' => 'clients',
                    'scope' => 'all',
                    'priority' => 'normal',
                    'title' => $title,
                    'message' => $body,
                    'request_id' => $requestId,
                    'status' => $baseline ? 'baseline' : 'sent',
                    'recipient_count' => $recipients->count(),
                ]);

                foreach ($recipients as $user) {
                    $notifications->create($user->id, $title, "{$body}\n\n— Servora", 'System', null, $announcement->id, 'normal');
                }
            });
        } catch (UniqueConstraintViolationException) {
            // Another run got there first.
            return self::SUCCESS;
        }

        $this->info($baseline
            ? "Recorded build {$build} as the starting point; nothing sent."
            : "Told {$recipients->count()} clients about build {$build}.");

        return self::SUCCESS;
    }
}
