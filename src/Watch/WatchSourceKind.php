<?php

declare(strict_types=1);

namespace ClipHunter\Watch;

enum WatchSourceKind: string
{
    /** Played by the YouTube IFrame player in the browser; the server never touches the media. */
    case YouTube = 'youtube';
    /** Prepared by the download worker and streamed from our server to a <video> element. */
    case File = 'file';
}
