<?php

declare(strict_types=1);

namespace Aicountly\Ice;

/**
 * Where a request came from, as a trusted edge says it — read from request headers named in the environment.
 *
 *   WEBRTC_GEO_LAT_HEADER     = cf-iplatitude        coarse latitude
 *   WEBRTC_GEO_LON_HEADER     = cf-iplongitude       coarse longitude
 *   WEBRTC_GEO_REGION_HEADER  = cf-region-code       a region/state code
 *   WEBRTC_GEO_REGION_MAP     = MH=in-west,GJ=in-west,DL=in-north,UP=in-north,HR=in-north   code → node region
 *
 * Off unless a header is named: nothing here is guessed, and a header the edge does not set is empty.
 * Name headers ONLY if every request really passes through an edge that sets them and strips any a client
 * sent — otherwise a client chooses its own position. That costs it nothing but a different first choice of
 * node (admission and tier do not read this), but it makes the routing worth less. Whether the production
 * edge supplies geography at all is an open infrastructure question (docs/ACTIVE_ACTIVE_TURN.md).
 */
final class GeoHeaders
{
    /** @param array<string, string> $regionMap upper-case code → node region */
    public function __construct(
        public readonly string $latHeader = '',
        public readonly string $lonHeader = '',
        public readonly string $regionHeader = '',
        public readonly array $regionMap = [],
    ) {
    }

    /** @param callable(string): (string|null) $read */
    public static function fromEnvironment(callable $read): self
    {
        $value = static fn (string $key): string => trim((string) ($read($key) ?? ''));

        $map = [];
        foreach (preg_split('/[\s,]+/', $value('WEBRTC_GEO_REGION_MAP')) ?: [] as $pair) {
            [$code, $region] = array_pad(explode('=', $pair, 2), 2, '');
            if ($code !== '' && ($region = ClientContext::region($region)) !== '') {
                $map[strtoupper($code)] = $region;
            }
        }

        return new self($value('WEBRTC_GEO_LAT_HEADER'), $value('WEBRTC_GEO_LON_HEADER'), $value('WEBRTC_GEO_REGION_HEADER'), $map);
    }

    public function enabled(): bool
    {
        return $this->latHeader !== '' || $this->regionHeader !== '';
    }

    /**
     * @param callable(string): (string|null) $header reads one request header by name
     * @return array{lat?: string, lon?: string, region?: string}
     */
    public function extract(callable $header): array
    {
        $out = [];

        if ($this->latHeader !== '' && $this->lonHeader !== '') {
            $lat = trim((string) ($header($this->latHeader) ?? ''));
            $lon = trim((string) ($header($this->lonHeader) ?? ''));
            if ($lat !== '' && $lon !== '') {
                $out['lat'] = $lat;
                $out['lon'] = $lon;
            }
        }

        if ($this->regionHeader !== '') {
            $code = strtoupper(trim((string) ($header($this->regionHeader) ?? '')));
            if ($code !== '' && isset($this->regionMap[$code])) {
                $out['region'] = $this->regionMap[$code];
            }
        }

        return $out;
    }
}
