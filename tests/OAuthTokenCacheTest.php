<?php
declare(strict_types=1);

namespace vielhuber\mailhelper {
    function curl_init(?string $url = null): mixed
    {
        if (($GLOBALS['mailhelper_oauth_curl_mock']['enabled'] ?? false) === true) {
            return (object) [];
        }
        return \curl_init($url);
    }

    function curl_setopt(mixed $handle, int $option, mixed $value): bool
    {
        if (($GLOBALS['mailhelper_oauth_curl_mock']['enabled'] ?? false) === true) {
            return true;
        }
        return \curl_setopt($handle, $option, $value);
    }

    function curl_exec(mixed $handle): string|bool
    {
        if (($GLOBALS['mailhelper_oauth_curl_mock']['enabled'] ?? false) === true) {
            $GLOBALS['mailhelper_oauth_curl_mock']['calls']++;
            return $GLOBALS['mailhelper_oauth_curl_mock']['response'];
        }
        return \curl_exec($handle);
    }
}

namespace {
    use PHPUnit\Framework\TestCase;
    use vielhuber\mailhelper\mailhelper;

    final class OAuthTokenCacheTest extends TestCase
    {
        public function test__tokens_are_cached_across_instances_and_separated_by_credentials(): void
        {
            $GLOBALS['mailhelper_oauth_curl_mock'] = [
                'enabled' => true,
                'calls' => 0,
                'response' => json_encode(['access_token' => 'first-token', 'expires_in' => 3600])
            ];
            $tokenMethod = new \ReflectionMethod(mailhelper::class, 'getMicrosoftOAuthToken');

            try {
                $firstToken = $tokenMethod->invoke(new mailhelper(), 'tenant', 'client', 'first-secret');
                $cachedToken = $tokenMethod->invoke(new mailhelper(), 'tenant', 'client', 'first-secret');
                $GLOBALS['mailhelper_oauth_curl_mock']['response'] = json_encode([
                    'access_token' => 'second-token',
                    'expires_in' => 3600
                ]);
                $secondToken = $tokenMethod->invoke(new mailhelper(), 'tenant', 'client', 'second-secret');
                $calls = $GLOBALS['mailhelper_oauth_curl_mock']['calls'];
            } finally {
                unset($GLOBALS['mailhelper_oauth_curl_mock']);
            }

            $this->assertSame('first-token', $firstToken);
            $this->assertSame('first-token', $cachedToken);
            $this->assertSame('second-token', $secondToken);
            $this->assertSame(2, $calls);
        }
    }
}
