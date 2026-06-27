<?php

namespace App\Services;

readonly class FrontendPublishResult
{
    public function __construct(
        public bool $success,
        public string $message,
    ) {}
}
