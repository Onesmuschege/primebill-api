<?php

namespace App\Services\Radius;

/**
 * Structured outcome of a RADIUS CoA / Disconnect attempt.
 *
 * CoaResult is TRUTHFUL by design: `success` reflects an ACK from the NAS,
 * `method` records which dynamic-authorisation operation ultimately
 * succeeded (coa | disconnect), and `failureReason` explains why nothing
 * happened when it did not (Core ISP Gate — Sections 21, 15).
 */
class CoaResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $method,
        public readonly ?int $responseCode,
        public readonly ?string $error,
        public readonly ?string $reasonCode,
    ) {}

    public function toArray(): array
    {
        return [
            'success'       => $this->success,
            'method'        => $this->method,
            'response_code' => $this->responseCode,
            'error'         => $this->error,
            'reason'        => $this->reasonCode,
        ];
    }
}
