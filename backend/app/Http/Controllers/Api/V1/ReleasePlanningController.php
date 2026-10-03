<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\ExperienceSetting;
use App\Models\ReleaseReminder;
use App\Models\User;
use App\Models\WebPushSubscription;
use App\Services\PersonalLibraryService;
use App\Services\ReleasePlanningService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ReleasePlanningController extends Controller
{
    public function settings(Request $request, ReleasePlanningService $planning)
    {
        $settings = $planning->settings($request->user());
        if ($request->isMethod('patch')) {
            $data = $request->validate(['timezone' => 'sometimes|timezone', 'quiet_start' => 'nullable|date_format:H:i', 'quiet_end' => 'nullable|date_format:H:i', 'weekly_digest' => 'sometimes|boolean', 'share_activity' => 'sometimes|boolean']);
            if (array_key_exists('share_activity', $data) && $data['share_activity'] && ! $settings->share_activity) {
                $data['activity_since'] = now();
            }
            $settings->update($data);
            if (isset($data['timezone'])) {
                $request->user()->forceFill(['timezone' => $data['timezone']])->save();
            }
        }

        return response()->json(['settings' => $settings, 'calendarActive' => $settings->calendar_token_hash !== null,
            'pushAvailable' => filled(config('webpush.public_key')) && filled(config('webpush.private_key')), 'pushPublicKey' => config('webpush.public_key')]);
    }

    public function reminders(Request $request, PersonalLibraryService $library)
    {
        if ($request->isMethod('post')) {
            $data = $request->validate(['media_type' => 'required|in:movie,show', 'media_id' => 'required|integer|min:1', 'remind_at' => 'required|date|after:now']);
            $library->owned($request->user(), $data['media_type'], $data['media_id']);
            $data['remind_at'] = CarbonImmutable::parse($data['remind_at'])->utc();

            return response()->json(['reminder' => ReleaseReminder::create(['user_id' => $request->user()->id, ...$data])], 201);
        }
        $reminders = ReleaseReminder::where('user_id', $request->user()->id)->latest('remind_at')->limit(100)->get()->map(function ($reminder) use ($request, $library) {
            try {
                $title = $library->owned($request->user(), $reminder->media_type, $reminder->media_id)->title;
            } catch (ModelNotFoundException) {
                $title = 'Removed title';
            }

            return [...$reminder->toArray(), 'title' => $title];
        });

        return response()->json(['reminders' => $reminders]);
    }

    public function reminder(Request $request, int $reminder)
    {
        $model = ReleaseReminder::where('user_id', $request->user()->id)->findOrFail($reminder);
        if ($request->isMethod('delete')) {
            $model->delete();

            return response()->noContent();
        }
        $data = $request->validate(['remind_at' => 'required|date|after:now']);
        $model->update(['remind_at' => CarbonImmutable::parse($data['remind_at'])->utc(), 'delivered_at' => null]);

        return response()->json(['reminder' => $model]);
    }

    public function subscription(Request $request, ReleasePlanningService $planning)
    {
        $settings = $planning->settings($request->user());
        if ($request->isMethod('delete')) {
            $settings->update(['calendar_token_hash' => null]);

            return response()->noContent();
        }
        $token = Str::random(48);
        $settings->update(['calendar_token_hash' => hash('sha256', $token)]);

        return response()->json(['url' => url('/api/v1/calendar/feed/'.$token)]);
    }

    public function feed(string $token, ReleasePlanningService $planning)
    {
        $settings = ExperienceSetting::where('calendar_token_hash', hash('sha256', $token))->firstOrFail();
        $user = User::findOrFail($settings->user_id);

        return response($planning->calendar($user))->header('Content-Type', 'text/calendar; charset=utf-8')->header('Cache-Control', 'private, no-store');
    }

    public function push(Request $request)
    {
        $data = $request->validate(['endpoint' => 'required|url:https|max:2048', 'keys.p256dh' => ($request->isMethod('delete') ? 'sometimes' : 'required').'|string|max:180', 'keys.auth' => ($request->isMethod('delete') ? 'sometimes' : 'required').'|string|max:100']);
        $parts = parse_url($data['endpoint']);
        $host = strtolower($parts['host'] ?? '');
        $allowed = in_array($host, ['fcm.googleapis.com', 'updates.push.services.mozilla.com', 'web.push.apple.com'], true)
            || str_ends_with($host, '.push.services.mozilla.com') || str_ends_with($host, '.notify.windows.com');
        abort_unless($allowed && ! isset($parts['user']) && ! isset($parts['pass']) && (! isset($parts['port']) || $parts['port'] === 443), 422, 'Unsupported browser push service.');
        $hash = hash('sha256', $data['endpoint']);
        if ($request->isMethod('delete')) {
            WebPushSubscription::where('user_id', $request->user()->id)->where('endpoint_hash', $hash)->delete();

            return response()->noContent();
        }
        abort_unless(config('webpush.public_key') && config('webpush.private_key'), 503, 'Push notifications are not configured on this server.');
        foreach (['p256dh' => 65, 'auth' => 16] as $key => $length) {
            $encoded = $data['keys'][$key];
            $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);
            abort_unless(preg_match('/^[A-Za-z0-9_-]+={0,2}$/', $encoded) && $decoded !== false && strlen($decoded) === $length, 422, 'Invalid browser push keys.');
        }
        $existing = WebPushSubscription::where('endpoint_hash', $hash)->first();
        abort_if($existing && $existing->user_id !== $request->user()->id, 409, 'Disable this device’s previous subscription before changing accounts.');
        WebPushSubscription::updateOrCreate(['endpoint_hash' => $hash], ['user_id' => $request->user()->id, 'subscription' => $data,
            'last_alert_id' => Alert::forUser($request->user())->max('id') ?? 0]);

        return response()->json(['subscribed' => true]);
    }
}
