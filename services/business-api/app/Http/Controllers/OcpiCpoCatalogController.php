<?php

namespace App\Http\Controllers;

use App\Models\OcpiProvider;
use App\Services\Ocpi\CpoCatalogPublisher;
use App\Services\Ocpi\CredentialExchange;
use App\Services\Ocpi\OcpiException;
use App\Services\Ocpi\OcpiMessageHeaders;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class OcpiCpoCatalogController extends Controller
{
    public function show(Request $request, OcpiProvider $provider, CpoCatalogPublisher $publisher)
    {
        abort_unless($request->user()->role === 'admin', 403);
        $request->validate(['page' => 'sometimes|integer|min:1|max:1000']);

        return $this->private($publisher->show($request->user(), $provider));
    }

    public function change(Request $request, OcpiProvider $provider, string $module, string $source, CpoCatalogPublisher $publisher)
    {
        abort_unless($request->user()->role === 'admin', 403);
        $data = $request->validate([
            'action' => 'required|in:publish,withdraw',
            'confirmed' => 'required|accepted',
            'expected' => 'nullable|string|max:27',
        ]);
        try {
            return $this->private($publisher->change($request->user(), $provider, $module, (int) $source, $data['action'], $data['expected'] ?? null));
        } catch (OcpiException) {
            abort(409, 'The approved catalog source requires review.');
        }
    }

    public function push(Request $request, OcpiProvider $provider, string $module, string $source, CpoCatalogPublisher $publisher)
    {
        abort_unless($request->user()->role === 'admin', 403);
        $data = $request->validate(['confirmed' => 'required|accepted', 'published_at' => 'required|string|size:27', 'operation_key' => 'required|uuid']);
        try {
            return $this->private($publisher->push($request->user(), $provider, $module, (int) $source, $data['published_at'], $data['operation_key']));
        } catch (OcpiException) {
            abort(409, 'The approved catalog source requires review.');
        }
    }

    public function reconcile(Request $request, OcpiProvider $provider, string $module, string $source, CpoCatalogPublisher $publisher)
    {
        abort_unless($request->user()->role === 'admin', 403);
        $data = $request->validate([
            'confirmed' => 'required|accepted',
            'published_at' => 'required|string|size:27',
            'cdr_receipt' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_-]{1,36}$/D'],
        ]);

        return $this->private($publisher->reconcile($request->user(), $provider, $module, (int) $source, $data['published_at'], $data['cdr_receipt'] ?? null));
    }

    private function private(array $data)
    {
        return response()->json($data)->header('Cache-Control', 'private, no-store');
    }

    private function utc(mixed $value): string
    {
        abort_unless(is_string($value) && preg_match('/^(\d{4})-(\d{2})-(\d{2})T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-](?:0\d|1[0-3]):[0-5]\d|[+-]14:00)?$/D', $value, $parts)
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]), 400);
        $zone = new DateTimeZone('UTC');
        $zoned = str_ends_with($value, 'Z') || preg_match('/[+-]\d{2}:\d{2}$/D', $value);
        $format = '!Y-m-d\TH:i:s'.(str_contains($value, '.') ? '.u' : '').($zoned ? 'P' : '');
        $date = DateTimeImmutable::createFromFormat($format, $value, $zone);
        abort_unless($date !== false && DateTimeImmutable::getLastErrors() === false, 400);
        $date = $date->setTimezone($zone);
        abort_unless((int) $date->format('Y') >= 1 && (int) $date->format('Y') <= 9999, 400);

        return $date->format('Y-m-d\TH:i:s.u\Z');
    }

    private function window(Request $request): array
    {
        $window = $request->query();
        abort_unless(array_diff(array_keys($window), ['date_from', 'date_to', 'offset', 'limit']) === [], 400);
        foreach (['date_from', 'date_to'] as $key) {
            if (array_key_exists($key, $window)) {
                $window[$key] = $this->utc($window[$key]);
            }
        }
        abort_if(isset($window['date_from'], $window['date_to']) && $window['date_from'] >= $window['date_to'], 400);
        $offset = $window['offset'] ?? '0';
        $limit = $window['limit'] ?? '25';
        abort_unless(is_string($offset) && preg_match('/^(?:0|[1-9][0-9]{0,4})$/D', $offset)
            && is_string($limit) && preg_match('/^[1-9][0-9]{0,4}$/D', $limit), 400);
        $window['offset'] = (int) $offset;
        $window['limit'] = min((int) $limit, 100);

        return $window;
    }

    private function node(array $parent, string $key, string $field, string $id): array
    {
        foreach ($parent[$key] ?? [] as $item) {
            if ($item[$field] === $id) {
                return $item;
            }
        }
        abort(404);
    }

    public function protocol(
        Request $request,
        CredentialExchange $credentials,
        CpoCatalogPublisher $publisher,
        string $module,
        ?string $external = null,
        ?string $evse = null,
        ?string $connector = null,
    ) {
        $headers = OcpiMessageHeaders::response($request) + ['Cache-Control' => 'private, no-store'];
        try {
            [$provider, $link] = app(OcpiProtocolCredentialsController::class)->authenticate($request, $credentials, 'catalog');
            [$connection, $local] = $credentials->configuration($provider);
            $role = $local['roles'][0];
            foreach ([
                'OCPI-from-country-code' => $connection['party_country'], 'OCPI-from-party-id' => $connection['party_id'],
                'OCPI-to-country-code' => $role['country_code'], 'OCPI-to-party-id' => $role['party_id'],
            ] as $header => $identity) {
                abort_if($request->headers->has($header) && $request->headers->all($header) !== [$identity], 403);
            }
            $window = $this->window($request);
            [$data, $total] = $publisher->read($provider, $link, $module, $window, $external === null ? null : (int) substr($external, 1));
            if ($external === null) {
                $headers['X-Total-Count'] = (string) $total;
                $headers['X-Limit'] = (string) $window['limit'];
                $window['offset'] += count($data);
                if ($window['offset'] < $total && $data !== []) {
                    $url = $credentials->localPayload('', $provider)['url'];
                    $query = http_build_query($window, '', '&', PHP_QUERY_RFC3986);
                    $headers['Link'] = '<'.substr($url, 0, -strlen('versions')).'2.2.1/cpo/'.$module.'?'.$query.'>; rel="next"';
                }
            } elseif ($evse !== null) {
                $data = $this->node($data, 'evses', 'uid', $evse);
                if ($connector !== null) {
                    $data = $this->node($data, 'connectors', 'id', $connector);
                }
            }

            return response()->json(['status_code' => 1000, 'timestamp' => now()->utc()->toISOString(), 'data' => $data], 200, $headers);
        } catch (HttpExceptionInterface|OcpiException $e) {
            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 409;

            return response()->json(['status_code' => in_array($status, [401, 403], true) ? 2004 : 2001, 'timestamp' => now()->utc()->toISOString(), 'data' => null], $status, $headers);
        }
    }
}
