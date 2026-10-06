<?php

declare(strict_types=1);

namespace ClipHunter\Job;

/**
 * Why a job exists. Watch jobs prepare a file for a watch room: they are streamed inline,
 * kept longer and invisible to the /api/downloads endpoints.
 */
enum JobPurpose: string
{
    case Download = 'download';
    case Watch = 'watch';
}
