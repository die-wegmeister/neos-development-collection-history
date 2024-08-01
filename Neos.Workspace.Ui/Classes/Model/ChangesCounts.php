<?php

/*
 * This file is part of the Neos.Workspace.Ui package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

declare(strict_types=1);

namespace Neos\Workspace\Ui\Model;

/**
 * Changes counts for a workspace
 */
final readonly class ChangesCounts
{
    public function __construct(
        public int $new,
        public int $changed,
        public int $removed,
        public int $total,
    ) {
    }

    public static function empty(): ChangesCounts
    {
        return new ChangesCounts(0, 0, 0, 0);
    }
}
