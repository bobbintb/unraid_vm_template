<?PHP
/* Copyright 2005-2025, Lime Technology
 * Copyright 2012-2025, Bergware International.
 * Copyright 2015-2021, Derek Macias, Eric Schultz, Jon Panozzo.
 * Copyright 2025, Armando Anglesey.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 */
?>
<?
$docroot ??= ($_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp');
require_once "$docroot/webGui/include/Helpers.php";
require_once "$docroot/webGui/include/Custom.php";
require_once "$docroot/plugins/dynamix.vm.manager/include/libvirt_helpers.php";

// add translations
if (substr($_SERVER['REQUEST_URI'],0,4) != '/VMs') {
	$_SERVER['REQUEST_URI'] = 'vms';
	require_once "$docroot/webGui/include/Translations.php";
}

$arrValidMachineTypes = getValidMachineTypes();
$arrValidPCIDevices   = getValidPCIDevices();
$arrValidGPUDevices   = getValidGPUDevices();
$arrValidAudioDevices = getValidAudioDevices();
$arrValidSoundCards   = getValidSoundCards();
$arrValidOtherDevices = getValidOtherDevices();
$arrValidUSBDevices   = getValidUSBDevices();
$arrValidDiskDrivers  = getValidDiskDrivers();
$arrValidDiskBuses    = getValidDiskBuses();
$arrValidDiskDiscard  = getValidDiskDiscard();
$arrValidCdromBuses   = getValidCdromBuses();
$arrValidVNCModels    = getValidVNCModels();
$arrValidProtocols    = getValidVMRCProtocols();
$arrValidKeyMaps      = getValidKeyMaps();
$arrValidNetworks     = getValidNetworks();
$arrNumaInfo          = getNumaInfo();
$strCPUModel          = getHostCPUModel();
$templateslocation    = "/boot/config/plugins/dynamix.vm.manager/savedtemplates.json";

// UnRAID image config -------------------------------------------------------
$strUnRAIDConfig = "/boot/config/plugins/dynamix.vm.manager/unraid.cfg";
$strStateFile    = "/tmp/unraid_setup.state";
$arrUnRAIDConfig = [];

if (file_exists($strUnRAIDConfig)) {
	$arrUnRAIDConfig = parse_ini_file($strUnRAIDConfig);
} elseif (!file_exists(dirname($strUnRAIDConfig))) {
	@mkdir(dirname($strUnRAIDConfig), 0777, true);
}

if (!function_exists('saveUnRAIDConfig')) {
	function saveUnRAIDConfig($config) {
		$strUnRAIDConfig = "/boot/config/plugins/dynamix.vm.manager/unraid.cfg";
		$text = '';
		foreach ($config as $key => $value) if ($value !== '') $text .= "$key=\"$value\"\n";
		file_put_contents($strUnRAIDConfig, $text);
	}
}

if (!function_exists('getSetupState')) {
	function getSetupState() {
		$strStateFile = "/tmp/unraid_setup.state";
		if (file_exists($strStateFile)) return trim(file_get_contents($strStateFile));
		return '';
	}
}

// Sort versions descending
uksort($arrUnRAIDVersions, fn($a, $b) => version_compare($b, $a));

// Populate localpath/valid from saved config
foreach ($arrUnRAIDConfig as $strID => $strLocalpath) {
	if (array_key_exists($strID, $arrUnRAIDVersions)) {
		$arrUnRAIDVersions[$strID]['localpath'] = $strLocalpath;
		clearstatcache();
		if (file_exists($strLocalpath) && ($arrUnRAIDConfig['pending_version'] ?? '') !== $strID) {
			$arrUnRAIDVersions[$strID]['valid'] = '1';
		}
	}
}
// ---------------------------------------------------------------------------

// get MAC address of wireless interface (if existing)
$mac = file_exists('/sys/class/net/wlan0/address') ? trim(file_get_contents('/sys/class/net/wlan0/address')) : '';

if (is_file($templateslocation)) {
	$arrAllTemplates["User-templates"] = "";
	$ut = json_decode(file_get_contents($templateslocation), true);
	$arrAllTemplates = array_merge($arrAllTemplates, $ut);
}

$arrUnRAIDVersion   = reset($arrUnRAIDVersions);
$strUnRAIDVersionID = key($arrUnRAIDVersions);

$arrConfigDefaults = [
	'template' => [
		'name'    => $strSelectedTemplate,
		'icon'    => $arrAllTemplates[$strSelectedTemplate]['icon'],
		'os'      => $arrAllTemplates[$strSelectedTemplate]['os'],
		'storage' => 'default',
		'unraid'  => $strUnRAIDVersionID
	],
	'domain' => [
		'name'          => $strSelectedTemplate,
		'persistent'    => 1,
		'uuid'          => $lv->domain_generate_uuid(),
		'clock'         => 'utc',
		'arch'          => 'x86_64',
		'machine'       => getLatestMachineType('q35'),
		'mem'           => 4096 * 1024,
		'maxmem'        => 4096 * 1024,
		'password'      => '',
		'cpumode'       => 'host-passthrough',
		'cpumigrate'    => 'on',
		'vcpus'         => 1,
		'vcpu'          => [0],
		'hyperv'        => 0,
		'ovmf'          => 1,
		'usbmode'       => 'usb3',
		'memoryBacking' => '{"nosharepages":{}}'
	],
	'media' => [
		'cdrom'      => '',
		'cdrombus'   => '',
		'drivers'    => '',
		'driversbus' => ''
	],
	'disk' => [
		[
			'image'    => $arrUnRAIDVersion['localpath'],
			'size'     => '',
			'driver'   => 'raw',
			'dev'      => 'hda',
			'readonly' => 1,
			'boot'     => 1
		]
	],
	'gpu' => [
		[
			'id'             => 'virtual',
			'protocol'       => 'vnc',
			'autoport'       => 'yes',
			'model'          => 'qxl',
			'keymap'         => 'en-us',
			'port'           => 5900,
			'wsport'         => 5700,
			'copypaste'      => 'no',
			'render'         => 'auto',
			'DisplayOptions' => ''
		]
	],
	'audio'  => [['id' => '']],
	'pci'    => [],
	'nic'    => [
		[
			'network' => $domain_bridge,
			'mac'     => $lv->generate_random_mac_addr(),
			'model'   => 'virtio-net'
		]
	],
	'usb'    => [],
	'shares' => [['source' => '', 'target' => '', 'mode' => '']]
];

$hdrXML = "<?xml version='1.0' encoding='UTF-8'?>\n";
$debug  = false;

// Merge in template overrides
if ($arrAllTemplates[$strSelectedTemplate] && $arrAllTemplates[$strSelectedTemplate]['overrides']) {
	$arrConfigDefaults = array_replace_recursive($arrConfigDefaults, $arrAllTemplates[$strSelectedTemplate]['overrides']);
}

// POST: delete UnRAID image -------------------------------------------------
if (isset($_POST['delete_version'])) {
	$arrDeleteUnRAID = [];
	if (array_key_exists($_POST['delete_version'], $arrUnRAIDVersions)) {
		$arrDeleteUnRAID = $arrUnRAIDVersions[$_POST['delete_version']];
	}
	if (empty($arrDeleteUnRAID)) {
		$reply = ['error' => 'Unknown version: ' . $_POST['delete_version']];
	} else {
		if (!empty($arrDeleteUnRAID['localpath'])) {
			@unlink($arrDeleteUnRAID['localpath']);
			@unlink($arrDeleteUnRAID['localpath'] . '.tmp');
			@unlink($arrDeleteUnRAID['localpath'] . '.log');
		}
		exec('pkill -f "UnRAID_' . $_POST['delete_version'] . '_install.sh"');
		exec('pkill -f "wget.*' . $_POST['delete_version'] . '"');
		unset($arrUnRAIDConfig[$_POST['delete_version']]);
		if (($arrUnRAIDConfig['pending_version'] ?? '') === $_POST['delete_version']) {
			unset($arrUnRAIDConfig['pending_version'], $arrUnRAIDConfig['pending_name'],
			      $arrUnRAIDConfig['pending_download_path'], $arrUnRAIDConfig['pending_size']);
			@unlink($strStateFile);
			@unlink('/tmp/UnRAID_' . $_POST['delete_version'] . '_install.sh');
		}
		saveUnRAIDConfig($arrUnRAIDConfig);
		$reply = ['status' => 'ok'];
	}
	echo json_encode($reply);
	exit;
}

// POST: reset pending setup -------------------------------------------------
if (isset($_POST['reset_setup'])) {
	@header('Content-Type: application/json');
	$pending_version = $arrUnRAIDConfig['pending_version'] ?? '';
	if ($pending_version && array_key_exists($pending_version, $arrUnRAIDVersions)) {
		$arrDownloadUnRAID = $arrUnRAIDVersions[$pending_version];
		$strDownloadPath   = $arrUnRAIDConfig['pending_download_path'] ?? '';
		if ($strDownloadPath) {
			$strCleanUrl = explode('?', $arrDownloadUnRAID['url'])[0];
			@unlink($strDownloadPath . basename($strCleanUrl));
			@unlink($strDownloadPath . basename($strCleanUrl, 'zip') . 'img.tmp');
			@unlink($strDownloadPath . basename($strCleanUrl) . '.log');
		}
		exec('pkill -f "UnRAID_' . $pending_version . '_install.sh"');
		@exec("rm -rf " . escapeshellarg('/tmp/UNRAID_' . $pending_version));
	}
	unset($arrUnRAIDConfig['pending_version'], $arrUnRAIDConfig['pending_name'],
	      $arrUnRAIDConfig['pending_download_path'], $arrUnRAIDConfig['pending_size']);
	saveUnRAIDConfig($arrUnRAIDConfig);
	@unlink($strStateFile);
	echo json_encode(['status' => 'ok']);
	exit;
}

// POST: download UnRAID image -----------------------------------------------
if (isset($_POST['download_path'])) {
	@header('Content-Type: application/json');
	$arrDownloadUnRAID = [];
	if (array_key_exists($_POST['download_version'], $arrUnRAIDVersions)) {
		$arrDownloadUnRAID = $arrUnRAIDVersions[$_POST['download_version']];
	}
	if (empty($arrDownloadUnRAID)) {
		$reply = ['error' => _('Unknown version').': ' . $_POST['download_version']];
	} elseif (empty($_POST['download_path'])) {
		$reply = ['error' => _('Please choose a folder the UnRAID image will download to')];
	} else {
		$boolCheckOnly = !empty($_POST['checkonly']);

		if (!$boolCheckOnly) {
			@mkdir($_POST['download_path'], 0777, true);
			$_POST['download_path'] = realpath($_POST['download_path']) . '/';
			$img_size_gb    = intval($_POST['download_size'] ?? 2);
			$img_size_bytes = $img_size_gb * 1024 * 1024 * 1024;
			if (disk_free_space($_POST['download_path']) < ($arrDownloadUnRAID['size'] + $img_size_bytes + 100*1024*1024)) {
				echo json_encode(['error' => _('Not enough free space')]);
				exit;
			}
			$arrUnRAIDConfig['pending_version']       = $_POST['download_version'];
			$arrUnRAIDConfig['pending_name']          = $_POST['vm_name'];
			$arrUnRAIDConfig['pending_download_path'] = $_POST['download_path'];
			$arrUnRAIDConfig['pending_size']          = $img_size_gb;
			saveUnRAIDConfig($arrUnRAIDConfig);
		}

		$strInstallScript      = '/tmp/UnRAID_' . $_POST['download_version'] . '_install.sh';
		$strInstallScriptPgrep = '-f "UnRAID_' . $_POST['download_version'] . '_install.sh"';
		$strDownloadPath       = $arrUnRAIDConfig['pending_download_path'] ?? $_POST['download_path'];
		$strCleanUrl           = explode('?', $arrDownloadUnRAID['url'])[0];
		$strZipFile            = $strDownloadPath . basename($strCleanUrl);
		$strLogFile            = $strZipFile . '.log';
		$strImgFile            = $strDownloadPath . basename($strCleanUrl, 'zip') . 'img';
		$strImgTmpFile         = $strImgFile . '.tmp';
		$strExtractTmpDir      = '/tmp/UNRAID_' . $_POST['download_version'];
		$img_size_gb           = $arrUnRAIDConfig['pending_size'] ?? 2;

		$strAllCmd = <<<EOD
		#!/bin/bash

		set -e

		update_state() {
		  echo "\$1" > "{$strStateFile}"
		}

		error_exit() {
		  update_state "Error: \$1"
		  exit 1
		}

		trap 'error_exit "Script interrupted"' INT TERM
		trap 'RESULT=\$?; if [ \$RESULT -ne 0 ]; then error_exit "Command failed with code \$RESULT. Check log."; fi' EXIT

		{
		  if [ ! -f "{$strImgFile}" ]; then
		    update_state "Downloading"
		    wget -nv -c -O "{$strZipFile}" "{$arrDownloadUnRAID['url']}" || error_exit "Download failed"
		    update_state "Downloading ... 100%"
		    sleep 1

		    update_state "Extracting"
		    mkdir -p "{$strExtractTmpDir}"
		    unzip -o "{$strZipFile}" -d "{$strExtractTmpDir}" || error_exit "Extraction failed"

		    update_state "Creating image"
		    dd if=/dev/zero of="{$strImgTmpFile}" bs=1M count=$(({$img_size_gb} * 1024)) conv=fsync || error_exit "Image creation failed"
		    parted "{$strImgTmpFile}" --script mklabel msdos && parted "{$strImgTmpFile}" --script mkpart primary fat32 1MiB 100% || error_exit "Partitioning failed"

		    LOOP_DEVICE=\$(losetup --find --show --partscan "{$strImgTmpFile}")
		    PARTITION="\${LOOP_DEVICE}p1"

		    update_state "Formatting"
		    mkfs.vfat -F 32 -n UNRAIDVM "\$PARTITION" || error_exit "Formatting failed"
		    "{$strExtractTmpDir}/syslinux/syslinux_linux" -f --install "\$PARTITION" 1>/dev/null 2>/dev/null || error_exit "Syslinux installation failed"
		    sed -i 's/\bappend\b/append unraidlabel=UNRAIDVM/g' "{$strExtractTmpDir}/syslinux/syslinux.cfg"

		    update_state "Copying files"
		    mcopy -i "\$PARTITION" -s "{$strExtractTmpDir}/"* ::/ || error_exit "File copy failed"
		    losetup -d "\$LOOP_DEVICE"
		    mv "{$strImgTmpFile}" "{$strImgFile}"
		  fi

		  chmod 777 "{$strDownloadPath}" "{$strImgFile}"
		  chown nobody:users "{$strDownloadPath}" "{$strImgFile}"
		  rm -f "{$strZipFile}"
		  rm -rf "{$strExtractTmpDir}"
		  update_state "Done"
		  trap - EXIT
		} >> "{$strLogFile}" 2>&1
		rm -f "{$strLogFile}"
		rm -f "{$strInstallScript}"
		EOD;

		$reply           = [];
		$currentState    = getSetupState();
		$isScriptRunning = pgrep($strInstallScriptPgrep, false);

		if (!$isScriptRunning && $currentState !== 'Done' && !$boolCheckOnly) {
			if (!file_exists($strInstallScript)) {
				file_put_contents($strInstallScript, $strAllCmd);
				chmod($strInstallScript, 0777);
			}
			exec($strInstallScript . ' >/dev/null 2>&1 &');
			$isScriptRunning = pgrep($strInstallScriptPgrep, false);
		}

		if ($currentState !== 'Done' && $currentState !== '' && strpos($currentState, 'Error') === false && !$isScriptRunning) {
			$currentState = 'Error: Process stopped unexpectedly';
		}

		switch ($currentState) {
			case 'Done':
				$reply['status']      = 'Done';
				$reply['localpath']   = $strImgFile;
				$reply['localfolder'] = dirname($strImgFile);
				unset($arrUnRAIDConfig['pending_version'], $arrUnRAIDConfig['pending_name'],
				      $arrUnRAIDConfig['pending_download_path'], $arrUnRAIDConfig['pending_size']);
				$arrUnRAIDConfig[$_POST['download_version']] = $strImgFile;
				saveUnRAIDConfig($arrUnRAIDConfig);
				@unlink($strStateFile);
				@unlink($strInstallScript);
				break;
			case 'Downloading':
				clearstatcache();
				$intSize    = file_exists($strZipFile) ? filesize($strZipFile) : 0;
				if ($intSize > $arrDownloadUnRAID['size']) $intSize = $arrDownloadUnRAID['size'];
				$strPercent = $intSize > 0 ? round(($intSize / $arrDownloadUnRAID['size']) * 100) : 0;
				$reply['status'] = _('Downloading') . ' ... ' . $strPercent . '%';
				break;
			case 'Downloading ... 100%':
				$reply['status'] = _('Downloading') . ' ... 100%';
				break;
			case 'Extracting':
				$reply['status'] = _('Downloading') . ' ... 100%<br>' . _('Extracting') . ' ... ';
				break;
			case 'Creating image':
				$reply['status'] = _('Downloading') . ' ... 100%<br>' . _('Extracting') . ' ... 100%<br>' . _('Creating image') . ' ... ';
				break;
			case 'Formatting':
				$reply['status'] = _('Downloading') . ' ... 100%<br>' . _('Extracting') . ' ... 100%<br>' . _('Creating image') . ' ... 100%<br>' . _('Formatting') . ' ... ';
				break;
			case 'Copying files':
				$reply['status'] = _('Downloading') . ' ... 100%<br>' . _('Extracting') . ' ... 100%<br>' . _('Creating image') . ' ... 100%<br>' . _('Formatting') . ' ... 100%<br>' . _('Copying files') . ' ... ';
				break;
			default:
				if (strpos($currentState, 'Error') !== false) {
					$reply['error'] = _($currentState);
					unset($arrUnRAIDConfig['pending_version'], $arrUnRAIDConfig['pending_name'],
					      $arrUnRAIDConfig['pending_download_path'], $arrUnRAIDConfig['pending_size']);
					saveUnRAIDConfig($arrUnRAIDConfig);
					@unlink($strStateFile);
				} else {
					$reply['status'] = _('Starting') . ' ... ';
				}
				break;
		}
		$reply['pid'] = $isScriptRunning;
	}
	echo json_encode($reply);
	exit;
}

// POST: create new VM -------------------------------------------------------
if (isset($_POST['createvm'])) {
	if (isset($_POST['xmldesc'])) {
		$new = $lv->domain_define($_POST['xmldesc'], $_POST['domain']['xmlstartnow']==1);
		if ($new) {
			$lv->domain_set_autostart($new, $_POST['domain']['autostart']==1);
			$reply = ['success' => true];
		} else {
			$reply = ['error' => $lv->get_last_error()];
		}
	} else {
		$_POST['clock'] = $arrDefaultClocks['other'];
		if ($lv->domain_new($_POST)) {
			$dom      = $lv->get_domain_by_name($_POST['domain']['name']);
			$vmrcport = $lv->domain_get_vnc_port($dom);
			$wsport   = $lv->domain_get_ws_port($dom);
			$protocol = $lv->domain_get_vmrc_protocol($dom);
			$reply    = ['success' => true];
			if ($vmrcport > 0) {
				if ($protocol == "vnc") $vmrcscale = "&resize=scale"; else $vmrcscale = "";
				$reply['vmrcurl'] = autov('/plugins/dynamix.vm.manager/'.$protocol.'.html',true).'&autoconnect=true'.$vmrcscale.'&host='.$_SERVER['HTTP_HOST'];
				if ($protocol == "spice") $reply['vmrcurl'] .= '&port=/wsproxy/'.$vmrcport.'/';
				else                      $reply['vmrcurl'] .= '&port=&path=/wsproxy/'.$wsport.'/';
			}
		} else {
			$reply = ['error' => $lv->get_last_error()];
		}
	}
	echo json_encode($reply);
	exit;
}

// POST: create VM template --------------------------------------------------
if (isset($_POST['createvmtemplate'])) {
	$reply = addtemplatexml($_POST);
	echo json_encode($reply);
	exit;
}

// POST: update existing VM --------------------------------------------------
if (isset($_POST['updatevm'])) {
	$uuid         = $_POST['domain']['uuid'];
	$dom          = $lv->domain_get_domain_by_uuid($uuid);
	$oldAutoStart = $lv->domain_get_autostart($dom)==1;
	$newAutoStart = $_POST['domain']['autostart']==1;
	$strXML       = $lv->domain_get_xml($dom);

	if ($lv->domain_get_state($dom)=='running') {
		$arrErrors         = [];
		$arrExistingConfig = domain_to_config($uuid);
		$arrNewUSBIDs      = $_POST['usb'];
		foreach ($arrNewUSBIDs as $strNewUSBID) {
			if (strpos($strNewUSBID,"#remove")) continue;
			$remove       = explode('#', $strNewUSBID);
			$strNewUSBID2 = $remove[0];
			foreach ($arrExistingConfig['usb'] as $arrExistingUSB) {
				if ($strNewUSBID2 == $arrExistingUSB['id']) continue 2;
			}
			[$strVendor,$strProduct] = my_explode(':', $strNewUSBID2);
			file_put_contents('/tmp/hotattach.tmp', "<hostdev mode='subsystem' type='usb'><source startupPolicy='optional'><vendor id='0x".$strVendor."'/><product id='0x".$strProduct."'/></source></hostdev>");
			exec("virsh attach-device ".escapeshellarg($uuid)." /tmp/hotattach.tmp --live 2>&1", $arrOutput, $intReturnCode);
			unlink('/tmp/hotattach.tmp');
			if ($intReturnCode != 0) $arrErrors[] = implode(' ', $arrOutput);
		}
		foreach ($arrExistingConfig['usb'] as $arrExistingUSB) {
			if (!in_array($arrExistingUSB['id'], $arrNewUSBIDs)) {
				[$strVendor, $strProduct] = my_explode(':', $arrExistingUSB['id']);
				file_put_contents('/tmp/hotdetach.tmp', "<hostdev mode='subsystem' type='usb'><source startupPolicy='optional'><vendor id='0x".$strVendor."'/><product id='0x".$strProduct."'/></source></hostdev>");
				exec("virsh detach-device ".escapeshellarg($uuid)." /tmp/hotdetach.tmp --live 2>&1", $arrOutput, $intReturnCode);
				unlink('/tmp/hotdetach.tmp');
				if ($intReturnCode != 0) $arrErrors[] = implode(' ',$arrOutput);
			}
		}
		$reply = !$arrErrors ? ['success' => true] : ['error' => implode(', ',$arrErrors)];
		echo json_encode($reply);
		exit;
	}

	if ($dom && empty($_POST['xmldesc'])) {
		$oldName = $lv->domain_get_name($dom);
		$newName = $_POST['domain']['name'];
		$oldDir  = $domain_cfg['DOMAINDIR'].$oldName;
		$newDir  = $domain_cfg['DOMAINDIR'].$newName;
		if ($oldName && $newName && is_dir($oldDir) && !is_dir($newDir)) {
			if (rename($oldDir, $newDir)) {
				foreach ($_POST['disk'] as &$arrDisk) {
					if ($arrDisk['new'])   $arrDisk['new']   = str_replace($oldDir, $newDir, $arrDisk['new']);
					if ($arrDisk['image']) $arrDisk['image'] = str_replace($oldDir, $newDir, $arrDisk['image']);
				}
			}
		}
	}

	$newuuid = $uuid;
	$olduuid = $uuid;

	if (isset($_POST['xmldesc'])) {
		$xml               = $_POST['xmldesc'];
		$arrExistingConfig = custom::createArray('domain',$xml);
		$newuuid           = $arrExistingConfig['uuid'];
		if ($_POST['template']['iconold'] != $_POST['template']['icon'])
			$xml = preg_replace('/icon="[^"]*"/','icon="' . $_POST['template']['icon'] . '"',$xml);
		$xml = str_replace($olduuid,$newuuid,$xml);
	} else {
		$_POST['clock'] = $arrDefaultClocks['other'];
		if (($error = create_vdisk($_POST)) === false) {
			$arrExistingConfig = custom::createArray('domain',$strXML);
			$arrUpdatedConfig  = custom::createArray('domain',$lv->config_to_xml($_POST));
			if ($debug) {
				file_put_contents("/tmp/vmdebug_exist",$strXML);
				file_put_contents("/tmp/vmdebug_new",$lv->config_to_xml($_POST));
				file_put_contents("/tmp/vmdebug_arrayN",json_encode($arrUpdatedConfig,JSON_PRETTY_PRINT));
				file_put_contents("/tmp/vmdebug_arrayE",json_encode($arrExistingConfig,JSON_PRETTY_PRINT));
			}
			array_update_recursive($arrExistingConfig, $arrUpdatedConfig);
			$arrConfig = array_replace_recursive($arrExistingConfig, $arrUpdatedConfig);
			$xml = custom::createXML('domain',$arrConfig)->saveXML();
			$xml = $lv->appendqemucmdline($xml,$_POST["qemucmdline"]);
		} else {
			echo json_encode(['error' => $error]);
			exit;
		}
	}

	$lv->nvram_backup($uuid);
	$lv->domain_undefine($dom);
	$lv->nvram_restore($uuid);
	if ($newuuid != $olduuid) $lv->nvram_rename($olduuid,$newuuid);
	$new = $lv->domain_define($xml);
	if ($new) {
		$lv->domain_set_autostart($new, $newAutoStart);
		$reply = ['success' => true];
	} else {
		$reply = ['error' => $lv->get_last_error()];
		$old   = $lv->domain_define($strXML);
		if ($old) $lv->domain_set_autostart($old, $oldAutoStart);
	}
	echo json_encode($reply);
	exit;
}

// GET: load existing or build new VM ----------------------------------------
if (isset($_GET['uuid'])) {
	$uuid        = unscript($_GET['uuid']);
	$dom         = $lv->domain_get_domain_by_uuid($uuid);
	$boolRunning = $lv->domain_get_state($dom)=='running';
	$strXML      = $lv->domain_get_xml($dom);
	$boolNew     = false;
	$arrConfig   = array_replace_recursive($arrConfigDefaults, domain_to_config($uuid));
	$arrVMUSBs   = getVMUSBs($strXML);
} else {
	$boolRunning = false;
	$strXML      = '';
	$boolNew     = true;
	$arrConfig   = $arrConfigDefaults;
	$arrVMUSBs   = getVMUSBs($strXML);
	$strXML      = $lv->config_to_xml($arrConfig);
	$domXML      = new DOMDocument();
	$domXML->preserveWhiteSpace = false;
	$domXML->formatOutput       = true;
	$domXML->loadXML($strXML);
	$strXML = $domXML->saveXML();
}

// Sync disk[0] image to the selected UnRAID version
if (array_key_exists($arrConfig['template']['unraid'], $arrUnRAIDVersions)) {
	$arrConfig['disk'][0]['image'] = $arrUnRAIDVersions[$arrConfig['template']['unraid']]['localpath'];
}

// OS type — always 'other' for UnRAID VMs
if (!$arrConfig['template']['os']) $arrConfig['template']['os'] = 'linux';
$os_type = 'other';

if (isset($arrConfig['clocks'])) $arrClocks = json_decode($arrConfig['clocks'],true);
else $arrClocks = $arrDefaultClocks['other'];

if (strpos($arrConfig['template']['name'],"User-") !== false) {
	$arrConfig['template']['name'] = str_replace("User-","",$arrConfig['template']['name']);
	unset($arrConfig['domain']['uuid']);
}
if ($usertemplate == 1) unset($arrConfig['domain']['uuid']);

$xml2 = build_xml_templates($strXML);

// Snapshot state
$snapshots = getvmsnapshots($arrConfig['domain']['name']);
if ($snapshots!=null && count($snapshots) && !$boolNew) {
	$snaphidden  = "";
	$namedisable = "disabled";
	$snapcount   = count($snapshots);
} else {
	$snaphidden  = "hidden";
	$namedisable = "";
	$snapcount   = "0";
}

$PCIchanges = comparePCIData();
?>

<link rel="stylesheet" href="<?autov('/plugins/dynamix.vm.manager/scripts/codemirror/lib/codemirror.css')?>">
<link rel="stylesheet" href="<?autov('/plugins/dynamix.vm.manager/scripts/codemirror/addon/hint/show-hint.css')?>">
<style type="text/css">
	#unraid_image {
		color: #BBB;
		display: none;
		transform: translate(0px, 3px);
	}
	.delete_unraid_image {
		cursor: pointer;
		margin-left: 12px;
		margin-right: 5px;
		color: #CC0011;
		font-size: 1.4rem;
		transform: translate(0px, 3px);
	}
</style>

<div class="formview">
<script>
const displayOptions = <?= json_encode($arrDisplayOptions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const PCIchanges = <?= json_encode($PCIchanges) ?>;
</script>

<!-- Hidden domain fields -->
<input type="hidden" name="template[os]" id="template_os" value="<?=htmlspecialchars($arrConfig['template']['os'])?>">
<input type="hidden" name="domain[persistent]" value="<?=htmlspecialchars($arrConfig['domain']['persistent'])?>">
<input type="hidden" name="domain[uuid]" value="<?=htmlspecialchars($arrConfig['domain']['uuid'])?>">
<input type="hidden" name="domain[arch]" value="<?=htmlspecialchars($arrConfig['domain']['arch'])?>">
<input type="hidden" name="domain[oldname]" id="domain_oldname" value="<?=htmlspecialchars($arrConfig['domain']['name'])?>">
<input type="hidden" name="domain[memoryBacking]" id="domain_memorybacking" value="<?=htmlspecialchars($arrConfig['domain']['memoryBacking'])?>">

<!-- UnRAID boot disk — hidden, managed by version selector -->
<input type="hidden" name="disk[0][image]" id="disk_0" value="<?=htmlspecialchars($arrConfig['disk'][0]['image'])?>">
<input type="hidden" name="disk[0][dev]" value="<?=htmlspecialchars($arrConfig['disk'][0]['dev'])?>">
<input type="hidden" name="disk[0][bus]" value="usb">
<input type="hidden" class="bootorder" name="disk[0][boot]" value="1">

<!-- ── NAME (always visible) ─────────────────────────────────────────────── -->
<table>
	<tr class="<?=$snaphidden?>">
		<td></td>
		<td><span class="orange-text"><i class="fa fa-fw fa-warning"></i> <?=sprintf(_('Rename disabled, %s snapshot(s) exists'), $snapcount)?>.</span></td>
		<td></td>
	</tr>
	<tr id="zfs-name" class="hidden">
		<td></td>
		<td>
			<span class="orange-text"><i class="fa fa-fw fa-warning"></i> _(Name contains invalid characters or does not start with an alphanumberic for a ZFS storage location)_</span><br>
			<span class="green-text"><i class="fa fa-fw fa-info-circle"></i> _(Only these special characters are valid Underscore (_) Hyphen (-) Colon (:) Period (.))_</span>
		</td>
		<td></td>
	</tr>
	<tr>
		<td>_(Name)_:</td>
		<td>
			<span class="width"><input <?=$namedisable?> type="text" name="domain[name]" id="domain_name" oninput="checkName(this.value)" class="textTemplate" placeholder="_(e.g.)_ _(UnRAID Server)_" value="<?=htmlspecialchars($arrConfig['domain']['name'] ?: ($arrUnRAIDConfig['pending_name'] ?? ''))?>" required /></span>
		</td>
		<td>
			<textarea class="xml" id="xmlname" rows="1" disabled><?=htmlspecialchars($xml2['name'])."\n".htmlspecialchars($xml2['uuid'])."\n".htmlspecialchars($xml2['metadata'])?></textarea>
		</td>
	</tr>
</table>
<blockquote class="inline_help">
	<p>Give the VM a name (e.g. UnRAID Server, UnRAID)</p>
</blockquote>

<!-- ── UNRAID VERSION SELECTOR (always visible) ──────────────────────────── -->
<table>
	<tr>
		<td>_(UnRAID Version)_:</td>
		<td>
			<select name="template[unraid]" id="template_unraid" class="narrow" title="_(Select the UnRAID version to use)_">
			<?
				foreach ($arrUnRAIDVersions as $strOEVersion => $arrOEVersion) {
					$strDefaultFolder = '';
					if (!empty($domain_cfg['DOMAINDIR']) && file_exists($domain_cfg['DOMAINDIR'])) {
						$strDefaultFolder = str_replace('//', '/', $domain_cfg['DOMAINDIR'].'/UnRAID/');
					}
					$strLocalFolder = ($arrOEVersion['localpath'] == '' ? $strDefaultFolder : dirname($arrOEVersion['localpath']));
					echo mk_option($arrConfig['template']['unraid'], $strOEVersion, $arrOEVersion['name'],
					               'localpath="' . $arrOEVersion['localpath'] . '" localfolder="' . $strLocalFolder . '" valid="' . $arrOEVersion['valid'] . '"');
				}
			?>
			</select> <i class="fa fa-trash delete_unraid_image installed" title="_(Remove UnRAID image)_"></i> <span id="unraid_image" class="installed"></span>
		</td>
		<td></td>
	</tr>
</table>
<blockquote class="inline_help">
	<p>Select which UnRAID version to download or use for this VM.</p>
</blockquote>

<!-- ── DOWNLOAD SECTION (shown when image not yet available) ─────────────── -->
<div class="available">
	<table>
		<tr>
			<td>_(Download Folder)_:</td>
			<td>
				<input type="text" autocomplete="off" spellcheck="false" data-pickfolders="true" data-pickfilter="NO_FILES_FILTER" data-pickroot="/mnt/" value="<?=htmlspecialchars($arrUnRAIDConfig['pending_download_path'] ?? '')?>" id="download_path" class="narrow" placeholder="_(e.g.)_ /mnt/user/domains/" title="_(Folder to save the UnRAID image to)_" />
			</td>
		</tr>
	</table>
	<blockquote class="inline_help">
		<p>Choose a folder where the UnRAID image will be downloaded to.</p>
	</blockquote>

	<table>
		<tr>
			<td>_(Image Size)_:</td>
			<td>
				<select id="download_size" class="narrow">
				<?
					for ($i = 1; $i <= 32; $i *= 2) {
						echo mk_option($arrUnRAIDConfig['pending_size'] ?? 2, $i, $i . ' GB');
					}
				?>
				</select>
			</td>
		</tr>
	</table>
	<blockquote class="inline_help">
		<p>Select the size of the UnRAID image to create.</p>
	</blockquote>

	<table>
		<tr>
			<td></td>
			<td>
				<input type="button" value="_(Download)_" busyvalue="_(Downloading)_..." readyvalue="_(Download)_" id="btnDownload" />
				<div id="download_status" style="margin-top: 10px;"></div>
			</td>
		</tr>
	</table>
</div>

<!-- ── FULL VM CONFIGURATION (shown once image is available) ─────────────── -->
<div class="installed">

<table>
	<tr class="advanced">
		<td>_(Description)_:</td>
		<td>
			<span class="width"><input type="text" name="domain[desc]" placeholder="_(description of virtual machine)_ (_(optional)_)" value="<?=htmlspecialchars($arrConfig['domain']['desc'])?>"/></span>
		</td>
		<td>
			<textarea class="xml" id="xmldesription" rows="1" disabled><?=htmlspecialchars($xml2['description'])?></textarea>
		</td>
	</tr>
</table>
<div class="advanced">
	<blockquote class="inline_help">
		<p>Give the VM a brief description (optional field).</p>
	</blockquote>
</div>

<table>
	<tr class="advanced">
		<td>_(WebUI)_:</td>
		<td>
			<span class="width"><input type="url" name="template[webui]" placeholder="_(Web UI to start from menu)_ (_(optional)_)" value="<?=htmlspecialchars($arrConfig['template']['webui'])?>"/></span>
		</td>
		<td></td>
	</tr>
</table>
<div class="advanced">
	<blockquote class="inline_help">
		<p>Specify a URL that for menu to start. Substitution variables are
			<br>[IP] IP address, this will take the first IP on the VM. Guest Agent must be installed for this to work.
			<br>[PORT:XX] Port Number in XX.
			<br>[VMNAME] VM Name will have spaces replaced with -
		</p>
	</blockquote>
</div>

<table>
	<tr>
		<?if (!$boolNew) $disablestorage = "disabled"; else $disablestorage = "";?>
		<td>_(Override Storage Location)_:</td>
		<td>
			<span class="width"><select <?=$disablestorage?> name="template[storage]" onchange="get_storage_fstype(this)" class="disk_select narrow" id="storage_location">
			<?
			$default_storage = htmlspecialchars($arrConfig['template']['storage']);
			echo mk_option($default_storage, 'default', _('Default'));
			$strShareUserLocalInclude  = '';
			$strShareUserLocalExclude  = '';
			$strShareUserLocalUseCache = 'no';
			$arrDomainDirParts = explode('/', $domain_cfg['DOMAINDIR']);
			$strShareName = $arrDomainDirParts[3];
			if (!empty($strShareName) && is_file('/boot/config/shares/'.$strShareName.'.cfg')) {
				$arrShareCfg = parse_ini_file('/boot/config/shares/'.$strShareName.'.cfg');
				if (!empty($arrShareCfg['shareInclude']))  $strShareUserLocalInclude  = $arrShareCfg['shareInclude'];
				if (!empty($arrShareCfg['shareExclude']))  $strShareUserLocalExclude  = $arrShareCfg['shareExclude'];
				if (!empty($arrShareCfg['shareUseCache'])) $strShareUserLocalUseCache = $arrShareCfg['shareUseCache'];
			}
			foreach ($pools as $pool) {
				if (isSubpool($pool)) continue;
				$strLabel = $pool.' - '.my_scale($disks[$pool]['fsFree']*1024, $strUnit).' '.$strUnit.' '._('free');
				echo mk_option($default_storage, $pool, $strLabel);
			}
			foreach ($disks as $name => $disk) {
				if ((strpos($name, 'disk') === 0) && (!empty($disk['device']))) {
					if ((!empty($strShareUserLocalInclude) && (strpos($strShareUserLocalInclude.',', $name.',') === false)) ||
					    (!empty($strShareUserLocalExclude) && (strpos($strShareUserLocalExclude.',', $name.',') !== false)) ||
					    (!empty($var['shareUserInclude'])  && (strpos($var['shareUserInclude'].',',  $name.',') === false)) ||
					    (!empty($var['shareUserExclude'])  && (strpos($var['shareUserExclude'].',',  $name.',') !== false))) continue;
					$strLabel = _(my_disk($name),3).' - '.my_scale($disk['fsFree']*1024, $strUnit).' '.$strUnit.' '._('free');
					echo mk_option($default_storage, $name, $strLabel);
				}
			}
			?>
			</select></span>
		</td>
		<td></td>
	</tr>
</table>
<blockquote class="inline_help">
	<p>Specify the override storage pool for VM snapshots. Default follows standard share processing.</p>
</blockquote>

<?$migratehidden = $arrConfig['domain']['cpumode']=='host-passthrough' ? "" : "hidden";?>
<table>
	<tr class="advanced">
		<td><span class="advanced">_(CPU)_ </span>_(Mode)_:</td>
		<td>
			<span class="width"><select id="cpu" name="domain[cpumode]" class="cpu">
			<?mk_dropdown_options(['host-passthrough' => _('Host Passthrough').' ('.$strCPUModel.')', 'custom' => _('Emulated').' ('._('QEMU64').')'], $arrConfig['domain']['cpumode']);?>
			</select></span>
			<span class="advanced label <?=$migratehidden?>" id="domain_cpumigrate_text">_(Migratable)_:</span>
			<select name="domain[cpumigrate]" id="domain_cpumigrate" class="narrow second <?=$migratehidden?>">
			<?
			echo mk_option($arrConfig['domain']['cpumigrate'], 'on', 'On');
			echo mk_option($arrConfig['domain']['cpumigrate'], 'off', 'Off');
			?>
			</select>
		</td>
		<td>
			<textarea class="xml" id="xmlcpu" rows="1" disabled><?=htmlspecialchars($xml2['cpu'])?></textarea>
		</td>
	</tr>
</table>
<div class="advanced">
	<blockquote class="inline_help">
		<p><b>Host Passthrough</b><br>The CPU visible to the guest is the same as the host CPU. Best performance.</p>
		<p><b>Emulated</b><br>Does not expose host-based CPU features. May impact performance.</p>
		<p><b>Migratable</b><br>On removes host features; Off keeps them. Not supported for emulated mode.</p>
	</blockquote>
</div>

<table>
	<tr class="advanced">
		<?
		$cpus = cpu_list();
		$corecount = 0;
		foreach ($cpus as $pair) {
			unset($cpu1,$cpu2);
			[$cpu1, $cpu2] = my_preg_split('/[,-]/',$pair);
			if (!$cpu2) $corecount++; else $corecount += 2;
		}
		if (is_array($arrConfig['domain']['vcpu'])) { $coredisable = "disabled"; $vcpubuttontext = "Deselect all"; }
		else                                         { $coredisable = "";          $vcpubuttontext = "Select all"; }
		?>
		<td><span class="advanced">_(vCPUs)_:</span></td>
		<td>
			<span class="width"><select id="vcpus" <?=$coredisable?> name="domain[vcpus]" class="domain_vcpus narrow">
			<?for ($i = 1; $i <= $corecount; $i++) echo mk_option($arrConfig['domain']['vcpus'], $i, $i);?>
			</select>
			<input type="button" value="_(<?=$vcpubuttontext?>)_" id="btnvCPUSelect"/></span>
			<span id="numacpu" class="status-warn"></span>
		</td>
		<td></td>
	</tr>
</table>

<table>
	<tr>
		<td>_(Pinned Cores)_:</td>
		<td>
			<?
			$total_cpus = count($cpus);
			$cols = ($total_cpus <= 4) ? $total_cpus : (int)ceil(sqrt($total_cpus));
			?>
			<div class="cpu-grid" style="grid-template-columns: repeat(<?=$cols?>, minmax(150px, 1fr));">
			<?
			$is_intel_cpu = is_intel_cpu();
			$core_types   = $is_intel_cpu ? get_intel_core_types() : [];
			foreach ($cpus as $pair) {
				unset($cpu1,$cpu2);
				[$cpu1, $cpu2] = my_preg_split('/[,-]/',$pair);
				$extra1    = ($arrConfig['domain']['vcpu'] && in_array($cpu1, $arrConfig['domain']['vcpu'])) ? ($arrConfig['domain']['vcpus'] > 1 ? 'checked' : 'checked disabled') : '';
				$core_type = ($is_intel_cpu && isset($core_types[$cpu1]) && !empty($core_types[$cpu1])) ? $core_types[$cpu1] : "";
				$core_indicator = "";
				if ($core_type == _('P-Core'))      $core_indicator = " <span class='cpu-core-indicator-p'>●</span>";
				elseif ($core_type == _('E-Core'))  $core_indicator = " <span class='cpu-core-indicator-e'>●</span>";
				if (!$cpu2) {
					echo "<div class='cpu-box'><div class='cpu-row'>";
					echo "<span title='".htmlspecialchars($core_type, ENT_QUOTES)."' class='cpu-label'>cpu $cpu1{$core_indicator}</span>";
					echo "<label for='vcpu$cpu1' class='checkbox cpu-checkbox'><input type='checkbox' name='domain[vcpu][]' class='domain_vcpu' id='vcpu$cpu1' value='$cpu1' $extra1><span class='checkmark'></span></label>";
					echo "</div></div>";
				} else {
					$extra2 = ($arrConfig['domain']['vcpu'] && in_array($cpu2, $arrConfig['domain']['vcpu'])) ? ($arrConfig['domain']['vcpus'] > 1 ? 'checked' : 'checked disabled') : '';
					echo "<div class='cpu-box-pair'><div class='cpu-dual-container'>";
					echo "<div class='cpu-dual-row'><span title='".htmlspecialchars($core_type, ENT_QUOTES)."' class='cpu-label-dual'>cpu $cpu1{$core_indicator}</span><label for='vcpu$cpu1' class='cpu1 checkbox cpu-checkbox-dual' title='Thread 1'><input type='checkbox' name='domain[vcpu][]' class='domain_vcpu' id='vcpu$cpu1' value='$cpu1' $extra1><span class='checkmark'></span></label></div>";
					echo "<div class='cpu-dual-row'><span class='cpu-label-dual'>cpu $cpu2</span><label for='vcpu$cpu2' class='cpu2 checkbox cpu-checkbox-dual' title='Thread 2'><input type='checkbox' name='domain[vcpu][]' class='domain_vcpu' id='vcpu$cpu2' value='$cpu2' $extra2><span class='checkmark'></span></label></div>";
					echo "</div></div>";
				}
			}
			?>
			</div>
		</td>
		<td>
			<textarea class="xml" id="xmlvcpu" rows="5" disabled><?=htmlspecialchars($xml2['vcpu'])."\n".htmlspecialchars($xml2['cputune'])?></textarea>
		</td>
	</tr>
</table>
<blockquote class="inline_help">
	<p>Select which pinned CPUs you wish to allow your VM to use. If no pinned cores are selected the vCPU value determines allocation.</p>
</blockquote>

<table>
	<tr>
		<td><span class="advanced">_(Initial)_ </span>_(Memory)_:</td>
		<td>
			<span class="width"><select name="domain[mem]" id="domain_mem" class="narrow">
			<?
			echo mk_option($arrConfig['domain']['mem'], 128 * 1024, '128 MB');
			echo mk_option($arrConfig['domain']['mem'], 256 * 1024, '256 MB');
			for ($i = 1; $i <= ($maxmem*2); $i++) {
				$sizeMB = $i * 512;
				$value  = $sizeMB * 1024;
				$label  = $sizeMB >= 1024 ? number_format($sizeMB / 1024, 1) . ' GB' : $sizeMB . ' MB';
				echo mk_option($arrConfig['domain']['mem'], $value, $label);
			}
			?>
			</select></span>
			<span class="advanced label">_(Max)_ _(Memory)_:</span>
			<select name="domain[maxmem]" id="domain_maxmem" class="narrow second">
			<?
			echo mk_option($arrConfig['domain']['maxmem'], 128 * 1024, '128 MB');
			echo mk_option($arrConfig['domain']['maxmem'], 256 * 1024, '256 MB');
			for ($i = 1; $i <= ($maxmem*2); $i++) {
				$sizeMB = $i * 512;
				$value  = $sizeMB * 1024;
				$label  = $sizeMB >= 1024 ? number_format($sizeMB / 1024, 1) . ' GB' : $sizeMB . ' MB';
				echo mk_option($arrConfig['domain']['maxmem'], $value, $label);
			}
			?>
			</select>
		</td>
		<td>
			<textarea class="xml" id="xmlmem" rows="2" disabled><?=htmlspecialchars($xml2['memory'])."\n".htmlspecialchars($xml2['currentMemory'])."\n".htmlspecialchars($xml2['memoryBacking'])?></textarea>
		</td>
	</tr>
</table>
<blockquote class="inline_help">
	<p>Select how much memory to allocate to the VM at boot.</p>
</blockquote>

<?
if (!isset($arrValidMachineTypes[$arrConfig['domain']['machine']])) {
	$arrConfig['domain']['machine'] = ValidateMachineType($arrConfig['domain']['machine']);
}
?>
<table>
	<tr class="advanced">
		<td>_(Machine)_:</td>
		<td>
			<span class="width"><select name="domain[machine]" id="domain_machine" class="narrow">
			<?mk_dropdown_options($arrValidMachineTypes, $arrConfig['domain']['machine']);?>
			</select></span>
		</td>
		<td>
			<textarea class="xml" id="xmlos" rows="5" cols="200" disabled><?=htmlspecialchars($xml2['os'])."\n".htmlspecialchars($xml2['features'])?></textarea>
		</td>
	</tr>
</table>

<table>
	<tr class="advanced">
		<td>_(BIOS)_:</td>
		<td>
			<span class="width"><select name="domain[ovmf]" id="domain_ovmf" onchange="BIOSChange(this.value)" class="narrow">
			<?
			echo mk_option($arrConfig['domain']['ovmf'], '0', _('SeaBIOS'));
			if (file_exists('/usr/share/qemu/ovmf-x64/OVMF_CODE-pure-efi.fd')) {
				echo mk_option($arrConfig['domain']['ovmf'], '1', _('OVMF'));
			} else {
				echo mk_option('', '0', _('OVMF').' ('._('Not Available').')', 'disabled');
			}
			if (file_exists('/usr/share/qemu/ovmf-x64/OVMF_CODE-pure-efi-tpm.fd')) {
				echo mk_option($arrConfig['domain']['ovmf'], '2', _('OVMF TPM'));
			} else {
				echo mk_option('', '0', _('OVMF TPM').' ('._('Not Available').')', 'disabled');
			}
			?>
			</select></span>
			<?$usbboothidden = $arrConfig['domain']['ovmf']!='0' ? "" : "hidden";?>
			<span id="USBBoottext" class="advanced label <?=$usbboothidden?>">_(Enable USB boot)_:</span>
			<select name="domain[usbboot]" id="domain_usbboot" class="narrow second <?=$usbboothidden?>" onchange="USBBootChange(this)">
			<?
			echo mk_option($arrConfig['domain']['usbboot'], 'No', 'No');
			echo mk_option($arrConfig['domain']['usbboot'], 'Yes', 'Yes');
			?>
			</select>
		</td>
		<td></td>
	</tr>
</table>

<table>
	<tr class="advanced">
		<td>_(USB Controller)_:</td>
		<td>
			<span class="width"><select name="domain[usbmode]" id="usbmode" class="narrow">
			<?
			echo mk_option($arrConfig['domain']['usbmode'], 'usb2', _('2.0 (EHCI)'));
			echo mk_option($arrConfig['domain']['usbmode'], 'usb3', _('3.0 (nec XHCI)'));
			echo mk_option($arrConfig['domain']['usbmode'], 'usb3-qemu', _('3.0 (qemu XHCI)'));
			?>
			</select></span>
		</td>
		<td></td>
	</tr>
</table>
<div class="advanced">
	<blockquote class="inline_help">
		<p><b>USB Controller</b><br>Select the USB Controller to emulate. The UnRAID boot disk requires at least USB 2.0. Recommended: qemu XHCI before resorting to nec XHCI.</p>
	</blockquote>
</div>

<table>
	<tr>
		<td>_(OS Install ISO)_:</td>
		<td>
			<span class="width"><input type="text" name="media[cdrom]" autocomplete="off" spellcheck="false" data-pickcloseonfile="true" data-pickfilter="iso" data-pickmatch="^[^.].*" data-pickroot="<?=htmlspecialchars($domain_cfg['MEDIADIR'])?>" class="cdrom" value="<?=htmlspecialchars($arrConfig['media']['cdrom'])?>" placeholder="_(Click and Select cdrom image to install operating system)_"></span>
		</td>
		<td>
			<textarea class="xml" id="xmlvdiskhda" rows="1" disabled wrap="soft"><?=htmlspecialchars($xml2['devices']['disk']['hda'])?></textarea>
		</td>
	</tr>
	<tr class="advanced">
		<td>_(OS Install CDRom Bus)_:</td>
		<td>
			<span class="width"><select name="media[cdrombus]" class="cdrom_bus narrow">
			<?mk_dropdown_options($arrValidCdromBuses, $arrConfig['media']['cdrombus']);?>
			</select></span>
			<span class="label">_(Boot Order)_:</span>
			<input type="number" size="5" maxlength="5" id="cdboot" class="trim bootorder second" name="media[cdromboot]" value="<?=$arrConfig['media']['cdromboot']?>">
		</td>
		<td></td>
	</tr>
</table>
<blockquote class="inline_help">
	<p>Optionally attach an ISO for additional software installation inside the UnRAID VM.</p>
</blockquote>

<?
$arrUnraidShares = getUnraidShares();
foreach ($arrConfig['shares'] as $i => $arrShare) {
	$strLabel = ($i > 0) ? appendOrdinalSuffix($i + 1) : '';
?>
<table data-category="Share" data-multiple="true" data-minimum="1" data-index="<?=$i?>" data-prefix="<?=$strLabel?>">
	<tr class="advanced">
		<td>_(Unraid Share Mode)_:</td>
		<td>
			<span class="width"><select name="shares[<?=$i?>][mode]" class="disk_bus narrow">
			<?echo mk_option($arrShare['mode'], "virtiofs", _('Virtiofs Mode'));?>
			<?echo mk_option($arrShare['mode'], "9p", _('9p Mode'));?>
			</select></span>
			<span class="label">_(Unraid Share)_:</span>
			<span class="width"><select name="shares[<?=$i?>][unraid]" class="disk_bus narrow second" onchange="ShareChange(this)">
			<?
			$UnraidShareDisabled = ' disabled="disabled"';
			$arrUnraidIndex = array_search("User:".$arrShare['target'],$arrUnraidShares);
			if ($arrUnraidIndex != false && substr($arrShare['source'],0,10) != '/mnt/user/') $arrUnraidIndex = false;
			if ($arrUnraidIndex == false) $arrUnraidIndex = array_search("Disk:".$arrShare['target'],$arrUnraidShares);
			if ($arrUnraidIndex == false) { $arrUnraidIndex = ''; $UnraidShareDisabled = ""; }
			mk_dropdown_options($arrUnraidShares, $arrUnraidIndex);
			?>
			</select></span>
		</td>
		<td>
			<textarea class="xml" id="xmlshare<?=$i?>" rows="4" wrap="soft" disabled><?=htmlspecialchars($xml2['devices']['filesystem'][$i])?></textarea>
		</td>
	</tr>
	<tr class="advanced">
		<td>_(Unraid Source Path)_:</td>
		<td>
			<span class="width"><input type="text" <?=$UnraidShareDisabled?> id="shares[<?=$i?>][source]" name="shares[<?=$i?>][source]" autocomplete="off" data-pickfolders="true" data-pickfilter="NO_FILES_FILTER" data-pickroot="/mnt/" value="<?=htmlspecialchars($arrShare['source'])?>" placeholder="_(e.g.)_ /mnt/user/..."></span>
		</td>
		<td></td>
	</tr>
	<tr class="advanced">
		<td>_(Unraid Mount Tag)_:</td>
		<td>
			<span class="width"><input type="text" <?=$UnraidShareDisabled?> name="shares[<?=$i?>][target]" id="shares[<?=$i?>][target]" value="<?=htmlspecialchars($arrShare['target'])?>" placeholder="_(e.g.)_ _(shares)_ (_(name of mount tag inside vm)_)"></span>
		</td>
		<td></td>
	</tr>
</table>
<?if ($i == 0) {?>
<div class="advanced">
	<blockquote class="inline_help">
		<p><b>Unraid Share Mode</b><br>Used to create a VirtFS mapping to the guest. Choose Virtiofs (recommended) or 9p.</p>
		<p>Additional shares can be added/removed by clicking the symbols to the left.</p>
	</blockquote>
</div>
<?}?>
<?}?>

<script type="text/html" id="tmplShare">
<table class="domain_os other">
	<tr class="advanced">
		<td>_(Unraid Share Mode)_:</td>
		<td>
			<span class="width"><select name="shares[{{INDEX}}][mode]" class="disk_bus narrow">
			<?echo mk_option('', "virtiofs", _('Virtiofs Mode'));?>
			<?echo mk_option('', "9p", _('9p Mode'));?>
			</select></span>
			<span class="label">_(Unraid Share)_:</span>
			<select name="shares[{{INDEX}}][unraid]" class="disk_bus narrow second" onchange="ShareChange(this)">
			<?mk_dropdown_options($arrUnraidShares, '');?>
			</select>
		</td>
		<td></td>
	</tr>
	<tr class="advanced">
		<td>_(Unraid Source Path)_:</td>
		<td>
			<span class="width"><input type="text" name="shares[{{INDEX}}][source]" id="shares[{{INDEX}}][source]" autocomplete="off" spellcheck="false" data-pickfolders="true" data-pickfilter="NO_FILES_FILTER" data-pickroot="/mnt/" value="" placeholder="_(e.g.)_ /mnt/user/..."></span>
		</td>
		<td></td>
	</tr>
	<tr class="advanced">
		<td>_(Unraid Mount Tag)_:</td>
		<td>
			<span class="width"><input type="text" name="shares[{{INDEX}}][target]" id="shares[{{INDEX}}][target]" value="" placeholder="_(e.g.)_ _(shares)_ (_(name of mount tag inside vm)_)"></span>
		</td>
		<td></td>
	</tr>
</table>
</script>

<?foreach ($arrConfig['gpu'] as $i => $arrGPU) {
	$strLabel     = ($i > 0) ? appendOrdinalSuffix($i + 1) : '';
	$bootgpuhidden = "hidden";
?>
<table data-category="Graphics_Card" data-multiple="true" data-minimum="1" data-maximum="<?=count($arrValidGPUDevices)+1?>" data-index="<?=$i?>" data-prefix="<?=$strLabel?>">
	<tr>
		<td>_(Graphics Card)_:</td>
		<td>
			<span class="width"><select name="gpu[<?=$i?>][id]" class="gpu narrow" data-numawarn="numagpu<?=$i?>">
			<?
			if ($i == 0) echo mk_option($arrGPU['id'], 'virtual', _('Virtual'));
			else         echo mk_option($arrGPU['id'], '', _('None'));
			echo mk_option($arrGPU['id'], 'nogpu', _('No GPU'));
			foreach ($arrValidGPUDevices as $arrDev) echo mk_option($arrGPU['id'], $arrDev['id'], $arrDev['name'].' ('.$arrDev['id'].')');
			?>
			</select></span>
			<?if ($arrGPU['id'] != 'virtual' && $arrGPU['id'] != 'nogpu') $multifunction = ""; else $multifunction = " disabled ";?>
			<span id="GPUMulti<?=$i?>" name="gpu[<?=$i?>][multi]" class="<?if ($arrGPU['id'] != 'virtual') echo 'was';?>advanced label gpumultiline<?=$i?>">_(Multifunction)_:</span>
			<select id="GPUMultiSel<?=$i?>" name="gpu[<?=$i?>][multi]" class="<?if ($arrGPU['id']!='virtual') echo 'was';?>advanced narrow second gpumultiselect<?=$i?>" <?=$multifunction?>>
			<?
			echo mk_option($arrGPU['guest']['multi'], 'off', 'Off');
			echo mk_option($arrGPU['guest']['multi'], 'on', 'On');
			?>
			</select>
		</td>
		<td>
		<?if ($arrGPU['id'] == 'virtual') {?>
			<textarea class="xml" id="xmlgraphics<?=$i?>" rows="5" disabled><?=htmlspecialchars($xml2['devices']['graphics'][0])."\n".htmlspecialchars($xml2['devices']['video'][0])."\n".htmlspecialchars($xml2['devices']['audio'][0])?></textarea>
		<?} else {?>
			<textarea class="xml" id="xmlgraphics<?=$i?>" rows="5" disabled><?=htmlspecialchars($xml2['devices']['vga'][$arrGPU['id']])?></textarea>
		<?}?>
		</td>
	</tr>
	<?if ($i == 0) {
		$hiddenport = $hiddenwsport = "hidden";
		if ($arrGPU['autoport'] == "no") {
			if ($arrGPU['protocol'] == "vnc")   $hiddenport = $hiddenwsport = "";
			if ($arrGPU['protocol'] == "spice") $hiddenport = "";
		}
	?>
	<tr class="<?if ($arrGPU['id'] != 'virtual') echo 'was';?>advanced protocol">
		<td>_(VM Console Protocol)_:</td>
		<td>
			<span class="width"><select id="protocol" name="gpu[<?=$i?>][protocol]" class="narrow" onchange="ProtocolChange(this)">
			<?mk_dropdown_options($arrValidProtocols, $arrGPU['protocol']);?>
			</select></span>
		</td>
		<td></td>
	</tr>
	<tr id="copypasteline" name="copypaste" class="<?if ($arrGPU['id'] != 'virtual') echo 'was';?>advanced copypaste">
		<td>_(VM Console enable Copy/paste)_:</td>
		<td>
			<span class="width"><select id="copypaste" name="gpu[<?=$i?>][copypaste]" class="narrow">
			<?
			echo mk_option($arrGPU['copypaste'], 'no', _('No'));
			echo mk_option($arrGPU['copypaste'], 'yes', _('Yes'));
			?>
			</select></span>
		</td>
		<td></td>
	</tr>
	<tr id="autoportline" name="autoportline" class="<?if ($arrGPU['id'] != 'virtual') echo 'was';?>advanced autoportline">
		<td>_(VM Console AutoPort)_:</td>
		<td>
			<span class="width"><select id="autoport" name="gpu[<?=$i?>][autoport]" class="narrow" onchange="AutoportChange(this)">
			<?
			echo mk_option($arrGPU['autoport'], 'yes', _('Yes'));
			echo mk_option($arrGPU['autoport'], 'no', _('No'));
			?>
			</select></span>
			<span id="Porttext" class="label <?=$hiddenport?>">_(VM Console Port)_:</span>
			<input id="port" onchange="checkVNCPorts()" min="5900" max="65535" type="number" size="5" maxlength="5" class="trim second <?=$hiddenport?>" name="gpu[<?=$i?>][port]" value="<?=$arrGPU['port']?>">
			<span id="WSPorttext" class="label <?=$hiddenwsport?>">_(VM Console WS Port)_:</span>
			<input id="wsport" onchange="checkVNCPorts()" min="5700" max="5899" type="number" size="5" maxlength="5" class="trim second <?=$hiddenwsport?>" name="gpu[<?=$i?>][wsport]" value="<?=$arrGPU['wsport']?>">
		</td>
		<td></td>
	</tr>
	<tr class="<?if ($arrGPU['id'] != 'virtual') echo 'was';?>advanced vncmodel">
		<td>_(VM Console Video Driver)_:</td>
		<td>
			<span class="width"><select id="vncmodel" name="gpu[<?=$i?>][model]" class="narrow" onchange="VMConsoleDriverChange(this)">
			<?mk_dropdown_options($arrValidVNCModels, $arrGPU['model']);?>
			</select></span>
			<?if ($arrGPU['model'] == "virtio3d") $vncrender = ""; else $vncrender = "hidden";?>
			<span id="vncrendertext" class="label <?=$vncrender?>">_(Render GPU)_:</span>
			<select id="vncrender" name="gpu[<?=$i?>][render]" class="second <?=$vncrender?>">
			<?
			echo mk_option($arrGPU['render'], 'auto', _('Auto'));
			foreach ($arrValidGPUDevices as $arrDev) {
				if (($arrDev['vendorid'] == "10de" && ($arrDev['driver'] == "nvidia" && !is_file("/etc/libvirt/virglnv"))) || $arrDev['driver'] == "vfio-pci") continue;
				echo mk_option($arrGPU['render'], $arrDev['id'], $arrDev['name'].' ('.$arrDev['id'].')');
			}
			?>
			</select>
			<?
			$arrGPU['DisplayOptions'] = htmlentities($arrDisplayOptions[$arrGPU['DisplayOptions']]['qxlxml'],ENT_QUOTES);
			if ($arrGPU['model'] == "qxl") $vncdspopt = ""; else $vncdspopt = "hidden";
			?>
			<span id="vncdspopttext" class="label <?=$vncdspopt?>">_(Display(s) and RAM)_:</span>
			<select id="vncdspopt" name="gpu[<?=$i?>][DisplayOptions]" class="second <?=$vncdspopt?>">
			<?
			foreach ($arrDisplayOptions as $key => $value) {
				if ($arrGPU['protocol'] == 'vnc' && substr($key,0,2) != "H1") continue;
				echo mk_option($arrGPU['DisplayOptions'], htmlentities($value['qxlxml'],ENT_QUOTES), _($value['text']));
			}
			?>
			</select>
		</td>
		<td></td>
	</tr>
	<tr class="vncpassword">
		<td>_(VM Console Password)_:</td>
		<td>
			<span class="width"><input type="password" name="domain[password]" autocomplete='new-password' value="<?=$arrGPU['password']?>" placeholder="_(password for VM Console)_ (_(optional)_)"/></span>
		</td>
		<td></td>
	</tr>
	<tr class="<?if ($arrGPU['id'] != 'virtual') echo 'was';?>advanced vnckeymap">
		<td>_(VM Console Keyboard)_:</td>
		<td>
			<span class="width"><select name="gpu[<?=$i?>][keymap]" class="narrow">
			<?mk_dropdown_options($arrValidKeyMaps, $arrGPU['keymap']);?>
			</select></span>
		</td>
		<td></td>
	</tr>
	<?}?>
	<tr class="<?if ($arrGPU['id'] == 'virtual' || $arrGPU['id'] == 'nogpu') echo 'was';?>advanced romfile">
		<td>_(Graphics ROM BIOS)_:</td>
		<td>
			<span class="width"><input type="text" name="gpu[<?=$i?>][rom]" autocomplete="off" spellcheck="false" data-pickcloseonfile="true" data-pickfilter="rom,bin" data-pickmatch="^[^.].*" data-pickroot="/mnt/" value="<?=htmlspecialchars($arrGPU['rom'])?>" placeholder="_(Path to ROM BIOS file)_ (_(optional)_)"></span>
			<span id="numagpu<?=$i?>" class="status-warn"></span>
		</td>
		<td></td>
	</tr>
	<?if ($arrValidGPUDevices[$arrGPU['id']]['bootvga'] == "1") $bootgpuhidden = "";?>
	<tr id="gpubootvga<?=$i?>" class="<?=$bootgpuhidden?>"><td>_(Graphics ROM Needed)_?:</td><td><span class="orange-text"><i class="fa fa-warning"></i> _(GPU is primary adapter, vbios may be required)_.</span></td></tr>
	<tr id="gpupcichange<?=$i?>" class="hidden"><td>_(PCI Check)_:</td><td><span class="orange-text"><i class="fa fa-warning"></i></span></td></tr>
</table>
<?if ($i == 0 || $i == 1) {?>
<blockquote class="inline_help">
	<p>Additional devices can be added/removed by clicking the symbols to the left.</p>
</blockquote>
<?}?>
<?}?>

<script type="text/html" id="tmplGraphics_Card">
<table>
	<tr>
		<td>_(Graphics Card)_:</td>
		<td>
			<span class="width"><select name="gpu[{{INDEX}}][id]" class="gpu narrow" data-numawarn="numagpu{{INDEX}}">
			<?
			echo mk_option('', '', _('None'));
			foreach ($arrValidGPUDevices as $arrDev) echo mk_option('', $arrDev['id'], $arrDev['name'].' ('.$arrDev['id'].')');
			?>
			</select></span>
			<span id="GPUMulti" name="gpu[{{INDEX}}][multi]" class="label">_(Multifunction)_:</span>
			<select name="gpu[{{INDEX}}][multi]" class="narrow second">
			<?
			echo mk_option("off", 'off', 'Off');
			echo mk_option("off", 'on', 'On');
			?>
			</select>
		</td>
		<td></td>
	</tr>
	<tr class="advanced romfile">
		<td>_(Graphics ROM BIOS)_:</td>
		<td>
			<span class="width"><input type="text" name="gpu[{{INDEX}}][rom]" autocomplete="off" spellcheck="false" data-pickcloseonfile="true" data-pickfilter="rom,bin" data-pickmatch="^[^.].*" data-pickroot="/mnt/" value="" placeholder="_(Path to ROM BIOS file)_ (_(optional)_)"></span>
			<span id="numagpu{{INDEX}}" class="status-warn"></span>
		</td>
		<td></td>
	</tr>
	<tr id="gpubootvga{{INDEX}}" class="hidden"><td>_(Graphics ROM Needed)_?:</td><td><span class="orange-text"><i class="fa fa-warning"></i> _(GPU is primary adapter, vbios may be required)_.</span></td></tr>
	<tr id="gpupcichange{{INDEX}}" class="hidden"><td>_(PCI Check)_:</td><td><span class="orange-text"><i class="fa fa-warning"></i></span></td></tr>
</table>
</script>

<?foreach ($arrConfig['audio'] as $i => $arrAudio) {
	$strLabel = ($i > 0) ? appendOrdinalSuffix($i + 1) : '';
?>
<table data-category="Sound_Card" data-multiple="true" data-minimum="1" data-maximum="<?=count($arrValidAudioDevices)?>" data-index="<?=$i?>" data-prefix="<?=$strLabel?>">
	<tr>
		<td>_(Sound Card)_:</td>
		<td>
			<span class="width"><select name="audio[<?=$i?>][id]" class="audio narrow" data-numawarn="numaaudio<?=$i?>">
			<?
			echo mk_option($arrAudio['id'], '', _('None'));
			foreach ($arrValidAudioDevices as $arrDev) echo mk_option($arrAudio['id'], $arrDev['id'], $arrDev['name'].' ('.$arrDev['id'].')');
			foreach ($arrValidSoundCards as $arrSound) echo mk_option($arrAudio['id'], $arrSound['id'], $arrSound['name'].' ('._("Virtual").')');
			?>
			</select></span>
			<span id="numaaudio<?=$i?>" class="status-warn"></span>
		</td>
		<td>
			<textarea class="xml" id="xmlaudio<?=$i?>" rows="5" disabled><?=htmlspecialchars($xml2['devices']['audio'][$arrAudio['id']])?></textarea>
		</td>
	</tr>
	<tr id="audiopcichange<?=$i?>" class="hidden"><td></td><td><span class="orange-text"><i class="fa fa-warning"></i></span></td></tr>
</table>
<?if ($i == 0) {?>
<blockquote class="inline_help">
	<p>Select a sound device to assign to your VM. Additional devices can be added/removed by clicking the symbols to the left.</p>
</blockquote>
<?}?>
<?}?>

<script type="text/html" id="tmplSound_Card">
<table>
	<tr>
		<td>_(Sound Card)_:</td>
		<td>
			<span class="width"><select name="audio[{{INDEX}}][id]" class="audio narrow" data-numawarn="numaaudio{{INDEX}}">
			<?
			foreach ($arrValidAudioDevices as $arrDev) echo mk_option('', $arrDev['id'], $arrDev['name'].' ('.$arrDev['id'].')');
			foreach ($arrValidSoundCards as $arrSound) echo mk_option($arrAudio['id'], $arrSound['id'], $arrSound['name'].' ('._("Virtual").')');
			?>
			</select></span>
			<span id="numaaudio{{INDEX}}" class="status-warn"></span>
		</td>
		<td></td>
	</tr>
	<tr id="audiopcichange{{INDEX}}" class="hidden"><td></td><td><span class="orange-text"><i class="fa fa-warning"></i></span></td></tr>
</table>
</script>

<?
if ($arrConfig['nic'] == false) {
	$arrConfig['nic']['0'] = ['network' => $domain_bridge, 'mac' => "", 'model' => 'virtio-net'];
}
foreach ($arrConfig['nic'] as $i => $arrNic) {
	$strLabel = ($i > 0) ? appendOrdinalSuffix($i + 1) : '';
	$disabled = $arrNic['network']=='wlan0' ? 'disabled' : '';
?>
<table data-category="Network" data-multiple="true" data-minimum="1" data-index="<?=$i?>" data-prefix="<?=$strLabel?>">
	<tr class="advanced">
		<td>_(Network MAC)_:</td>
		<td>
			<span class="width"><input type="text" name="nic[<?=$i?>][mac]" class="narrow" value="<?=htmlspecialchars($arrNic['mac'])?>" <?=$disabled?>><i class="fa fa-refresh mac_generate <?=$i?>" <?=$disabled?>></i></span>
		</td>
		<td>
			<textarea class="xml" id="xmlnet<?=$i?>" rows="5" disabled><?=htmlspecialchars($xml2['devices']['interface'][$i])?></textarea>
		</td>
	</tr>
	<tr class="advanced">
		<td>_(Network Source)_:</td>
		<td>
			<span class="width"><select name="nic[<?=$i?>][network]" class="narrow" onchange="updateMAC(<?=$i?>,this.value)">
			<?
			foreach (array_keys($arrValidNetworks) as $key) {
				echo mk_option("", $key, "- "._($key)." -", "disabled");
				foreach ($arrValidNetworks[$key] as $strNetwork) echo mk_option($arrNic['network'], $strNetwork, $strNetwork);
			}
			$wlan0_hidden = $arrNic['network'] == 'wlan0' ? '' : 'hidden';
			?>
			</select><span class="wlan0 orange-text <?=$wlan0_hidden?>"><i class="fa fa-fw fa-warning"></i> _(Manual configuration required)_ <input type="button" class="wlan0_info" value="_(Info)_" onclick="wlan0_info()"></span></span>
		</td>
		<td></td>
	</tr>
	<tr class="advanced">
		<td>_(Network Model)_:</td>
		<td>
			<span class="width"><select name="nic[<?=$i?>][model]" class="narrow">
			<?
			echo mk_option($arrNic['model'], 'virtio-net', 'virtio-net');
			echo mk_option($arrNic['model'], 'virtio', 'virtio');
			echo mk_option($arrNic['model'], 'e1000', 'e1000');
			echo mk_option($arrNic['model'], 'rtl8139', 'rtl8139');
			echo mk_option($arrNic['model'], 'vmxnet3', 'vmxnet3');
			?>
			</select></span>
		</td>
		<td></td>
	</tr>
	<tr class="advanced">
		<td>_(Boot Order)_:</td>
		<td>
			<span class="width"><input type="number" size="5" maxlength="5" id="nic[<?=$i?>][boot]" class="trim bootorder" <?=$bootdisable?> name="nic[<?=$i?>][boot]" value="<?=$arrNic['boot']?>"></span>
		</td>
		<td></td>
	</tr>
</table>
<?if ($i == 0) {?>
<div class="advanced">
	<blockquote class="inline_help">
		<p>Additional network devices can be added/removed by clicking the symbols to the left.</p>
	</blockquote>
</div>
<?}?>
<?}?>

<script type="text/html" id="tmplNetwork">
<table>
	<tr class="advanced">
		<td>_(Network MAC)_:</td>
		<td>
			<span class="width"><input type="text" name="nic[{{INDEX}}][mac]" class="narrow" value=""> <i class="fa fa-refresh mac_generate INDEX"></i></span>
		</td>
	</tr>
	<tr class="advanced">
		<td>_(Network Source)_:</td>
		<td>
			<span class="width"><select name="nic[{{INDEX}}][network]" class="narrow" onchange="updateMAC(INDEX,this.value)">
			<?
			foreach (array_keys($arrValidNetworks) as $key) {
				echo mk_option("", $key, "- "._($key)." -", "disabled");
				foreach ($arrValidNetworks[$key] as $strNetwork) echo mk_option($domain_bridge, $strNetwork, $strNetwork);
			}
			?>
			</select><span class="wlan0 orange-text hidden"><i class="fa fa-fw fa-warning"></i> _(Manual configuration required)_ <input type="button" class="wlan0_info" value="_(Info)_" onclick="wlan0_info()"></span></span>
		</td>
		<td></td>
	</tr>
	<tr class="advanced">
		<td>_(Network Model)_:</td>
		<td>
			<span class="width"><select name="nic[{{INDEX}}][model]" class="narrow">
			<?
			echo mk_option(1, 'virtio-net', 'virtio-net');
			echo mk_option(1, 'virtio', 'virtio');
			echo mk_option($arrNic['model'], 'e1000', 'e1000');
			echo mk_option($arrNic['model'], 'vmxnet3', 'vmxnet3');
			?>
			</select></span>
		</td>
		<td></td>
	</tr>
	<tr class="advanced">
		<td>_(Boot Order)_:</td>
		<td>
			<span class="width"><input type="number" size="5" maxlength="5" id="nic[{{INDEX}}][boot]" class="trim bootorder" <?=$bootdisable?> name="nic[{{INDEX}}][boot]" value=""></span>
		</td>
		<td></td>
	</tr>
</table>
</script>

<table>
	<tr>
		<td>_(USB Devices)_:</td>
		<td>
			<span class="space">_(Select)_</span><span class="space">_(Optional)_</span><span class="space">_(Boot Order)_</span><br>
			<?
			if (!empty($arrVMUSBs)) {
				foreach ($arrVMUSBs as $i => $arrDev) {
				?>
				<label for="usb<?=$i?>">
				<span class="space"><input type="checkbox" name="usb[]" id="usb<?=$i?>" value="<?=htmlspecialchars($arrDev['id'])?>" <?if (count(array_filter($arrConfig['usb'], function($arr) use ($arrDev){return ($arr['id']==$arrDev['id']);}))) echo 'checked';?>></span>
				<span class="space"><input type="checkbox" name="usbopt[<?=htmlspecialchars($arrDev['id'])?>]" id="usbopt<?=$i?>" value="<?=htmlspecialchars($arrDev['id'])?>"<?if ($arrDev["startupPolicy"]=="optional") echo ' checked=';?>></span>
				<input type="number" size="5" maxlength="5" id="usbboot<?=$i?>" class="trim bootorder" <?=$bootdisable?> name="usbboot[<?=htmlspecialchars($arrDev['id'])?>]" value="<?=$arrDev['usbboot']?>">
				<?=htmlspecialchars(substr($arrDev['name'],0,90))?> (<?=htmlspecialchars($arrDev['id'])?>)
				</label><br>
				<?
				}
			} else {
				echo "<i>"._('None available')."</i><br>";
			}
			?>
			<br>
		</td>
		<td>
			<textarea class="xml" id="xmlusb<?=$i?>" rows="5" disabled><?=htmlspecialchars($xml2['devices']['allusb'])?></textarea>
		</td>
	</tr>
</table>
<blockquote class="inline_help">
	<p>If you wish to assign any USB devices to your guest, you can select them from this list.</p>
	<p>Select optional if you want the device to be ignored when the VM starts if not present.</p>
</blockquote>

<table>
	<tr>
		<td>_(Other PCI Devices)_:</td>
		<td>
			<span class="space">_(Select)_</span><span class="space">_(Boot Order)_</span><br>
			<?
			$intAvailableOtherPCIDevices = 0;
			if (!empty($arrValidOtherDevices)) {
				foreach ($arrValidOtherDevices as $i => $arrDev) {
					$bootdisable = $extra = $pciboot = '';
					if ($arrDev["typeid"] != "0108" && substr($arrDev["typeid"],0,2) != "02") $bootdisable = ' disabled="disabled"';
					if (count($pcidevice=array_filter($arrConfig['pci'], function($arr) use ($arrDev) { return ($arr['id'] == $arrDev['id']); }))) {
						$extra .= ' checked="checked"';
						foreach ($pcidevice as $pcikey => $pcidev) $pciboot = $pcidev["boot"];
					} elseif (!in_array($arrDev['driver'], ['pci-stub', 'vfio-pci'])) {
						continue;
					}
					$intAvailableOtherPCIDevices++;
				?>
				<label for="pci<?=$i?>">&nbsp;&nbsp;&nbsp;&nbsp;<input type="checkbox" name="pci[]" id="pci<?=$i?>" value="<?=htmlspecialchars($arrDev['id'])?>" <?=$extra?> data-numawarn="numapci<?=$i?>"/> &nbsp;
				<input type="number" size="5" maxlength="5" id="pciboot<?=$i?>" class="trim pcibootorder" <?=$bootdisable?> name="pciboot[<?=htmlspecialchars($arrDev['id'])?>]" value="<?=$pciboot?>">
				<?=htmlspecialchars($arrDev['name'])?> | <?=htmlspecialchars($arrDev['type'])?> (<?=htmlspecialchars($arrDev['id'])?>)
				<?if (isset($PCIchanges["0000:".$i])) {
					echo " <i class=\"fa fa-warning fa-fw orange-text\" title=\""._('PCI Change')."\n";
					if ($PCIchanges["0000:".$i]['status']=="changed") {
						echo _("Differences");
						foreach($PCIchanges["0000:".$i]['differences'] as $key => $changes) {
							echo " $key "._("before").":{$changes['old']} "._("after").":{$changes['new']} ";
						}
						echo "\n".$PCIchanges["0000:".$i]['device']['description']."\"></i>";
						echo ucfirst($PCIchanges["0000:".$i]['status']);
					}
				}?>
				</label><span id="numapci<?=$i?>" class="status-warn" style="display:none;"></span><br>
				<?
				}
			}
			if (!empty($arrConfig['pci'])) {
				foreach ($arrConfig['pci'] as $pci) {
					if (!$pci['found']) {
						$i = $pci['id'];
						$intAvailableOtherPCIDevices++;
						?>
						<label for="pci<?=$i?>">&nbsp;&nbsp;&nbsp;&nbsp;<input type="checkbox" name="pci[]" id="pci<?=$i?>" value="<?=htmlspecialchars($pci['id'])?>" checked="checked" data-numawarn="numapci<?=$i?>"/> &nbsp;
						<input type="number" size="5" maxlength="5" id="pciboot<?=$i?>" class="trim pcibootorder" disabled="disabled" name="pciboot[<?=htmlspecialchars($i)?>]" value="<?=$pci['boot']?>">
						_(Old PCI Address)_:<?=htmlspecialchars($i);?>
						<?if (isset($PCIchanges["0000:".$i])) echo " <i class=\"fa fa-warning fa-fw orange-text\" title=\""._('PCI Removed')."\"></i>"." ".ucfirst($PCIchanges["0000:".$i]['status'])." ".$PCIchanges["0000:".$i]['device']['description'];?>
						<span id="numapci<?=$i?>" class="status-warn"></span>
						</label><br>
						<?
					}
				}
			}
			if (empty($intAvailableOtherPCIDevices)) echo "<i>"._('None available')."</i><br>";
			?>
			<br>
		</td>
		<td>
			<textarea class="xml" id="xmlpci<?=$i?>" rows="2" disabled><?=htmlspecialchars($xml2['devices']['other']["allotherpci"])?></textarea>
		</td>
	</tr>
</table>
<blockquote class="inline_help">
	<p>If you wish to assign any other PCI devices to your guest, you can select them from this list.</p>
</blockquote>

<table>
	<tr>
		<td></td>
		<td>
		<?if (!$boolNew) {?>
			<input type="hidden" name="updatevm" value="1"/>
			<input type="button" value="_(Update)_" busyvalue="_(Updating)_..." readyvalue="_(Update)_" id="btnSubmit"/>
		<?} else {?>
			<label for="domain_start"><input type="checkbox" name="domain[startnow]" id="domain_start" value="1" checked="checked"/> _(Start VM after creation)_</label>
			<br>
			<input type="hidden" name="createvm" value="1"/>
			<input type="button" value="_(Create)_" busyvalue="_(Creating)_..." readyvalue="_(Create)_" id="btnSubmit"/>
		<?}?>
			<input type="button" value="_(Cancel)_" id="btnCancel"/>
		</td>
		<td></td>
	</tr>
</table>

<hr>
<table>
	<tr>
		<td>_(QEMU Command Line)_:</td>
		<?$qemurows = $arrConfig['qemucmdline']=="" ? 2 : 15;?>
		<td>
			_(Advanced tuning options)_<br>
			<textarea id="qemucmdline" name="qemucmdline" class="xmlqemu" rows="<?=$qemurows?>" onchange="QEMUChgCmd(this)"><?=htmlspecialchars($arrConfig['qemucmdline'])."\n".htmlspecialchars($arrConfig['qemuoverride'])?></textarea>
		</td>
		<td></td>
	</tr>
</table>

<table class="timers">
	<tr>
		<td></td>
		<td>_(Clocks)_</td>
		<td></td>
	</tr>
	<tr>
		<td>_(Clocks Offset)_:</td>
		<td>
			<span class="width"><select name="domain[clock]" id="clockoffset" class="narrow <?=$arrConfig["domain"]['clock']?>">
			<?
			echo mk_option($arrConfig['domain']['clock'], 'localtime', 'Localtime');
			echo mk_option($arrConfig['domain']['clock'], 'utc', "UTC");
			?>
			</select></span>
		</td>
		<td>
			<textarea class="xml" id="xmlclock" rows="5" disabled><?=htmlspecialchars($xml2['clock'])."\n".htmlspecialchars($xml2['on_poweroff'])."\n".htmlspecialchars($xml2['on_reboot'])."\n".htmlspecialchars($xml2['on_crash'])?></textarea>
		</td>
	</tr>
	<?
	$clockcount = 0;
	if (!empty($arrClocks)) {
		foreach ($arrClocks as $i => $arrTimer) {
			if ($i == 'offset') continue;
			$clocksourcetext = ($clockcount == 0) ? _('Timer Source').':' : "";
	?>
	<tr>
		<td><?=$clocksourcetext?></td>
		<td>
			<span class="column1"><span><?=ucfirst($i)?>:</span></span>
			<span class="column2">_(Present)_:
			<select name="clock[<?=$i?>][present]" id="clock[<?=$i?>][present]" class="narrow second" <?=$arrTimer["present"]?>>
			<?
			echo mk_option($arrTimer["present"], 'yes', 'Yes');
			echo mk_option($arrTimer["present"], 'no', "No");
			?>
			</select></span>
			_(Tickpolicy)_:
			<select name="clock[<?=$i?>][tickpolicy]" id="clock[<?=$i?>][tickpolicy]" class="narrow second" <?=$arrTimer["tickpolicy"]?>>
			<?
			echo mk_option($arrTimer["tickpolicy"], 'delay', 'Delay');
			echo mk_option($arrTimer["tickpolicy"], 'catchup', 'Catchup');
			echo mk_option($arrTimer["tickpolicy"], 'merge', "Merge");
			echo mk_option($arrTimer["tickpolicy"], 'discard', "Discard");
			?>
			</select>
		</td>
	</tr>
	<?
			$clockcount++;
		}
	}
	?>
</table>

<?
if (!isset($arrConfig['evdev'])) $arrConfig['evdev'][0] = ['dev'=>"",'grab'=>"",'repeat'=>"",'grabToggle'=>""];
foreach ($arrConfig['evdev'] as $i => $arrEvdev) {
	$strLabel = ($i > 0) ? appendOrdinalSuffix($i + 1) : '';
?>
<table data-category="evdev" data-multiple="true" data-minimum="1" data-index="<?=$i?>" data-prefix="<?=$strLabel?>">
	<tr>
		<td>_(Evdev Device)_:</td>
		<td>
			<span class="width"><select name="evdev[<?=$i?>][dev]" class="dev narrow">
			<?
			echo mk_option($arrEvdev['dev'], '', _('None'));
			foreach (getValidevDev() as $line) echo mk_option($arrEvdev['dev'], $line, $line);
			?>
			</select></span>
		</td>
		<td>
			<textarea class="xml" id="xmlevdev<?=$i?>" rows="5" disabled><?=htmlspecialchars($xml2['devices']['allinput'])?></textarea>
		</td>
	</tr>
	<tr class="advanced disk_file_options">
		<td>_(Grab)_:</td>
		<td>
			<span class="width"><select name="evdev[<?=$i?>][grab]" class="evdev_grab narrow">
			<?
			echo mk_option($arrEvdev['grab'], '', _('None'));
			foreach (["all"] as $line) echo mk_option($arrEvdev['grab'],$line,ucfirst($line));
			?>
			</select></span>
		</td>
		<td></td>
	</tr>
	<tr class="advanced disk_file_options">
		<td>_(Repeat)_:</td>
		<td>
			<span class="width"><select name="evdev[<?=$i?>][repeat]" class="evdev_repeat narrow">
			<?
			echo mk_option($arrEvdev['repeat'], '', _('None'));
			foreach (["on","off"] as $line) echo mk_option($arrEvdev['repeat'],$line,ucfirst($line));
			?>
			</select></span>
		</td>
		<td></td>
	</tr>
	<tr class="advanced disk_file_options">
		<td>_(Grab Toggle)_:</td>
		<td>
			<span class="width"><select name="evdev[<?=$i?>][grabToggle]" class="evdev_grabtoggle narrow">
			<?
			echo mk_option($arrEvdev['grabToggle'], '', _('None'));
			foreach (["ctrl-ctrl", "alt-alt", "shift-shift", "meta-meta", "scrolllock", "ctrl-scrolllock"] as $line) echo mk_option($arrEvdev['grabToggle'],$line,$line);
			?>
			</select></span>
		</td>
		<td></td>
	</tr>
</table>
<?if ($i == 0) {?>
<table>
	<tr class="advanced">
		<td><span class="advanced">_(Physical Address Bit Limit)_</span></td>
		<td>
			<span class="width"><select id="cpupmemlmt" name="domain[cpupmemlmt]" class="cpupmem">
			<?
			echo mk_option($arrConfig['domain']['cpupmemlmt'], 'None', 'None');
			echo mk_option($arrConfig['domain']['cpupmemlmt'], '32', '32-bit (4 GB)');
			echo mk_option($arrConfig['domain']['cpupmemlmt'], '36', '36-bit (64 GB)');
			echo mk_option($arrConfig['domain']['cpupmemlmt'], '39', '39-bit (512 GB)');
			echo mk_option($arrConfig['domain']['cpupmemlmt'], '42', '42-bit (4 TB)');
			echo mk_option($arrConfig['domain']['cpupmemlmt'], '48', '48-bit (256 TB)');
			?>
			</select></span>
		</td>
	</tr>
</table>
<?}?>
<?}?>

<script type="text/html" id="tmplevdev">
<table>
	<tr>
		<td>_(Evdev Device)_:</td>
		<td>
			<span class="width"><select name="evdev[{{INDEX}}][dev]" class="dev narrow">
			<?
			echo mk_option("", '', _('None'));
			foreach (getValidevDev() as $line) echo mk_option("", $line, $line);
			?>
			</select></span>
		</td>
		<td></td>
	</tr>
	<tr class="advanced disk_file_options">
		<td>_(Grab)_:</td>
		<td>
			<span class="width"><select name="evdev[{{INDEX}}][grab]" class="evdev_grab narrow">
			<?
			echo mk_option("", '', _('None'));
			foreach (["all"] as $line) echo mk_option("",$line,ucfirst($line));
			?>
			</select></span>
		</td>
		<td></td>
	</tr>
	<tr class="advanced disk_file_options">
		<td>_(Repeat)_:</td>
		<td>
			<span class="width"><select name="evdev[{{INDEX}}][repeat]" class="evdev_repeat narrow">
			<?
			echo mk_option("", '', _('None'));
			foreach (["on","off"] as $line) echo mk_option("",$line,ucfirst($line));
			?>
			</select></span>
		</td>
		<td></td>
	</tr>
	<tr class="advanced disk_file_options">
		<td>_(Grab Toggle)_:</td>
		<td>
			<span class="width"><select name="evdev[{{INDEX}}][grabToggle]" class="evdev_grabtoggle narrow">
			<?
			echo mk_option("", '', _('None'));
			foreach (["ctrl-ctrl", "alt-alt", "shift-shift", "meta-meta", "scrolllock", "ctrl-scrolllock"] as $line) echo mk_option("",$line,$line);
			?>
			</select></span>
		</td>
		<td></td>
	</tr>
</table>
</script>

<table>
	<tr class="xml">
		<td>_(Other XML)_:</td>
		<td></td>
		<td>
			<textarea id="xmlother" name="xmlother" disabled class="xml" rows="10"><?=htmlspecialchars($xml2['devices']['emulator'][0])."\n".htmlspecialchars($xml2['devices']['console'][0])."\n".htmlspecialchars($xml2['devices']['serial'][0])."\n".htmlspecialchars($xml2['devices']['channel'][0])."\n"?></textarea>
		</td>
	</tr>
</table>

<table>
	<tr>
		<td></td>
		<td>
		<?if (!$boolNew) {?>
			<input type="hidden" name="updatevm" value="1"/>
			<input type="button" value="_(Update)_" busyvalue="_(Updating)_..." readyvalue="_(Update)_" id="btnSubmit"/>
		<?} else {?>
			<input type="hidden" name="createvm" value="1"/>
			<input type="button" value="_(Create)_" busyvalue="_(Creating)_..." readyvalue="_(Create)_" id="btnSubmit"/>
		<?}?>
			<input type="button" value="_(Cancel)_" id="btnCancel"/>
		</td>
		<td></td>
	</tr>
</table>

</div><!-- /.installed -->
</div><!-- /.formview -->

<div class="xmlview">
<textarea id="addcode" name="xmldesc" placeholder="_(Copy &amp; Paste Domain XML Configuration Here)_." autofocus><?=htmlspecialchars($hdrXML).htmlspecialchars($strXML)?></textarea>
<table>
	<tr>
		<td></td>
		<td>
		<?if (!$boolRunning) {?>
		<?if ($strXML) {?>
			<input type="hidden" name="updatevm" value="1"/>
			<input type="button" value="_(Update)_" busyvalue="_(Updating)_..." readyvalue="_(Update)_" id="btnSubmit"/>
		<?} else {?>
			<label for="xmldomain_start"><input type="checkbox" name="domain[xmlstartnow]" id="xmldomain_start" value="1" checked="checked"/> _(Start VM after creation)_</label>
			<br>
			<input type="hidden" name="createvm" value="1"/>
			<input type="button" value="_(Create)_" busyvalue="_(Creating)_..." readyvalue="_(Create)_" id="btnSubmit"/>
		<?}?>
			<input type="button" value="_(Cancel)_" id="btnCancel"/>
		<?} else {?>
			<input type="button" value="_(Back)_" id="btnCancel"/>
		<?}?>
		</td>
		<td></td>
	</tr>
</table>
</div>

<script src="<?autov('/plugins/dynamix.vm.manager/scripts/codemirror/lib/codemirror.js')?>"></script>
<script src="<?autov('/plugins/dynamix.vm.manager/scripts/codemirror/addon/display/placeholder.js')?>"></script>
<script src="<?autov('/plugins/dynamix.vm.manager/scripts/codemirror/addon/fold/foldcode.js')?>"></script>
<script src="<?autov('/plugins/dynamix.vm.manager/scripts/codemirror/addon/hint/show-hint.js')?>"></script>
<script src="<?autov('/plugins/dynamix.vm.manager/scripts/codemirror/addon/hint/xml-hint.js')?>"></script>
<script src="<?autov('/plugins/dynamix.vm.manager/scripts/codemirror/addon/hint/libvirt-schema.js')?>"></script>
<script src="<?autov('/plugins/dynamix.vm.manager/scripts/codemirror/mode/xml/xml.js')?>"></script>
<script type="text/javascript">
var storageType = "<?=get_storage_fstype($arrConfig['template']['storage']);?>";
var storageLoc  = "<?=$arrConfig['template']['storage']?>";

function checkVNCPorts() {
	const port = $("#port").val();
	const wsport = $("#wsport").val();
	if (port < 5900 || port > 65535 || wsport < 5700 || wsport > 5899 || port == wsport) {
		swal({title:"_(Invalid Port)_",text:"_(VNC/SPICE ports must be between 5900 and 65535, and cannot be equal to each other. WS port should be between 5700 and 5899)_",type:"error",confirmButtonText:"_(Ok)_"});
	}
}

function updateMAC(index, port) {
	var wlan0 = '<?=$mac?>';
	var mac   = $('input[name="nic['+index+'][mac]"');
	mac.prop('disabled', port=='wlan0');
	$('i.mac_generate.'+index).prop('disabled', port=='wlan0');
	$('span.wlan0').removeClass('hidden');
	if (port == 'wlan0') {
		mac.val(wlan0);
	} else {
		$('span.wlan0').addClass('hidden');
		if (wlan0 && mac.val()==wlan0) $('i.mac_generate.'+index).click();
	}
}

function ShareChange(share) {
	var text   = share.options[share.selectedIndex].text;
	var strArray = text.split(":");
	var index  = share.name.indexOf("]") + 1;
	var name   = share.name.substr(0,index);
	var path   = (strArray[0] === "User") ? "/mnt/user/" + strArray[1] : "/mnt/" + strArray[1];
	if (strArray[0] != "Manual") {
		$('#'+name+"[target]").val(strArray[1]);
		$('#'+name+"[source]").val(path);
		$('#'+name+"[target]").prop("disabled",true);
		$('#'+name+"[source]").prop("disabled",true);
	} else {
		$('#'+name+"[target]").prop("disabled",false);
		$('#'+name+"[source]").prop("disabled",false);
	}
}

function BIOSChange(value) {
	$("#USBBoottext").removeClass('hidden');
	$("#domain_usbboot").removeClass('hidden');
	if (value == "0") {
		$("#USBBoottext").addClass('hidden');
		$("#domain_usbboot").addClass('hidden');
	}
}

function QEMUChgCmd(qemu) {
	if (qemu.value != "") $("#qemucmdline").attr("rows","15");
	else                   $("#qemucmdline").attr("rows","2");
}

function SetBootorderfields(usbbootvalue) {
	var bootelements = document.getElementsByClassName("bootorder");
	for (var i = 0; i < bootelements.length; i++) {
		if (usbbootvalue == "Yes") { bootelements[i].value = ""; bootelements[i].setAttribute("disabled","disabled"); }
		else bootelements[i].removeAttribute("disabled");
	}
	var bootelements = document.getElementsByClassName("pcibootorder");
	const bootpcidevs = <?
		$devlist = [];
		foreach ($arrValidOtherDevices as $i => $arrDev) {
			if ($arrDev["typeid"] != "0108" && substr($arrDev["typeid"],0,2) != "02") $devlist[$arrDev['id']] = "N";
			else $devlist[$arrDev['id']] = "Y";
		}
		echo json_encode($devlist);
	?>;
	for (var i = 0; i < bootelements.length; i++) {
		let bootpciid = bootelements[i].name.split('[');
		bootpciid = bootpciid[1].replace(']', '');
		if (usbbootvalue == "Yes") { bootelements[i].value = ""; bootelements[i].setAttribute("disabled","disabled"); }
		else { if (bootpcidevs[bootpciid] === "Y") bootelements[i].removeAttribute("disabled"); }
	}
}

function USBBootChange(usbboot) {
	SetBootorderfields(usbboot.value);
}

function AutoportChange(autoport) {
	$("#port").removeClass('hidden');
	$("#Porttext").removeClass('hidden');
	$("#wsport").removeClass('hidden');
	$("#WSPorttext").removeClass('hidden');
	if (autoport.value == "yes") {
		$("#port").addClass('hidden');
		$("#Porttext").addClass('hidden');
		$("#wsport").addClass('hidden');
		$("#WSPorttext").addClass('hidden');
	} else {
		var protocol = document.getElementById("protocol").value;
		if (protocol != "vnc") {
			$("#wsport").addClass('hidden');
			$("#WSPorttext").addClass('hidden');
		}
	}
}

function VMConsoleDriverChange(driver) {
	if (driver.value == "virtio3d") { $("#vncrender").removeClass('hidden'); $("#vncrendertext").removeClass('hidden'); }
	else                            { $("#vncrender").addClass('hidden');    $("#vncrendertext").addClass('hidden'); }
	if (driver.value == "qxl") { $("#vncdspopt").removeClass('hidden'); $("#vncdspopttext").removeClass('hidden'); }
	else                       { $("#vncdspopt").addClass('hidden');    $("#vncdspopttext").addClass('hidden'); }
}

function ProtocolChange(protocol) {
	var autoport = $("#autoport").val();
	if (autoport != "yes") {
		$("#port").removeClass('hidden'); $("#Porttext").removeClass('hidden');
		if (protocol.value == "vnc") { $("#wsport").removeClass('hidden'); $("#WSPorttext").removeClass('hidden'); }
		else                         { $("#wsport").addClass('hidden');    $("#WSPorttext").addClass('hidden'); }
	}
	const select = document.getElementById('vncdspopt');
	const currentValue = select.value;
	select.innerHTML = '';
	let foundMatch = false;
	for (const key in displayOptions) {
		const opt = displayOptions[key];
		const xml = opt.qxlxml;
		const headsMatch = xml.match(/heads='(\d+)'/);
		const heads = headsMatch ? parseInt(headsMatch[1]) : 1;
		if (protocol.value === 'vnc' && heads !== 1) continue;
		const optionEl = document.createElement('option');
		optionEl.value = xml;
		optionEl.textContent = opt.text;
		if (!foundMatch && xml === currentValue) { optionEl.selected = true; foundMatch = true; }
		select.appendChild(optionEl);
	}
	if (!foundMatch && select.options.length > 0) select.options[0].selected = true;
}

function wlan0_info() {
	swal({title:"_(Manual Configuration Required)_",text:"<div class='wlan0'><i class='fa fa-fw fa-hand-o-right'></i> _(Configure the VM with a static IP address)_<br><br><i class='fa fa-fw fa-hand-o-right'></i> _(Only one VM can be active at the time)_<br><br><i class='fa fa-fw fa-hand-o-right'></i> _(Configure the same IP address on the ipvtap interface)_<br><span class='ipvtap'><i class='fa fa-fw fa-long-arrow-right'></i> ip addr add IP-ADDRESS dev shim-wlan0</span></div>",html:true,animation:"none",type:"info",confirmButtonText:"_(Ok)_"});
}

function checkName(name) {
	var isValidName = /^[A-Za-z0-9][A-Za-z0-9\-_.: ]*$/.test(name);
	$('#zfs-name').removeClass();
	if (isValidName) {
		$('#btnSubmit').prop("disabled",false);
		$('#zfs-name').addClass('hidden');
	} else {
		if (storageType == "zfs") $('#btnSubmit').prop("disabled",true);
		else { $('#btnSubmit').prop("disabled",false); $('#zfs-name').addClass('hidden'); }
	}
}

function get_storage_fstype(item) {
	storageLoc = item.value;
	$.post("/plugins/dynamix.vm.manager/include/VMajax.php", {action:"get_storage_fstype", storage:item.value}, function(data) {
		if (data.success && data.fstype) { storageType = data.fstype; checkName($("#domain_name").val()); }
	}, "json");
}

// NUMA info
var numaInfo = <?php echo json_encode($arrNumaInfo); ?>;

function getSelectedCpuNodes() {
	const nodes = new Set();
	document.querySelectorAll('.domain_vcpu:checked').forEach(cb => {
		const info = numaInfo.cpus["cpu" + cb.value];
		if (info) nodes.add(info.numa_node);
	});
	return [...nodes];
}

function showWarn(el) { if (el) el.style.display = "inline"; }
function hideWarn(el) { if (el) el.style.display = "none"; }
function updateWarn(el, text) { if (el) el.textContent = text; }

const cpuWarnEl = document.getElementById("numacpu");

document.querySelectorAll(".domain_vcpu").forEach(cb => {
	cb.addEventListener("change", () => {
		const cpuNodes = getSelectedCpuNodes();
		if (cpuNodes.length > 1) { updateWarn(cpuWarnEl, _("Warning: Selected CPUs span multiple NUMA nodes.")); showWarn(cpuWarnEl); }
		else hideWarn(cpuWarnEl);
		refreshAllDeviceWarnings();
	});
});

function handleNumaCheck(el) {
	const warnId = el.dataset.numawarn;
	const warnEl = document.getElementById(warnId);
	const cpuNodes = getSelectedCpuNodes();
	hideWarn(warnEl);
	let dev = el.value;
	if (el.tagName === "INPUT" && el.type === "checkbox") {
		if (!el.checked || cpuNodes.length === 0) return;
	} else {
		if (!dev || (el.classList.contains("audio") && dev.startsWith("virtual::"))) return;
	}
	const node = numaInfo.pci_devices["0000:" + dev]?.numa_node;
	if (node === undefined) return;
	if (cpuNodes.length && !cpuNodes.includes(node)) {
		let msg = _("Warning: Device is on a different NUMA node than selected CPUs.");
		if (el.classList.contains("gpu"))     msg = _("Warning: Selected GPU is on a different NUMA node than CPUs.");
		if (el.classList.contains("audio"))   msg = _("Warning: Selected audio device is on a different NUMA node than CPUs.");
		if (el.type === "checkbox")           msg = _("Warning: This PCI device is on a different NUMA node than selected CPUs.");
		updateWarn(warnEl, msg); showWarn(warnEl);
	}
}

function refreshAllDeviceWarnings() {
	document.querySelectorAll('input[name="pci[]"]').forEach(cb => handleNumaCheck(cb));
	document.querySelectorAll('select.gpu, select.audio').forEach(sel => handleNumaCheck(sel));
}

function attachNumaHandlers() {
	document.querySelectorAll('input[name="pci[]"]').forEach(cb => {
		cb.removeEventListener("change", cb._numaHandler);
		cb._numaHandler = () => handleNumaCheck(cb);
		cb.addEventListener("change", cb._numaHandler);
	});
	document.querySelectorAll('select.gpu, select.audio').forEach(sel => {
		sel.removeEventListener("change", sel._numaHandler);
		sel._numaHandler = () => handleNumaCheck(sel);
		sel.addEventListener("change", sel._numaHandler);
	});
}

window.addEventListener("load", () => {
	const cpuNodes = getSelectedCpuNodes();
	if (cpuNodes.length > 1) { updateWarn(cpuWarnEl, _("Warning: Selected CPUs span multiple NUMA nodes.")); showWarn(cpuWarnEl); }
	else hideWarn(cpuWarnEl);
	refreshAllDeviceWarnings();
});

$(function() {
	// ── CodeMirror setup ──────────────────────────────────────────────────────
	function completeAfter(cm, pred) {
		var cur = cm.getCursor();
		if (!pred || pred()) setTimeout(function(){ if (!cm.state.completionActive) cm.showHint({completeSingle: false}); }, 100);
		return CodeMirror.Pass;
	}
	function completeIfAfterLt(cm) {
		return completeAfter(cm, function(){
			var cur = cm.getCursor();
			return cm.getRange(CodeMirror.Pos(cur.line, cur.ch - 1), cur) == "<";
		});
	}
	function completeIfInTag(cm) {
		return completeAfter(cm, function(){
			var tok = cm.getTokenAt(cm.getCursor());
			if (tok.type == "string" && (!/['"]/.test(tok.string.charAt(tok.string.length - 1)) || tok.string.length == 1)) return false;
			var inner = CodeMirror.innerMode(cm.getMode(), tok.state).state;
			return inner.tagName;
		});
	}
	var editor = CodeMirror.fromTextArea(document.getElementById("addcode"), {
		mode: "xml", lineNumbers: true, foldGutter: true,
		gutters: ["CodeMirror-linenumbers", "CodeMirror-foldgutter"],
		extraKeys: { "'<'": completeAfter, "'/'": completeIfAfterLt, "' '": completeIfInTag, "'='": completeIfInTag, "Ctrl-Space": "autocomplete" },
		hintOptions: {schemaInfo: getLibvirtSchema()}
	});

	SetBootorderfields("<?=$arrConfig['domain']['usbboot']?>");

	function resetForm() {
		$("#vmform .domain_vcpu").change();
		<?if (!empty($arrConfig['domain']['state'])):?>
		<?=$arrConfig['domain']['ovmf']==0 ? "$('#vmform #domain_ovmf').prop('disabled',true);\n" : "$('#vmform #domain_ovmf option[value=0]').prop('disabled',true);\n"?>
		<?endif?>
		<?if ($boolRunning):?>
		$("#vmform").find('input[type!="button"],select,.mac_generate').prop('disabled', true);
		$("#vmform").find('input[name^="usb"]').prop('disabled', false);
		<?endif?>
	}

	$('.advancedview').change(function(){
		if ($(this).is(':checked')) {
			setTimeout(function() {
				var xmlPanelHeight = window.outerHeight;
				if (xmlPanelHeight > 1024) xmlPanelHeight -= 550;
				editor.setSize(null, xmlPanelHeight);
				editor.refresh();
			}, 100);
		}
	});

	// ── vCPU selection ────────────────────────────────────────────────────────
	$("#vmform .domain_vcpu").change(function changeVCPUEvent(){
		var $cores = $("#vmform .domain_vcpu:checked");
		if ($cores.length < 1) {
			$("#vmform .domain_vcpus").prop("disabled", false);
			$("#vmform .domain_vcpu").prop("disabled", false);
			$("#vmform .formview #btnvCPUSelect").prop("value", "_(Select All)_");
		} else {
			$("#vmform .domain_vcpus").prop("disabled", true).prop("value", $cores.length);
			$("#vmform .domain_vcpu").prop("disabled", false);
			$("#vmform .formview #btnvCPUSelect").prop("value", "_(Deselect All)_");
		}
	});

	$("#vmform #domain_mem").change(function(){ $("#vmform #domain_maxmem").val($(this).val()); });
	$("#vmform #domain_maxmem").change(function(){
		if (parseFloat($(this).val()) < parseFloat($("#vmform #domain_mem").val())) $("#vmform #domain_mem").val($(this).val());
	});

	$("#vmform #domain_ovmf").change(function(){
		if ($(this).val() != '0' && $("#vmform #vncmodel").val() == 'vmvga') $("#vmform #vncmodel").val('qxl');
		$("#vmform #vncmodel option[value='vmvga']").prop('disabled', ($(this).val() != '0'));
	}).change();

	// ── GPU / Audio events ────────────────────────────────────────────────────
	$("#vmform").on("spawn_section", function(evt, section, sectiondata){
		if (sectiondata.category == 'Graphics_Card') { $(section).find(".gpu").change(); attachNumaHandlers(); refreshAllDeviceWarnings(); }
		if (sectiondata.category == 'Sound_Card')    { attachNumaHandlers(); refreshAllDeviceWarnings(); }
	});
	$("#vmform").on("destroy_section", function(evt, section, sectiondata){
		if (sectiondata.category == 'Graphics_Card') { attachNumaHandlers(); refreshAllDeviceWarnings(); }
		if (sectiondata.category == 'Sound_Card')    { attachNumaHandlers(); refreshAllDeviceWarnings(); }
	});

	$("#vmform").on("input change", ".cdrom", function(){
		if ($(this).val() == '') slideUpRows($(this).closest('table').find('.cdrom_bus').closest('tr'));
		else                     slideDownRows($(this).closest('table').find('.cdrom_bus').closest('tr'));
	});

	$("#vmform").on("change", ".cpu", function(){
		$("#domain_cpumigrate_text").removeClass('hidden');
		$("#domain_cpumigrate").removeClass('hidden');
		if ($(this).val() == "custom") { $("#domain_cpumigrate_text").addClass('hidden'); $("#domain_cpumigrate").addClass('hidden'); }
	});

	$("#vmform").on("change", ".gpu", function changeGPUEvent(){
		const ValidGPUs = <?=json_encode($arrValidGPUDevices)?>;
		var myvalue  = $(this).val();
		var mylabel  = $(this).children('option:selected').text();
		var myindex  = $(this).closest('table').data('index');
		if (myindex == 0) {
			$vnc_sections = $('.autoportline,.protocol,.vncmodel,.vncpassword,.vnckeymap,.copypaste');
			if (myvalue == 'virtual') {
				$vnc_sections.filter('.wasadvanced').removeClass('wasadvanced').addClass('advanced');
				slideDownRows($vnc_sections.not(isVMAdvancedMode() ? '.basic' : '.advanced'));
				document.getElementById("GPUMultiSel0").disabled = true;
				if ($("#vncmodel").val() == "virtio3d") { $("#vncrender").removeClass('hidden'); $("#vncrendertext").removeClass('hidden'); }
				else                                    { $("#vncrender").addClass('hidden');    $("#vncrendertext").addClass('hidden'); }
				if ($("#vncmodel").val() == "qxl") { $("#vncdspopt").removeClass('hidden'); $("#vncdspopttext").removeClass('hidden'); }
				else                               { $("#vncdspopt").addClass('hidden');    $("#vncdspopttext").addClass('hidden'); }
			} else {
				slideUpRows($vnc_sections);
				$vnc_sections.filter('.advanced').removeClass('advanced').addClass('wasadvanced');
				var MultiSel = document.getElementById("GPUMultiSel0");
				if (myvalue=="nogpu") MultiSel.disabled = true; else MultiSel.disabled = false;
			}
		}
		$("#gpubootvga"+myindex).addClass('hidden');
		if (myvalue != "virtual" && myvalue != "" && myvalue != "nogpu") {
			if (ValidGPUs[myvalue] && ValidGPUs[myvalue].bootvga == "1") $("#gpubootvga"+myindex).removeClass('hidden');
		}
		$romfile = $(this).closest('table').find('.romfile');
		if (myvalue == "virtual" || myvalue == "" || myvalue == "nogpu") {
			slideUpRows($romfile.not(isVMAdvancedMode() ? '.basic' : '.advanced'));
			$romfile.filter('.advanced').removeClass('advanced').addClass('wasadvanced');
		} else {
			$romfile.filter('.wasadvanced').removeClass('wasadvanced').addClass('advanced');
			slideDownRows($romfile.not(isVMAdvancedMode() ? '.basic' : '.advanced'));
			$("#vmform .gpu").not(this).each(function(){
				if (myvalue == $(this).val()) $(this).prop("selectedIndex", 0).change();
			});
		}
		var gpuPciRow = $("#gpupcichange" + myindex);
		gpuPciRow.addClass("hidden");
		var pciId = "0000:" + myvalue;
		if (PCIchanges[pciId]) {
			var change = PCIchanges[pciId];
			var tooltip = "_(PCI Change)_\n";
			if (change.status === "changed") {
				tooltip += "_(Differences)_\n";
				for (var key in change.differences) tooltip += `${key} _(before)_: ${change.differences[key].old} _(after)_: ${change.differences[key].new}\n`;
				tooltip += change.device.description;
			}
			gpuPciRow.find("td:last").html(`<span class="orange-text"><i class="fa fa-warning fa-fw" title="${tooltip}"></i> ${change.status.charAt(0).toUpperCase() + change.status.slice(1)}</span>`);
			gpuPciRow.removeClass("hidden");
		}
	});

	$("#vmform").on("change", ".audio", function changeAudioEvent(){
		var myvalue = $(this).val();
		var myindex = $(this).closest('table').data('index');
		var audioPciRow = $("#audiopcichange" + myindex);
		audioPciRow.addClass("hidden");
		var pciId = "0000:" + myvalue;
		if (PCIchanges[pciId]) {
			var change = PCIchanges[pciId];
			var tooltip = "_(PCI Change)_\n";
			if (change.status === "changed") {
				tooltip += "_(Differences)_\n";
				for (var key in change.differences) tooltip += `${key} _(before)_: ${change.differences[key].old} _(after)_: ${change.differences[key].new}\n`;
				tooltip += change.device.description;
			}
			audioPciRow.find("td:last").html(`<span class="orange-text"><i class="fa fa-warning fa-fw" title="${tooltip}"></i> ${change.status.charAt(0).toUpperCase() + change.status.slice(1)}</span>`);
			audioPciRow.removeClass("hidden");
		}
	});

	$("#vmform").on("click", ".mac_generate", function(){
		var $input = $(this).prev('input');
		$.getJSON("/plugins/dynamix.vm.manager/include/VMajax.php?action=generate-mac", function(data){ if (data.mac) $input.val(data.mac); });
	});

	// ── Select/Deselect all CPUs ──────────────────────────────────────────────
	$("#vmform .formview #btnvCPUSelect").click(function(){
		if (this.value == "_(Select All)_") {
			$('.domain_vcpu').prop('checked', true);
			$("#vmform .domain_vcpus").prop("disabled", true).prop("value", $("#vmform .domain_vcpu:checked").length);
			this.value = "_(Deselect All)_";
		} else {
			$('.domain_vcpu').prop('checked', false);
			$("#vmform .domain_vcpus").prop("disabled", false).prop("value", 1);
			this.value = "_(Select All)_";
		}
	});

	// ── Form submit (Create/Update) ───────────────────────────────────────────
	function buildAndSubmit($button, $panel, form, postdata_callback, then_callback) {
		$panel.find('input').prop('disabled', false);
		<?if (!$boolNew):?>
		form.find('input[name="usb[]"],input[name="pci[]"],input[name="usbopt[]"]').each(function(){
			if (!$(this).prop('checked')) $(this).prop('checked',true).val($(this).val()+'#remove');
		});
		var gpus = [], i = 0;
		do { var gpu = form.find('select[name="gpu['+(i++)+'][id]"] option:selected').val(); if (gpu) gpus.push(gpu); } while (gpu);
		form.find('select[name="gpu[0][id]"] option').each(function(){
			var gpu = $(this).val();
			if ((gpu != 'virtual' && gpu != 'nogpu') && !gpus.includes(gpu)) form.append('<input type="hidden" name="pci[]" value="'+gpu+'#remove">');
		});
		var sound = [], i = 0;
		do { var audio = form.find('select[name="audio['+(i++)+'][id]"] option:selected').val(); if (audio) sound.push(audio); } while (audio);
		form.find('select[name="audio[0][id]"] option').each(function(){
			var audio = $(this).val();
			if (audio && !sound.includes(audio)) form.append('<input type="hidden" name="pci[]" value="'+audio+'#remove">');
		});
		<?endif?>
		var postdata = postdata_callback();
		<?if (!$boolNew):?>
		form.find('input[name="usb[]"],input[name="usbopt[]"],input[name="pci[]"]').each(function(){
			if ($(this).val().indexOf('#remove')>0) $(this).prop('checked',false);
		});
		<?endif?>
		$panel.find('input').prop('disabled', true);
		$button.val($button.attr('busyvalue'));
		$.post("/plugins/dynamix.vm.manager/templates/UnRAID.form.php", postdata, function(data){
			if (data.success) {
				if (data.vmrcurl) {
					var w = window.open(data.vmrcurl, '_blank', 'scrollbars=yes,resizable=yes');
					try { w.focus(); } catch(e) {
						swal({title:"_(Browser error)_",text:"_(Pop-up Blocker is enabled! Please add this site to your exception list)_",type:"warning",confirmButtonText:"_(Ok)_"},function(){done();});
						return;
					}
				}
				done();
			}
			if (data.error) {
				swal({title:"_(VM creation error)_",text:data.error,type:"error",confirmButtonText:"_(Ok)_"});
				$panel.find('input').prop('disabled', false);
				$button.val($button.attr('readyvalue'));
				resetForm();
			}
		}, "json");
	}

	$("#vmform .formview #btnSubmit").click(function(){
		var $button = $(this), $panel = $('.formview'), form = $button.closest('form');
		buildAndSubmit($button, $panel, form,
			function(){ return form.find('input,select,textarea[name="qemucmdline"]').serialize().replace(/'/g,"%27"); },
			null
		);
	});

	$("#vmform .formview #btnTemplateSubmit").click(function(){
		var $button = $(this), $panel = $('.formview'), form = $button.closest('form');
		form.append('<input type="hidden" name="createvmtemplate" value="1"/>');
		form.find('input[name="createvm"],input[name="updatevm"]').remove();
		swal({title:"_(Template Name)_",text:"_(Enter name)_:\n_(If name already exists it will be replaced)_.",type:"input",showCancelButton:true,closeOnConfirm:false,inputPlaceholder:"_(Leaving blank will use OS name)_."},
		function(inputValue){
			buildAndSubmit($button, $panel, form,
				function(){ return form.find('input,select,textarea[name="qemucmdline"]').serialize().replace(/'/g,"%27") + "&templatename="+inputValue; },
				null
			);
		});
	});

	$("#vmform .xmlview #btnSubmit").click(function(){
		var $button = $(this), $panel = $('.xmlview');
		editor.save();
		$panel.find('input').prop('disabled', false);
		var postdata = $panel.closest('form').serialize().replace(/'/g,"%27");
		$panel.find('input').prop('disabled', true);
		$button.val($button.attr('busyvalue'));
		$.post("/plugins/dynamix.vm.manager/templates/UnRAID.form.php", postdata, function(data){
			if (data.success) done();
			if (data.error) {
				swal({title:"_(VM creation error)_",text:data.error,type:"error",confirmButtonText:"_(Ok)_"});
				$panel.find('input').prop('disabled', false);
				$button.val($button.attr('readyvalue'));
				resetForm();
			}
		}, "json");
	});

	$("#vmform .xmlview #btnTemplateSubmit").click(function(){
		var $button = $(this), $panel = $('.xmlview'), form = $button.closest('form');
		editor.save();
		$panel.find('input').prop('disabled', false);
		form.append('<input type="hidden" name="createvmtemplate" value="1"/>');
		form.find('input[name="createvm"],input[name="updatevm"]').remove();
		var postdata = $panel.closest('form').serialize().replace(/'/g,"%27");
		$panel.find('input').prop('disabled', true);
		$button.val($button.attr('busyvalue'));
		swal({title:"_(Template Name)_",text:"_(Enter name)_:\n_(If name already exists it will be replaced)_.",type:"input",showCancelButton:true,closeOnConfirm:false,inputPlaceholder:"_(Leaving blank will use OS name)_."},
		function(inputValue){
			$.post("/plugins/dynamix.vm.manager/templates/UnRAID.form.php", postdata+"&templatename="+inputValue, function(data){
				if (data.success) done();
				if (data.error) {
					swal({title:"_(VM creation error)_",text:data.error,type:"error",confirmButtonText:"_(Ok)_"});
					$panel.find('input').prop('disabled', false);
					$button.val($button.attr('readyvalue'));
					resetForm();
				}
			}, "json");
		});
	});

	// ── UnRAID version / download logic ───────────────────────────────────────
	var checkDownloadTimer = null;

	var checkOrInitDownload = function(checkonly) {
		clearTimeout(checkDownloadTimer);
		var $button = $("#vmform #btnDownload");
		var $form   = $button.closest('form');
		var postdata = {
			download_version: $('#vmform #template_unraid').val(),
			download_path:    $('#vmform #download_path').val(),
			vm_name:          $('#vmform #domain_name').val(),
			download_size:    $('#vmform #download_size').val(),
			checkonly:        ((typeof checkonly === 'undefined') ? false : !!checkonly) ? 1 : 0
		};
		$form.find('input,select').prop('disabled', true);
		$button.val($button.attr('busyvalue'));
		$.post("/plugins/dynamix.vm.manager/templates/UnRAID.form.php", postdata, function(data){
			if (data.error) {
				$("#vmform #download_status").html('<span style="color:red">' + data.error + '</span><br><input type="button" value="_(Retry)_" id="btnRetryDownload"> <input type="button" value="_(Reset)_" id="btnResetSetup">');
				$("#vmform #btnRetryDownload").click(function(){ checkOrInitDownload(false); });
				$("#vmform #btnResetSetup").click(function(){
					$.post("/plugins/dynamix.vm.manager/templates/UnRAID.form.php", {reset_setup: 1}, function(){ location.reload(); });
				});
			} else if (data.status) {
				if (data.status === 'Done') {
					$("#vmform #download_status").html('Done');
				} else {
					$("#vmform #download_status").html(data.status);
				}
				if (data.pid) {
					checkDownloadTimer = setTimeout(function(){ checkOrInitDownload(true); }, 1000);
					return;
				}
				if (data.status == 'Done') {
					$("#vmform #template_unraid").find('option:selected').attr({
						localpath: data.localpath, localfolder: data.localfolder, valid: '1'
					});
					$("#vmform #template_unraid").change();
				}
			}
			$button.val($button.attr('readyvalue'));
			$form.find('input,select').prop('disabled', false);
			if (data.status && data.status !== 'Done') {
				$('#vmform #template_unraid, #vmform #download_path, #vmform #domain_name, #vmform #download_size').prop('disabled', true);
			}
		}, "json");
	};

	$("#vmform #btnDownload").click(function(){ checkOrInitDownload(false); });

	$("#vmform #template_unraid").change(function changeUnRAIDVersion(){
		clearTimeout(checkDownloadTimer);
		var $selected      = $(this).find('option:selected');
		var pending_version = "<?=($arrUnRAIDConfig['pending_version'] ?? '')?>";
		if (pending_version !== '' && pending_version !== $selected.val()) {
			$(this).val(pending_version);
			$selected = $(this).find('option:selected');
		}
		if ($selected.attr('valid') === '0' && <?=$boolNew ? 'true' : 'false'?>) {
			$("#vmform .available").slideDown('fast');
			$("#vmform .installed").slideUp('fast');
			if (pending_version === $selected.val()) {
				checkOrInitDownload(true);
			} else {
				$("#vmform #download_status").html('');
				$("#vmform #download_path").val($selected.attr('localfolder'));
				$("#vmform .name_section input, #vmform #domain_name").prop('disabled', false);
				$("#vmform .available input, #vmform .available select").prop('disabled', false);
			}
		} else {
			$("#vmform .available").slideUp('fast');
			$("#vmform .installed").slideDown('fast', function(){
				resetForm();
				$("#vmform .delete_unraid_image").off().click(function(){
					swal({title:"_(Are you sure)_?",text:"_(Remove this UnRAID file)_:\n"+$selected.attr('localpath'),type:"warning",showCancelButton:true,confirmButtonText:"_(Proceed)_",cancelButtonText:"_(Cancel)_"}, function(){
						$.post("/plugins/dynamix.vm.manager/templates/UnRAID.form.php", {delete_version: $selected.val()}, function(data){
							if (data.error) {
								swal({title:"_(VM image deletion error)_",text:data.error,type:"error",confirmButtonText:"_(Ok)_"});
							} else if (data.status == 'ok') {
								$selected.attr({localpath: '', valid: '0'});
							}
							$("#vmform #template_unraid").change();
						}, "json");
					});
				}).hover(
					function(){ $("#vmform #unraid_image").css('color','#666'); },
					function(){ $("#vmform #unraid_image").css('color','#BBB'); }
				);
			});
			$("#vmform #disk_0").val($selected.attr('localpath'));
			$("#vmform #unraid_image").html($selected.attr('localpath')).show();
		}
	}).change(); // fire on load

	// ── Initial page state ────────────────────────────────────────────────────
	$('#vmform .domain_os').hide(); // no windows sections for UnRAID VMs
	SetBootorderfields("<?=$arrConfig['domain']['usbboot']?>");
	$("#vmform .gpu").change();
	$("#vmform .audio").change();
	$('#vmform .cdrom').change();
	resetForm();
});

attachNumaHandlers();
</script>
