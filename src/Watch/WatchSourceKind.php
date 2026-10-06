<?php

declare(strict_types=1);

namespace ClipHunter\Watch;

enum WatchSourceKind: string
{
    /** Played by the YouTube IFrame player in the browser; the server never touches the media. */
    case YouTube = 'youtube';
    /** Played by the official VK Video embed (video_ext.php + JS API) in the browser. */
    case Vk = 'vk';
    /** Played by the official Twitch embed (Twitch.Player): recordings and live channels. */
    case Twitch = 'twitch';
    /** An AniLiberty episode: its HLS stream plays in the browser straight from their CDN. */
    case AniLiberty = 'aniliberty';
    /** Prepared by the download worker and streamed from our server to a <video> element. */
    case File = 'file';

    /**
     * What a ticket's ref must look like for this kind. Tickets carry identifiers only, never
     * URLs. Mirrored by REF_PATTERN in rooms/src/security/ticket.ts; both are pinned by
     * tests/fixtures/watch/ticket-v1.json.
     */
    public function refPattern(): string
    {
        return match ($this) {
            self::YouTube => '~^[A-Za-z0-9_-]{11}$~D',
            self::Vk => '~^-?[1-9][0-9]{0,18}_[1-9][0-9]{0,18}$~D',
            self::Twitch => '~^(?:video:[1-9][0-9]{0,14}|channel:[a-z0-9][a-z0-9_]{2,24})$~D',
            self::AniLiberty => '~^[1-9][0-9]{0,9}:[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$~D',
            self::File => '~^[a-f0-9]{32}$~D',
        };
    }

    /** Whether the media plays in the browser from the platform itself (no server preparation). */
    public function isEmbedded(): bool
    {
        return $this !== self::File;
    }
}
