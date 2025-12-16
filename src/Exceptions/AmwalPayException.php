<?php

declare(strict_types=1);

namespace Amwal\Payment\Exceptions;

class AmwalPayException extends \Exception {
    private ?array $context;

    public function __construct(string $message, int $code = 0, ?array $context = null, ?\Throwable $previous = null) {
        $this->context = $context;
        parent::__construct($message, $code, $previous);
    }

    public function getContext(): ?array {
        return $this->context;
    }
}
