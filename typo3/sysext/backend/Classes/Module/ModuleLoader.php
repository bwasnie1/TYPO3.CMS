<?php
namespace TYPO3\CMS\Backend\Module;

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

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Lang\LanguageService;

/**
 * This document provides a class that loads the modules for the TYPO3 interface.
 *
 * Load Backend Interface modules
 *
 * Typically instantiated like this:
 * $this->loadModules = GeneralUtility::makeInstance(\TYPO3\CMS\Backend\Module\ModuleLoader::class);
 * $this->loadModules->load($TBE_MODULES);
 * @internal
 */
class ModuleLoader
{
    /**
     * After the init() function this array will contain the structure of available modules for the backend user.
     *
     * @var array
     */
    public $modules = array();

    /**
     * This array will hold the elements that should go into the select-list of modules for groups...
     *
     * @var array
     */
    public $modListGroup = array();

    /**
     * This array will hold the elements that should go into the select-list of modules for users...
     *
     * @var array
     */
    public $modListUser = array();

    /**
     * The backend user for use internally
     *
     * @var \TYPO3\CMS\Core\Authentication\BackendUserAuthentication
     */
    public $BE_USER;

    /**
     * If set TRUE, workspace "permissions" will be observed so non-allowed modules will not be included in the array of modules.
     *
     * @var bool
     */
    public $observeWorkspaces = false;

    /**
     * Contains the registered navigation components
     *
     * @var array
     */
    protected $navigationComponents = array();

    /**
     * Init.
     * The outcome of the load() function will be a $this->modules array populated with the backend module structure available to the BE_USER
     * Further the global var $LANG will have labels and images for the modules loaded in an internal array.
     *
     * @param array $modulesArray Should be the global var $TBE_MODULES, $BE_USER can optionally be set to an alternative Backend user object than the global var $BE_USER (which is the currently logged in user)
     * @param BackendUserAuthentication $beUser Optional backend user object to use. If not set, the global BE_USER object is used.
     * @return void
     */
    public function load($modulesArray, BackendUserAuthentication $beUser = null)
    {
        // Setting the backend user for use internally
        $this->BE_USER = $beUser ?: $GLOBALS['BE_USER'];

        // Unset the array for calling backend modules based on external backend module dispatchers in typo3/index.php
        unset($modulesArray['_configuration']);
        $this->navigationComponents = $modulesArray['_navigationComponents'];
        unset($modulesArray['_navigationComponents']);
        $mainModules = $this->parseModulesArray($modulesArray);

        // Traverses the module setup and creates the internal array $this->modules
        foreach ($mainModules as $mainModuleName => $subModules) {
            $mainModuleConfiguration = $this->checkMod($mainModuleName);
            // If $mainModuleConfiguration is not set (FALSE) there is no access to the module !(?)
            if (is_array($mainModuleConfiguration)) {
                $this->modules[$mainModuleName] = $mainModuleConfiguration;
                // Load the submodules
                if (is_array($subModules)) {
                    foreach ($subModules as $subModuleName) {
                        $subModuleConfiguration = $this->checkMod($mainModuleName . '_' . $subModuleName);
                        if (is_array($subModuleConfiguration)) {
                            $this->modules[$mainModuleName]['sub'][$subModuleName] = $subModuleConfiguration;
                        }
                    }
                }
            } elseif ($mainModuleConfiguration !== false) {
                // Although the configuration was not found, still check if there are submodules
                // This must be done in order to fill out the select-lists for modules correctly!!
                if (is_array($subModules)) {
                    foreach ($subModules as $subModuleName) {
                        $this->checkMod($mainModuleName . '_' . $subModuleName);
                    }
                }
            }
        }
    }

    /**
     * Here we check for the module.
     *
     * Return values:
     * 'notFound':	If the module was not found in the path (no "conf.php" file)
     * FALSE:		If no access to the module (access check failed)
     * array():	    Configuration array, in case a valid module where access IS granted exists.
     *
     * @param string $name Module name
     * @return string|bool|array See description of function
     */
    public function checkMod($name)
    {
        if ($name === 'user_ws' && !ExtensionManagementUtility::isLoaded('version')) {
            return false;
        }
        // Check for own way of configuring module
        if (is_array($GLOBALS['TBE_MODULES']['_configuration'][$name]['configureModuleFunction'])) {
            $obj = $GLOBALS['TBE_MODULES']['_configuration'][$name]['configureModuleFunction'];
            if (is_callable($obj)) {
                $MCONF = call_user_func($obj, $name);
                if ($this->checkModAccess($name, $MCONF) !== true) {
                    return false;
                }
                return $MCONF;
            }
        }

        // merge configuration and labels into one array
        $setupInformation = $this->getModuleSetupInformation($name);

        // clean up the configuration part
        if (empty($setupInformation['configuration'])) {
            return 'notFound';
        }
        if (
            $setupInformation['configuration']['shy']
            || !$this->checkModAccess($name, $setupInformation['configuration'])
            || !$this->checkModWorkspace($name, $setupInformation['configuration'])
        ) {
            return false;
        }
        $finalModuleConfiguration = $setupInformation['configuration'];
        $finalModuleConfiguration['name'] = $name;
        // Language processing. This will add module labels and image reference to the internal ->moduleLabels array of the LANG object.
        $lang = $this->getLanguageService();
        if (is_object($lang)) {
            $defaultLabels = $setupInformation['labels']['default'];

            // If LOCAL_LANG references are used for labels of the module:
            if ($defaultLabels['ll_ref']) {
                // Now the 'default' key is loaded with the CURRENT language - not the english translation...
                $defaultLabels['labels']['tablabel'] = $lang->sL($defaultLabels['ll_ref'] . ':mlang_labels_tablabel');
                $defaultLabels['labels']['tabdescr'] = $lang->sL($defaultLabels['ll_ref'] . ':mlang_labels_tabdescr');
                $defaultLabels['tabs']['tab'] = $lang->sL($defaultLabels['ll_ref'] . ':mlang_tabs_tab');
                $lang->addModuleLabels($defaultLabels, $name . '_');
            } else {
                // ... otherwise use the old way:
                $lang->addModuleLabels($defaultLabels, $name . '_');
                $lang->addModuleLabels($setupInformation['labels'][$lang->lang], $name . '_');
            }
        }

        // Default script setup
        if ($setupInformation['configuration']['script'] === '_DISPATCH' || isset($setupInformation['configuration']['routeTarget'])) {
            if ($setupInformation['configuration']['extbase']) {
                $finalModuleConfiguration['script'] = BackendUtility::getModuleUrl('Tx_' . $name);
            } else {
                // just go through BackendModuleRequestHandler where the routeTarget is resolved
                $finalModuleConfiguration['script'] = BackendUtility::getModuleUrl($name);
            }
        } else {
            $finalModuleConfiguration['script'] = BackendUtility::getModuleUrl('dummy');
        }

        if (!empty($setupInformation['configuration']['navigationFrameModule'])) {
            $finalModuleConfiguration['navFrameScript'] = BackendUtility::getModuleUrl(
                $setupInformation['configuration']['navigationFrameModule'],
                !empty($setupInformation['configuration']['navigationFrameModuleParameters'])
                    ? $setupInformation['configuration']['navigationFrameModuleParameters']
                    : array()
            );
        }

        // Check if this is a submodule
        $mainModule = '';
        if (strpos($name, '_') !== false) {
            list($mainModule, ) = explode('_', $name, 2);
        }

        // check if there is a navigation component (like the pagetree)
        if (is_array($this->navigationComponents[$name])) {
            $finalModuleConfiguration['navigationComponentId'] = $this->navigationComponents[$name]['componentId'];
        // navigation component can be overriden by the main module component
        } elseif ($mainModule && is_array($this->navigationComponents[$mainModule]) && $setupInformation['configuration']['inheritNavigationComponentFromMainModule'] !== false) {
            $finalModuleConfiguration['navigationComponentId'] = $this->navigationComponents[$mainModule]['componentId'];
        }
        return $finalModuleConfiguration;
    }

    /**
     * fetches the conf.php file of a certain module, and also merges that with
     * some additional configuration
     *
     * @param \string $moduleName the combined name of the module, can be "web", "web_info", or "tools_log"
     * @return array an array with subarrays, named "configuration" (aka $MCONF), "labels" (previously known as $MLANG) and the stripped path
     */
    protected function getModuleSetupInformation($moduleName)
    {
        $moduleSetupInformation = array(
            'configuration' => array(),
            'labels' => array()
        );

        $moduleConfiguration = !empty($GLOBALS['TBE_MODULES']['_configuration'][$moduleName])
            ? $GLOBALS['TBE_MODULES']['_configuration'][$moduleName]
            : null;
        if ($moduleConfiguration !== null) {
            // Overlay setup with additional labels
            if (!empty($moduleConfiguration['labels']) && is_array($moduleConfiguration['labels'])) {
                if (empty($moduleSetupInformation['labels']['default']) || !is_array($moduleSetupInformation['labels']['default'])) {
                    $moduleSetupInformation['labels']['default'] = $moduleConfiguration['labels'];
                } else {
                    $moduleSetupInformation['labels']['default'] = array_replace_recursive($moduleSetupInformation['labels']['default'], $moduleConfiguration['labels']);
                }
                unset($moduleConfiguration['labels']);
            }
            // Overlay setup with additional configuration
            if (is_array($moduleConfiguration)) {
                $moduleSetupInformation['configuration'] = array_replace_recursive($moduleSetupInformation['configuration'], $moduleConfiguration);
            }
        }

        // Add some default configuration
        if (!isset($moduleSetupInformation['configuration']['inheritNavigationComponentFromMainModule'])) {
            $moduleSetupInformation['configuration']['inheritNavigationComponentFromMainModule'] = true;
        }

        return $moduleSetupInformation;
    }

    /**
     * Returns TRUE if the internal BE_USER has access to the module $name with $MCONF (based on security level set for that module)
     *
     * @param string $name Module name
     * @param array $MCONF MCONF array (module configuration array) from the modules conf.php file (contains settings about what access level the module has)
     * @return bool TRUE if access is granted for $this->BE_USER
     */
    public function checkModAccess($name, $MCONF)
    {
        if (empty($MCONF['access'])) {
            return true;
        }
        $access = strtolower($MCONF['access']);
        // Checking if admin-access is required
        // If admin-permissions is required then return TRUE if user is admin
        if (strpos($access, 'admin') !== false && $this->BE_USER->isAdmin()) {
            return true;
        }
        // This will add modules to the select-lists of user and groups
        if (strpos($access, 'user') !== false) {
            $this->modListUser[] = $name;
        }
        if (strpos($access, 'group') !== false) {
            $this->modListGroup[] = $name;
        }
        // This checks if a user is permitted to access the module
        if ($this->BE_USER->isAdmin() || $this->BE_USER->check('modules', $name)) {
            return true;
        }
        return false;
    }

    /**
     * Check if a module is allowed inside the current workspace for be user
     * Processing happens only if $this->observeWorkspaces is TRUE
     *
     * @param string $name Module name (unused)
     * @param array $MCONF MCONF array (module configuration array) from the modules conf.php file (contains settings about workspace restrictions)
     * @return bool TRUE if access is granted for $this->BE_USER
     */
    public function checkModWorkspace($name, $MCONF)
    {
        if (!$this->observeWorkspaces) {
            return true;
        }
        $status = true;
        if (!empty($MCONF['workspaces'])) {
            $status = $this->BE_USER->workspace === 0 && GeneralUtility::inList($MCONF['workspaces'], 'online')
                || $this->BE_USER->workspace === -1 && GeneralUtility::inList($MCONF['workspaces'], 'offline')
                || $this->BE_USER->workspace > 0 && GeneralUtility::inList($MCONF['workspaces'], 'custom');
        } elseif ($this->BE_USER->workspace === -99) {
            $status = false;
        }
        return $status;
    }

    /**
     * Parses the moduleArray ($TBE_MODULES) into an internally useful structure.
     * Returns an array where the keys are names of the module and the values may be TRUE (only module) or an array (of submodules)
     *
     * @param array $arr ModuleArray ($TBE_MODULES)
     * @return array Output structure with available modules
     */
    public function parseModulesArray($arr)
    {
        $theMods = array();
        if (is_array($arr)) {
            foreach ($arr as $mod => $subs) {
                // Clean module name to alphanum
                $mod = $this->cleanName($mod);
                if ($mod) {
                    if ($subs) {
                        $subsArr = GeneralUtility::trimExplode(',', $subs);
                        foreach ($subsArr as $subMod) {
                            $subMod = $this->cleanName($subMod);
                            if ($subMod) {
                                $theMods[$mod][] = $subMod;
                            }
                        }
                    } else {
                        $theMods[$mod] = 1;
                    }
                }
            }
        }
        return $theMods;
    }

    /**
     * The $str is cleaned so that it contains alphanumerical characters only.
     * Module names must only consist of these characters
     *
     * @param string $str String to clean up
     * @return string
     */
    public function cleanName($str)
    {
        return preg_replace('/[^a-z0-9]/i', '', $str);
    }

    /**
     * Get relative path for $destDir compared to $baseDir
     *
     * @param string $baseDir Base directory
     * @param string $destDir Destination directory
     * @return string The relative path of destination compared to base.
     */
    public function getRelativePath($baseDir, $destDir)
    {
        // A special case, the dirs are equal
        if ($baseDir === $destDir) {
            return './';
        }
        // Remove beginning
        $baseDir = ltrim($baseDir, '/');
        $destDir = ltrim($destDir, '/');
        $found = true;
        do {
            $slash_pos = strpos($destDir, '/');
            if ($slash_pos !== false && substr($destDir, 0, $slash_pos) == substr($baseDir, 0, $slash_pos)) {
                $baseDir = substr($baseDir, $slash_pos + 1);
                $destDir = substr($destDir, $slash_pos + 1);
            } else {
                $found = false;
            }
        } while ($found);
        $slashes = strlen($baseDir) - strlen(str_replace('/', '', $baseDir));
        for ($i = 0; $i < $slashes; $i++) {
            $destDir = '../' . $destDir;
        }
        return GeneralUtility::resolveBackPath($destDir);
    }

    /**
     * @return LanguageService
     */
    protected function getLanguageService()
    {
        return $GLOBALS['LANG'];
    }
}
