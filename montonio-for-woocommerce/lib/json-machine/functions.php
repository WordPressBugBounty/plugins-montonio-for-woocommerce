<?php

declare(strict_types=1);

function montonio_json_machine_to_iterator(Traversable $traversable): Iterator
{
    if ($traversable instanceof IteratorAggregate) {
        return montonio_json_machine_to_iterator($traversable->getIterator());
    }

    if ($traversable instanceof Iterator) {
        return $traversable;
    }

    throw new \LogicException('Cannot turn Traversable into Iterator');
}
