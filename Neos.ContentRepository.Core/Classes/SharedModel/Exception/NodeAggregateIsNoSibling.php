<?php

/*
 * This file is part of the Neos.ContentRepository package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

declare(strict_types=1);

namespace Neos\ContentRepository\Core\SharedModel\Exception;

use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;

/**
 * The exception to be thrown if a node aggregate is not a sibling of a reference node aggregate
 * but was expected to be
 *
 * @api because exception is thrown during invariant checks on command execution
 */
final class NodeAggregateIsNoSibling extends \DomainException
{
    public static function butWasExpectedToBeInDimensionSpacePoint(NodeAggregateId $nodeAggregateId, NodeAggregateId $referenceNodeAggregateId, DimensionSpacePoint $dimensionSpacePoint): self
    {
        return new self(
            'Node aggregate "' . $nodeAggregateId->value
                . '" is no sibling of node aggregate "' . $referenceNodeAggregateId->value
                . '" but was expected to be in dimension space point '
                . json_encode($dimensionSpacePoint, JSON_THROW_ON_ERROR),
            1713081020
        );
    }
}
