<?php

declare(strict_types=1);

namespace App\Scenarios;

/**
 * What a scenario found: checks that must hold, and facts worth knowing.
 */
final class Report
{
    /** @var list<array{claim: string, expected: string, measured: string, passed: bool}> */
    private array $checks = [];

    /** @var array<string, mixed> */
    private array $facts = [];

    public function __construct(
        public readonly string $name,
        public readonly string $title,
    ) {}

    public function check(string $claim, string $expected, int|float|string $measured, bool $passed): self
    {
        $this->checks[] = [
            'claim' => $claim,
            'expected' => $expected,
            'measured' => (string) $measured,
            'passed' => $passed,
        ];

        return $this;
    }

    public function equals(string $claim, int $expected, int $measured): self
    {
        return $this->check($claim, (string) $expected, $measured, $measured === $expected);
    }

    public function atMost(string $claim, int|float $limit, int|float $measured): self
    {
        return $this->check($claim, "at most {$limit}", $measured, $measured <= $limit);
    }

    public function atLeast(string $claim, int|float $minimum, int|float $measured): self
    {
        return $this->check($claim, "at least {$minimum}", $measured, $measured >= $minimum);
    }

    public function fact(string $name, mixed $value): self
    {
        $this->facts[$name] = $value;

        return $this;
    }

    public function passed(): bool
    {
        return array_all($this->checks, static fn (array $check): bool => $check['passed']);
    }

    /**
     * @return list<array{claim: string, expected: string, measured: string, passed: bool}>
     */
    public function checks(): array
    {
        return $this->checks;
    }

    /**
     * @return array<string, mixed>
     */
    public function facts(): array
    {
        return $this->facts;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'scenario' => $this->name,
            'title' => $this->title,
            'passed' => $this->passed(),
            'checks' => $this->checks,
            'facts' => $this->facts,
        ];
    }
}
