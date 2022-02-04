<?php
error_reporting(E_ALL);
ini_set("display_errors", 1);

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

class easycontrol extends eqLogic {
    /*     * *************************Attributs****************************** */



    /*     * ***********************Methode static*************************** */
    public static function deamon_info() {
        $return = [];
        $return['log'] = 'easycontrol';
        $return['state'] = 'nok';
        
        $result = exec("ps -eo pid,command | grep 'bosch-xmpp' | grep -v grep | awk '{print $1}'");
        if ($result <> 0) {
            $return['state'] = 'ok';
        }
        $return['launchable'] = 'ok';
        return $return;
    }

    public static function deamon_start() {
        if(log::getLogLevel('easycontrol')==100){
            $_debug=true;
        } else{
            $_debug=false;
        }
        // log::add('easycontrol', 'debug', 'logLevel : ' . log::getLogLevel('easycontrol'));
        // log::add('easycontrol', 'info', 'Mode debug : ' . $_debug);
        self::deamon_stop();
        $deamon_info = self::deamon_info();
        if ($deamon_info['launchable'] != 'ok') {
            throw new Exception(__('Veuillez vérifier la configuration', __FILE__));
        }
        $serial = config::byKey('serialNumber','easycontrol');
        $access = config::byKey('accessKey','easycontrol');
        $password = config::byKey('password','easycontrol');
        $easycontrolHandle = ' bosch-xmpp --serial=' . $serial . ' --access-key=' . $access . ' --password="' . $password . '" easycontrol bridge';
        $cmd = 'if [ $(ps -ef | grep -v grep | grep "bosch-xmpp" | wc -l) -eq 0 ]; then ' . system::getCmdSudo() . $easycontrolHandle . ';echo "Démarrage bosch-xmpp bridge";sleep 1; fi';
        log::add('easycontrol', 'debug', str_replace($password,'****',$cmd));
        if ($_debug) {
            exec($cmd . ' >> ' . log::getPathToLog('easycontrol') . ' 2>&1 &');
        } else {
            $result = exec($cmd . ' > /dev/null 2>&1 &');
        }

        $i = 0;
        while ($i < 30) {
            $deamon_info = self::deamon_info();
            if ($deamon_info['state'] == 'ok') {
                break;
            }
            sleep(1);
            $i++;
        }
        if ($i >= 30) {
            log::add('easycontrol', 'error', 'Impossible de lancer le démon Easycontrol, relancez le démon en debug et vérifiez la log', 'unableStartDeamon');
            return false;
        }
        message::removeAll('easycontrol', 'unableStartDeamon');
        log::add('easycontrol', 'info', 'Bridge XMPP lancé');

        return true;
    }

    public static function deamon_stop() {
        $deamon_info = self::deamon_info();
        if ($deamon_info['state'] <> 'ok') {
            return true;
        }
        $pid = exec("ps -eo pid,command | grep 'bosch-xmpp' | grep -v grep | awk '{print $1}'");
        log::add('easycontrol', 'debug', 'pid=' . $pid);

        if ($pid) {
            system::kill($pid);
        }
        system::kill('bosch-xmpp');
        system::fuserk(3000);

        $check = self::deamon_info();
        $retry = 0;
        while ($deamon_info['state'] == 'ok') {
           $retry++;
            if ($retry > 10) {
                return;
            } else {
                sleep(1);
            }
        }
        return self::deamon_info();
    }

    public static function cron() {
        foreach (self::byType('easycontrol') as $easycontrol) {
            $cron_isEnable = $easycontrol->getConfiguration('cron_isEnable', 0);
            $autorefresh = $easycontrol->getConfiguration('autorefresh', '');
            $serial = config::byKey('serialNumber','easycontrol');
            $access = config::byKey('accessKey','easycontrol');
            $password = config::byKey('password','easycontrol');
            if ($easycontrol->getIsEnable() == 1 && $cron_isEnable == 1 && $serial != '' && $access != '' && $password != '' && $autorefresh != '') {
                try {
                    $c = new Cron\CronExpression($autorefresh, new Cron\FieldFactory);
                    if ($c->isDue()) {
                        try {
                            $easycontrol->getIndoorTemp();
                            $easycontrol->getIndoorHumidity();
                            $easycontrol->getOutdoorTemp();
                            $easycontrol->getHeatSetpoint();
                            $easycontrol->getUserMode();
                            $easycontrol->getAwayStatus();
                            $easycontrol->getActualSupplyTemp();
                            $easycontrol->getSystemPressure();
                            $easycontrol->getBoilerStatus();
                            $easycontrol->getcurrentProgram();
                            $easycontrol->refreshWidget();
                        } catch (Exception $exc) {
                            log::add('easycontrol', 'error', __('Error in ', __FILE__) . $easycontrol->getHumanName() . ' : yop' . $exc->getMessage());
                        }
                    }
                } catch (Exception $exc) {
                    log::add('easycontrol', 'error', __('Expression cron non valide pour ', __FILE__) . $easycontrol->getHumanName() . ' : ' . $autorefresh);
                }
            }
        }
    }

    public static function dependancy_info() {
        $return = array();
        $return['log'] = 'easycontrol_update';
        $return['progress_file'] = jeedom::getTmpFolder('easycontrol') . '/dependance';
        if (shell_exec('ls /usr/bin/bosch-xmpp 2>/dev/null | wc -l') == 1 || shell_exec('ls /usr/local/bin/bosch-xmpp 2>/dev/null | wc -l') == 1) {
            $state = 'ok';
        }else{
            $state = 'nok';
        }
        $return['state'] = $state;
        return $return;
    }

    public static function dependancy_install() {
        if (file_exists(jeedom::getTmpFolder('easycontrol') . '/dependance')) {
            return;
        }
        log::remove(__CLASS__ . '_update');
        $update=update::byTypeAndLogicalId('plugin','easycontrol');
        $ver=$update->getLocalVersion();
        $conf=$update->getConfiguration();
        shell_exec('echo "'."== Jeedom ".jeedom::version()." sur ".trim(shell_exec("lsb_release -d -s")).'/'.trim(shell_exec('dpkg --print-architecture')).'/'.trim(shell_exec('arch')).'/'.trim(shell_exec('getconf LONG_BIT'))."bits aka '".jeedom::getHardwareName()."' avec nodeJS ".trim(shell_exec('nodejs -v'))." et jsonrpc:".config::byKey('api::core::jsonrpc::mode', 'core', 'enable')." et easycontrol (".$conf['version'].") ".$ver.'" >> '.log::getPathToLog(__CLASS__ . '_update'));
        return array('script' => dirname(__FILE__) . '/../../resources/install_#stype#.sh ' . jeedom::getTmpFolder('easycontrol') . '/dependance', 'log' => log::getPathToLog(__CLASS__ . '_update'));
    }
    /*     * *********************Méthodes d'instance************************* */

    public function postInsert() {

    }

    public function preSave() {
        if ($this->getConfiguration('autorefresh') == '') {
            //$this->setConfiguration('autorefresh', '*/5 * * * *');
            $this->setConfiguration('autorefresh', '* * * * *');
	}
        if ($this->getConfiguration('cron_isEnable',"initial") == 'initial') {
            $this->setConfiguration('cron_isEnable', 1);
        }
        // Force la categorie "chauffage".
        $this->setCategory('heating', 1);
    }
    public function postSave() {
        if ($this->getIsEnable() == 1) {
            // Température de consigne (info).
            $order = $this->getCmd(null, 'order');
            if (!is_object($order)) {
                $order = new easycontrolCmd();
                $order->setIsVisible(0);
                $order->setUnite('°C');
                $order->setName(__('Consigne', __FILE__));
                $order->setConfiguration('historizeMode', 'none');
                $order->setIsHistorized(1);
            }
            $order->setDisplay('generic_type', 'THERMOSTAT_SETPOINT');
            $order->setEqLogic_id($this->getId());
            $order->setType('info');
            $order->setSubType('numeric');
            $order->setLogicalId('order');
            $order->setConfiguration('maxValue', 30);
            $order->setConfiguration('minValue', 18);
            $order->save();

            // Température de consigne (action)
            $thermostat = $this->getCmd(null, 'thermostat');
            if (!is_object($thermostat)) {
                $thermostat = new easycontrolCmd();
                $thermostat->setTemplate('dashboard', 'value');
                $thermostat->setTemplate('mobile', 'value');
                $thermostat->setUnite('°C');
                $thermostat->setName(__('Thermostat', __FILE__));
                $thermostat->setIsVisible(1);
            }
            $thermostat->setDisplay('generic_type', 'THERMOSTAT_SET_SETPOINT');
            $thermostat->setEqLogic_id($this->getId());
            $thermostat->setConfiguration('maxValue', 30);
            $thermostat->setConfiguration('minValue', 18);
            $thermostat->setType('action');
            $thermostat->setSubType('slider');
            $thermostat->setLogicalId('thermostat');
            $thermostat->setValue($order->getId());
            $thermostat->save();

            // Température de la pièce.
            $temperature = $this->getCmd(null, 'temperature');
            if (!is_object($temperature)) {
                $temperature = new easycontrolCmd();
                $temperature->setTemplate('dashboard', 'line');
                $temperature->setTemplate('mobile', 'line');
                $temperature->setName(__('Température', __FILE__));
                $temperature->setIsVisible(1);
                $temperature->setIsHistorized(1);
            }
            $temperature->setEqLogic_id($this->getId());
            $temperature->setType('info');
            $temperature->setSubType('numeric');
            $temperature->setLogicalId('temperature');
            $temperature->setUnite('°C');
            $temperature->setDisplay('generic_type', 'THERMOSTAT_TEMPERATURE');
            $temperature->save();
	
            // Température extérieure.	    
            $temperature_outdoor = $this->getCmd(null, 'temperature_outdoor');
            if (!is_object($temperature_outdoor)) {
                $temperature_outdoor = new easycontrolCmd();
                $temperature_outdoor->setTemplate('dashboard', 'line');
                $temperature_outdoor->setTemplate('mobile', 'line');
                $temperature_outdoor->setIsVisible(1);
                $temperature_outdoor->setIsHistorized(1);
                $temperature_outdoor->setConfiguration('historizeMode', 'none');
                $temperature_outdoor->setName(__('Température extérieure', __FILE__));
            }
            $temperature_outdoor->setEqLogic_id($this->getId());
            $temperature_outdoor->setType('info');
            $temperature_outdoor->setSubType('numeric');
            $temperature_outdoor->setLogicalId('temperature_outdoor');
            $temperature_outdoor->setUnite('°C');
            $temperature_outdoor->setDisplay('generic_type', 'THERMOSTAT_TEMPERATURE_OUTDOOR');
            $temperature_outdoor->save();

            // Humidité de la pièce
            $humidite = $this->getCmd(null, 'humidite');
            if (!is_object($humidite)) {
                $humidite = new easycontrolCmd();
                $humidite->setTemplate('dashboard', 'line');
                $humidite->setTemplate('mobile', 'line');
                $humidite->setIsVisible(1);
                $humidite->setIsHistorized(1);
                $humidite->setConfiguration('historizeMode', 'none');
                $humidite->setName(__('Humidité', __FILE__));
            }
            $humidite->setEqLogic_id($this->getId());
            $humidite->setType('info');
            $humidite->setSubType('numeric');
            $humidite->setLogicalId('humidite');
            $humidite->setUnite('%');
            $humidite->setDisplay('generic_type', 'THERMOSTAT_TEMPERATURE_HUMIDITE');
            $humidite->save();

            // Température eau de chauffage en sortie de chaudière.
            $heatingsupplytemp = $this->getCmd(null, 'heatingsupplytemp');
            if (!is_object($heatingsupplytemp)) {
                $heatingsupplytemp = new easycontrolCmd();
                $heatingsupplytemp->setTemplate('dashboard', 'line');
                $heatingsupplytemp->setTemplate('mobile', 'line');
                $heatingsupplytemp->setIsVisible(1);
                $heatingsupplytemp->setIsHistorized(1);
                $heatingsupplytemp->setConfiguration('historizeMode', 'none');
                $heatingsupplytemp->setName(__('Température eau de chauffage', __FILE__));
            }
            $heatingsupplytemp->setEqLogic_id($this->getId());
            $heatingsupplytemp->setType('info');
            $heatingsupplytemp->setSubType('numeric');
            $heatingsupplytemp->setLogicalId('heatingsupplytemp');
            $heatingsupplytemp->setUnite('°C');
            $heatingsupplytemp->setDisplay('generic_type', 'DONT');
            $heatingsupplytemp->save();
            
            // Etat Bruleur (info).
            $boiler = $this->getCmd(null, 'boiler');
            if (!is_object($boiler)) {
                $boiler = new easycontrolCmd();
                $boiler->setIsVisible(1);
                $boiler->setName(__('Etat bruleur', __FILE__));
                $boiler->setIsHistorized(0);
            }
            $boiler->setDisplay('generic_type', 'DONT');
            $boiler->setEqLogic_id($this->getId());
            $boiler->setType('info');
            $boiler->setSubType('string');
            $boiler->setLogicalId('boiler');
            $boiler->save();
            
            // Pression en bar (info).
            $systempressure = $this->getCmd(null, 'systempressure');
            if (!is_object($systempressure)) {
                $systempressure = new easycontrolCmd();
                $systempressure->setIsVisible(1);
                $systempressure->setUnite('bar');
                $systempressure->setName(__('Pression', __FILE__));
                $systempressure->setTemplate('dashboard', 'line');
                $systempressure->setTemplate('mobile', 'line');
                $systempressure->setIsHistorized(0);
            }
            $systempressure->setDisplay('generic_type', 'DONT');
            $systempressure->setEqLogic_id($this->getId());
            $systempressure->setType('info');
            $systempressure->setSubType('numeric');
            $systempressure->setLogicalId('systempressure');
            $systempressure->save();
            
            
            $clockmode = $this->getCmd(null, 'clockmode');
            if (is_object($clockmode)) {
                $clockmode->remove();
            }
            
            // Commande info associée aux deux modes
            // Mode programme et Mode manuel
            $mode = $this->getCmd(null, 'mode');
            if (!is_object($mode)) {
                $mode = new easycontrolCmd();
                $mode->setName(__('Mode', __FILE__));
                $mode->setIsVisible(0);
            }
            $mode->setDisplay('generic_type', 'THERMOSTAT_MODE');
            $mode->setEqLogic_id($this->getId());
            $mode->setType('info');
            $mode->setSubType('string');
            $mode->setLogicalId('mode');
            $mode->save();

            // Commande action mode programme
            $clock = $this->getCmd(null, 'clock');
            if (!is_object($clock)) {
                $clock = new easycontrolCmd();
                $clock->setLogicalId('clock');
                $clock->setIsVisible(1);
                $clock->setTemplate('dashboard', 'usermode');
                $clock->setTemplate('mobile', 'usermode');
                $clock->setName(__('Mode horloge', __FILE__));
            }
            $clock->setType('action');
            $clock->setSubType('other');
            $clock->setOrder(1);
            $clock->setDisplay('generic_type', 'THERMOSTAT_SET_MODE');
            $clock->setEqLogic_id($this->getId());
            $clock->setValue($mode->getId());
            $clock->save();

            // Commande action mode manuel.
            $manual = $this->getCmd(null, 'manual');
            if (!is_object($manual)) {
                $manual = new easycontrolCmd();
                $manual->setLogicalId('manual');
                $manual->setIsVisible(1);
                $manual->setTemplate('dashboard', 'usermode');
                $manual->setTemplate('mobile', 'usermode');
                $manual->setName(__('Mode manuel', __FILE__));
            }
            $manual->setType('action');
            $manual->setSubType('other');
            $manual->setOrder(1);
            $manual->setDisplay('generic_type', 'THERMOSTAT_SET_MODE');
            $manual->setEqLogic_id($this->getId());
            $manual->setValue($mode->getId());
            $manual->save();
            
            // Commande info associée au mode absence
            // Absent/Présent
            $awaymode = $this->getCmd(null, 'absence');
            if (!is_object($awaymode)) {
                $awaymode = new easycontrolCmd();
                $awaymode->setName(__('Etat Absence', __FILE__));
                $awaymode->setIsVisible(0);
            }
            $awaymode->setEqLogic_id($this->getId());
            $awaymode->setType('info');
            $awaymode->setSubType('string');
            $awaymode->setLogicalId('absence');
            $awaymode->save();

            // Commande action présence
            $home = $this->getCmd(null, 'home');
            if (!is_object($home)) {
                $home = new easycontrolCmd();
                $home->setLogicalId('home');
                $home->setIsVisible(1);
                $home->setTemplate('dashboard', 'userpresence');
                $home->setTemplate('mobile', 'userpresence');
                $home->setName(__('Présent', __FILE__));
            }
            $home->setType('action');
            $home->setSubType('other');
            $home->setOrder(1);
            $home->setDisplay('generic_type', 'THERMOSTAT_SET_AWAY');
            $home->setEqLogic_id($this->getId());
            $home->setValue($awaymode->getId());
            $home->save();

            // Commande action absence
            $away = $this->getCmd(null, 'away');
            if (!is_object($away)) {
                $away = new easycontrolCmd();
                $away->setLogicalId('away');
                $away->setIsVisible(1);
                $away->setTemplate('dashboard', 'userpresence');
                $away->setTemplate('mobile', 'userpresence');
                $away->setName(__('Absent', __FILE__));
            }
            $away->setType('action');
            $away->setSubType('other');
            $away->setOrder(1);
            $away->setDisplay('generic_type', 'THERMOSTAT_SET_AWAY');
            $away->setEqLogic_id($this->getId());
            $away->setValue($awaymode->getId());
            $away->save();
            
            //Info programme actif
            $schedule = $this->getCmd(null, 'schedule');
            if (!is_object($schedule)) {
                $schedule = new easycontrolCmd();
                $schedule->setIsVisible(1);
                $schedule->setName(__('Programme Actif', __FILE__));
            }
            $schedule->setLogicalId('schedule');
            $schedule->setType('info');
            $schedule->setSubType('string');
            $schedule->setEqLogic_id($this->getId());
            $schedule->save();  
            
            //Liste déroulante des programmes
            $listschedule = $this->getCmd(null, 'listschedule');
            if (!is_object($listschedule)) {
                $listschedule = new easycontrolCmd();
                $listschedule->setIsVisible(1);
                $listschedule->setName(__('Programmes', __FILE__));
            }
            $listschedule->setType('action');
            $listschedule->setSubType('select');
            $listschedule->setLogicalId('listschedule');
            $listprograms = $this->getPrograms('list');
            $listschedule->setConfiguration('listValue', $listprograms);
            $listschedule->setEqLogic_id($this->getId());
            $listschedule->setValue($schedule->getId());
            $listschedule->save();
            
        } else {
            // TODO supprimer crons et listeners
        }
    }

    public function preUpdate() {

    }

    public function postUpdate() {

    }

    public function preRemove() {

    }

    public function postRemove() {

    }

    /*
     * Non obligatoire mais permet de modifier l'affichage du widget si vous en avez besoin
      public function toHtml($_version = 'dashboard') {

      }
     */

    /*
     * Non obligatoire mais ca permet de déclencher une action après modification de variable de configuration
    public static function postConfig_<Variable>() {
    }
     */

    /*
     * Non obligatoire mais ca permet de déclencher une action avant modification de variable de configuration
    public static function preConfig_<Variable>() {
    }
     */

    public function getIndoorTemp() {
        log::add('easycontrol', 'debug', 'Running getIndoorTemp');
        $url = 'http://127.0.0.1:3000/bridge/zones/zn1/temperatureActual';
        $request_http = new com_http($url);
        $request_http->setNoReportError(true);
        $json_string = $request_http->exec(30);
        if ($json_string === false) {
            log::add('easycontrol', 'debug', 'Problème de lecture status');
            $request_http->setNoReportError(false);
            $json_string = $request_http->exec(30,1);
            return;
        }
        $parsed_json = json_decode($json_string, true);
        $inhousetemp = floatval($parsed_json['value']);
        if ( $inhousetemp >= 5 && $inhousetemp <= 30) {
            log::add('easycontrol', 'info', 'Température intérieure : ' . $inhousetemp.'°');
            $this->checkAndUpdateCmd('temperature', $inhousetemp);
        } else {
            log::add('easycontrol', 'debug', 'temp incorrecte ' . $inhousetemp);
        }
    }
    public function getcurrentProgram() {
        log::add('easycontrol', 'debug', 'Running getCurrentProgram');
        $url = 'http://127.0.0.1:3000/bridge/zones/zn1/clockProgram';
        $request_http = new com_http($url);
        $request_http->setNoReportError(true);
        $json_string = $request_http->exec(30);
        if ($json_string === false) {
            log::add('easycontrol', 'debug', 'Problème de lecture status');
            $request_http->setNoReportError(false);
            $json_string = $request_http->exec(30,1);
            return;
        }
        $parsed_json = json_decode($json_string, true);
        $programActual = floatval($parsed_json['value']);
        $programList = $this->getPrograms();
        foreach($programList as $programInfo){
            if($programActual == $programInfo['id']){
                log::add('easycontrol', 'info', 'Programme en cours : ' . base64_decode($programInfo['name']));
                $this->getCmd(null, 'schedule')->event(base64_decode($programInfo['name']));
            }
        }
    }
    public function getPrograms($type='table') {
        $programListItems = '';
        log::add('easycontrol', 'debug', 'Running getPrograms');
        $url = 'http://127.0.0.1:3000/bridge/programs/list';
        $request_http = new com_http($url);
        $request_http->setNoReportError(true);
        $json_string = $request_http->exec(30);
        if ($json_string === false) {
            log::add('easycontrol', 'debug', 'Problème de lecture status');
            $request_http->setNoReportError(false);
            $json_string = $request_http->exec(30,1);
            return;
        }
        $parsed_json = json_decode($json_string, true);
        $programList = $parsed_json['value'];
        if($type=='table'){
            return $programList;
        } else {
            foreach($programList as $programInfo){
                $programListItems = $programListItems.'program-'.$programInfo['id']."|".base64_decode($programInfo['name']).";";
            }
            return substr($programListItems,0,-1);
        }        
    }
    public function getIndoorHumidity() {
        log::add('easycontrol', 'debug', 'Running getIndoorTemp');
        $url = 'http://127.0.0.1:3000/bridge/zones/zn1/humidity';
        $request_http = new com_http($url);
        $request_http->setNoReportError(true);
        $json_string = $request_http->exec(30);
        if ($json_string === false) {
            log::add('easycontrol', 'debug', 'Problème de lecture status');
            $request_http->setNoReportError(false);
            $json_string = $request_http->exec(30,1);
            return;
        }
        $parsed_json = json_decode($json_string, true);
        $inhousehum = floatval($parsed_json['value']);
        $this->checkAndUpdateCmd('humidite', $inhousehum);
        log::add('easycontrol', 'info', 'Humidité : ' . $inhousehum.'%');
    }
    public function getBoilerStatus() {
        log::add('easycontrol', 'debug', 'Running getUserMode');
        $url = 'http://127.0.0.1:3000/bridge/zones/zn1/status';
        $request_http = new com_http($url);
        $request_http->setNoReportError(true);
        $json_string = $request_http->exec(30);
        if ($json_string === false) {
            log::add('easycontrol', 'debug', 'Problème de lecture status');
            $request_http->setNoReportError(false);
            $json_string = $request_http->exec(30,1);
            return;
        }
        $parsed_json = json_decode($json_string, true);
        $boilerStatus = $parsed_json['value'];

        $existingState = array('heat request' => __('Chauffage', __FILE__), 'idle' => __('Standby', __FILE__));
        foreach ($existingState as $BoilerStateId => $BoilerStateName) {
            if ($boilerStatus == $BoilerStateId) {
                log::add('easycontrol', 'info', 'Etat Bruleur : ' . $BoilerStateName);
                $this->getCmd(null, 'boiler')->event($BoilerStateName);
            }
        }
    }
    public function getHeatSetpoint() {
        log::add('easycontrol', 'debug', 'Running getHeatSetpoint');
        $url = 'http://127.0.0.1:3000/bridge/zones/zn1/temperatureHeatingSetpoint';
        $request_http = new com_http($url);
        $request_http->setNoReportError(true);
        $json_string = $request_http->exec(30);
        if ($json_string === false) {
            log::add('easycontrol', 'debug', 'Problème de lecture status');
            $request_http->setNoReportError(false);
            $json_string = $request_http->exec(30,1);
            return;
        }
        $parsed_json = json_decode($json_string, true);
        $tempsetpoint = floatval($parsed_json['value']);
        if ( $tempsetpoint >= 5 && $tempsetpoint <= 30) {
            log::add('easycontrol', 'info', 'Consigne : ' . $tempsetpoint);
            $this->checkAndUpdateCmd('order', $tempsetpoint);
        } else {
            log::add('easycontrol', 'debug', 'tempsetpoint incorrecte ' . $tempsetpoint);
        }
     }
     public function getUserMode(){
        log::add('easycontrol', 'debug', 'Running getUserMode');
        $url = 'http://127.0.0.1:3000/bridge/zones/zn1/userMode';
        $request_http = new com_http($url);
        $request_http->setNoReportError(true);
        $json_string = $request_http->exec(30);
        if ($json_string === false) {
            log::add('easycontrol', 'debug', 'Problème de lecture status');
            $request_http->setNoReportError(false);
            $json_string = $request_http->exec(30,1);
            return;
        }
        $parsed_json = json_decode($json_string, true);
        $currentUserMode = $parsed_json['value'];

        $existingModes = array('manual' => __('Mode manuel', __FILE__), 'clock' => __('Mode horloge', __FILE__));
        foreach ($existingModes as $modeId => $modeName) {
            if ($currentUserMode == $modeId) {
                log::add('easycontrol', 'info', 'Mode utilisateur : ' . $modeName);
                log::add('easycontrol', 'debug', 'evenement mode value = '.$modeName);
                $this->getCmd(null, 'mode')->event($modeName);
            }
        }
    }
   
    public function getAwayStatus(){
        log::add('easycontrol', 'debug', 'Running getAwayStatus');
        $url = 'http://127.0.0.1:3000/bridge/system/awayMode/enabled';
        $request_http = new com_http($url);
        $request_http->setNoReportError(true);
        $json_string = $request_http->exec(30);
        if ($json_string === false) {
            log::add('easycontrol', 'debug', 'Problème de lecture status');
            $request_http->setNoReportError(false);
            $json_string = $request_http->exec(30,1);
            return;
        }
        $parsed_json = json_decode($json_string, true);
        $currentAwayMode = $parsed_json['value'];
        if($currentAwayMode == 'false'){
            $remapState = 'home';
        } else{
            $remapState = 'away';
        }      
        $existingState = array('away' => __('Absent', __FILE__), 'home' => __('Présent', __FILE__));
        foreach ($existingState as $stateId => $stateName) {
            if ($remapState == $stateId) {
                log::add('easycontrol', 'info', 'Mode absence : ' . $stateName);
                log::add('easycontrol', 'debug', 'evenement mode absence = '.$stateName);
                $this->getCmd(null, 'absence')->event($stateName);
            }
        }
    }

    public function getOutdoorTemp() {
        log::add('easycontrol', 'debug', 'Running getOutdoorTemp');
        $url = 'http://127.0.0.1:3000/bridge/system/sensors/temperatures/outdoor_t1';
        $request_http = new com_http($url);
        $request_http->setNoReportError(true);
        $json_string = $request_http->exec(30);
        if ($json_string === false) {
            log::add('easycontrol', 'debug', 'Problème de lecture outdoortemp');
            $request_http->setNoReportError(false);
            $json_string = $request_http->exec(30,1);
            return;
        }
        $parsed_json = json_decode($json_string, true);
        $outdoortemp = floatval($parsed_json['value']);
        if ( $outdoortemp >= -40 && $outdoortemp <= 50) {
            log::add('easycontrol', 'info', 'Température extérieure : ' . $outdoortemp.'°');
            $this->checkAndUpdateCmd('temperature_outdoor', $outdoortemp);
        } else {
            log::add('easycontrol', 'debug', 'outdoortemp incorrecte ' . $outdoortemp);
        }
    }

    public function getActualSupplyTemp() {
        log::add('easycontrol', 'debug', 'Running getActualSupplyTemp');
        $url = 'http://127.0.0.1:3000/bridge/heatingCircuits/hc1/supplyTemperatureSetpoint';
        $request_http = new com_http($url);
        $request_http->setNoReportError(true);
        $json_string = $request_http->exec(30);
        if ($json_string === false) {
            log::add('easycontrol', 'debug', 'Problème de lecture actualSupplyTemp');
            $request_http->setNoReportError(false);
            $json_string = $request_http->exec(30,1);
            return;
        }
        $parsed_json = json_decode($json_string, true);
        $supplytemp = floatval($parsed_json['value']);
        if ( $supplytemp >= 0 && $supplytemp <= 100) {
            log::add('easycontrol', 'info', 'Température eau de chauffage : ' . $supplytemp.'°');
            $this->checkAndUpdateCmd('heatingsupplytemp', $supplytemp);
        } else {
            log::add('easycontrol', 'debug', 'supplytemp incorrecte ' . $supplytemp);
        }
    }

    public function getSystemPressure() {
        log::add('easycontrol', 'debug', 'Running getPressure');
        $url = 'http://127.0.0.1:3000/bridge/system/appliance/systemPressure';
        $request_http = new com_http($url);
        $request_http->setNoReportError(true);
        $json_string = $request_http->exec(30);
        if ($json_string === false) {
            log::add('easycontrol', 'debug', 'Problème de lecture Pressure');
            $request_http->setNoReportError(false);
            $json_string = $request_http->exec(30,1);
            return;
        }
        $parsed_json = json_decode($json_string, true);
        $pressure = floatval($parsed_json['value']);
        if ( $pressure >= 0 && $pressure <= 30 ) {
            log::add('easycontrol', 'info', 'Pression : ' . $pressure.' bar');
            $this->checkAndUpdateCmd('systempressure', $pressure);
        } else {
            log::add('easycontrol', 'debug', 'Pression incorrecte ' . $pressure);
        }
    }

    public function writeThermostatData($endpoint, $data) {
        $easycontrolHandle = " bosch-xmpp --serial=" . config::byKey('serialNumber','easycontrol') . " --access-key=" . config::byKey('accessKey','easycontrol') . " --password=" . config::byKey('password','easycontrol') . " easycontrol put " .$endpoint. " '". $data ."'";
        $result = exec(system::getCmdSudo() . $easycontrolHandle);
        $parsed_result = json_decode($result, true);
        if($parsed_result['status'] == 'ok'){
            log::add('easycontrol', 'debug', 'commande envoyée au Thermostat = '.$endpoint.' '.$data);
            $this->refreshWidget();
        } else{
            log::add('easycontrol', 'error', 'la commande a échoué = '.$endpoint.' '.$data);
        }
    }

    public function executeMode($_name) {
        log::add('easycontrol', 'debug', 'début de executeMode name = '. $_name);
        $existingModes = array('manual' => __('Mode manuel', __FILE__), 'clock' => __('Mode horloge', __FILE__));
        foreach ($existingModes as $modeId => $modeName) {
            if ($_name == $modeName) {
                log::add('easycontrol', 'debug', 'ecriture dans le thermostat value = '.$modeId);
                $this->writeThermostatData('/zones/zn1/userMode', '{ "value" : "' .$modeId . '" }');
            }
        }
        $this->getCmd(null, 'mode')->event($_name);
    }
    public function executeAwayMode($_name) {
        log::add('easycontrol', 'debug', 'début de changeState name = '. $_name);
        $existingState = array('away' => __('Absent', __FILE__), 'home' => __('Présent', __FILE__));
        foreach ($existingState as $StateId => $stateName) {
            if ($_name == $stateName) {
                if($StateId == 'home'){
                    $remapState = 'false';
                } else{
                    $remapState = 'true';
                }
                log::add('easycontrol', 'debug', 'ecriture dans le thermostat value = '.$remapState);
                $this->writeThermostatData('/system/awayMode/enabled', '{ "value" : "' .$remapState . '" }');
            }
        }
        $this->getCmd(null, 'etat_presence')->event($_name);
    }
    public function setProgram($programSet) {
        $programSet = substr($programSet,-1);
        $this->writeThermostatData('/zones/zn1/clockProgram', '{ "value" : ' .$programSet . ' }');
        log::add('easycontrol', 'info', 'ecriture dans le thermostat programme = '.$programSet);
    }
    public function setTemperature($value) {
        $this->writeThermostatData('/zones/zn1/manualTemperatureHeating', '{ "value" : ' .$value . ' }');
        log::add('easycontrol', 'info', 'ecriture dans le thermostat temperature = '.$value.'°');
    }
    public function runtimeByDay($_startDate = null, $_endDate = null) {
        $actifCmd = $this->getCmd(null, 'actif');
        if (!is_object($actifCmd)) {
            return array();
        }
        $return = array();
        $prevValue = 0;
        $prevDatetime = 0;
        $day = null;
        $day = strtotime($_startDate . ' 00:00:00 UTC');
        $endDatetime = strtotime($_endDate . ' 00:00:00 UTC');
        while ($day <= $endDatetime) {
            $return[date('Y-m-d', $day)] = array($day * 1000, 0);
            $day = $day + 3600 * 24;
        }
        foreach ($actifCmd->getHistory($_startDate, $_endDate) as $history) {
            if (date('Y-m-d', strtotime($history->getDatetime())) != $day && $prevValue == 1 && $day != null) {
                if (strtotime($day . ' 23:59:59') > $prevDatetime) {
                    $return[$day][1] += (strtotime($day . ' 23:59:59') - $prevDatetime) / 60;
                }
                $prevDatetime = strtotime(date('Y-m-d 00:00:00', strtotime($history->getDatetime())));
            }
            $day = date('Y-m-d', strtotime($history->getDatetime()));
            if (!isset($return[$day])) {
                $return[$day] = array(strtotime($day . ' 00:00:00 UTC') * 1000, 0);
            }
            if ($history->getValue() == 1 && $prevValue == 0) {
                $prevDatetime = strtotime($history->getDatetime());
                $prevValue = 1;
            }
            if ($history->getValue() == 0 && $prevValue == 1) {
                if ($prevDatetime > 0 && strtotime($history->getDatetime()) > $prevDatetime) {
                    $return[$day][1] += (strtotime($history->getDatetime()) - $prevDatetime) / 60;
                }
                $prevValue = 0;
            }
        }
        return $return;
    }
}

class easycontrolCmd extends cmd {
    /*     * *************************Attributs****************************** */


    /*     * ***********************Methode static*************************** */


    /*     * *********************Methode d'instance************************* */

    /**
     * Indique que les commandes obligatoires ne peuvent pas être supprimée.
     * @return boolean
     */
    public function dontRemoveCmd() {
        return true;
    }

    public function execute($_options = array()) {
        log::add('easycontrol', 'debug', print_r($_options, true));
        if ($this->getType() == '') {
            return '';
        }
        $eqLogic = $this->getEqlogic();
        $action = $this->getLogicalId();
        if ($action == 'clock' || $action =='manual') {
            log::add('easycontrol', 'debug', 'action set mode ' . $action);
            $eqLogic->executeMode($this->getName());
            return true;
        }
        else if ($action == 'listschedule') {
            log::add('easycontrol', 'debug', 'action set program ' . $_options['select']);
            $eqLogic->setProgram($_options['select']);
            return true;
        }
        else if ($action == 'home' || $action =='away') {
            log::add('easycontrol', 'debug', 'action set absence ' . $action);
            $eqLogic->executeAwayMode($this->getName());
            return true;
        } else if ($action == 'thermostat') {
            log::add('easycontrol', 'debug', 'action thermostat');
            // log::add('easycontrol', 'debug', print_r($_options, true));
            if (!isset($_options['slider']) || $_options['slider'] == '' || !is_numeric(intval($_options['slider']))) {
                log::add('easycontrol', 'debug', 'mauvaise valeur du slider dans execute thermostat');
            }
            if ($_options['slider'] > 30) {
                $_options['slider'] = 30;
            }
            if ($_options['slider'] < 18) {
                $_options['slider'] = 18;
            }
            $eqLogic->getCmd(null, 'order')->event($_options['slider']);

            $eqLogic->setTemperature(floatval($_options['slider']));
            return true;
        }
    }

    /*     * **********************Getteur Setteur*************************** */
}
