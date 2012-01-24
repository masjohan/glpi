<?php
/*
 * @version $Id$
 -------------------------------------------------------------------------
 GLPI - Gestionnaire Libre de Parc Informatique
 Copyright (C) 2003-2011 by the INDEPNET Development Team.

 http://indepnet.net/   http://glpi-project.org
 -------------------------------------------------------------------------

 LICENSE

 This file is part of GLPI.

 GLPI is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License as published by
 the Free Software Foundation; either version 2 of the License, or
 (at your option) any later version.

 GLPI is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License
 along with GLPI. If not, see <http://www.gnu.org/licenses/>.
 --------------------------------------------------------------------------
 */

// Based on cacti plugin system
// ----------------------------------------------------------------------
// Original Author of file:
// Purpose of file:
// ----------------------------------------------------------------------

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

class Plugin extends CommonDBTM {

   // Class constant : Plugin state
   const ANEW           = 0;
   const ACTIVATED      = 1;
   const NOTINSTALLED   = 2;
   const TOBECONFIGURED = 3;
   const NOTACTIVATED   = 4;
   const TOBECLEANED    = 5;
   const NOTUPDATED     = 6;


   /**
    * Retrieve an item from the database using its directory
    *
    *@param $dir directory of the plugin
    *@return true if succeed else false
    *
   **/
   function getFromDBbyDir($dir) {
      global $DB;

      $query = "SELECT *
                FROM `".$this->getTable()."`
                WHERE (`directory` = '" . $dir . "')";

      if ($result = $DB->query($query)) {
         if ($DB->numrows($result) != 1) {
            return false;
         }
         $this->fields = $DB->fetch_assoc($result);
         if (is_array($this->fields) && count($this->fields)) {
            return true;
         }
      }
      return false;
   }


   /**
   * Init plugins list reading plugins directory
   * @return nothing
   */
   function init() {

      $this->checkStates();
      $plugins=$this->find('state='.self::ACTIVATED);

      $_SESSION["glpi_plugins"] = array();

      if (count($plugins)) {
         foreach ($plugins as $ID => $plug) {
            $_SESSION["glpi_plugins"][$ID] = $plug['directory'];
         }
      }
   }


   /**
   * Init a plugin including setup.php file
   * launching plugin_init_NAME function  after checking compatibility
   *
   * @param $name Name of hook to use
   * @param $withhook boolean to load hook functions
   *
   * @return nothing
   */
   static function load($name, $withhook=false) {
      global $LOADED_PLUGINS;

      if (file_exists(GLPI_ROOT . "/plugins/$name/setup.php")) {
         include_once(GLPI_ROOT . "/plugins/$name/setup.php");
         if (!isset($LOADED_PLUGINS[$name])) {
            self::loadLang($name);
            $function = "plugin_init_$name";
            if (function_exists($function)) {
               $function();
               $LOADED_PLUGINS[$name] = $name;
            }
         }
      }
      if ($withhook && file_exists(GLPI_ROOT . "/plugins/$name/hook.php")) {
         include_once(GLPI_ROOT . "/plugins/$name/hook.php");
      }
   }


   /**
   * Load lang file for a plugin
   *
   * @param $name Name of hook to use
   * @param $forcelang force a specific lang
   * @param $coretrytoload lang trying to be load from core
   *
   * @return nothing
   */
   static function loadLang($name, $forcelang='', $coretrytoload = '') {
      // $LANG needed : used when include lang file
      global $CFG_GLPI,$LANG,$TRANSLATE;

      // For compatibility for plugins using $LANG
      $LANG = array();

      $trytoload = 'en_GB';
      if (isset($_SESSION['glpilanguage'])) {
         $trytoload = $_SESSION["glpilanguage"];
      }
      // Force to load a specific lang
      if (!empty($forcelang)) {
         $trytoload = $forcelang;
      }

      // If not set try default lang file
      if (empty($trytoload)) {
         $trytoload = $CFG_GLPI["language"];
      }

      if (empty($coretrytoload)) {
            $coretrytoload = $trytoload;
      }

      $dir = GLPI_ROOT . "/plugins/$name/locales/";

      if (file_exists($dir.$CFG_GLPI["languages"][$trytoload][1])) {
         include ($dir.$CFG_GLPI["languages"][$trytoload][1]);
      } else if (file_exists($dir.$CFG_GLPI["languages"][$CFG_GLPI["language"]][1])) {
         include ($dir.$CFG_GLPI["languages"][$CFG_GLPI["language"]][1]);
      } else if (file_exists($dir . "en_GB.php")) {
         include ($dir . "en_GB.php");
      } else if (file_exists($dir . "fr_FR.php")) {
         include ($dir . "fr_FR.php");
      }

      // New localisation system
      if (file_exists($dir.$trytoload.".mo")) {
         $TRANSLATE->addTranslation(
            array(
               'content' => $dir.$trytoload.".mo",
               'locale'  => $coretrytoload
            )
         );
      } else if (file_exists($dir.$CFG_GLPI["language"].".mo")) {
         $TRANSLATE->addTranslation(
            array(
               'content' => $dir.$CFG_GLPI["language"].".mo",
               'locale'  => $coretrytoload
            )
         );
      } else if (file_exists($dir."en_GB.mo")) {
         $TRANSLATE->addTranslation(
            array(
               'content' => $dir."en_GB.mo",
               'locale'  => $coretrytoload
            )
         );
      } else if (file_exists($dir."fr_FR.mo")) {
         $TRANSLATE->addTranslation(
            array(
               'content' => $dir."fr_FR.mo",
               'locale'  => $coretrytoload
            )
         );
      }
   }


   /**
    * Check plugins states and detect new plugins
    *
   **/
   function checkStates() {

      //// Get all plugins
      // Get all from DBs
      $pluglist   = $this->find("","name, directory");
      $db_plugins = array();
      if (count($pluglist)) {
         foreach ($pluglist as $plug) {
            $db_plugins[$plug['directory']] = $plug['id'];
         }
      }
      // Parse plugin dir
      $file_plugins  = array();
      $error_plugins = array();
      $dirplug       = GLPI_ROOT."/plugins";
      $dh            = opendir($dirplug);

      while (false !== ($filename = readdir($dh))) {
         if ($filename!=".svn"
             && $filename!="."
             && $filename!=".."
             && is_dir($dirplug."/".$filename)) {

            // Find version
            if (file_exists($dirplug."/".$filename."/setup.php")) {
               self::loadLang($filename);
               include_once($dirplug."/".$filename."/setup.php");
               $function = "plugin_version_$filename";
               if (function_exists($function)) {
                  $file_plugins[$filename] = $function();
                  $file_plugins[$filename] = Toolbox::addslashes_deep($file_plugins[$filename]);
               }
            }
         }
      }

      // check plugin state
      foreach ($db_plugins as $plug => $ID) {
         $install_ok = true;
         // Check file
         if (!isset($file_plugins[$plug])) {
            $this->update(array('id'    => $ID,
                                'state' => self::TOBECLEANED));
            $install_ok = false;
         } else {
            // Check version
            if ($file_plugins[$plug]['version']!=$pluglist[$ID]['version']) {
               $input = $file_plugins[$plug];
               $input['id'] = $ID;
               if ($pluglist[$ID]['version']) {
                  $input['state'] = self::NOTUPDATED;
               }
               $this->update($input);
               $install_ok = false;
            }
         }
         // Check install is ok for activated plugins
         if ($install_ok && ($pluglist[$ID]['state'] == self::ACTIVATED)) {
            $usage_ok = true;
            $function = "plugin_".$plug."_check_prerequisites";
            if (function_exists($function)) {
               if (!$function()) {
                  $usage_ok = false;
               }
            }
            $function = "plugin_".$plug."_check_config";
            if (function_exists($function)) {
               if (!$function()) {
                  $usage_ok = false;
               }
            } else {
               $usage_ok = false;
            }
            if (!$usage_ok) {
               $input = $file_plugins[$plug];
               $this->unactivate($ID);
            }
         }
         // Delete plugin for file list
         if (isset($file_plugins[$plug])) {
            unset($file_plugins[$plug]);
         }
      }

      if (count($file_plugins)) {
         foreach ($file_plugins as $plug => $data) {
            if (isset($data['oldname'])) {
               $checking = $pluglist;
               foreach ($checking as $check) {
                  if (isset($check['directory']) && $check['directory'] == $data['oldname']) {
                     $data['state'] = self::NOTUPDATED;
                     $this->delete(array('id' => $check['id']));
                  }
               }
            } else {
               $data['state'] = self::NOTINSTALLED;
            }
            $data['directory']=$plug;
            $this->add($data);
         }
      }
   }


   /**
    * List availabled plugins
    *
   **/
   function listPlugins() {
      global $CFG_GLPI, $PLUGIN_HOOKS;

      $this->checkStates();
      echo "<div class='center'><table class='tab_cadrehov'>";

      $pluglist = $this->find("", "name, directory");
      $i = 0;
      $PLUGIN_HOOKS_SAVE = $PLUGIN_HOOKS;
      echo "<tr><th colspan='8'>".__('Plugins list')."</th></tr>\n";

      if (!empty($pluglist)) {
         echo "<tr><th>".__('Name')."</th><th>"._n('Version', 'Versions',1)."</th>";
         echo "<th>".__('License')."</th>";
         echo "<th>".__('Status')."</th><th>".__('Authors')."</th>";
         echo "<th>".__('Website')."</th><th colspan='2'>&nbsp;</th></tr>\n";

         foreach ($pluglist as $ID => $plug) {
            if (function_exists("plugin_".$plug['directory']."_check_config")) {
               // init must not be called for incompatible plugins
               self::load($plug['directory'], true);
            }
            $i++;
            $class = 'tab_bg_1';
            if ($i%2==0) {
               $class = 'tab_bg_2';
            }
            echo "<tr class='$class'>";
            echo "<td>";
            $name = trim($plug['name']);
            if (empty($name)) {
               $plug['name'] = $plug['directory'];
            }

            // Only config for install plugins
            if (in_array($plug['state'], array(self::ACTIVATED,
                                               self::TOBECONFIGURED,
                                               self::NOTACTIVATED))
                && isset($PLUGIN_HOOKS['config_page'][$plug['directory']])) {

               echo "<a href='".$CFG_GLPI["root_doc"]."/plugins/".$plug['directory']."/".
                      $PLUGIN_HOOKS['config_page'][$plug['directory']]."'>
                      <span class='b'>".$plug['name']."</span></a>";
            } else {
               echo $plug['name'];
            }
            echo "</td>";
            echo "<td>".$plug['version']."</td><td>";
            if ($plug['license']) {
               $link = '';
               if (file_exists(GLPI_ROOT.'/plugins/'.$plug['directory'].'/LICENSE')) {
                  $link = $CFG_GLPI['root_doc'].'/plugins/'.$plug['directory'].'/LICENSE';
               } else if (file_exists(GLPI_ROOT.'/plugins/'.$plug['directory'].'/COPYING.txt')) {
                  $link = $CFG_GLPI['root_doc'].'/plugins/'.$plug['directory'].'/COPYING.txt';
               }
               if ($link) {
                  echo "<a href='$link'>".$plug['license']."</a>";
               } else {
                  echo $plug['license'];
               }
            } else {
               echo "&nbsp;";
            }
            echo "</td><td>";
            switch ($plug['state']) {
               case self::ANEW :
                  echo _x('plugin', 'New');
                  break;

               case self::ACTIVATED :
                  _e('Enabled');
                  break;

               case self::NOTINSTALLED :
                  _e('Not installed');
                  break;

               case self::NOTUPDATED :
                  _e('To update');
                  break;

               case self::TOBECONFIGURED :
                  _e('Installed / not configured');
                  break;

               case self::NOTACTIVATED :
                  _e('Installed / not activated');
                  break;

               case self::TOBECLEANED :
               default:
                  _e('Error / to clean');
                  break;
            }
            echo "</td>";
            echo "<td>".$plug['author']."</td>";
            $weblink = trim($plug['homepage']);
            echo "<td>";
            if (!empty($weblink)) {
               echo "<a href='".formatOutputWebLink($weblink)."' target='_blank'>";
               echo "<img src='".$CFG_GLPI["root_doc"]."/pics/web.png' class='middle' alt=\"".
                      __s('Web')."\" title=\"".__s('Web')."\" ></a>";
            } else {
               echo "&nbsp;";
            }
            echo "</td>";

            switch ($plug['state']) {
               case self::ACTIVATED :
                  echo "<td><a class='vsubmit' href='".$this->getSearchURL()."?id=$ID&amp;action=unactivate'>".
                             __('Disable')."</a></td>";
                  echo "<td>";
                  if (function_exists("plugin_".$plug['directory']."_uninstall")) {
                     echo "<a class='vsubmit' href='".$this->getSearchURL()."?id=$ID&amp;action=uninstall'>".
                            __('Uninstall')."</a>";
                  } else {
                     //TRANS: %s is the list of missing functions
                     echo sprintf(__('Non-existent functions: %s'), "plugin_".$plug['directory']."_uninstall");
                  }
                  echo "</td>";
                  break;

               case self::ANEW :
               case self::NOTINSTALLED :
               case self::NOTUPDATED :
                  echo "<td>";
                  if (function_exists("plugin_".$plug['directory']."_install")
                      && function_exists("plugin_".$plug['directory']."_check_config")) {

                     $function = 'plugin_' . $plug['directory'] . '_check_prerequisites';
                     $do_install = true;
                     if (function_exists($function)) {
                        $do_install = $function();
                     }
                     if ($plug['state']==self::NOTUPDATED) {
                        //TRANS: verb, for button
                        $msg = _x('button', 'Upgrade');
                     } else {
                        $msg = __('Install');
                     }
                     if ($do_install) {
                        echo "<a class='vsubmit' href='".$this->getSearchURL()."?id=$ID&amp;action=install'>".$msg.
                             "</a>";
                     }
                  } else {

                     $missing = '';
                     if (!function_exists("plugin_".$plug['directory']."_install")) {
                        $missing .= "plugin_".$plug['directory']."_install";
                     }
                     if (!function_exists("plugin_".$plug['directory']."_check_config")) {
                        $missing .= " plugin_".$plug['directory']."_check_config";
                     }
                     //TRANS: %s is the list of missing functions
                     echo sprintf(__('Non-existent functions: %s'), $missing);
                  }
                  echo "</td><td>";
                  if (function_exists("plugin_".$plug['directory']."_uninstall")) {
                     if (function_exists("plugin_".$plug['directory']."_check_config")) {
                        echo "<a href='".$this->getSearchURL()."?id=$ID&amp;action=uninstall'>".
                               __('Uninstall')."</a>";
                     } else {
                        // This is an incompatible plugin (0.71), uninstall fonction could crash
                        echo "&nbsp;";
                     }
                  } else {
                     //TRANS: %s is the list of missing functions
                     echo sprintf(__('Non-existent functions: %s'), "plugin_".$plug['directory']."_uninstall");
                  }
                  echo "</td>";
                  break;

               case self::TOBECONFIGURED :
                  echo "<td>";
                  $function = 'plugin_' . $plug['directory'] . '_check_config';
                  if (function_exists($function)) {
                     if ($function(true)) {
                        $this->update(array('id'    => $ID,
                                            'state' => self::NOTACTIVATED));
                        Html::redirect($this->getSearchURL());
                     }
                  } else {
                     //TRANS: %s is the list of missing functions
                     echo sprintf(__('Non-existent functions: %s'), "plugin_".$plug['directory']."_check_config");
                  }
                  echo "</td><td>";
                  if (function_exists("plugin_".$plug['directory']."_uninstall")) {
                     echo "<a class='vsubmit' href='".$this->getSearchURL()."?id=$ID&amp;action=uninstall'>".
                            __('Uninstall')."</a>";
                  } else {
                     //TRANS: %s is the list of missing functions
                     echo sprintf(__('Non-existent functions: %s'), "plugin_".$plug['directory']."_uninstall");
                  }
                  echo "</td>";
                  break;

               case self::NOTACTIVATED :
                  echo "<td>";
                  $function = 'plugin_' . $plug['directory'] . '_check_prerequisites';
                  if (function_exists($function) && $function()) {
                     echo "<a class='vsubmit' href='".$this->getSearchURL()."?id=$ID&amp;action=activate'>".
                                __('Enable')."</a>";
                  }
                  // Else : reason displayed by the plugin
                  echo "</td><td>";
                  if (function_exists("plugin_".$plug['directory']."_uninstall")) {
                     echo "<a class='vsubmit' href='".$this->getSearchURL()."?id=$ID&amp;action=uninstall'>".
                            __('Uninstall')."</a>";
                  } else {
                     //TRANS: %s is the list of missing functions
                     echo sprintf(__('Non-existent functions: %s'), "plugin_".$plug['directory']."_uninstall");
                  }
                  echo "</td>";
                  break;

               case self::TOBECLEANED :
               default :
                  echo "<td colspan='2'>";
                  echo "<a class='vsubmit' href='".$this->getSearchURL()."?id=$ID&amp;action=clean'>".
                         __('Clean')."</a>";
                  echo "</td>";
                  break;
            }
            echo "</tr>\n";
         }
      }
      else {
         echo "<tr class='tab_bg_1'><td class='center' colspan='7'>".__('No plugin installed')."</td></tr>";
      }
      echo "</table></div>";
      echo "<br>";
      echo "<div class='center'><p>";
      echo "<a href='http://plugins.glpi-project.org'  class='vsubmit' target='_blank'>".
            __('See the catalog of plugins')."</a></p>";
      echo "</div>";

      $PLUGIN_HOOKS = $PLUGIN_HOOKS_SAVE;
   }


   /**
    * uninstall a plugin
    *
    *@param $ID ID of the plugin
   **/
   function uninstall($ID) {

      if ($this->getFromDB($ID)) {
         CronTask::Unregister($this->fields['directory']);
         self::load($this->fields['directory'],true);
         FieldUnicity::deleteForItemtype($this->fields['directory']);

         // Run the Plugin's Uninstall Function first
         $function = 'plugin_' . $this->fields['directory'] . '_uninstall';
         if (function_exists($function)) {
            $function();
         }

         $this->update(array('id'      => $ID,
                             'state'   => self::NOTINSTALLED,
                             'version' => ''));
         $this->removeFromSession($this->fields['directory']);
      }
   }


   /**
    * install a plugin
    *
    *@param $ID ID of the plugin
   **/
   function install($ID) {

      if ($this->getFromDB($ID)) {
         self::load($this->fields['directory'],true);
         $function = 'plugin_' . $this->fields['directory'] . '_install';
         $install_ok = false;
         if (function_exists($function)) {
            if ($function()) {
               $function = 'plugin_' . $this->fields['directory'] . '_check_config';
               if (function_exists($function)) {
                  if ($function()) {
                     $this->update(array('id'    => $ID,
                                         'state' => self::NOTACTIVATED));
                  } else {
                     $this->update(array('id'    => $ID,
                                         'state' => self::TOBECONFIGURED));
                  }
               }
            }
         }
      }
   }


   /**
    * activate a plugin
    *
    *@param $ID ID of the plugin
   **/
   function activate($ID) {
      global $PLUGIN_HOOKS;

      if ($this->getFromDB($ID)) {
         self::load($this->fields['directory'],true);
         $function = 'plugin_' . $this->fields['directory'] . '_check_prerequisites';
         if (function_exists($function)) {
            if (!$function()) {
               return false;
            }
         }
         $function = 'plugin_' . $this->fields['directory'] . '_check_config';
         if (function_exists($function)) {
            if ($function()) {
               $this->update(array('id'    => $ID,
                                   'state' => self::ACTIVATED));
               $_SESSION['glpi_plugins'][$ID] = $this->fields['directory'];

               // Initialize session for the plugin
               if (isset($PLUGIN_HOOKS['init_session'][$this->fields['directory']])
                   && is_callable($PLUGIN_HOOKS['init_session'][$this->fields['directory']])) {

                  call_user_func($PLUGIN_HOOKS['init_session'][$this->fields['directory']]);
               }

               // Initialize profile for the plugin
               if (isset($PLUGIN_HOOKS['change_profile'][$this->fields['directory']])
                   && is_callable($PLUGIN_HOOKS['change_profile'][$this->fields['directory']])) {

                  call_user_func($PLUGIN_HOOKS['change_profile'][$this->fields['directory']]);
               }
            }
         }  // exists _check_config
      } // getFromDB
   }


   /**
    * unactivate a plugin
    *
    *@param $ID ID of the plugin
   **/
   function unactivate($ID) {

      if ($this->getFromDB($ID)) {
         $this->update(array('id'    => $ID,
                             'state' => self::NOTACTIVATED));
         $this->removeFromSession($this->fields['directory']);
      }
   }


   /**
    * unactivate all activated plugins for update process
    *
   **/
   function unactivateAll() {
      global $DB;

      $query = "UPDATE `".$this->getTable()."`
                SET `state` = ".self::NOTACTIVATED."
                WHERE `state` = ".self::ACTIVATED;
      $DB->query($query);
      $_SESSION['glpi_plugins'] = array();
   }


   /**
    * clean a plugin
    *
    *@param $ID ID of the plugin
   **/
   function clean($ID) {

      if ($this->getFromDB($ID)) {
         // Clean crontask after "hard" remove
         CronTask::Unregister($this->fields['directory']);

         $this->delete(array('id' => $ID));
         $this->removeFromSession($this->fields['directory']);
      }
   }


   /**
    * is a plugin activated
    *
    *@param $plugin plugin directory
   **/
   function isActivated($plugin) {

      if ($this->getFromDBbyDir($plugin)) {
         return ($this->fields['state'] == self::ACTIVATED);
      }
   }


   /**
    * is a plugin installed
    *
    *@param $plugin plugin directory
   **/
   function isInstalled($plugin) {

      if ($this->getFromDBbyDir($plugin)) {
         return ($this->fields['state']    == self::ACTIVATED
                 || $this->fields['state'] == self::TOBECONFIGURED
                 || $this->fields['state'] == self::NOTACTIVATED);
      }
   }


   /**
    * remove plugin from session variable
    *
    *@param $plugin plugin directory
   **/
   function removeFromSession($plugin) {

      $key = array_search($plugin,$_SESSION['glpi_plugins']);
      if ($key!==false) {
         unset($_SESSION['glpi_plugins'][$key]);
      }
   }


   /**
    * Migrate itemtype from interger (0.72) to string (0.80)
    *
    * @param $types array of (num=>name) of type manage by the plugin
    * @param $glpitables array of GLPI table name used by the plugin
    * @param $plugtables array of Plugin table name which have an itemtype
    *
    * @return nothing
   **/
   static function migrateItemType ($types=array(), $glpitables=array(), $plugtables=array()) {
      global $DB;

      $typetoname = array(0  => "",// For tickets
                          1  => "Computer",
                          2  => "NetworkEquipment",
                          3  => "Printer",
                          4  => "Monitor",
                          5  => "Peripheral",
                          6  => "Software",
                          7  => "Contact",
                          8  => "Supplier",
                          9  => "Infocom",
                          10 => "Contract",
                          11 => "CartridgeItem",
                          12 => "DocumentType",
                          13 => "Document",
                          14 => "KnowbaseItem",
                          15 => "User",
                          16 => "Ticket",
                          17 => "ConsumableItem",
                          18 => "Consumable",
                          19 => "Cartridge",
                          20 => "SoftwareLicense",
                          21 => "Link",
                          22 => "State",
                          23 => "Phone",
                          24 => "Device",
                          25 => "Reminder",
                          26 => "Stat",
                          27 => "Group",
                          28 => "Entity",
                          29 => "ReservationItem",
                          30 => "AuthMail",
                          31 => "AuthLDAP",
                          32 => "OcsServer",
                          33 => "RegistryKey",
                          34 => "Profile",
                          35 => "MailCollector",
                          36 => "Rule",
                          37 => "Transfer",
                          38 => "Bookmark",
                          39 => "SoftwareVersion",
                          40 => "Plugin",
                          41 => "ComputerDisk",
                          42 => "NetworkPort",
                          43 => "TicketFollowup",
                          44 => "Budget");

      //Add plugins types
      $typetoname = self::doHookFunction("migratetypes",$typetoname);

      foreach ($types as $num => $name) {
         $typetoname[$num] = $name;
         foreach ($glpitables as $table) {
            $query = "UPDATE `$table`
                      SET `itemtype` = '$name'
                      WHERE `itemtype` = '$num'";
            $DB->queryOrDie($query, "update itemtype of table $table for $name");
         }
      }

      if (in_array('glpi_infocoms', $glpitables)) {
         $entities = getAllDatasFromTable('glpi_entities');
         $entities[0]="Root";

         foreach ($types as $num => $name) {
            $itemtable = getTableForItemType($name);
            if (!TableExists($itemtable)) {
               // Just for security, shouldn't append
               continue;
            }
            $do_recursive = false;
            if (FieldExists($itemtable,'is_recursive')) {
               $do_recursive = true;
            }
            foreach ($entities as $entID => $val) {
               if ($do_recursive) {
                  // Non recursive ones
                  $query3 = "UPDATE `glpi_infocoms`
                             SET `entities_id` = '$entID',
                                 `is_recursive` = '0'
                             WHERE `itemtype` = '$name'
                                   AND `items_id` IN (SELECT `id`
                                                      FROM `$itemtable`
                                                      WHERE `entities_id` = '$entID'
                                                            AND `is_recursive` = '0')";
                  $DB->queryOrDie($query3, "0.80 update entities_id and is_recursive=0
                                 in glpi_infocoms for $name");

                  // Recursive ones
                  $query3 = "UPDATE `glpi_infocoms`
                             SET `entities_id` = '$entID',
                                 `is_recursive` = '1'
                             WHERE `itemtype` = '$name'
                                   AND `items_id` IN (SELECT `id`
                                                      FROM `$itemtable`
                                                      WHERE `entities_id` = '$entID'
                                                            AND `is_recursive` = '1')";
                  $DB->queryOrDie($query3, "0.80 update entities_id and is_recursive=1
                                 in glpi_infocoms for $name");
               } else {
                  $query3 = "UPDATE `glpi_infocoms`
                             SET `entities_id` = '$entID'
                             WHERE `itemtype` = '$name'
                                   AND `items_id` IN (SELECT `id`
                                                      FROM `$itemtable`
                                                      WHERE `entities_id` = '$entID')";
                  $DB->queryOrDie($query3, "0.80 update entities_id in glpi_infocoms
                        for $name");
               }
            } // each entity
         } // each plugin type
      }

      foreach ($typetoname as $num => $name) {
         foreach ($plugtables as $table) {
            $query = "UPDATE `$table`
                      SET `itemtype` = '$name'
                      WHERE `itemtype` = '$num'";
            $DB->queryOrDie($query, "update itemtype of table $table for $name");
         }
      }
   }


   function showSystemInformations($width) {

      // No need to translate, this part always display in english (for copy/paste to forum)

      echo "\n</pre></td></tr><tr class='tab_bg_2'><th>Plugins list</th></tr>";
      echo "<tr class='tab_bg_1'><td><pre>\n&nbsp;\n";

      $plug = new Plugin();
      $pluglist = $plug->find("","name, directory");
      foreach ($pluglist as $plugin) {
         $msg  = substr(str_pad($plugin['directory'],30),0,20).
                 " Name: ".Toolbox::substr(str_pad($plugin['name'],40),0,30).
                 " Version: ".str_pad($plugin['version'],10).
                 " State: ";

         switch ($plugin['state']) {
            case self::ANEW :
               $msg .=  'New';
               break;

            case self::ACTIVATED :
               $msg .=  'Enabled';
               break;

            case self::NOTINSTALLED :
               $msg .=  'Not installed';
               break;

            case self::TOBECONFIGURED :
               $msg .=  'To be configured';
               break;

            case self::NOTACTIVATED :
               $msg .=  'Not activated';
               break;

            case self::TOBECLEANED :
            default :
               $msg .=  'To be cleaned';
               break;
         }
         echo wordwrap("\t".$msg."\n", $width, "\n\t\t");
      }
      echo "\n</pre></td></tr>";
   }


   /**
    * Define a new class managed by a plugin
    *
    * @param $itemtype class name
    * @param $attrib Array of attributes, a hashtable with index in
    *    (classname, typename, reservation_types)
    *
    * @return bool
    */
   static function registerClass($itemtype, $attrib=array()) {
      global $CFG_GLPI;

      $plug = isPluginItemType($itemtype);
      if (!$plug) {
         return false;
      }
      $plugin = strtolower($plug['plugin']);

      if (isset($attrib['doc_types'])) {
         $attrib['document_types'] = $attrib['doc_types'];
         unset($attrib['doc_types']);
      }
      if (isset($attrib['helpdesk_types'])) {
         $attrib['ticket_types'] = $attrib['helpdesk_types'];
         unset($attrib['helpdesk_types']);
      }
      if (isset($attrib['netport_types'])) {
         $attrib['networkport_types'] = $attrib['netport_types'];
         unset($attrib['netport_types']);
      }

      foreach (array('contract_types', 'document_types', 'helpdesk_visible_types', 'infocom_types',
                     'linkgroup_tech_types', 'linkgroup_types', 'linkuser_tech_types',
                     'linkuser_types', 'massiveaction_nodelete_types','massiveaction_noupdate_types',
                     'networkport_types', 'notificationtemplates_types', 'planning_types',
                     'reservation_types', 'rulecollections_types', 'systeminformations_types',
                     'ticket_types', 'unicity_types') as $att) {
         if (isset($attrib[$att]) && $attrib[$att]) {
            array_push($CFG_GLPI[$att], $itemtype);
            unset($attrib[$att]);
         }
      }

      if (isset($attrib['addtabon'])) {
         if (!is_array($attrib['addtabon'])) {
            $attrib['addtabon'] = array($attrib['addtabon']);
         }
         foreach ($attrib['addtabon'] as $form) {
            CommonGLPI::registerStandardTab($form, $itemtype);
         }
      }

      //Manage entity forward from a source itemtype to this itemtype
      if (isset($attrib['forwardentityfrom'])) {
         CommonDBTM::addForwardEntity($attrib['forwardentityfrom'], $itemtype);
      }

      // Use it for plugin debug
//       if (count($attrib)) {
//          foreach ($attrib as $key => $val) {
//             Toolbox::logInFile('debug',"Attribut $key used by $itemtype no more used for plugins\n");
//          }
      //}
      return true;
   }


   /**
    * This function executes a hook.
    * @param $name Name of hook to fire
    * @param $param Parameters if needed : if object limit to the itemtype
    * @return mixed $data
    */
   static function doHook ($name,$param=NULL) {
      global $PLUGIN_HOOKS;

      if ($param==NULL) {
         $data = func_get_args();
      } else {
         $data = $param;
      }

      // Apply hook only for the item
      if ($param != NULL && is_object($param)) {
         $itemtype = get_class($param);
         if (isset($PLUGIN_HOOKS[$name]) && is_array($PLUGIN_HOOKS[$name])) {
            foreach ($PLUGIN_HOOKS[$name] as $plug => $tab) {
               if (isset($tab[$itemtype])) {
                  if (file_exists(GLPI_ROOT . "/plugins/$plug/hook.php")) {
                     include_once(GLPI_ROOT . "/plugins/$plug/hook.php");
                  }
                  if (is_callable($tab[$itemtype])) {
                     call_user_func($tab[$itemtype],$data);
                  }
               }
            }
         }
      } else { // Standard hook call
         if (isset($PLUGIN_HOOKS[$name]) && is_array($PLUGIN_HOOKS[$name])) {
            foreach ($PLUGIN_HOOKS[$name] as $plug => $function) {
               if (file_exists(GLPI_ROOT . "/plugins/$plug/hook.php")) {
                  include_once(GLPI_ROOT . "/plugins/$plug/hook.php");
               }
               if (is_callable($function)) {
                  call_user_func($function,$data);
               }
            }
         }
      }
      /* Variable-length argument lists have a slight problem when */
      /* passing values by reference. Pity. This is a workaround.  */
      return $data;
   }


   /**
    * This function executes a hook.
    * @param $name Name of hook to fire
    * @param $parm Parameters
    * @return mixed $data
    */
   static function doHookFunction($name,$parm=NULL) {
      global $PLUGIN_HOOKS;

      $ret = $parm;
      if (isset($PLUGIN_HOOKS[$name]) && is_array($PLUGIN_HOOKS[$name])) {
         foreach ($PLUGIN_HOOKS[$name] as $plug => $function) {
            if (file_exists(GLPI_ROOT . "/plugins/$plug/hook.php")) {
               include_once(GLPI_ROOT . "/plugins/$plug/hook.php");
            }
            if (is_callable($function)) {
               $ret = call_user_func($function, $ret);
            }
         }
      }
      /* Variable-length argument lists have a slight problem when */
      /* passing values by reference. Pity. This is a workaround.  */
      return $ret;
   }


   /**
    * This function executes a hook for 1 plugin.
    * @param $plugname Name of the plugin
    * @param $hook function to be called (may be an array for call a class method)
    * @param $options params passed to the function
    *
    * @return mixed $data
    */
   static function doOneHook($plugname,$hook,$options=array()) {

      $plugname=strtolower($plugname);
      if (!is_array($hook)) {
         $hook = "plugin_" . $plugname . "_" . $hook;
         if (file_exists(GLPI_ROOT . "/plugins/$plugname/hook.php")) {
            include_once(GLPI_ROOT . "/plugins/$plugname/hook.php");
         }
      }
      if (is_callable($hook)) {
         return call_user_func($hook, $options);
      }
   }


   /**
    * Get dropdowns for plugins
    *
    * @return Array containing plugin dropdowns
    */
   static function getDropdowns() {

      $dps = array();
      if (isset($_SESSION["glpi_plugins"]) && is_array($_SESSION["glpi_plugins"])) {
         foreach ($_SESSION["glpi_plugins"] as  $plug) {
            $tab = self::doOneHook($plug,'getDropdown');
            if (is_array($tab)) {
               $function = "plugin_version_$plug";
               $name     = $function();
               $dps      = array_merge($dps, array($name['name'] => $tab));
            }
         }
      }
      return $dps;
   }


   /**
    * Get database relations for plugins
    *
    * @return Array containing plugin database relations
    */
   static function getDatabaseRelations() {

      $dps = array();
      if (isset($_SESSION["glpi_plugins"]) && is_array($_SESSION["glpi_plugins"])) {
         foreach ($_SESSION["glpi_plugins"] as $plug) {
            if (file_exists(GLPI_ROOT . "/plugins/$plug/hook.php")) {
               include_once(GLPI_ROOT . "/plugins/$plug/hook.php");
            }
            $function2 = "plugin_".$plug."_getDatabaseRelations";
            if (function_exists($function2)) {
               $dps = array_merge_recursive($dps,$function2());
            }
         }
      }
      return $dps;
   }


   /**
    * Get additional search options managed by plugins
    *
    * @param $itemtype
    *
    * @return Array containing plugin search options for given type
    */
   static function getAddSearchOptions($itemtype) {
      global $PLUGIN_HOOKS;

      $sopt = array();
      if (isset($_SESSION['glpi_plugins']) && count($_SESSION['glpi_plugins'])) {
         foreach ($_SESSION['glpi_plugins'] as $plug) {
            if (file_exists(GLPI_ROOT . "/plugins/$plug/hook.php")) {
               include_once(GLPI_ROOT . "/plugins/$plug/hook.php");
            }
            $function = "plugin_".$plug."_getAddSearchOptions";
            if (function_exists($function)) {
               $tmp = $function($itemtype);
               if (count($tmp)) {
                  $sopt += $tmp;
               }
            }
         }
      }
      return $sopt;
   }

}
?>
