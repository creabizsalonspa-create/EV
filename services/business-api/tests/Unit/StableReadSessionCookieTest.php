<?php

namespace Tests\Unit;

use App\Http\Middleware\StableReadSessionCookie;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class StableReadSessionCookieTest extends TestCase
{
    private function request(string $method, mixed $incoming): Request
    {
        config(['session.cookie' => 'ev_session']);
        $request = Request::create('/api/vehicles', $method, cookies: ['ev_session' => $incoming]);
        $store = new Store('cookie_test', new ArraySessionHandler(120));
        $store->setId(str_repeat('a', 40));
        $store->start();
        $request->setLaravelSession($store);

        return $request;
    }

    public static function reads(): array
    {
        return [['GET'], ['HEAD']];
    }

    #[DataProvider('reads')]
    public function test_stable_reads_cannot_reissue_old_session_cookie_but_keep_csrf_and_preference_cookies(string $method): void
    {
        $request = $this->request($method, str_repeat('a', 40));
        $response = (new StableReadSessionCookie)->handle($request, function () {
            $response = new Response('scoped body');
            $response->headers->setCookie(Cookie::create('ev_session', str_repeat('a', 40), path: '/', domain: 'example.test', secure: true, httpOnly: true));
            $response->headers->setCookie(Cookie::create('XSRF-TOKEN', 'csrf'));
            $response->headers->setCookie(Cookie::create('locale', 'ar'));

            return $response;
        });
        $this->assertSame(['XSRF-TOKEN', 'locale'], array_map(fn ($c) => $c->getName(), $response->headers->getCookies()));
        $this->assertSame('scoped body', $response->getContent());
    }

    public static function writes(): array
    {
        return [['POST'], ['PUT'], ['PATCH'], ['DELETE']];
    }

    #[DataProvider('writes')]
    public function test_mutations_keep_cookie_refresh_and_security_attributes(string $method): void
    {
        $response = (new StableReadSessionCookie)->handle($this->request($method, str_repeat('a', 40)), function () {
            $response = new Response;
            $response->headers->setCookie(Cookie::create('ev_session', str_repeat('a', 40), secure: true, httpOnly: true, sameSite: 'lax'));

            return $response;
        });
        $cookie = $response->headers->getCookies()[0];
        $this->assertSame('ev_session', $cookie->getName());
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
    }

    public function test_first_bootstrap_malformed_cookie_and_intentional_session_rotation_are_preserved(): void
    {
        foreach ([null, '', ['invalid']] as $incoming) {
            $response = (new StableReadSessionCookie)->handle($this->request('GET', $incoming), function () {
                $response = new Response;
                $response->headers->setCookie(Cookie::create('ev_session', str_repeat('a', 40)));

                return $response;
            });
            $this->assertCount(1, $response->headers->getCookies());
        }
        $request = $this->request('GET', str_repeat('a', 40));
        $response = (new StableReadSessionCookie)->handle($request, function ($request) {
            $request->session()->migrate(true);
            $response = new Response;
            $response->headers->setCookie(Cookie::create('ev_session', $request->session()->getId()));

            return $response;
        });
        $this->assertSame($request->session()->getId(), $response->headers->getCookies()[0]->getValue());
        $this->assertNotSame(str_repeat('a', 40), $request->session()->getId());
    }
}
