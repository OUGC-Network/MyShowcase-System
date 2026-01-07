<?php

declare(strict_types=1);

namespace MyShowcase\Collection;


use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;

class Collection implements IteratorAggregate, Countable, ArrayAccess
{
    public function __construct(private array $elements = [])
    {
    }

    public function add($element, int|string|null $id = null): void
    {
        if ($id === null) {
            $this->elements[] = $element;
        } else {
            $this->elements[$id] = $element;
        }
    }

    public function remove(array $element): bool
    {
        $id = array_search($element, $this->elements, true);

        if ($id !== false) {
            unset($this->elements[$id]);

            return true;
        }

        return false;
    }

    public function contains(mixed $element): bool
    {
        return in_array($element, $this->elements, true);
    }

    public function all(): array
    {
        return $this->elements;
    }

    public function clear(): void
    {
        $this->elements = [];
    }

    public function filter(callable $callback): self
    {
        return new self(array_filter($this->elements, $callback));
    }

    public function map(callable $callback): self
    {
        return new self(array_map($callback, $this->elements));
    }

    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->elements);
    }

    public function count(): int
    {
        return count($this->elements);
    }

    public function offsetExists($offset): bool
    {
        return isset($this->elements[$offset]);
    }

    public function offsetGet($offset): mixed
    {
        if (isset($this->elements[$offset])) {
            return $this->elements[$offset];
        }

        return false;
    }

    public function offsetSet($offset, $value): void
    {
        if (is_null($offset)) {
            $this->elements[] = $value;
        } else {
            $this->elements[$offset] = $value;
        }
    }

    public function offsetUnset($offset): void
    {
        unset($this->elements[$offset]);
    }

    public function pluck(string $property): array
    {
        return array_map(
            fn($element) => $element->$property,
            $this->all()
        );
    }
}
