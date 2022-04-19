<?php
declare(strict_types=1);

namespace sys\db;

use Stringable;

class ExpValue implements Stringable{
    protected string $exp;
    public function __construct(string $exp)
    {
        $this->exp = $exp;
    }

    public function __toString(): string
    {
        return $this->exp;
    }
}