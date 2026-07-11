<?php

namespace Tests\Feature;

use App\Services\ProviderConnectionService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProviderConnectionServiceTest extends TestCase
{
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
