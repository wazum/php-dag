<?php

declare(strict_types=1);

namespace PhpDag\Layout;

use InvalidArgumentException;

final class Pipeline
{
    /** @var list<Processor> */
    private array $processors = [];

    public function add(Processor $processor): void
    {
        $this->processors[] = $processor;
    }

    /** @param class-string<Processor> $processorClass */
    public function insertBefore(string $processorClass, Processor $processor): self
    {
        array_splice($this->processors, $this->indexOf($processorClass), 0, [$processor]);

        return $this;
    }

    /** @param class-string<Processor> $processorClass */
    public function insertAfter(string $processorClass, Processor $processor): self
    {
        array_splice($this->processors, $this->indexOf($processorClass) + 1, 0, [$processor]);

        return $this;
    }

    /** @param class-string<Processor> $processorClass */
    public function replace(string $processorClass, Processor $processor): self
    {
        array_splice($this->processors, $this->indexOf($processorClass), 1, [$processor]);

        return $this;
    }

    /** @param class-string<Processor> $processorClass */
    public function remove(string $processorClass): self
    {
        array_splice($this->processors, $this->indexOf($processorClass), 1);

        return $this;
    }

    /** @param class-string<Processor> $processorClass */
    public function contains(string $processorClass): bool
    {
        foreach ($this->processors as $existing) {
            if ($existing instanceof $processorClass) {
                return true;
            }
        }

        return false;
    }

    /** @param class-string<Processor> $processorClass */
    private function indexOf(string $processorClass): int
    {
        foreach ($this->processors as $index => $existing) {
            if ($existing instanceof $processorClass) {
                return $index;
            }
        }

        throw new InvalidArgumentException(sprintf('Pipeline contains no processor of class "%s"', $processorClass));
    }

    public function execute(LayoutGraph $graph): void
    {
        foreach ($this->processors as $processor) {
            $processor->process($graph);
        }
    }
}
