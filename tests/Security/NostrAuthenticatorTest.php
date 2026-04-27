<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\NostrAuthenticator;
use App\Service\NostrKeyHelper;
use PHPUnit\Framework\TestCase;
use swentel\nostr\Event\Event;
use swentel\nostr\Key\Key;
use swentel\nostr\Sign\Sign;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Unit tests for {@see NostrAuthenticator} (no full HTTP stack / DB: no nsec parameter, no Docker MySQL).
 */
class NostrAuthenticatorTest extends TestCase
{
    public function testValidNostrEventReturnsPassport(): void
    {
        $nsec = (new Key())->convertPrivateKeyToBech32((new Key())->generatePrivateKey());
        $token = 'Nostr '.$this->signedAuthEventBase64($nsec);
        $request = Request::create('/login', 'GET', [], [], [], ['HTTP_AUTHORIZATION' => $token]);

        $out = (new NostrAuthenticator(new NostrKeyHelper()))->authenticate($request);

        $this->assertInstanceOf(SelfValidatingPassport::class, $out);
    }

    public function testInvalidAuthorizationHeaderThrows(): void
    {
        $this->expectException(AuthenticationException::class);
        $request = Request::create('/login', 'GET', [], [], [], [
            'HTTP_AUTHORIZATION' => 'InvalidHeader',
        ]);
        (new NostrAuthenticator(new NostrKeyHelper()))->authenticate($request);
    }

    public function testExpiredEventThrows(): void
    {
        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Expired');
        $expiredToken = 'Nostr eyJjcmVhdGVkX2F0IjoxNzMzMzIxMzUyLCJraW5kIjoyNzIzNSwidGFncyI6W1sidSIsImh0dHBzOi8vbG9jYWxob3N0L2xvZ2luIl0sWyJtZXRob2QiLCJHRVQiXV0sImNvbnRlbnQiOiIiLCJwdWJrZXkiOiJkNDc1Y2U0YjM5Nzc1MDcxMzBmNDJjN2Y4NjM0NmVmOTM2ODAwZjNhZTc0ZDVlY2Y4MDg5MjgwY2RjMTkyM2U5IiwiaWQiOiJhYjA4NGM1NWQ5Y2UzMDliN2UxNzIyZGI2ODNjZTc2ZDg5NGNjN2QyYTIzZTRkNWUyMTUyYTM2Y2M2ODI1MTQ5Iiwic2lnIjoiOWI1Yjk2YjhkN2U2ZGM4YWU3ZmM4NjU2ZTE0NDVlZjkwYzc1YWQxNzZkYTRmNmNhMjI0NTRkNTJjNTk3ZTBmNjYwZjAwZjE3MmIxYjMzYzM4YTg2Y2U0YTBiMTdmMDgwMWEyNzJmZmVmYWU0NmY2OTgzZGZjYjRlM2YyZDgwZGYifQ==';
        $request = Request::create('/login', 'GET', [], [], [], [
            'HTTP_AUTHORIZATION' => $expiredToken,
        ]);
        (new NostrAuthenticator(new NostrKeyHelper()))->authenticate($request);
    }

    private function signedAuthEventBase64(string $nsec): string
    {
        $note = new Event();
        $note->setContent('');
        $note->setKind(27235);
        $note->setTags([
            ['u', 'https://localhost/login'],
            ['method', 'POST'],
        ]);
        (new Sign())->signEvent($note, $nsec);

        return base64_encode($note->toJson());
    }
}
