<?php

namespace App\Tenancy;

use App\Models\Business;
use Closure;
use LogicException;

final class BusinessContext
{
    private ?Business $business = null;

    public function business(): ?Business { return $this->business; }
    public function id(): ?string { return $this->business?->id; }
    public function requireId(): string { return $this->id() ?? throw new LogicException('A business context is required for this operation.'); }
    public function set(?Business $business): void { $this->business = $business; }
    public function run(Business $business, Closure $callback): mixed
    {
        $previous = $this->business;
        try { $this->set($business); return $callback(); }
        finally { $this->set($previous); }
    }
}
