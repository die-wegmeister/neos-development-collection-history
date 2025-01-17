<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\EventStore;

/**
 * A set of Content Repository "domain events"
 *
 * @implements \IteratorAggregate<EventInterface|DecoratedEvent>
 * @internal only used during event publishing (from within command handlers) - and their implementation is not API
 */
final class Events implements \IteratorAggregate, \Countable
{
    /**
     * @var array<EventInterface|DecoratedEvent>
     */
    private readonly array $events;

    private function __construct(EventInterface|DecoratedEvent ...$events)
    {
        $this->events = $events;
    }

    public static function with(EventInterface|DecoratedEvent $event): self
    {
        return new self($event);
    }

    public function withAppendedEvents(Events $events): self
    {
        return new self(...$this->events, ...$events->events);
    }

    /**
     * @param array<EventInterface|DecoratedEvent> $events
     * @return static
     */
    public static function fromArray(array $events): self
    {
        return new self(...$events);
    }

    public function getIterator(): \Traversable
    {
        yield from $this->events;
    }

    /**
     * @template T
     * @param \Closure(EventInterface|DecoratedEvent $event): T $callback
     * @return list<T>
     */
    public function map(\Closure $callback): array
    {
        return array_map($callback, $this->events);
    }

    public function isEmpty(): bool
    {
        return empty($this->events);
    }

    public function count(): int
    {
        return count($this->events);
    }
}
