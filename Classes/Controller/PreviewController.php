<?php
declare(strict_types = 1);

namespace F7\Preview\Controller;

/*
 * This file is part of TYPO3 CMS extension preview by F7.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use F7\Preview\Preview\PreviewUriBuilder;
use F7\Preview\Utility\PreviewUtility;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Fluid\View\StandaloneView;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Class PreviewController
 */
class PreviewController
{
    /**
     * @var ModuleTemplate
     */
    protected $moduleTemplate;

    /**
     * @var StandaloneView
     */
    protected $view;

    /**
     * @var ServerRequestInterface
     */
    protected $request;

    /**
     * @var IconFactory
     */
    protected $iconFactory;

    /**
     * @var SiteFinder
     */
    protected $siteFinder;

    /**
     * @var ExtensionConfiguration
     */
    protected $extensionConfiguration;

    public function __construct(?ModuleTemplate $moduleTemplate = null, ?IconFactory $iconFactory = null, ?SiteFinder $siteFinder = null, ?ExtensionConfiguration $extensionConfiguration = null)
    {
       // $this->moduleTemplate = $moduleTemplate ?? GeneralUtility::makeInstance(ModuleTemplate::class);
        $this->iconFactory = $iconFactory ?? GeneralUtility::makeInstance(IconFactory::class);
        $this->siteFinder = $siteFinder ?? GeneralUtility::makeInstance(SiteFinder::class);
        $this->extensionConfiguration = $extensionConfiguration ?? GeneralUtility::makeInstance(ExtensionConfiguration::class);

        $this->initializeView('index');
    }

    protected function initializeView(string $templateName): void
    {
        $this->view = GeneralUtility::makeInstance(StandaloneView::class);
        $this->view->setTemplate($templateName);
        $this->view->setTemplateRootPaths(['EXT:preview/Resources/Private/Templates/Preview']);
    }

    public function addLinkAction(): ResponseInterface
    {
        $pageId = (int)$_REQUEST['addLink']['page'];
        $languageId = (int)$_REQUEST['addLink']['language'];
        // check if link already exist
        $linkInformation = PreviewUtility::getPreviewLink($pageId, $languageId);

        if ($linkInformation === []) {
            $configuration = $this->extensionConfiguration->get('preview');
            $lifetime = (int)$configuration['lifetime'];
            debug($lifetime);
            $previewUriBuilder = new PreviewUriBuilder();
            $previewUriBuilder->generatePreviewUrl($pageId, $languageId, $lifetime);
        }

        $this->redirectToPage($pageId);

        return new HtmlResponse('');
    }

    public function removeLinkAction(): ResponseInterface
    {
        $pageId = (int)$_REQUEST['removeLink']['page'];
        $languageId = (int)$_REQUEST['removeLink']['language'];

        PreviewUtility::removeLink($pageId, $languageId);

        $this->redirectToPage($pageId);

        return new HtmlResponse('');
    }

    private function redirectToPage(int $pageId): void
    {
        // redirect to page again
        $backendUriBuilder = GeneralUtility::makeInstance(\TYPO3\CMS\Backend\Routing\UriBuilder::class);
        $uri = $backendUriBuilder->buildUriFromRoute('web_layout', ['id' => $pageId]);
        header('Location: ' . $uri);
    }
}
