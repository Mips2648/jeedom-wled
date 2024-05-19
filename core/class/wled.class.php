<?php

/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

/* * ***************************Includes********************************* */
require_once __DIR__  . '/../../../../core/php/core.inc.php';

class wled extends eqLogic {
    /*     * *************************Attributs****************************** */

    /*
   * Permet de définir les possibilités de personnalisation du widget (en cas d'utilisation de la fonction 'toHtml' par exemple)
   * Tableau multidimensionnel - exemple: array('custom' => true, 'custom::layout' => false)
    public static $_widgetPossibility = array();
   */

    /*     * ***********************Methode static*************************** */
    public static function request($_ip, $_endpoint = '', $_payload = null, $_method = 'GET') {
        $url = 'http://' . $_ip . $_endpoint;
        log::add('wled', 'debug', 'Request method : ' . $_method);
        if ($_method == 'GET') {
            if (is_array($_payload) && count($_payload) > 0) {
                log::add('wled', 'debug', 'GET Request payload : ' . print_r($_payload, true));
                $url .= '&';
                foreach ($_payload as $key => $value) {
                    $url .= $key . '=' . urlencode($value) . '&';
                }
                $url = trim($url, '&');
            }
            log::add('wled', 'debug', 'GET request with url : ' . $url);
            $request_http = new com_http($url);
        } else {
            log::add('wled', 'debug', 'non GET request with url : ' . $url);
            $request_http = new com_http($url);
            $request_http->setHeader(array(
                'Content-Type: application/json', 'Content-Length: ' . strlen($_payload)
            ));
            log::add('wled', 'debug', 'Non GET request payload : ' . $_payload);
            if ($_payload !== "") {
                if ($_method == 'POST') {
                    $request_http->setPost($_payload);
                } elseif ($_method == 'PUT') {
                    $request_http->setPut($_payload);
                }
            }
        }
        $result = $request_http->exec(60, 1);
        return $result;
    }

    public static function discoverDevices() {
        log::add('wled', 'debug', 'Function discoverDevices');
        if (!class_exists('mDNS')) {
            require_once dirname(__FILE__) . '/../../3rdparty/mdns.php';
        }
        $mdns = new mDNS();
        // Search for wled devices
        $mdns->query("_http._tcp.local", 1, 12, "");
        $cc = 15;
        $wleds = array();
        while ($cc > 0) {
            $inpacket = $mdns->readIncoming();
            if ($inpacket->packetheader != NULL) {
                $ans = $inpacket->packetheader->getAnswerRRs();
                if ($ans > 0) {
                    $name = $inpacket->answerrrs[1]->name;
                    $pos = strpos($name, 'wled');
                    if ($pos !== false) {
                        $localname = explode('.', $inpacket->answerrrs[1]->name);
                        $ip = gethostbyname($localname[0] . '.local');
                        log::add('wled', 'debug', 'Discovered ' . $inpacket->answerrrs[1]->name . ' at ' . $ip);
                        // Friendly name.
                        $infos = wled::request($ip, '/json/infos', null, 'GET');
                        log::add('wled', 'debug', 'request infos result ' . $infos);
                        $infos = is_json($infos, $infos);
                        if (isset($infos['name'])) {
                            $friendlyName = $infos['name'];
                        } else {
                            $friendlyName = $localname[0] . '.local';
                        }
                        log::add('wled', 'debug', 'friendlyName : ' . $friendlyName);
                        $state = self::request($ip, '/json/state', null, 'GET');
                        log::add('wled', 'debug', 'state : ' . $state);
                        $state = is_json($state, $state);
                        $mainSegment = $state['mainseg'];
                        log::add('wled', 'debug', 'Segment principal ' . $mainSegment);
                        $segments = $state['seg'];
                        foreach ($segments as $segment) {
                            log::add('wled', 'debug', 'Segment détecté ' . $segment['id']);
                            $numseg = $segment['id'];
                            $eqLogics = self::byLogicalId($ip . '_seg' . $numseg, 'wled');
                            if (empty($eqLogics)) {
                                log::add('wled', 'debug', 'Nouvel équipement ' . $ip . '_seg' . $numseg);
                                event::add('jeedom::alert', array(
                                    'level' => 'warning',
                                    'page' => 'wled',
                                    'message' => __('Nouvel équipement detecté', __FILE__),
                                ));
                                $eqLogic = new wled();
                                $eqLogic->setEqType_name('wled');
                                $eqLogic->setLogicalId($ip . '_seg' . $numseg);
                                $eqLogic->setIsEnable(1);
                                if ($numseg == 0) {
                                    $eqLogic->setName($friendlyName);
                                } else {
                                    $eqLogic->setName($friendlyName . ' segment ' . $numseg);
                                }
                                log::add('wled', 'debug', 'Nom équipement ' . $eqLogic->getName());
                                $eqLogic->setIsVisible(1);
                                $eqLogic->setConfiguration('ip_address', $ip);
                                $eqLogic->setConfiguration('segment', $numseg);
                                $eqLogic->setConfiguration('autorefresh', '* * * * *');
                                $eqLogic->updateInfos($infos);
                                $eqLogic->setConfiguration('segledscount', $segment['stop'] - $segment['start']);
                                $eqLogic->save();
                            } else {
                                log::add('wled', 'debug', 'Déjà existant ' . $ip . '_seg' . $numseg);
                            }
                        }
                        $cc = 15;
                    }
                    $cc--;
                }
            }
        }
        log::add('wled', 'debug', 'End function discoverDevices');
    }

    /*
     * Fonction exécutée automatiquement toutes les minutes par Jeedom
     */
    public static function cron() {
        foreach (self::byType('wled') as $eqLogic) {
            $autorefresh = $eqLogic->getConfiguration('autorefresh', '');
            $ipAddress = $eqLogic->getConfiguration('ip_address');
            if ($eqLogic->getIsEnable() == 1 && $ipAddress != '' && $autorefresh != '') {
                try {
                    $c = new Cron\CronExpression($autorefresh, new Cron\FieldFactory);
                    if ($c->isDue()) {
                        try {
                            $eqLogic->getWledStatus();
                            $eqLogic->getWledInfos();
                            $eqLogic->refreshWidget();
                        } catch (Exception $exc) {
                            log::add('wled', 'error', __('Error in ', __FILE__) . $eqLogic->getHumanName() . ' : ' . $exc->getMessage());
                        }
                    }
                } catch (Exception $exc) {
                    log::add('wled', 'error', __('Expression cron non valide pour ', __FILE__) . $eqLogic->getHumanName() . ' : ' . $autorefresh);
                }
            }
        }
    }

    public static function remove_emoji($string) {
        // Match Enclosed Alphanumeric Supplement
        $regex_alphanumeric = '/[\x{1F100}-\x{1F1FF}]/u';
        $clear_string = preg_replace($regex_alphanumeric, '', $string);

        // Match Miscellaneous Symbols and Pictographs
        $regex_symbols = '/[\x{1F300}-\x{1F5FF}]/u';
        $clear_string = preg_replace($regex_symbols, '', $clear_string);

        // Match Emoticons
        $regex_emoticons = '/[\x{1F600}-\x{1F64F}]/u';
        $clear_string = preg_replace($regex_emoticons, '', $clear_string);

        // Match Transport And Map Symbols
        $regex_transport = '/[\x{1F680}-\x{1F6FF}]/u';
        $clear_string = preg_replace($regex_transport, '', $clear_string);

        // Match Supplemental Symbols and Pictographs
        $regex_supplemental = '/[\x{1F900}-\x{1F9FF}]/u';
        $clear_string = preg_replace($regex_supplemental, '', $clear_string);

        // Match Miscellaneous Symbols
        $regex_misc = '/[\x{2600}-\x{26FF}]/u';
        $clear_string = preg_replace($regex_misc, '', $clear_string);

        // Match Dingbats
        $regex_dingbats = '/[\x{2700}-\x{27BF}]/u';
        $clear_string = preg_replace($regex_dingbats, '', $clear_string);

        return $clear_string;
    }
    /*
     * Fonction exécutée automatiquement toutes les 5 minutes par Jeedom
      public static function cron5() {
      }
     */

    /*
     * Fonction exécutée automatiquement toutes les 10 minutes par Jeedom
      public static function cron10() {
      }
     */

    /*
     * Fonction exécutée automatiquement toutes les 15 minutes par Jeedom
      public static function cron15() {
      }
     */

    /*
     * Fonction exécutée automatiquement toutes les 30 minutes par Jeedom
      public static function cron30() {
      }
     */

    /*
     * Fonction exécutée automatiquement toutes les heures par Jeedom
      public static function cronHourly() {
      }
     */

    /*
     * Fonction exécutée automatiquement tous les jours par Jeedom
      public static function cronDaily() {
      }
     */



    /*     * *********************Méthodes d'instance************************* */

    // Fonction exécutée automatiquement avant la création de l'équipement
    public function preInsert() {
        $this->setCategory('light', 1);
    }

    // Fonction exécutée automatiquement après la création de l'équipement
    public function postInsert() {
    }

    // Fonction exécutée automatiquement avant la mise à jour de l'équipement
    public function preUpdate() {
        if ($this->getConfiguration('ip_address') == '') {
            throw new Exception(__('L\'adresse IP du WLED ne peut être vide', __FILE__));
        }
    }

    // Fonction exécutée automatiquement après la mise à jour de l'équipement
    public function postUpdate() {
    }

    // Fonction exécutée automatiquement avant la sauvegarde (création ou mise à jour) de l'équipement
    public function preSave() {
    }

    // Fonction exécutée automatiquement après la sauvegarde (création ou mise à jour) de l'équipement
    public function postSave() {
        // Création des commandes

        $onCmd = $this->getCmd(null, "on");
        if (!is_object($onCmd)) {
            $onCmd = new wledCmd();
            $onCmd->setName('On');
            $onCmd->setEqLogic_id($this->getId());
            $onCmd->setLogicalId('on');
            $onCmd->setType('action');
            $onCmd->setSubType('other');
            $onCmd->setGeneric_type('LIGHT_ON');
            $onCmd->setIsVisible(1);
            $onCmd->setValue('on');
            $onCmd->setDisplay('icon', '<i class="icon jeedom-lumiere-on"></i>');
            $onCmd->setTemplate('dashboard', 'light');
            $onCmd->setTemplate('mobile', 'light');
            $onCmd->setOrder(0);
            $onCmd->save();
        }

        $offCmd = $this->getCmd(null, "off");
        if (!is_object($offCmd)) {
            $offCmd = new wledCmd();
            $offCmd->setName('Off');
            $offCmd->setEqLogic_id($this->getId());
            $offCmd->setLogicalId('off');
            $offCmd->setType('action');
            $offCmd->setSubType('other');
            $offCmd->setGeneric_type('LIGHT_OFF');
            $offCmd->setIsVisible(1);
            $offCmd->setValue('off');
            $offCmd->setDisplay('icon', '<i class="icon jeedom-lumiere-off"></i>');
            $offCmd->setTemplate('dashboard', 'light');
            $offCmd->setTemplate('mobile', 'light');
            $offCmd->setOrder(1);
            $offCmd->save();
        }
        $stateCmd = $this->getCmd(null, "state");
        if (!is_object($stateCmd)) {
            $stateCmd = new wledCmd();
            $stateCmd->setName(__('Etat', __FILE__));
            $stateCmd->setEqLogic_id($this->getId());
            $stateCmd->setLogicalId('state');
            $stateCmd->setType('info');
            $stateCmd->setSubType('binary');
            $stateCmd->setGeneric_type('LIGHT_STATE');
            $stateCmd->setIsVisible(0);
            $stateCmd->setOrder(2);
            $stateCmd->save();
        }
        $brightnessCmd = $this->getCmd(null, "brightness");
        if (!is_object($brightnessCmd)) {
            $brightnessCmd = new wledCmd();
            $brightnessCmd->setName(__('Luminosité', __FILE__));
            $brightnessCmd->setEqLogic_id($this->getId());
            $brightnessCmd->setLogicalId('brightness');
            $brightnessCmd->setType('action');
            $brightnessCmd->setSubType('slider');
            $brightnessCmd->setGeneric_type('LIGHT_SLIDER');
            $brightnessCmd->setConfiguration('minValue', '0');
            $brightnessCmd->setConfiguration('maxValue', '255');
            $brightnessCmd->setIsVisible(1);
            $brightnessCmd->setOrder(3);
            $brightnessCmd->save();
        }

        $brightnessStateCmd = $this->getCmd(null, "brightness_state");
        if (!is_object($brightnessStateCmd)) {
            $brightnessStateCmd = new wledCmd();
            $brightnessStateCmd->setName(__('Etat Luminosité', __FILE__));
            $brightnessStateCmd->setEqLogic_id($this->getId());
            $brightnessStateCmd->setLogicalId('brightness_state');
            $brightnessStateCmd->setType('info');
            $brightnessStateCmd->setSubType('numeric');
            $brightnessStateCmd->setGeneric_type('LIGHT_BRIGHTNESS');
            $brightnessStateCmd->setIsVisible(0);
            $brightnessStateCmd->setOrder(4);
            $brightnessStateCmd->save();
        }
        $colorCmd = $this->getCmd(null, "color");
        if (!is_object($colorCmd)) {
            $colorCmd = new wledCmd();
            $colorCmd->setName(__('Couleur', __FILE__));
            $colorCmd->setEqLogic_id($this->getId());
            $colorCmd->setLogicalId('color');
            $colorCmd->setType('action');
            $colorCmd->setSubType('color');
            $colorCmd->setGeneric_type('LIGHT_SET_COLOR');
            $colorCmd->setIsVisible(1);
            $colorCmd->setOrder(5);
            $colorCmd->save();
        }
        $colorStateCmd = $this->getCmd(null, "color_state");
        if (!is_object($colorStateCmd)) {
            $colorStateCmd = new wledCmd();
            $colorStateCmd->setName(__('Etat Couleur', __FILE__));
            $colorStateCmd->setEqLogic_id($this->getId());
            $colorStateCmd->setLogicalId('color_state');
            $colorStateCmd->setType('info');
            $colorStateCmd->setSubType('string');
            $colorStateCmd->setGeneric_type('LIGHT_COLOR');
            $colorStateCmd->setIsVisible(0);
            $colorStateCmd->setOrder(6);
            $colorStateCmd->save();
        }
        $colorBgCmd = $this->getCmd(null, "color_bg");
        if (!is_object($colorBgCmd)) {
            $colorBgCmd = new wledCmd();
            $colorBgCmd->setName(__('Couleur Bg', __FILE__));
            $colorBgCmd->setEqLogic_id($this->getId());
            $colorBgCmd->setLogicalId('color_bg');
            $colorBgCmd->setType('action');
            $colorBgCmd->setSubType('color');
            $colorBgCmd->setGeneric_type('LIGHT_SET_COLOR');
            $colorBgCmd->setIsVisible(1);
            $colorBgCmd->setOrder(17);
            $colorBgCmd->save();
        }
        $colorStateBgCmd = $this->getCmd(null, "color_state_bg");
        if (!is_object($colorStateBgCmd)) {
            $colorStateBgCmd = new wledCmd();
            $colorStateBgCmd->setName(__('Etat Couleur Bg', __FILE__));
            $colorStateBgCmd->setEqLogic_id($this->getId());
            $colorStateBgCmd->setLogicalId('color_state_bg');
            $colorStateBgCmd->setType('info');
            $colorStateBgCmd->setSubType('string');
            $colorStateBgCmd->setGeneric_type('LIGHT_COLOR');
            $colorStateBgCmd->setIsVisible(0);
            $colorStateBgCmd->setOrder(18);
            $colorStateBgCmd->save();
        }
        $colorThirdCmd = $this->getCmd(null, "color_third");
        if (!is_object($colorThirdCmd)) {
            $colorThirdCmd = new wledCmd();
            $colorThirdCmd->setName(__('Couleur Third', __FILE__));
            $colorThirdCmd->setEqLogic_id($this->getId());
            $colorThirdCmd->setLogicalId('color_third');
            $colorThirdCmd->setType('action');
            $colorThirdCmd->setSubType('color');
            $colorThirdCmd->setGeneric_type('LIGHT_SET_COLOR');
            $colorThirdCmd->setIsVisible(1);
            $colorThirdCmd->setOrder(17);
            $colorThirdCmd->save();
        }
        $colorStateThirdCmd = $this->getCmd(null, "color_state_third");
        if (!is_object($colorStateThirdCmd)) {
            $colorStateThirdCmd = new wledCmd();
            $colorStateThirdCmd->setName(__('Etat Couleur Third', __FILE__));
            $colorStateThirdCmd->setEqLogic_id($this->getId());
            $colorStateThirdCmd->setLogicalId('color_state_third');
            $colorStateThirdCmd->setType('info');
            $colorStateThirdCmd->setSubType('string');
            $colorStateThirdCmd->setGeneric_type('LIGHT_COLOR');
            $colorStateThirdCmd->setIsVisible(0);
            $colorStateThirdCmd->setOrder(18);
            $colorStateThirdCmd->save();
        }
        $effectCmd = $this->getCmd(null, "effect");
        if (!is_object($effectCmd)) {
            $effectCmd = new wledCmd();
            $effectCmd->setName(__('Effet', __FILE__));
            $effectCmd->setEqLogic_id($this->getId());
            $effectCmd->setLogicalId('effect');
            $effectCmd->setType('action');
            $effectCmd->setSubType('select');
            // The listValue will be updated later.
            $effectCmd->setGeneric_type('LIGHT_MODE');
            $effectCmd->setIsVisible(1);
            $effectCmd->setOrder(7);
            $effectCmd->save();
        }
        $effectStateCmd = $this->getCmd(null, "effect_state");
        if (!is_object($effectStateCmd)) {
            $effectStateCmd = new wledCmd();
            $effectStateCmd->setName(__('Etat effet', __FILE__));
            $effectStateCmd->setEqLogic_id($this->getId());
            $effectStateCmd->setLogicalId('effect_state');
            $effectStateCmd->setType('info');
            $effectStateCmd->setSubType('numeric');
            $effectStateCmd->setIsVisible(0);
            $effectStateCmd->setOrder(8);
            $effectStateCmd->save();
        }
        $effectNameCmd = $this->getCmd(null, "effect_name");
        if (!is_object($effectNameCmd)) {
            $effectNameCmd = new wledCmd();
            $effectNameCmd->setName(__('Nom effet', __FILE__));
            $effectNameCmd->setEqLogic_id($this->getId());
            $effectNameCmd->setLogicalId('effect_name');
            $effectNameCmd->setType('info');
            $effectNameCmd->setSubType('string');
            $effectNameCmd->setIsVisible(0);
            $effectNameCmd->setOrder(13);
            $effectNameCmd->save();
        }
        $speedCmd = $this->getCmd(null, "speed");
        if (!is_object($speedCmd)) {
            $speedCmd = new wledCmd();
            $speedCmd->setName(__('Vitesse effet', __FILE__));
            $speedCmd->setEqLogic_id($this->getId());
            $speedCmd->setLogicalId('speed');
            $speedCmd->setType('action');
            $speedCmd->setSubType('slider');
            $speedCmd->setGeneric_type('DONT');
            $speedCmd->setConfiguration('minValue', '0');
            $speedCmd->setConfiguration('maxValue', '255');
            $speedCmd->setIsVisible(1);
            $speedCmd->setOrder(9);
            $speedCmd->save();
        }
        $speedStateCmd = $this->getCmd(null, "speed_state");
        if (!is_object($speedStateCmd)) {
            $speedStateCmd = new wledCmd();
            $speedStateCmd->setName(__('Etat vitesse effet', __FILE__));
            $speedStateCmd->setEqLogic_id($this->getId());
            $speedStateCmd->setLogicalId('speed_state');
            $speedStateCmd->setType('info');
            $speedStateCmd->setSubType('numeric');
            $speedStateCmd->setGeneric_type('DONT');
            $speedStateCmd->setIsVisible(0);
            $speedStateCmd->setOrder(10);
            $speedStateCmd->save();
        }
        $intensityCmd = $this->getCmd(null, "intensity");
        if (!is_object($intensityCmd)) {
            $intensityCmd = new wledCmd();
            $intensityCmd->setName(__('Intensité effet', __FILE__));
            $intensityCmd->setEqLogic_id($this->getId());
            $intensityCmd->setLogicalId('intensity');
            $intensityCmd->setType('action');
            $intensityCmd->setSubType('slider');
            $intensityCmd->setGeneric_type('DONT');
            $intensityCmd->setConfiguration('minValue', '0');
            $intensityCmd->setConfiguration('maxValue', '255');
            $intensityCmd->setIsVisible(1);
            $intensityCmd->setOrder(11);
            $intensityCmd->save();
        }
        $intensityStateCmd = $this->getCmd(null, "intensity_state");
        if (!is_object($intensityStateCmd)) {
            $intensityStateCmd = new wledCmd();
            $intensityStateCmd->setName(__('Etat intensité effet', __FILE__));
            $intensityStateCmd->setEqLogic_id($this->getId());
            $intensityStateCmd->setLogicalId('intensity_state');
            $intensityStateCmd->setType('info');
            $intensityStateCmd->setSubType('numeric');
            $intensityStateCmd->setGeneric_type('DONT');
            $intensityStateCmd->setIsVisible(0);
            $intensityStateCmd->setOrder(12);
            $intensityStateCmd->save();
        }
        $paletteCmd = $this->getCmd(null, "palette");
        if (!is_object($paletteCmd)) {
            $paletteCmd = new wledCmd();
            $paletteCmd->setName(__('Palette', __FILE__));
            $paletteCmd->setEqLogic_id($this->getId());
            $paletteCmd->setLogicalId('palette');
            $paletteCmd->setType('action');
            $paletteCmd->setSubType('select');
            // The listValue will be updated later.
            $paletteCmd->setGeneric_type('LIGHT_MODE');
            $paletteCmd->setIsVisible(1);
            $paletteCmd->setOrder(14);
            $paletteCmd->save();
        }
        $paletteStateCmd = $this->getCmd(null, "palette_state");
        if (!is_object($paletteStateCmd)) {
            $paletteStateCmd = new wledCmd();
            $paletteStateCmd->setName(__('Etat palette', __FILE__));
            $paletteStateCmd->setEqLogic_id($this->getId());
            $paletteStateCmd->setLogicalId('palette_state');
            $paletteStateCmd->setType('info');
            $paletteStateCmd->setSubType('numeric');
            $paletteStateCmd->setIsVisible(0);
            $paletteStateCmd->setOrder(15);
            $paletteStateCmd->save();
        }
        $paletteNameCmd = $this->getCmd(null, "palette_name");
        if (!is_object($paletteNameCmd)) {
            $paletteNameCmd = new wledCmd();
            $paletteNameCmd->setName(__('Nom palette', __FILE__));
            $paletteNameCmd->setEqLogic_id($this->getId());
            $paletteNameCmd->setLogicalId('palette_name');
            $paletteNameCmd->setType('info');
            $paletteNameCmd->setSubType('string');
            $paletteNameCmd->setIsVisible(0);
            $paletteNameCmd->setOrder(16);
            $paletteNameCmd->save();
        }
        $presetCmd = $this->getCmd(null, "preset");
        if (!is_object($presetCmd)) {
            $presetCmd = new wledCmd();
            $presetCmd->setName(__('Preset', __FILE__));
            $presetCmd->setEqLogic_id($this->getId());
            $presetCmd->setLogicalId('preset');
            $presetCmd->setType('action');
            $presetCmd->setSubType('message');
            $presetCmd->setIsVisible(1);
            $presetCmd->save();
        }
        // Liens entre les commandes
        $onCmd->setValue($stateCmd->getId());
        $onCmd->save();
        $offCmd->setValue($stateCmd->getId());
        $offCmd->save();
        $brightnessCmd->setValue($brightnessStateCmd->getId());
        $brightnessCmd->save();
        $colorCmd->setValue($colorStateCmd->getId());
        $colorCmd->save();
        $colorBgCmd->setValue($colorStateBgCmd->getId());
        $colorBgCmd->save();
        $colorThirdCmd->setValue($colorStateThirdCmd->getId());
        $colorThirdCmd->save();
        $effectCmd->setValue($effectStateCmd->getId());
        $effectCmd->save();
        $speedCmd->setValue($speedStateCmd->getId());
        $speedCmd->save();
        $intensityCmd->setValue($intensityStateCmd->getId());
        $intensityCmd->save();
        $paletteCmd->setValue($paletteStateCmd->getId());
        $paletteCmd->save();
        $this->getWledEffects();
        $this->getWledPalettes();
    }

    // Fonction exécutée automatiquement avant la suppression de l'équipement
    public function preRemove() {
    }

    // Fonction exécutée automatiquement après la suppression de l'équipement
    public function postRemove() {
    }

    /*
     * Non obligatoire : permet de modifier l'affichage du widget (également utilisable par les commandes)
      public function toHtml($_version = 'dashboard') {

      }
     */

    /*
     * Non obligatoire : permet de déclencher une action après modification de variable de configuration
    public static function postConfig_<Variable>() {
    }
     */

    /*
     * Non obligatoire : permet de déclencher une action avant modification de variable de configuration
    public static function preConfig_<Variable>() {
    }
     */

    /*     * **********************Getteur Setteur*************************** */
    public function getWledStatus() {
        log::add('wled', 'debug', 'Running getWledStatus');
        $endPoint = '/json/state';
        $ipAddress = $this->getConfiguration('ip_address');
        if ($ipAddress != '') {
            $result = wled::request($ipAddress, $endPoint, null, 'GET');
            log::add('wled', 'debug', 'request result ' . $result);
            $result = is_json($result, $result);
            if (is_array($result)) {
                $this->applyState($result);
            }
        }
    }

    public function getWledEffects() {
        log::add('wled', 'debug', 'Running getWledEfects');
        $endPoint = '/json/eff';
        $ipAddress = $this->getConfiguration('ip_address');
        if ($ipAddress != '') {
            $result = wled::request($ipAddress, $endPoint, null, 'GET');
            log::add('wled', 'debug', 'getWledEfects request result ' . $result);
            $result = is_json($result, $result);
            if (is_array($result)) {
                $this->updateEffects($result);
            }
        } else {
            log::add('wled', 'debug', 'Error : getWledEfects called with empty ip address');
        }
    }

    public function getWledPalettes() {
        log::add('wled', 'debug', 'Running getWledPalettes');
        $endPoint = '/json/pal';
        $ipAddress = $this->getConfiguration('ip_address');
        if ($ipAddress != '') {
            $result = wled::request($ipAddress, $endPoint, null, 'GET');
            log::add('wled', 'debug', 'getWledPalettes request result ' . $result);
            $result = is_json($result, $result);
            if (is_array($result)) {
                $this->updatePalettes($result);
            }
        } else {
            log::add('wled', 'debug', 'Error : getWledEfects called with empty ip address');
        }
    }

    public function getWledInfos() {
        log::add('wled', 'debug', 'Running getWledInfos');
        $endPoint = '/json/infos';
        $ipAddress = $this->getConfiguration('ip_address');
        if ($ipAddress != '') {
            $result = wled::request($ipAddress, $endPoint, null, 'GET');
            log::add('wled', 'debug', 'getWledInfos request result ' . $result);
            $result = is_json($result, $result);
            if (is_array($result)) {
                $this->updateInfos($result);
            }
        } else {
            log::add('wled', 'debug', 'Error : getWledInfos called with empty ip address');
        }
    }

    public function applyState($result) {
        log::add('wled', 'debug', 'applyState for ' . print_r($result, true));
        $info = $result['on'];
        if ($info) {
            $this->checkAndUpdateCmd('state', 1);
        } else {
            $this->checkAndUpdateCmd('state', 0);
        }
        $info = $result['bri'];
        $this->checkAndUpdateCmd('brightness_state', $info);
        $numseg = $this->getConfiguration('segment', 0);
        // A revoir utiliser segment "id"
        $segment = $result['seg'][$numseg];
        log::add('wled', 'debug', 'Traitement segment ' . print_r($segment, true));
        $effectNumber = $segment['fx'];
        $this->checkAndUpdateCmd('effect_state', $effectNumber);
        $effectCmd = $this->getCmd(null, "effect");
        if (is_object($effectCmd)) {
            $elements = explode(';', $effectCmd->getConfiguration('listValue', ''));
            foreach ($elements as $element) {
                $coupleArray = explode('|', $element);
                if ($effectNumber == $coupleArray[0]) {
                    $this->checkAndUpdateCmd('effect_name', $coupleArray[1]);
                }
            }
        }
        $paletteNumber = $segment['pal'];
        $this->checkAndUpdateCmd('palette_state', $paletteNumber);
        $paletteCmd = $this->getCmd(null, "palette");
        if (is_object($paletteCmd)) {
            $elements = explode(';', $paletteCmd->getConfiguration('listValue', ''));
            foreach ($elements as $element) {
                $coupleArray = explode('|', $element);
                if ($paletteNumber == $coupleArray[0]) {
                    $this->checkAndUpdateCmd('palette_name', $coupleArray[1]);
                }
            }
        }
        $this->checkAndUpdateCmd('speed_state', $segment['sx']);
        $this->checkAndUpdateCmd('intensity_state', $segment['ix']);
        log::add('wled', 'debug', 'segment ' . print_r($segment, true));

        $mainColor = $segment['col'][0];
        log::add('wled', 'debug', 'main color ' . print_r($mainColor, true));
        log::add('wled', 'debug', 'bg color ' . print_r($bgColor, true));
        log::add('wled', 'debug', 'third color ' . print_r($thirdColor, true));
        $value = '#' . sprintf('%02x', $mainColor[0]) . sprintf('%02x', $mainColor[1]) . sprintf('%02x', $mainColor[2]);
        log::add('wled', 'debug', 'color value ' . $value);
        $this->checkAndUpdateCmd('color_state', $value);
        $value = '#' . sprintf('%02x', $bgColor[0]) . sprintf('%02x', $bgColor[1]) . sprintf('%02x', $bgColor[2]);
        log::add('wled', 'debug', 'color bg value ' . $value);
        $this->checkAndUpdateCmd('color_state_bg', $value);
        $value = '#' . sprintf('%02x', $thirdColor[0]) . sprintf('%02x', $thirdColor[1]) . sprintf('%02x', $thirdColor[2]);
        log::add('wled', 'debug', 'color third value ' . $value);
        $this->checkAndUpdateCmd('color_state_third', $value);
    }

    public function updateEffects($result) {
        log::add('wled', 'debug', 'updateEffects for ' . print_r($result, true));
        $effects = array();
        foreach ($result as $k => $name) {
            if ($name != 'RSVD' && $name != "-") {
                $effects[] = $k . '|' . wled::remove_emoji($name);
            }
        }
        $listEffects = implode(';', $effects);
        log::add('wled', 'debug', 'listEffects ' . $listEffects);
        $effectCmd = $this->getCmd(null, "effect");
        if (is_object($effectCmd)) {
            $effectCmd->setConfiguration('listValue', $listEffects);
            $effectCmd->save();
        }
    }

    public function updatePalettes($result) {
        log::add('wled', 'debug', 'updatePalettes for ' . print_r($result, true));
        $palettes = array();
        foreach ($result as $k => $name) {
            if ($name != 'RSVD' && $name != "-") {
                $palettes[] = $k . '|' . wled::remove_emoji($name);
            }
        }
        $listPalettes = implode(';', $palettes);
        log::add('wled', 'debug', 'listPalettes ' . $listPalettes);
        $paletteCmd = $this->getCmd(null, "palette");
        if (is_object($paletteCmd)) {
            $paletteCmd->setConfiguration('listValue', $listPalettes);
            $paletteCmd->save();
        }
    }

    public function updateInfos($result) {
        log::add('wled', 'debug', 'updateInfos for ' . print_r($result, true));
        $this->setConfiguration('version', $result['ver']);
        $this->setConfiguration('ledscount', $result['leds']['count']);
        $this->setConfiguration('ledsmaxpwr', $result['leds']['maxpwr']);
        $this->setConfiguration('ledsfxcount', $result['fxcount']);
        $this->setConfiguration('ledspalcount', $result['palcount']);
        $this->save();
    }
}

class wledCmd extends cmd {
    /*     * *************************Attributs****************************** */

    /*
      public static $_widgetPossibility = array();
    */

    /*     * ***********************Methode static*************************** */


    /*     * *********************Methode d'instance************************* */

    /*
     * Non obligatoire permet de demander de ne pas supprimer les commandes même si elles ne sont pas dans la nouvelle configuration de l'équipement envoyé en JS
      public function dontRemoveCmd() {
      return true;
      }
     */

    // Exécution d'une commande
    public function execute($_options = array()) {
        if ($this->getType() != 'action') {
            return;
        }

        $eqLogic = $this->getEqLogic();
        $segment = $eqLogic->getConfiguration('segment', 0);
        $action = $this->getLogicalId();
        log::add('wled', 'debug', 'execute action ' . $action);
        log::add('wled', 'debug', 'execute options ' . print_r($_options, true));
        if ($action == 'on') {
            if ($segment == 0) {
                $data = '{"on":true}';
            } else {
                $data = '{"seg":[{"id":' . $segment . ', "on":true}]}';
            }
        } else if ($action == 'off') {
            if ($segment == 0) {
                $data = '{"on":false}';
            } else {
                $data = '{"seg":[{"id":' . $segment . ', "on":false}]}';
            }
        } else if ($action == 'brightness') {
            if ($segment == 0) {
                $data = '{"bri":' . intval($_options['slider']) . '}';
            } else {
                $data = '{"seg":[{"id":' . $segment . ', "bri":' . intval($_options['slider']) . '}]}';
            }
        } else if ($action == 'effect') {
            $data = '{"seg":[{"id":' . $segment . ', "fx":' . intval($_options['select']) . '}]}';
        } else if ($action == 'palette') {
            $data = '{"seg":[{"id":' . $segment . ', "pal":' . intval($_options['select']) . '}]}';
        } else if ($action == 'color') {
            list($r, $g, $b) = str_split(str_replace('#', '', $_options['color']), 2);
            $r = hexdec($r);
            $g = hexdec($g);
            $b = hexdec($b);
            $data = '{"seg":[{"id":' . $segment . ', "col":[[' . $r . ',' . $g . ',' .  $b . ']]}]}';
        } else if ($action == 'color_bg') {
            list($r, $g, $b) = str_split(str_replace('#', '', $_options['color']), 2);
            $r = hexdec($r);
            $g = hexdec($g);
            $b = hexdec($b);
            $data = '{"seg":[{"id":' . $segment . ', "col":[[],[' . $r . ',' . $g . ',' .  $b . '],[]]}]}';
        } else if ($action == 'color_third') {
            list($r, $g, $b) = str_split(str_replace('#', '', $_options['color']), 2);
            $r = hexdec($r);
            $g = hexdec($g);
            $b = hexdec($b);
            $data = '{"seg":[{"id":' . $segment . ', "col":[[],[],[' . $r . ',' . $g . ',' .  $b . '],[]]}]}';
        } else if ($action == 'speed') {
            $data = '{"seg":[{"id":' . $segment . ', "sx":' . intval($_options['slider']) . '}]}';
        } else if ($action == 'intensity') {
            $data = '{"seg":[{"id":' . $segment . ', "sx":' . intval($_options['slider']) . '}]}';
        } else if ($action == 'preset') {
            $data = '{"ps":' . intval($_options['message'])  . '}';
        }
        log::add('wled', 'debug', 'execute data ' . $data);
        $endPoint = '/json/state';
        $ipAddress = $eqLogic->getConfiguration('ip_address');
        $result = wled::request($ipAddress, $endPoint, $data, 'POST');
        log::add('wled', 'debug', 'execute request result ' . $result);
        $eqLogic->getWledStatus();
        $eqLogic->refreshWidget();
    }

    /*     * **********************Getteur Setteur*************************** */
}
