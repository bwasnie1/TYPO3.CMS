<?php
defined('TYPO3_MODE') or die();

if (TYPO3_MODE === 'BE') {
    \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addModule(
        'web',
        'layout',
        'top',
        '',
        array(
            'routeTarget' => \TYPO3\CMS\Backend\Controller\PageLayoutController::class . '::mainAction',
            'access' => 'user,group',
            'name' => 'web_layout',
            'icon' => 'EXT:backend/Resources/Public/Icons/module-page.svg',
            'labels' => array(
                'll_ref' => 'LLL:EXT:backend/Resources/Private/Language/locallang_mod.xlf',
            ),
        )
    );

    // Register BackendLayoutDataProvider for PageTs
    $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['BackendLayoutDataProvider']['pagets'] = \TYPO3\CMS\Backend\Provider\PageTsBackendLayoutDataProvider::class;
}
