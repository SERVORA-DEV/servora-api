<?php

namespace Tests\Unit;

use App\Support\AllowedOrigin;
use PHPUnit\Framework\TestCase;

// Which front end is talking to the API, when it is one the API trusts.
class AllowedOriginTest extends TestCase
{
    private array $allowed = ['https://app.servora.ph', 'http://localhost:3000'];

    private array $patterns = [
        '#^https://[a-z0-9-]+(\.[a-z0-9-]+)*\.devtunnels\.ms$#i',
        '#^https?://192\.168\.\d{1,3}\.\d{1,3}(:\d+)?$#',
    ];

    public function test_an_origin_on_the_list_is_trusted(): void
    {
        $this->assertSame('https://app.servora.ph', AllowedOrigin::from('https://app.servora.ph', null, $this->allowed, $this->patterns));
        $this->assertSame('http://localhost:3000', AllowedOrigin::from('http://localhost:3000/', null, $this->allowed, $this->patterns));
    }

    public function test_a_forwarded_port_and_a_lan_address_match_the_patterns(): void
    {
        $this->assertSame('https://xb9nvs85-3000.asse.devtunnels.ms', AllowedOrigin::from('https://xb9nvs85-3000.asse.devtunnels.ms', null, $this->allowed, $this->patterns));
        $this->assertSame('http://192.168.1.20:3000', AllowedOrigin::from('http://192.168.1.20:3000', null, $this->allowed, $this->patterns));
    }

    public function test_an_unknown_site_is_never_trusted(): void
    {
        $this->assertNull(AllowedOrigin::from('https://evil.example', null, $this->allowed, $this->patterns));
        $this->assertNull(AllowedOrigin::from('https://devtunnels.ms.evil.example', null, $this->allowed, $this->patterns));
        $this->assertNull(AllowedOrigin::from('https://app.servora.ph.evil.example', 'https://evil.example/page', $this->allowed, $this->patterns));
    }

    public function test_the_referer_is_used_when_there_is_no_origin(): void
    {
        $this->assertSame('https://app.servora.ph', AllowedOrigin::from(null, 'https://app.servora.ph/business/subscription?x=1', $this->allowed, $this->patterns));
        $this->assertSame('https://app.servora.ph', AllowedOrigin::from('null', 'https://app.servora.ph/', $this->allowed, $this->patterns));
    }

    public function test_no_browser_headers_means_no_origin(): void
    {
        // The phone app, a webhook.
        $this->assertNull(AllowedOrigin::from(null, null, $this->allowed, $this->patterns));
        $this->assertNull(AllowedOrigin::from('', 'not a url', $this->allowed, $this->patterns));
    }

    public function test_addresses_are_tidied_before_comparing(): void
    {
        $this->assertSame('https://app.servora.ph', AllowedOrigin::normalize('HTTPS://App.Servora.PH/some/page'));
        $this->assertSame('http://localhost:3000', AllowedOrigin::normalize('http://localhost:3000'));
        $this->assertNull(AllowedOrigin::normalize('ftp://example.com'));
        $this->assertNull(AllowedOrigin::normalize('javascript:alert(1)'));
    }

    public function test_a_star_in_a_configured_origin_stands_for_one_host_label(): void
    {
        $pattern = AllowedOrigin::wildcardPattern('https://*.servora.ph/');

        $this->assertTrue(AllowedOrigin::isAllowed('https://staging.servora.ph', [], [$pattern]));
        $this->assertFalse(AllowedOrigin::isAllowed('https://a.b.servora.ph', [], [$pattern]));
        $this->assertFalse(AllowedOrigin::isAllowed('https://staging.servora.ph.evil.example', [], [$pattern]));
        $this->assertFalse(AllowedOrigin::isAllowed('http://staging.servora.ph', [], [$pattern]));
    }
}
