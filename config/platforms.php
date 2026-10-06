<?php

declare(strict_types=1);

/*
 * Supported platforms: the SSRF allowlist.
 *
 * - domains:    a URL host must equal one of these or be a subdomain of it.
 * - extractors: yt-dlp extractor names passed to --use-extractors. Only single-video
 *               extractors are listed; "generic" is never allowed (it fetches arbitrary URLs).
 *               An empty list means the platform is never handed to yt-dlp: its links only
 *               open watch rooms that play in the browser (see config/aniliberty.php).
 *
 * Verify names with `yt-dlp --list-extractors` after upgrading yt-dlp.
 */

return [
    'youtube' => [
        'name' => 'YouTube',
        'domains' => ['youtube.com', 'youtu.be', 'youtube-nocookie.com'],
        'extractors' => ['youtube', 'youtube:clip'],
    ],
    'tiktok' => [
        'name' => 'TikTok',
        'domains' => ['tiktok.com'],
        'extractors' => ['TikTok', 'vm.tiktok'],
    ],
    'vk' => [
        'name' => 'ВКонтакте',
        'domains' => ['vk.com', 'vk.ru', 'vkvideo.ru'],
        'extractors' => ['vk', 'vk:wallpost'],
    ],
    'instagram' => [
        'name' => 'Instagram',
        'domains' => ['instagram.com', 'instagr.am'],
        'extractors' => ['Instagram'],
    ],
    'twitter' => [
        'name' => 'X (Twitter)',
        'domains' => ['x.com', 'twitter.com'],
        'extractors' => ['twitter'],
    ],
    'reddit' => [
        'name' => 'Reddit',
        'domains' => ['reddit.com', 'redd.it'],
        'extractors' => ['Reddit'],
    ],
    'twitch' => [
        'name' => 'Twitch',
        'domains' => ['twitch.tv'],
        'extractors' => ['twitch:vod', 'twitch:clips'],
    ],
    'rutube' => [
        'name' => 'RuTube',
        'domains' => ['rutube.ru'],
        'extractors' => ['rutube', 'rutube:embed'],
    ],
    'dailymotion' => [
        'name' => 'Dailymotion',
        'domains' => ['dailymotion.com', 'dai.ly'],
        'extractors' => ['dailymotion'],
    ],
    'ok' => [
        'name' => 'Одноклассники',
        'domains' => ['ok.ru'],
        'extractors' => ['Odnoklassniki'],
    ],
    'aniliberty' => [
        'name' => 'AniLiberty',
        'domains' => ['aniliberty.top', 'anilibria.top'],
        'extractors' => [],
    ],
];
