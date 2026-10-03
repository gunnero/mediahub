<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\ExperienceSetting;
use App\Models\ReleaseReminder;
use App\Models\User;
use App\Models\WebPushSubscription;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class ReleasePlanningService
{
    public function settings(User $user): ExperienceSetting
    {
        return ExperienceSetting::firstOrCreate(['user_id' => $user->id]);
    }

    public function quiet(ExperienceSetting $settings): bool
    {
        if (! $settings->quiet_start || ! $settings->quiet_end || $settings->quiet_start === $settings->quiet_end) {
            return false;
        }
        $time = now($settings->timezone)->format('H:i');

        return $settings->quiet_start < $settings->quiet_end
            ? ($time >= $settings->quiet_start && $time < $settings->quiet_end)
            : ($time >= $settings->quiet_start || $time < $settings->quiet_end);
    }

    public function deliver(User $user): void
    {
        $settings = $this->settings($user);
        if ($this->quiet($settings)) {
            return;
        }
        if (! app(AlertService::class)->preferences($user)->in_app_enabled) {
            return;
        }
        DB::transaction(function () use ($user, $settings): void {
            ReleaseReminder::where('user_id', $user->id)->whereNull('delivered_at')->where('remind_at', '<=', now())->lockForUpdate()->get()->each(function ($reminder) use ($user): void {
                try {
                    $media = app(PersonalLibraryService::class)->owned($user, $reminder->media_type, $reminder->media_id);
                } catch (ModelNotFoundException) {
                    $reminder->delete();

                    return;
                }
                Alert::firstOrCreate(['user_id' => $user->id, 'dedupe_key' => 'reminder:'.$reminder->id.':'.$reminder->remind_at->timestamp], [
                    'category' => 'reminders', 'title' => 'Time for '.$media->title, 'subtitle' => 'Your personal reminder', 'due_text' => 'Now', 'unread' => true,
                    'payload' => ['kind' => $reminder->media_type, $reminder->media_type.'Id' => $media->id],
                ]);
                $reminder->update(['delivered_at' => now()]);
            });
            if ($settings->weekly_digest && (! $settings->last_digest_at || $settings->last_digest_at->lt(now()->subWeek()))) {
                $today = now($settings->timezone);
                $releases = app(CalendarService::class)->forUser($user, ['date_from' => $today->toDateString(), 'date_to' => $today->copy()->addDays(6)->toDateString()]);
                Alert::firstOrCreate(['user_id' => $user->id, 'dedupe_key' => 'digest:'.$today->format('o-W')], [
                    'category' => 'upcoming', 'title' => 'Your week in stories', 'subtitle' => count($releases['items']).' releases in the next seven days. Open Calendar to plan your week.',
                    'due_text' => 'This week', 'unread' => true, 'payload' => ['kind' => 'digest'],
                ]);
                $settings->update(['last_digest_at' => now()]);
            }
        });
        if (! config('webpush.public_key') || ! config('webpush.private_key')) {
            return;
        }
        $push = new WebPush(['VAPID' => ['subject' => config('webpush.subject'), 'publicKey' => config('webpush.public_key'), 'privateKey' => config('webpush.private_key')]], [], 10, ['allow_redirects' => false]);
        WebPushSubscription::where('user_id', $user->id)->each(function ($subscription) use ($user, $push): void {
            $latest = Alert::forUser($user)->unread()->where('id', '>', $subscription->last_alert_id)->latest('id')->first();
            if (! $latest) {
                return;
            }
            // Keep personal title names off the device lock screen.
            $report = $push->sendOneNotification(Subscription::create($subscription->subscription), json_encode(['title' => 'MediaHub', 'body' => 'New releases or reminders are ready in Alerts.', 'url' => '/alerts']));
            if ($report->isSubscriptionExpired()) {
                $subscription->delete();
            } elseif ($report->isSuccess()) {
                $subscription->update(['last_alert_id' => $latest->id]);
            }
        });
    }

    public function calendar(User $user): string
    {
        $today = now($this->settings($user)->timezone);
        $data = app(CalendarService::class)->forUser($user, ['date_from' => $today->copy()->subDays(7)->toDateString(), 'date_to' => $today->copy()->addDays(80)->toDateString()]);
        $escape = fn ($text) => str_replace(['\\', "\r", "\n", ';', ','], ['\\\\', '', '\\n', '\\;', '\\,'], $text);
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//MediaHub//Release Calendar//EN', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH'];
        foreach ($data['items'] as $item) {
            $lines = [...$lines, 'BEGIN:VEVENT', 'UID:'.hash('sha256', $user->id.':'.$item['id']).'@mediahub', 'DTSTAMP:'.now()->utc()->format('Ymd\\THis\\Z'),
                'DTSTART;VALUE=DATE:'.str_replace('-', '', $item['date']), 'SUMMARY:'.$escape($item['title']), 'DESCRIPTION:'.$escape($item['subtitle']), 'END:VEVENT'];
        }
        $lines[] = 'END:VCALENDAR';

        // RFC 5545 folding at UTF-8 character boundaries.
        return implode("\r\n", array_map(function ($line) {
            $parts = [];
            while (strlen($line) > 70) {
                $part = mb_strcut($line, 0, 70, 'UTF-8');
                $parts[] = $part;
                $line = substr($line, strlen($part));
            }
            $parts[] = $line;

            return implode("\r\n ", $parts);
        }, $lines))."\r\n";
    }
}
