<?php

namespace Tests\Feature;

use App\Services\ProviderConnectionService;
use App\Services\ProviderDestinationGuard;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProviderConnectionServiceTest extends TestCase
{
    public function test_private_dns_resolution_is_rejected_before_http_is_sent(): void
    {
        $guard = new ProviderDestinationGuard(fn (string $host): array => ['127.0.0.1']);

        $this->app->instance(ProviderDestinationGuard::class, $guard);
        Http::fake();

        $result = app(ProviderConnectionService::class)->test('m3u', [
            'playlist_url' => 'https://provider.example.test/catalog.m3u',
        ]);

        $this->assertSame('provider_url_not_allowed', $result['errorCode']);
        Http::assertNothingSent();
    }

    public function test_provider_redirect_is_not_followed(): void
    {
        Http::fake([
            'https://provider.example.test/*' => Http::response('', 302, ['Location' => 'http://127.0.0.1/private']),
        ]);

        $result = app(ProviderConnectionService::class)->test('m3u', [
            'playlist_url' => 'https://provider.example.test/catalog.m3u',
        ]);

        $this->assertSame('provider_http_302', $result['errorCode']);
        Http::assertSentCount(1);
    }

    public function test_xmltv_request_preserves_credentials_embedded_in_the_url_query(): void
    {
        $start = now()->subMinute()->format('YmdHis O');
        $stop = now()->addHour()->format('YmdHis O');

        Http::fake(function ($request) use ($start, $stop) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $this->assertSame('private-user', $query['username'] ?? null);
            $this->assertSame('private-password', $query['password'] ?? null);

            return Http::response("<tv><channel id=\"one\"/><programme channel=\"one\" start=\"{$start}\" stop=\"{$stop}\"><title>Current program</title></programme></tv>", 200, ['Content-Type' => 'application/xml']);
        });

        $result = app(ProviderConnectionService::class)->test('xmltv', [
            'xmltv_url' => 'https://provider.example.test/guide.xml?username=private-user&password=private-password',
        ]);

        $this->assertTrue($result['reachable']);
        $this->assertTrue($result['authenticated']);
        $this->assertTrue($result['epgAvailable']);
        $this->assertNull($result['errorCode']);
    }
}
