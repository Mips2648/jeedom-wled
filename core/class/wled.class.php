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
                'Content-Type: application/json'
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
        $result = $request_http->exec(10, 3);
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
                                $eqLogic->setConfiguration('autorefresh', '* * * * *');
                                $eqLogic->updateInfos($infos);
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
        /** @var wled */
        foreach (self::byType('wled', true) as $eqLogic) {
            $autorefresh = $eqLogic->getConfiguration('autorefresh', '');
            $ipAddress = $eqLogic->getConfiguration('ip_address');
            if ($ipAddress != '' && $autorefresh != '') {
                try {
                    $c = new Cron\CronExpression($autorefresh, new Cron\FieldFactory);
                    if ($c->isDue()) {
                        try {
                            $eqLogic->getWledState();
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
        $this->getWledInfos();
    }

    // Fonction exécutée automatiquement après la sauvegarde (création ou mise à jour) de l'équipement
    public function postSave() {
        // Création des commandes

        $refreshCmd = $this->getCmd('action', 'refresh');
        if (!is_object($refreshCmd)) {
            $refreshCmd = new wledCmd();
            $refreshCmd->setName('Rafraichir');
            $refreshCmd->setEqLogic_id($this->getId());
            $refreshCmd->setLogicalId('refresh');
            $refreshCmd->setType('action');
            $refreshCmd->setSubType('other');
            $refreshCmd->setIsVisible(1);
            $refreshCmd->setOrder(0);
            $refreshCmd->save();
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
            $stateCmd->setOrder(1);
            $stateCmd->save();
        }
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
            $onCmd->setOrder(2);
            $onCmd->setValue($stateCmd->getId());
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
            $offCmd->setOrder(3);
            $offCmd->setValue($stateCmd->getId());
            $offCmd->save();
        }

        $brightnessStateCmd = $this->getCmd(null, "brightness_state");
        if (!is_object($brightnessStateCmd)) {
            $brightnessStateCmd = new wledCmd();
            $brightnessStateCmd->setName(__('Etat Luminosité', __FILE__));
            $brightnessStateCmd->setEqLogic_id($this->getId());
            $brightnessStateCmd->setLogicalId('brightness_state');
            $brightnessStateCmd->setType('info');
            $brightnessStateCmd->setSubType('numeric');
            $brightnessStateCmd->setGeneric_type('LIGHT_STATE');
            $brightnessStateCmd->setIsVisible(0);
            $brightnessStateCmd->setOrder(4);
            $brightnessStateCmd->save();
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
            $brightnessCmd->setOrder(5);
            $brightnessCmd->setValue($brightnessStateCmd->getId());
            $brightnessCmd->save();
        }

        $this->getWledEffects();
        $this->getWledPalettes();

        if ($this->getIsEnable() == 1) {
            $this->getWledState();
        }
    }

    private function isActionCmdExists($logicalId, $segmentId) {
        $cmds = $this->getCmd('action', $logicalId, null, true);
        foreach ($cmds as $cmd) {
            if ($cmd->getConfiguration('segment', 0) == $segmentId) {
                return true;
            }
        }
        return false;
    }

    private function createSegmentCmds($id, $name) {
        $stateCmd = $this->getCmd('info', "state_segment_{$id}");
        if (!is_object($stateCmd)) {
            $stateCmd = new wledCmd();
            $stateCmd->setName(__('Etat', __FILE__) . " {$name}");
            $stateCmd->setEqLogic_id($this->getId());
            $stateCmd->setLogicalId("state_segment_{$id}");
            $stateCmd->setType('info');
            $stateCmd->setSubType('binary');
            $stateCmd->setGeneric_type('LIGHT_STATE');
            $stateCmd->setIsVisible(0);
            $stateCmd->save();
        }
        if (!$this->isActionCmdExists('on_segment', $id)) {
            $onCmd = new wledCmd();
            $onCmd->setName("On {$name}");
            $onCmd->setEqLogic_id($this->getId());
            $onCmd->setConfiguration('segment', $id);
            $onCmd->setLogicalId("on_segment");
            $onCmd->setType('action');
            $onCmd->setSubType('other');
            $onCmd->setGeneric_type('LIGHT_ON');
            $onCmd->setIsVisible(1);
            $onCmd->setValue($stateCmd->getId());
            $onCmd->setDisplay('icon', '<i class="icon jeedom-lumiere-on"></i>');
            $onCmd->setTemplate('dashboard', 'light');
            $onCmd->setTemplate('mobile', 'light');
            $onCmd->save();
        }
        if (!$this->isActionCmdExists('off_segment', $id)) {
            $offCmd = new wledCmd();
            $offCmd->setName("Off {$name}");
            $offCmd->setEqLogic_id($this->getId());
            $offCmd->setLogicalId("off_segment");
            $offCmd->setConfiguration('segment', $id);
            $offCmd->setType('action');
            $offCmd->setSubType('other');
            $offCmd->setGeneric_type('LIGHT_OFF');
            $offCmd->setIsVisible(1);
            $offCmd->setValue($stateCmd->getId());
            $offCmd->setDisplay('icon', '<i class="icon jeedom-lumiere-off"></i>');
            $offCmd->setTemplate('dashboard', 'light');
            $offCmd->setTemplate('mobile', 'light');
            $offCmd->save();
        }

        $brightnessStateCmd = $this->getCmd('info', "brightness_state_segment_{$id}");
        if (!is_object($brightnessStateCmd)) {
            $brightnessStateCmd = new wledCmd();
            $brightnessStateCmd->setName(__('Etat Luminosité', __FILE__) . " {$name}");
            $brightnessStateCmd->setEqLogic_id($this->getId());
            $brightnessStateCmd->setLogicalId("brightness_state_segment_{$id}");
            $brightnessStateCmd->setType('info');
            $brightnessStateCmd->setSubType('numeric');
            $brightnessStateCmd->setGeneric_type('LIGHT_STATE');
            $brightnessStateCmd->setIsVisible(0);
            $brightnessStateCmd->save();
        }
        if (!$this->isActionCmdExists('brightness_segment', $id)) {
            $brightnessCmd = new wledCmd();
            $brightnessCmd->setName(__('Luminosité', __FILE__) . " {$name}");
            $brightnessCmd->setEqLogic_id($this->getId());
            $brightnessCmd->setLogicalId("brightness_segment");
            $brightnessCmd->setConfiguration('segment', $id);
            $brightnessCmd->setType('action');
            $brightnessCmd->setSubType('slider');
            $brightnessCmd->setGeneric_type('LIGHT_SLIDER');
            $brightnessCmd->setConfiguration('minValue', '0');
            $brightnessCmd->setConfiguration('maxValue', '255');
            $brightnessCmd->setValue($brightnessStateCmd->getId());
            $brightnessCmd->setIsVisible(1);
            $brightnessCmd->save();
        }

        $colorStateCmd = $this->getCmd('info', "color_state_segment_{$id}");
        if (!is_object($colorStateCmd)) {
            $colorStateCmd = new wledCmd();
            $colorStateCmd->setName(__('Etat Couleur', __FILE__) . " {$name}");
            $colorStateCmd->setEqLogic_id($this->getId());
            $colorStateCmd->setLogicalId("color_state_segment_{$id}");
            $colorStateCmd->setType('info');
            $colorStateCmd->setSubType('string');
            $colorStateCmd->setGeneric_type('LIGHT_COLOR');
            $colorStateCmd->setIsVisible(0);
            $colorStateCmd->save();
        }
        if (!$this->isActionCmdExists('color', $id)) {
            $colorCmd = new wledCmd();
            $colorCmd->setName(__('Couleur', __FILE__) . " {$name}");
            $colorCmd->setEqLogic_id($this->getId());
            $colorCmd->setLogicalId("color");
            $colorCmd->setConfiguration('segment', $id);
            $colorCmd->setType('action');
            $colorCmd->setSubType('color');
            $colorCmd->setGeneric_type('LIGHT_SET_COLOR');
            $colorCmd->setValue($colorStateCmd->getId());
            $colorCmd->setIsVisible(1);
            $colorCmd->save();
        }

        $effectStateCmd = $this->getCmd('info', "effect_state_segment_{$id}");
        if (!is_object($effectStateCmd)) {
            $effectStateCmd = new wledCmd();
            $effectStateCmd->setName(__('Etat effet', __FILE__) . " {$name}");
            $effectStateCmd->setEqLogic_id($this->getId());
            $effectStateCmd->setLogicalId("effect_state_segment_{$id}");
            $effectStateCmd->setType('info');
            $effectStateCmd->setSubType('numeric');
            $effectStateCmd->setIsVisible(0);
            $effectStateCmd->save();
        }
        $effectNameCmd = $this->getCmd('info', "effect_name_segment_{$id}");
        if (!is_object($effectNameCmd)) {
            $effectNameCmd = new wledCmd();
            $effectNameCmd->setName(__('Nom effet', __FILE__) . " {$name}");
            $effectNameCmd->setEqLogic_id($this->getId());
            $effectNameCmd->setLogicalId("effect_name_segment_{$id}");
            $effectNameCmd->setType('info');
            $effectNameCmd->setSubType('string');
            $effectNameCmd->setIsVisible(0);
            $effectNameCmd->save();
        }
        if (!$this->isActionCmdExists('effect', $id)) {
            $effectCmd = new wledCmd();
            $effectCmd->setName(__('Effet', __FILE__) . " {$name}");
            $effectCmd->setEqLogic_id($this->getId());
            $effectCmd->setLogicalId("effect");
            $effectCmd->setConfiguration('segment', $id);
            $effectCmd->setType('action');
            $effectCmd->setSubType('select');
            // The listValue will be updated later.
            $effectCmd->setGeneric_type('LIGHT_MODE');
            $effectCmd->setValue($effectStateCmd->getId());
            $effectCmd->setIsVisible(1);
            $effectCmd->save();
        }

        $speedStateCmd = $this->getCmd(null, "speed_state_segment_{$id}");
        if (!is_object($speedStateCmd)) {
            $speedStateCmd = new wledCmd();
            $speedStateCmd->setName(__('Etat vitesse effet', __FILE__) . " {$name}");
            $speedStateCmd->setEqLogic_id($this->getId());
            $speedStateCmd->setLogicalId("speed_state_segment_{$id}");
            $speedStateCmd->setType('info');
            $speedStateCmd->setSubType('numeric');
            $speedStateCmd->setGeneric_type('DONT');
            $speedStateCmd->setIsVisible(0);
            $speedStateCmd->save();
        }
        if (!$this->isActionCmdExists('speed', $id)) {
            $speedCmd = new wledCmd();
            $speedCmd->setName(__('Vitesse effet', __FILE__) . " {$name}");
            $speedCmd->setEqLogic_id($this->getId());
            $speedCmd->setLogicalId("speed");
            $speedCmd->setConfiguration('segment', $id);
            $speedCmd->setType('action');
            $speedCmd->setSubType('slider');
            $speedCmd->setGeneric_type('DONT');
            $speedCmd->setConfiguration('minValue', '0');
            $speedCmd->setConfiguration('maxValue', '255');
            $speedCmd->setIsVisible(1);
            $speedCmd->setValue($speedStateCmd->getId());
            $speedCmd->save();
        }

        $intensityStateCmd = $this->getCmd(null, "intensity_state_segment_{$id}");
        if (!is_object($intensityStateCmd)) {
            $intensityStateCmd = new wledCmd();
            $intensityStateCmd->setName(__('Etat intensité effet', __FILE__) . " {$name}");
            $intensityStateCmd->setEqLogic_id($this->getId());
            $intensityStateCmd->setLogicalId("intensity_state_segment_{$id}");
            $intensityStateCmd->setType('info');
            $intensityStateCmd->setSubType('numeric');
            $intensityStateCmd->setGeneric_type('DONT');
            $intensityStateCmd->setIsVisible(0);
            $intensityStateCmd->save();
        }
        if (!$this->isActionCmdExists('intensity', $id)) {
            $intensityCmd = new wledCmd();
            $intensityCmd->setName(__('Intensité effet', __FILE__) . " {$name}");
            $intensityCmd->setEqLogic_id($this->getId());
            $intensityCmd->setLogicalId("intensity");
            $intensityCmd->setConfiguration('segment', $id);
            $intensityCmd->setType('action');
            $intensityCmd->setSubType('slider');
            $intensityCmd->setGeneric_type('DONT');
            $intensityCmd->setConfiguration('minValue', '0');
            $intensityCmd->setConfiguration('maxValue', '255');
            $intensityCmd->setValue($intensityStateCmd->getId());
            $intensityCmd->setIsVisible(1);
            $intensityCmd->save();
        }

        $paletteStateCmd = $this->getCmd(null, "palette_state_segment_{$id}");
        if (!is_object($paletteStateCmd)) {
            $paletteStateCmd = new wledCmd();
            $paletteStateCmd->setName(__('Etat palette', __FILE__) . " {$name}");
            $paletteStateCmd->setEqLogic_id($this->getId());
            $paletteStateCmd->setLogicalId("palette_state_segment_{$id}");
            $paletteStateCmd->setType('info');
            $paletteStateCmd->setSubType('numeric');
            $paletteStateCmd->setIsVisible(0);
            $paletteStateCmd->save();
        }
        $paletteNameCmd = $this->getCmd(null, "palette_name_segment_{$id}");
        if (!is_object($paletteNameCmd)) {
            $paletteNameCmd = new wledCmd();
            $paletteNameCmd->setName(__('Nom palette', __FILE__) . " {$name}");
            $paletteNameCmd->setEqLogic_id($this->getId());
            $paletteNameCmd->setLogicalId("palette_name_segment_{$id}");
            $paletteNameCmd->setType('info');
            $paletteNameCmd->setSubType('string');
            $paletteNameCmd->setIsVisible(0);
            $paletteNameCmd->save();
        }
        if (!$this->isActionCmdExists('palette', $id)) {
            $paletteCmd = new wledCmd();
            $paletteCmd->setName(__('Palette', __FILE__) . " {$name}");
            $paletteCmd->setEqLogic_id($this->getId());
            $paletteCmd->setLogicalId("palette");
            $paletteCmd->setConfiguration('segment', $id);
            $paletteCmd->setType('action');
            $paletteCmd->setSubType('select');
            // The listValue will be updated later.
            $paletteCmd->setGeneric_type('LIGHT_MODE');
            $paletteCmd->setValue($paletteStateCmd->getId());
            $paletteCmd->setIsVisible(1);
            $paletteCmd->save();
        }
    }

    // Fonction exécutée automatiquement avant la suppression de l'équipement
    public function preRemove() {
    }

    // Fonction exécutée automatiquement après la suppression de l'équipement
    public function postRemove() {
    }

    /*     * **********************Getteur Setteur*************************** */
    public function getWledState() {
        log::add('wled', 'debug', 'Running getWledState');
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

    private function getWledEffects() {
        log::add('wled', 'debug', 'Running getWledEffects');
        $endPoint = '/json/eff';
        $ipAddress = $this->getConfiguration('ip_address');
        if ($ipAddress != '') {
            $result = wled::request($ipAddress, $endPoint, null, 'GET');
            log::add('wled', 'debug', 'getWledEffects request result ' . $result);
            $result = is_json($result, $result);
            if (is_array($result)) {
                $this->setCache('wled::effects', json_encode($result));
            }
        } else {
            log::add('wled', 'debug', 'Error : getWledEffects called with empty ip address');
        }
    }

    private function getWledPalettes() {
        log::add('wled', 'debug', 'Running getWledPalettes');
        $endPoint = '/json/pal';
        $ipAddress = $this->getConfiguration('ip_address');
        if ($ipAddress != '') {
            $result = wled::request($ipAddress, $endPoint, null, 'GET');
            log::add('wled', 'debug', 'getWledPalettes request result ' . $result);
            $result = is_json($result, $result);
            if (is_array($result)) {
                $this->setCache('wled::palettes', json_encode($result));
            }
        } else {
            log::add('wled', 'debug', 'Error : getWledEfects called with empty ip address');
        }
    }

    private function getWledInfos() {
        log::add('wled', 'debug', 'Running getWledInfos');
        $endPoint = '/json/infos';
        $ipAddress = $this->getConfiguration('ip_address');
        if ($ipAddress != '') {
            $result = wled::request($ipAddress, $endPoint, null, 'GET');
            log::add('wled', 'debug', 'getWledInfos request result ' . $result);
            $result = json_decode($result, true);
            if (is_array($result)) {
                $this->updateInfos($result);
            }
        } else {
            log::add('wled', 'debug', 'Error : getWledInfos called with empty ip address');
        }
    }

    private function applyState($result) {
        log::add('wled', 'debug', 'applyState for ' . json_encode($result));

        $this->checkAndUpdateCmd('state', $result['on']);
        $this->checkAndUpdateCmd('brightness_state', $result['bri']);

        /** @var string */
        $effects = $this->getCache('wled::effects');
        $effectList = json_decode($effects, true);
        /** @var string */
        $palettes = $this->getCache('wled::palettes');
        $paletteList = json_decode($palettes, true);

        foreach ($result['seg'] as $id => $segment) {
            log::add('wled', 'debug', 'Traitement segment ' . json_encode($segment));
            $this->createSegmentCmds($id, $segment['n'] ?? "segment {$id}");

            $this->checkAndUpdateCmd("state_segment_{$id}", $segment['on']);
            $this->checkAndUpdateCmd("brightness_state_segment_{$id}", $segment['bri']);

            $effectNumber = $segment['fx'];
            $this->checkAndUpdateCmd("effect_state_segment_{$id}", $effectNumber);

            $this->checkAndUpdateCmd("effect_name_segment_{$id}", $effectList[$effectNumber]);

            $paletteNumber = $segment['pal'];
            $this->checkAndUpdateCmd("palette_state_segment_{$id}", $paletteNumber);
            $this->checkAndUpdateCmd("palette_name_segment_{$id}", $paletteList[$paletteNumber]);

            $this->checkAndUpdateCmd("speed_state_segment_{$id}", $segment['sx']);
            $this->checkAndUpdateCmd("intensity_state_segment_{$id}", $segment['ix']);
            $mainColor = $segment['col'][0];
            log::add('wled', 'debug', 'main color ' . print_r($mainColor, true));
            $value = '#' . sprintf('%02x', $mainColor[0]) . sprintf('%02x', $mainColor[1]) . sprintf('%02x', $mainColor[2]);
            log::add('wled', 'debug', 'color value ' . $value);
            $this->checkAndUpdateCmd("color_state_segment_{$id}", $value);
        }
        $this->updateEffects($effectList);
        $this->updatePalettes($paletteList);
    }

    private function updateEffects($result) {
        log::add('wled', 'debug', 'updateEffects for ' . print_r($result, true));
        $effects = array();
        foreach ($result as $k => $name) {
            if ($name != 'RSVD' && $name != "-") {
                $effects[] = $k . '|' . $name;
            }
        }
        $listEffects = implode(';', $effects);
        log::add('wled', 'debug', 'listEffects ' . $listEffects);
        $effectCmds = $this->getCmd('action', "effect", null, true);
        foreach ($effectCmds as $effectCmd) {
            $effectCmd->setConfiguration('listValue', $listEffects);
            $effectCmd->save();
        }
    }

    private function updatePalettes($result) {
        log::add('wled', 'debug', 'updatePalettes for ' . print_r($result, true));
        $palettes = array();
        foreach ($result as $k => $name) {
            if ($name != 'RSVD' && $name != "-") {
                $palettes[] = $k . '|' . $name;
            }
        }
        $listPalettes = implode(';', $palettes);
        log::add('wled', 'debug', 'listPalettes ' . $listPalettes);
        $paletteCmds = $this->getCmd('action', "palette", null, true);
        foreach ($paletteCmds as $paletteCmd) {
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
    }
}

class wledCmd extends cmd {
    // Exécution d'une commande
    public function execute($_options = array()) {
        if ($this->getType() != 'action') {
            return;
        }

        /** @var wled */
        $eqLogic = $this->getEqLogic();
        $action = $this->getLogicalId();
        log::add('wled', 'debug', 'execute action ' . $action);
        log::add('wled', 'debug', 'execute options ' . print_r($_options, true));
        if ($action == 'on') {
            $data = '{"on":true}';
        } else if ($action == 'off') {
            $data = '{"on":false}';
        } else if ($action == 'brightness') {
            $data = '{"bri":' . intval($_options['slider']) . '}';
        } elseif ($action == 'on_segment') {
            $data = '{"seg":[{"id":' . $this->getConfiguration('segment', 0) . ', "on":true}]}';
        } elseif ($action == 'off_segment') {
            $data = '{"seg":[{"id":' . $this->getConfiguration('segment', 0) . ', "on":false}]}';
        } elseif ($action == 'brightness_segment') {
            $data = '{"seg":[{"id":' . $this->getConfiguration('segment', 0) . ', "bri":' . intval($_options['slider']) . '}]}';
        } else if ($action == 'effect') {
            $data = '{"seg":[{"id":' . $this->getConfiguration('segment', 0) . ', "fx":' . intval($_options['select']) . '}]}';
        } else if ($action == 'palette') {
            $data = '{"seg":[{"id":' . $this->getConfiguration('segment', 0) . ', "pal":' . intval($_options['select']) . '}]}';
        } else if ($action == 'color') {
            list($r, $g, $b) = str_split(str_replace('#', '', $_options['color']), 2);
            $r = hexdec($r);
            $g = hexdec($g);
            $b = hexdec($b);
            $data = '{"seg":[{"id":' . $this->getConfiguration('segment', 0) . ', "col":[[' . $r . ',' . $g . ',' .  $b . ']]}]}';
        } else if ($action == 'speed') {
            $data = '{"seg":[{"id":' . $this->getConfiguration('segment', 0) . ', "sx":' . intval($_options['slider']) . '}]}';
        } else if ($action == 'intensity') {
            $data = '{"seg":[{"id":' . $this->getConfiguration('segment', 0) . ', "ix":' . intval($_options['slider']) . '}]}';
        }
        if ($action != 'refresh') {
            log::add('wled', 'debug', "POST state: {$data}");
            $endPoint = '/json/state';
            $ipAddress = $eqLogic->getConfiguration('ip_address');
            $result = wled::request($ipAddress, $endPoint, $data, 'POST');
            log::add('wled', 'debug', 'execute request result ' . $result);
        }

        $eqLogic->getWledState();
    }
}
