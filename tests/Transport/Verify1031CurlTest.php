<?php

declare(strict_types=1);

namespace VoltKit\Rtc\Tests\Transport;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use VoltKit\Rtc\StreamClient;
use VoltKit\Rtc\StreamError;
use VoltKit\Rtc\Tests\Support\Loopback;
use VoltKit\Rtc\Transport\CurlTransport;

/**
 * Independent adversarial verification of the 2026-10-31 fixes (PHP 0.1.2), the parts that need the real curl
 * transport: user info of every shape on the wire (D8, spec 2.2) and a refused redirect whose body never comes
 * (spec 3.5). Written by a verifier who did not make the fixes; nothing here changes product code.
 */
final class Verify1031CurlTest extends TestCase
{
    private static ?Loopback $server = null;

    public static function setUpBeforeClass(): void
    {
        self::$server = Loopback::start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;
    }

    private static function server(): Loopback
    {
        self::assertNotNull(self::$server);
        return self::$server;
    }

    #[Test]
    public function userInfoOfEveryShapeGoesOutAsBasicOrNotAtAll(): void
    {
        $echo = self::server()->baseUrl('echo');
        $withUser = static fn (string $userInfo): string => str_replace('http://', 'http://' . $userInfo, $echo);
        $cases = [
            '' => null,
            '@' => ':',
            'user@' => 'user:',
            'user:@' => 'user:',
            ':pw@' => ':pw',
            'u%3Ax:p%40w@' => 'u:x:p@w',
            'u:p%2Fq%3Fr%23s@' => 'u:p/q?r#s',
        ];
        foreach ($cases as $userInfo => $basic) {
            self::server()->forget();
            $c = new StreamClient($withUser($userInfo), 'sk_test', retries: 0, timeoutMs: 2000);
            $c->getUsage();
            $seen = self::server()->requests();
            self::assertCount(1, $seen, $userInfo);
            $sent = $seen[0]['headers']['authorization'] ?? null;
            if ($basic === null || $basic === ':') {
                // curl sends nothing for empty credentials; whatever it does, nothing else may go out.
                self::assertTrue($sent === null || $sent === 'Basic ' . base64_encode(':'), "$userInfo: " . var_export($sent, true));
            } else {
                self::assertSame('Basic ' . base64_encode($basic), $sent, $userInfo);
            }
            self::assertSame('sk_test', $seen[0]['headers']['x-api-key'] ?? null, $userInfo);
            self::assertStringNotContainsString('pw', (string) $c, $userInfo);
            self::assertStringNotContainsString('p%40w', (string) $c, $userInfo);
        }
    }

    #[Test]
    public function whatCurlCannotSendIsRefusedWhenTheClientIsMadeAndNotShown(): void
    {
        $echo = self::server()->baseUrl('echo');
        foreach (['user:p@hunter2@', 'u%00:hunter2@', 'user:hunter2%00@'] as $userInfo) {
            $url = str_replace('http://', 'http://' . $userInfo, $echo);
            foreach ([null, new CurlTransport()] as $transport) {
                try {
                    new StreamClient($url, 'sk_test', transport: $transport);
                    self::fail("$userInfo was accepted with curl");
                } catch (\InvalidArgumentException $e) {
                    self::assertStringNotContainsString('hunter2', $e->getMessage(), $userInfo);
                    self::assertStringNotContainsString('127.0.0.1', $e->getMessage(), $userInfo);
                    self::assertNull($e->getPrevious());
                }
            }
        }
    }

    #[Test]
    public function aRedirectWhoseBodyNeverComesIsRefusedAtOnceOnEveryVerb(): void
    {
        // The loopback's /redirect-stall declares a 100-byte body and sends 9 bytes of it, then nothing.
        $c = new StreamClient(self::server()->baseUrl('redirect-stall'), 'sk_test', retries: 2, timeoutMs: 1500, sleeper: static function (): void {
        });
        foreach (['listRooms' => static fn () => $c->listRooms(), 'deleteRoom' => static fn () => $c->deleteRoom('r'), 'ensureRoom' => static fn () => $c->ensureRoom('r', 'h')] as $name => $call) {
            self::server()->forget();
            $started = hrtime(true);
            try {
                $call();
                self::fail("$name resolved");
            } catch (StreamError $e) {
                $ms = (hrtime(true) - $started) / 1_000_000;
                self::assertSame('redirected', $e->errorCode, "$name: {$e->getMessage()} after $ms ms");
                self::assertSame(302, $e->status);
                self::assertFalse($e->retryable);
                self::assertLessThan(1000, $ms, "$name: the body was waited for");
                self::assertCount(1, self::server()->requests(), "$name: never retried");
            }
        }
    }
}
