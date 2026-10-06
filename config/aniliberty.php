<?php

declare(strict_types=1);

/*
 * AniLiberty (ex-AniLibria) public API v1 — the only service our server calls on behalf of a
 * user without yt-dlp. Its base URL comes from ANILIBERTY_API_URL and must use one of these hosts.
 *
 * - api_hosts:    hosts the API may be reached at (every request is checked by UrlValidator,
 *                 redirects are not followed).
 * - stream_hosts: hosts of the HLS playlists the API returns. Browsers fetch them directly from
 *                 there; our server never downloads or proxies a stream. Mirrored in the CSP of
 *                 the watch pages (deploy/nginx/cliphunter-watch-headers.conf).
 * - site_url:     the public site, linked from rooms as the source of the episode.
 */

return [
    'api_hosts' => ['aniliberty.top', 'anilibria.top', 'api.anilibria.app'],
    'stream_hosts' => ['libria.fun'],
    'site_url' => 'https://aniliberty.top',
];
