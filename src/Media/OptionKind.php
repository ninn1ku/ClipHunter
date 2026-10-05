<?php

declare(strict_types=1);

namespace ClipHunter\Media;

enum OptionKind: string
{
    case Video = 'video';
    case Audio = 'audio';
}
