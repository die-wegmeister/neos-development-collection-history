<?php
namespace PackageFactory\AtomicFusion\AFX\Dsl;

use Neos\Flow\Annotations as Flow;
use Neos\Fusion;
use Neos\Fusion\Core\DslInterface;
use PackageFactory\AtomicFusion\AFX\Service\AfxService;
use PackageFactory\AtomicFusion\AFX\Exception\AfxException;

/**
 * Class Fusion AFX Dsl
 *
 * @Flow\Scope("singleton")
 */
class AfxDslImplementation implements DslInterface
{

    /**
     * Transpile the given dsl-code to fusion-code
     *
     * @param string $code
     * @return string
     * @throws Fusion\Exception
     */
    public function transpile($code)
    {
        try {
            return AfxService::convertAfxToFusion($code);
        } catch (AfxException $afxException) {
            throw new FusionException(sprintf('Error during AFX-parsing: %s', $afxException->getMessage()));
        }
    }
}
