<?php
namespace TYPO3\CMS\Fluid\ViewHelpers\Uri;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Fluid\Core\Rendering\RenderingContextInterface;

/**
 * A view helper for creating URIs to resources.
 *
 * = Examples =
 *
 * <code title="Defaults">
 * <link href="{f:uri.resource(path:'css/stylesheet.css')}" rel="stylesheet" />
 * </code>
 * <output>
 * <link href="Resources/Packages/MyPackage/stylesheet.css" rel="stylesheet" />
 * (depending on current package)
 * </output>
 */
class ResourceViewHelper extends \TYPO3\CMS\Fluid\Core\ViewHelper\AbstractViewHelper
{
    /**
     * Render the URI to the resource. The filename is used from child content.
     *
     * @param string $path The path and filename of the resource (relative to Public resource directory of the extension).
     * @param string $extensionName Target extension name. If not set, the current extension name will be used
     * @param bool $absolute If set, an absolute URI is rendered
     * @return string The URI to the resource
     * @api
     */
    public function render($path, $extensionName = null, $absolute = false)
    {
        return static::renderStatic(
            array(
                'path' => $path,
                'extensionName' => $extensionName,
                'absolute' => $absolute
            ),
            $this->buildRenderChildrenClosure(),
            $this->renderingContext
        );
    }

    /**
     * @param array $arguments
     * @param callable $renderChildrenClosure
     * @param RenderingContextInterface $renderingContext
     * @return string
     */
    public static function renderStatic(array $arguments, \Closure $renderChildrenClosure, RenderingContextInterface $renderingContext)
    {
        $path = $arguments['path'];
        $extensionName = $arguments['extensionName'];
        $absolute = $arguments['absolute'];

        if ($extensionName === null) {
            $extensionName = $renderingContext->getControllerContext()->getRequest()->getControllerExtensionName();
        }
        $uri = 'EXT:' . GeneralUtility::camelCaseToLowerCaseUnderscored($extensionName) . '/Resources/Public/' . $path;
        $uri = GeneralUtility::getFileAbsFileName($uri);
        $uri = \TYPO3\CMS\Core\Utility\PathUtility::stripPathSitePrefix($uri);
        if (TYPO3_MODE === 'BE' && $absolute === false && $uri !== false) {
            $uri = '../' . $uri;
        }
        if ($absolute === true) {
            $uri = $renderingContext->getControllerContext()->getRequest()->getBaseUri() . $uri;
        }
        return $uri;
    }
}
