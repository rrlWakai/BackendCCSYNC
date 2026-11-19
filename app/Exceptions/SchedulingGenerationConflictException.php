<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class SchedulingGenerationConflictException extends RuntimeException
{
    public function __construct(
        public readonly int $academicTermId,
        public readonly ?string $generationUuid = null,
    ) {
        parent::__construct('Another scheduling generation is already running for this academic term.');
    }
}
