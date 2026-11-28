"""Measure the isolated staging Apache/Laravel HTTP surface through loopback."""

from concurrent.futures import ThreadPoolExecutor
from http.client import HTTPConnection
import json
import math
import re
import sys
from threading import Barrier
from time import monotonic


WORKERS = 4
ROUNDS = 4
DEADLINE = 5
MAX_BODY = 512 * 1024
PATHS = (('/', 200), ('/api/stations?page=2&country=AE', 200), ('/api/reservations', 401))


def sample(port, path, expected_status):
    started = monotonic()
    connection = HTTPConnection('127.0.0.1', port, timeout=DEADLINE)
    try:
        connection.request('GET', path, headers={'Accept': 'application/json'})
        response = connection.getresponse()
        if response.status != expected_status:
            raise ValueError(f'Unexpected status for {path}: {response.status}')
        if response.getheader('X-Content-Type-Options') != 'nosniff':
            raise ValueError(f'Missing security header for {path}')
        cache_directives = {part.strip() for part in (response.getheader('Cache-Control') or '').split(',')}
        if path != '/' and not {'no-store', 'private'} <= cache_directives:
            raise ValueError(f'Missing private API cache policy for {path}')
        body = response.read(MAX_BODY + 1)
    finally:
        connection.close()

    elapsed = monotonic() - started
    if not body or len(body) > MAX_BODY or elapsed > DEADLINE:
        raise ValueError(f'Response exceeded the timing or payload bounds for {path}')
    if path == '/':
        if b'discovery-map' not in body:
            raise ValueError('Discovery page marker missing')
    elif expected_status == 401:
        if json.loads(body) != {'message': 'Unauthenticated.'}:
            raise ValueError('Protected API returned an unexpected anonymous response')
    else:
        page = json.loads(body)
        names = [item.get('name') for item in page['data']]
        if (page.get('current_page') != 2 or page.get('total') != 75 or
                names != [f'Synthetic performance station {n}' for n in range(50, 25, -1)]):
            raise ValueError('Catalog did not return the expected synthetic page')
    return elapsed * 1000


def benchmark(port, revision):
    barrier = Barrier(WORKERS, timeout=DEADLINE)

    def worker():
        barrier.wait()
        return [(path, sample(port, path, status)) for _ in range(ROUNDS) for path, status in PATHS]

    by_route = {path: [] for path, _ in PATHS}
    with ThreadPoolExecutor(max_workers=WORKERS) as pool:
        for samples in pool.map(lambda _: worker(), range(WORKERS)):
            for path, duration in samples:
                by_route[path].append(duration)

    def summarize(samples):
        ordered = sorted(samples)
        return {'requests': len(ordered),
                'p50_ms': round(ordered[math.ceil(len(ordered) * 0.50) - 1], 2),
                'p95_ms': round(ordered[math.ceil(len(ordered) * 0.95) - 1], 2),
                'max_ms': round(ordered[-1], 2)}

    return {'source_sha': revision, 'suite': 'disposable-staging-http',
            'workers': WORKERS, 'per_request_deadline_ms': DEADLINE * 1000,
            'catalog_fixture_rows': 75,
            'routes': {path: summarize(samples) for path, samples in by_route.items()}}


if __name__ == '__main__':
    if (len(sys.argv) != 3 or not re.fullmatch(r'[1-9][0-9]{0,4}', sys.argv[1])
            or not 1 <= int(sys.argv[1]) <= 65535 or not re.fullmatch(r'[0-9a-f]{40}', sys.argv[2])):
        sys.exit('Expected a loopback port and exact staging image source SHA.')
    print(json.dumps(benchmark(int(sys.argv[1]), sys.argv[2]), allow_nan=False))
