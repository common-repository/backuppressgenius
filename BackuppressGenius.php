<?php
/*
Plugin Name: BackupPress GENIUS
Plugin URI:  http://backuppress.de
Description: BackupPress GENIUS sichert Ihre Wordpress-Daten und lagert diese auf den backupgermany-Server aus.
Version:     0.96
Author:      Laempe RiskManagement
Author URI:  http://backuppress.de
License:     GPL2
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Domain Path: /languages
Text Domain: BackupPress

{Plugin Name} is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 2 of the License, or
any later version.
 
{Plugin Name} is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.
 
You should have received a copy of the GNU General Public License
along with {Plugin Name}. If not, see {License URI}.
*/
defined( 'ABSPATH' ) or die( __('Diese Datei ist nicht direkt aufzurufen!','BackupPress') );
include dirname(__FILE__).'/PHPSecLib/Crypt/AES.php';
$BackuppressDir = dirname(__FILE__);

class BackuppressGenius{

// Version aktualisieren bei Änderung!
private $version = '0.96';
private $cryptkey='TiotKfsitMDweHca';
private $Meldung='';
private $license_settings_key = 'bpg_license_settings';
private $enckey_settings_key = 'bpg_enckey_settings';
private $manback_settings_key = 'bpg_manback_settings';
private $autoback_settings_key = 'bpg_autoback_settings';
private $restore_settings_key = 'bpg_restore_settings';
private $plugin_options_key = 'bpg_plugin_options';
private $plugin_settings_tabs = array();
private $fUrl = "http://bup.backupgermany.de";
private $bpgfilesname = "Verzeichnisse.txt";
private $bpguploads = '/bpg_backup/';
private $bpglogfilename='bpglogfile.txt';
private $dateilistename='Dateiliste.txt';
private $dateinameupstart='uploadstart.txt';
private $dateinameupok='uploadok.txt';

function __construct() {
    add_action( 'admin_init', array( &$this, 'enqueue_scripts') ); 
    add_action( 'admin_init', array( &$this, 'enqueue_styles' ) );
    add_action( 'init', array( &$this, 'load_settings' ) );
    add_action( 'admin_init', array( &$this, 'register_manback_settings' ) );
	add_action( 'admin_init', array( &$this, 'register_restore_settings' ) );
    add_action( 'admin_init', array( &$this, 'register_autoback_settings' ) );
	add_action( 'admin_init', array( &$this, 'register_license_settings' ) );
    add_action( 'admin_init', array( &$this, 'register_enckey_settings' ) );
    add_action('backuppress_cron_hook', array( &$this, 'backuppress_cron_exec' ) );
    add_action( 'admin_menu', array( &$this, 'backuppress_menu' ) );
}
function enqueue_scripts() {
    register_activation_hook( __FILE__, array( &$this, 'BackuppressGenius_activation' ) );
    register_deactivation_hook( __FILE__, array( &$this, 'BackuppressGenius_deactivation' ) );

}

function enqueue_styles() {
    wp_register_style( 'Backuppress', plugins_url('css/BackuppressGenius.css',__FILE__) );
    wp_enqueue_style( 'Backuppress', plugins_url('css/BackuppressGenius.css',__FILE__), array(), 1.0, 'screen' );
}

function BackuppressGenius_activation() {

}

function BackuppressGenius_deactivation() {
	$deltatime=date('G',(current_time('timestamp')-time()));
	$autostarttime=$this->autoback_settings['autoback_option']-$deltatime;
	$bup_license=$this->license_settings['license_option'];
    if (strlen($bup_license)==0) {$bup_license='XXX';}
	$pfadini_content=ABSPATH.'---'.get_home_url().'---'.$autostarttime.'---'.get_option('admin_email').'---'.$bup_license.'---'.$this->version.'---'.$this->autoback_settings['autoback_option'].'---'.'Deaktiv';
	$this->pfadini_update($pfadini_content);
    $timestamp = wp_next_scheduled( 'backuppress_cron_hook' );
    wp_unschedule_event($timestamp, 'backuppress_cron_hook' );
}

function BackuppressGenius_uninstall() {}

function backuppress_cron_exec() {
    $return=true;
    $admin_email = get_option('admin_email');
    $maindir = ABSPATH;
    $source=$maindir;
    $pathwp=get_home_url();
    $filedate=date("Y-m-d-H-i",current_time('timestamp'));
    $upload_dir = wp_upload_dir();
    $zipdir=$upload_dir['basedir'].$this->bpguploads;
    $zipurl=$upload_dir['baseurl'].$this->bpguploads;
    $blacklist=array('uploads','BackuppressGenius/backups','backuppressgenius/backups',$this->bpguploads,'.zip','.zipe','.rar','.bpg');
    $bup_enckey=$this->enckey_settings['enckey_option'];
	if (($bup_enckey!==$this->enckey_settings['enckey_confirm'])||($bup_enckey=='')||( !preg_match('/^(?=.*\d)(?=.*[@#\-_$%^&+=§!\?\.])(?=.*[a-z])(?=.*[A-Z])[0-9A-Za-z@#\-_$%^&+=§!\?\.]{10,50}$/',$bup_enckey) )) {
		$subject="BackupPress GENIUS - Automatische WordPress-Sicherung fehlgeschlagen";
		$message="Sehr geehrter BackupPress GENIUS-Kunde,\n\nIhre automatische Sicherung von WordPress ($pathwp) war nicht erfolgreich.\n\nEs wurde kein den notwendigen Sicherheitskriterien entsprechendes Verschlüsselungskennwort angegeben.\n\nBitte tragen Sie unter Einstellungen -> BackupPress GENIUS ein entsprechendes Verschlüsselungskennwort ein oder kontaktieren Sie unseren Support unter service@laempe-riskmanagement.de !\n\nIhr Service-Team";
		wp_mail($admin_email,$subject,$message);
		$messagebpg=$pathwp."\n\n".str_replace("<br />","\n",$this->Meldung)."\n\nDas Verschlüsselungskennwort entspricht nicht den notwendigen Kriterien!";
		wp_mail('service@laempe-group.de',$subject,$messagebpg);
		return false;
	}
    $mysqlDatei=$this->backup_mysql(DB_HOST, DB_USER, DB_PASSWORD,DB_NAME,'*',$filedate);
    if ($mysqlDatei===false) {
		$subject="BackupPress GENIUS - Automatische WordPress-Sicherung fehlgeschlagen";
		$message="Sehr geehrter BackupPress GENIUS-Kunde,\n\nIhre automatische Sicherung von WordPress ($pathwp) war nicht erfolgreich.\n\nEs gab einen Fehler beim Erstellen der MySQL-Sicherungsdatei.\n\nBitte kontaktieren Sie unseren Support unter service@laempe-riskmanagement.de !\n\nIhr Service-Team";
		wp_mail($admin_email,$subject,$message);
		$messagebpg=$pathwp."\n\n".str_replace("<br />","\n",$this->Meldung);
		wp_mail('service@laempe-group.de',$subject,$messagebpg);
		return false;
    }
    $this->createPath($zipdir);
    if ($this->Zip_backuppress($source,$zipdir,$zipurl,$filedate, $blacklist, $mysqlDatei,$bup_enckey)) {} else {
		$subject="BackupPress GENIUS - Automatische WordPress-Sicherung fehlgeschlagen";
		$message="Sehr geehrter BackupPress GENIUS-Kunde,\n\nIhre automatische Sicherung von WordPress ($pathwp) war nicht erfolgreich.\n\n Es gab einen Fehler beim Packen der Dateien.\n\nBitte kontaktieren Sie unseren Support unter service@laempe-riskmanagement.de !\n\nIhr Service-Team";
		wp_mail($admin_email,$subject,$message);
		$messagebpg=$pathwp."\n\n".str_replace("<br />","\n",$this->Meldung);
		wp_mail('service@laempe-group.de',$subject,$messagebpg);
		return false;
    }
    $this->manback_settings['manback_option']='';
    $subject="BackupPress GENIUS - Automatische WordPress-Sicherung erfolgreich";
    $message="Sehr geehrter BackupPress GENIUS-Kunde,\n\nIhre automatische Sicherung von WordPress ($pathwp) war erfolgreich.\n\nBei Rückfragen sind wir von Montag bis Freitag zwischen 09:00 und 16:00 Uhr erreichbar unter service@laempe-riskmanagement.de\n\nIhr Service-Team";
    wp_mail($admin_email,$subject,$message);
    return $return;
}

function pfadini_update($pfadini_content) {
    $upload_dir = wp_upload_dir();
    $zipurl=$upload_dir['baseurl'];
    $rUrl=$this->fUrl."/Backuppress.aspx?url=$zipurl&pfad=$pfadini_content";
    $data=array('pfad'=>$pfadini_content);
    $query=http_build_query($data);
    $ch = curl_init($rUrl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $query);
    $response = curl_exec($ch);
    $json = json_decode($response, true);
    curl_close($ch);
    if ($response===false) {$this->Meldung.='Fehler beim Aktualisieren der Pfad.ini.<br />';return false;}
}

function backuppress_man_exec() {
    $return=true;
	$admin_email = get_option('admin_email');
    $maindir = ABSPATH;
    $source=$maindir;
	$pathwp=get_home_url();
    $filedate=date("Y-m-d-H-i",current_time('timestamp'));
    $upload_dir = wp_upload_dir();
    $zipdir=$upload_dir['basedir'].$this->bpguploads;
    $zipurl=$upload_dir['baseurl'].$this->bpguploads;
    $blacklist=array('uploads','BackuppressGenius/backups','backuppressgenius/backups',$this->bpguploads,'.zip','.zipe','.rar','.bpg');
    $bup_enckey=$this->enckey_settings['enckey_option'];
	if (($bup_enckey!==$this->enckey_settings['enckey_confirm'])||($bup_enckey=='')||( !preg_match('/^(?=.*\d)(?=.*[@#\-_$%^&+=§!\?\.])(?=.*[a-z])(?=.*[A-Z])[0-9A-Za-z@#\-_$%^&+=§!\?\.]{10,50}$/',$bup_enckey) )) {
		$subject="BackupPress GENIUS - Automatische WordPress-Sicherung fehlgeschlagen";
		$message="Sehr geehrter BackupPress GENIUS-Kunde,\n\nIhre automatische Sicherung von WordPress ($pathwp) war nicht erfolgreich.\n\nEs wurde kein den notwendigen Sicherheitskriterien entsprechendes Verschlüsselungskennwort angegeben.\n\nBitte tragen Sie unter Einstellungen -> BackupPress GENIUS ein entpsrechendes Verschlüsselungskennwort ein oder kontaktieren Sie unseren Support unter service@laempe-riskmanagement.de !\n\nIhr Service-Team";
		wp_mail($admin_email,$subject,$message);
		$messagebpg=$pathwp."\n\n".str_replace("<br />","\n",$this->Meldung)."\n\nDas Verschlüsselungskennwort entspricht nicht den notwendigen Kriterien!";
		wp_mail('service@laempe-group.de',$subject,$messagebpg);
		return false;
	}
    $mysqlDatei=$this->backup_mysql(DB_HOST, DB_USER, DB_PASSWORD,DB_NAME,'*',$filedate);
    if ($mysqlDatei===false) {
		$this->Meldung.='Fehler beim Erstellen der MySQL-Sicherungsdatei.<br />';
		$subject="BackupPress GENIUS - Manuelle WordPress-Sicherung fehlgeschlagen";
		$message="Sehr geehrter BackupPress GENIUS-Kunde,\n\nIhre manuelle Sicherung von WordPress ($pathwp) war nicht erfolgreich.\n\nEs gab einen Fehler beim Erstellen der MySQL-Sicherungsdatei.\n\nBitte kontaktieren Sie unseren Support unter service@laempe-riskmanagement.de !\n\nIhr Service-Team";
		wp_mail($admin_email,$subject,$message);
		$messagebpg=$pathwp."\n\n".str_replace("<br />","\n",$this->Meldung);
		wp_mail('service@laempe-group.de',$subject,$messagebpg);
		return false;
	}
    $this->createPath($zipdir);
    $return=$this->Zip_backuppress($source,$zipdir,$zipurl,$filedate,$blacklist,$mysqlDatei,$bup_enckey);
    if ($return===false) {
		$this->Meldung.='Fehler beim Packen der Dateien.<br />';
		$subject="BackupPress GENIUS - Manuelle WordPress-Sicherung fehlgeschlagen";
		$message="Sehr geehrter BackupPress GENIUS-Kunde,\n\nIhre manuelle Sicherung von WordPress ($pathwp) war nicht erfolgreich.\n\n Es gab einen Fehler beim Packen der Dateien.\n\nBitte kontaktieren Sie unseren Support unter service@laempe-riskmanagement.de !\n\nIhr Service-Team";
		wp_mail($admin_email,$subject,$message);
		$messagebpg=$pathwp."\n\n".str_replace("<br />","\n",$this->Meldung);
		wp_mail('service@laempe-group.de',$subject,$messagebpg);
		return false;
	}
    $this->Meldung.='Backup fertig.<br />';
    $this->manback_settings['manback_option']='';
	$subject="BackupPress GENIUS - Manuelle WordPress-Sicherung erfolgreich";
    $message="Sehr geehrter BackupPress GENIUS-Kunde,\n\nIhre manuelle Sicherung von WordPress ($pathwp) war erfolgreich.\n\nBei Rückfragen sind wir von Montag bis Freitag zwischen 09:00 und 16:00 Uhr erreichbar unter service@laempe-riskmanagement.de\n\nIhr Service-Team";
    wp_mail($admin_email,$subject,$message);
    return $return;
}

function backuppress_restore_exec($zipfile) {
    $return=true;
    $filedate=substr(substr($zipfile, -16),0,16);
    $password=$this->enckey_settings['enckey_option'];
    $maindir = ABSPATH;
    $zipfilefolder=$maindir;
    if (strpos($zipfile,'ttp://')!==false) {
		$zipdir=$zipfilefolder;
		//Dateiliste herunterladen
		$dateilisteserver=$zipfile."/".$this->dateilistename;
		$dateilistelokal=$zipdir."/".$this->dateilistename;
		$return=$this->downloadFile($dateilisteserver,$dateilistelokal);
		if ($return===false) {$this->Meldung.='Dateiliste konnte nicht heruntergeladen werden.<br />';return false;};
		//Dateiliste einlesen
		$dateilisteninhalt=file($dateilistelokal);
		foreach ($dateilisteninhalt as $zeile) {
			$filesplit=explode(".zip",$zeile);
			$zeile=$filesplit[0].'.zip';
			$zipfilesave=$zipdir.basename($zeile);
			$return=$this->downloadFile($zeile,$zipfilesave);
			if ($return===false) {$this->Meldung.="Datei $zeile nicht gefunden auf dem BackupGermany-Server.<br />";return false;}
			$zeile=$zipfilesave;
			$contents=$this->decryptContent(file_get_contents($zeile),$password);
			if ($contents===false) {$this->Meldung.='Fehler beim Entschlüsseln der Datei '.$zeile.', Sicherung abgebrochen!<br />Bitte kontaktieren Sie unseren Support unter service@laempe-riskmanagement.de !<br />';unlink ($zeile);return false;}
			if (file_put_contents($zeile, $contents) === false) {$this->Meldung.='Fehler beim Schreiben der entschlüsselten Daten in Datei '.$zeile.'<br />Bitte kontaktieren Sie unseren Support unter service@laempe-riskmanagement.de !<br />';unlink ($zeile);return false;}
			$unzipok=$this->unzipfilebpg($zeile,$zipfilefolder);
			if ($unzipok===false) {
				unlink($zeile);$this->Meldung.="Datei $zeile konnte nicht entpackt werden!<br />";
				return false;
			}
		}
    } else {
		//lokale Dateien entpacken
		$upload_dir = wp_upload_dir();
		$zipdir=$upload_dir['basedir'].$this->bpguploads.$filedate.'/';
		$dateilistelokal=$zipdir."/".$this->bpglogfilename;
		$dateilisteninhalt=file($dateilistelokal);
		foreach ($dateilisteninhalt as $zeile) {
			$zeile=str_replace(str_replace('\\', '/',$zipfilefolder),str_replace('\\', '/',$zipdir),str_replace('\\', '/',$zeile));
			$filesplit=explode(".zip",$zeile);
			$zeile=$filesplit[0].'.zip';
			$contents=$this->decryptContent(file_get_contents($zeile),$password);
			$zeilelokal=$zipfilefolder.basename($zeile);
			if ($contents===false) {$this->Meldung.='Fehler beim Entschlüsseln der Datei '.$zeile.', Sicherung abgebrochen!<br />Bitte kontaktieren Sie unseren Support unter service@laempe-riskmanagement.de !<br />';unlink ($zeilelokal);return false;}
			if (file_put_contents($zeilelokal, $contents) === false) {$this->Meldung.='Fehler beim Schreiben der entschlüsselten Daten in Datei '.$zeilelokal.'<br />Bitte kontaktieren Sie unseren Support unter service@laempe-riskmanagement.de !<br />';unlink ($zeilelokal);return false;}
			$unzipok=$this->unzipfilebpg($zeilelokal,$zipfilefolder);
			if ($unzipok===false) {
				unlink($zeilelokal);$this->Meldung.="Datei $zeilelokal konnte nicht entpackt werden!<br />";
				return false;
			}
		}
	}
    $return=$this->mySQLRestore($zipfilefolder,$filedate);
    if ($return===false) {$this->Meldung.='Die mySQL-Datenbank konnte nicht zurückgespielt werden.<br />';return false;}
    return true;
}

function downloadFile($file_url, $save_to) {
	$return=copy($file_url, $save_to);
	if ($return===false) {return false;}
}

function unzipfilebpg ($zipfile,$zipfilefolder) {
	//Use strpos() to check if the entry name contains file we do not want to extract
    if ((strpos($zipfile, "BackuppressGenius.php")) || (strpos($zipfile, "PlugInTitelV1.png")) || (strpos($zipfile, "BackuppressGenius.css"))) { unlink($zipfile); } else {
		$zip = new ZipArchive;
		$res = $zip->open($zipfile);
		if ($res === TRUE) {
			for($i = 0; $i < $zip->numFiles; $i++) {
				$entry = $zip->getNameIndex($i);
				//Add the entry to our array
				$files[] = $entry;
			}
			if ($zip->extractTo($zipfilefolder,$files)){
				$zip->close();
				unlink($zipfile);
				return true;
			} else {
				$zip->close();
			return false;
			}
		} else {
		return false;
		}
	}
}

function backuppress_menu() {
	add_options_page( sprintf(__('%s Optionen','BackupPress'),'BackupPress GENIUS'), 'BackupPress GENIUS', 'manage_options', $this->plugin_options_key, array( &$this, 'backuppress_options' ) );
}
function load_settings() {
    $this->license_settings = (array) get_option( $this->license_settings_key );
    $this->enckey_settings = (array) get_option( $this->enckey_settings_key );
    $this->manback_settings = (array) get_option( $this->manback_settings_key );
    $this->autoback_settings = (array) get_option( $this->autoback_settings_key );
    $this->restore_settings = (array) get_option( $this->restore_settings_key );
		
    // Merge with defaults
    $this->license_settings = array_merge( array(
	'license_option' => ''
    ), $this->license_settings );

    $this->enckey_settings = array_merge( array(
	'enckey_option' => '',
	'enckey_confirm' => ''
    ), $this->enckey_settings );

    $this->manback_settings = array_merge( array(
	'manback_option' => '',
	'manback_button' => 'Jetzt manuell sichern'
    ), $this->manback_settings );

    $this->autoback_settings = array_merge( array(
	'autoback_option' => '0'
    ), $this->autoback_settings );

    $this->restore_settings = array_merge( array(
	'restore_option' => '',
	'restore_button' => 'Jetzt zurücksichern'
    ), $this->restore_settings );
}
function register_license_settings() {
	$this->plugin_settings_tabs[$this->license_settings_key] = 'Lizenz';
		
	register_setting( $this->license_settings_key, $this->license_settings_key );
	add_settings_section( 'section_license', 'Lizenz-Einstellungen', array( &$this, 'section_license_desc' ), $this->license_settings_key );
	add_settings_field( 'license_option', 'Lizenzschlüssel', array( &$this, 'field_license_option' ), $this->license_settings_key, 'section_license' );
}
function section_license_desc() { echo 'Tragen Sie hier bitte Ihren Lizenzschlüssel ein.'; }
function field_license_option() {
	?>
	<input type="text" id="licensekey" class="EditLizenz" name="<?php echo $this->license_settings_key; ?>[license_option]" value="<?php echo esc_attr( $this->license_settings['license_option'] ); ?>" />
	<input type="button" class="ButtonStandard" value="Aktivieren" onclick="activateLicenseField()" /><script language="JavaScript" type="text/javascript">function activateLicenseField() {var licenseitem=document.getElementById('licensekey');licenseitem.disabled = false;licenseitem.focus();}</script>
	<?php
}

function register_enckey_settings() {
	$this->plugin_settings_tabs[$this->enckey_settings_key] = 'Verschlüsselung';
		
	register_setting( $this->enckey_settings_key, $this->enckey_settings_key );
	add_settings_section( 'section_enckey', 'Verschlüsselungseinstellungen ändern', array( &$this, 'section_enckey_desc' ), $this->enckey_settings_key );
	add_settings_field( 'enckey_option', 'Verschlüsselungskennwort', array( &$this, 'field_enckey_option' ), $this->enckey_settings_key, 'section_enckey' );
	add_settings_field( 'enckey_confirm', 'Verschlüsselungskennwort bestätigen', array( &$this, 'field_enckey_confirm' ), $this->enckey_settings_key, 'section_enckey' );
}
function section_enckey_desc() { echo 'Hier führen Sie Änderungen am Verschlüsselungskennwort durch.<br />Aus Schutz vor unbeabsichtigter Änderung müssen Sie dazu zuerst das jeweilige Feld mit der zugehörigen Schaltfläche "Aktivieren".<br />Bitte beachten Sie dabei, dass nur die danach erstellten Sicherungsdateien mit dem neuen Verschlüsselungskennwort verschlüsselt werden!<br />Das Verschlüsselungskennwort muss außerdem mindestens einen Kleinbuchstaben, einen Großbuchstaben, eine Ziffer und ein Sonderzeichen beinhalten sowie aus mindestens 10 Zeichen bestehen!'; }
function field_enckey_option() {
	?>
	<input type="password" id="enckey" name="<?php echo $this->enckey_settings_key; ?>[enckey_option]" value="<?php echo esc_attr( $this->enckey_settings['enckey_option'] ); ?>" />
	<input type="button" class="ButtonStandard" value="Aktivieren" onclick="activateEnckeyField()" /><script language="JavaScript" type="text/javascript">function activateEnckeyField() {var enckeyitem=document.getElementById('enckey');enckeyitem.disabled = false;enckeyitem.focus();}</script>
	<?php
}
function field_enckey_confirm() {
	?>
	<input type="password" id="enckeycon" name="<?php echo $this->enckey_settings_key; ?>[enckey_confirm]" value="<?php echo esc_attr( $this->enckey_settings['enckey_confirm'] ); ?>" />
	<input type="button" class="ButtonStandard" value="Aktivieren" onclick="activateEnckeyconField()" /><script language="JavaScript" type="text/javascript">function activateEnckeyconField() {var enckeyconitem=document.getElementById('enckeycon');enckeyconitem.disabled = false;enckeyconitem.focus();}</script>
	<?php
}

function register_manback_settings() {
	$this->plugin_settings_tabs[$this->manback_settings_key] = 'Manuelles Backup';
		
	register_setting( $this->manback_settings_key, $this->manback_settings_key );
	add_settings_section( 'section_manback', 'Manuelles Backup', array( &$this, 'section_manback_desc' ), $this->manback_settings_key );
	add_settings_field( 'manback_option', 'Vergebenes Verschlüsselungskennwort', array( &$this, 'field_manback_option' ), $this->manback_settings_key, 'section_manback' );
	add_settings_field( 'manback_button', 'Manuelles Backup starten', array( &$this, 'field_manback_button' ), $this->manback_settings_key, 'section_manback' );
}
function section_manback_desc() { echo 'Hier können Sie manuelle Sicherungen starten. Tragen Sie dazu bitte das bisher vergebene Verschlüsselungskennwort ein und speichern Sie dieses, bevor Sie auf "Jetzt manuell sichern" links klicken. Das eingegebene Verschlüsselungskennwort muss mit dem bisher vergebenen Verschlüsselungskennwort übereinstimmen. Sollten Sie das bisher vergebene Verschlüsselungskennwort nicht mehr verfügbar haben, ändern Sie dieses bitte zuvor unter dem Reiter "Verschlüsselung" ab.<br /><br /><span style="color:red;font-size:200%;">Bitte warten Sie, bis die manuelle Sicherung abgeschlossen ist!</style>'; }
function field_manback_option() {
	?>
	<input type="password" name="<?php echo $this->manback_settings_key; ?>[manback_option]" value="" />
	<?php
}
function field_manback_button() {
	?>
	
	<input type="submit" onclick="showspinner()" class="ButtonStandard" name="<?php echo $this->manback_settings_key; ?>[manback_button]" value="<?php echo esc_attr( $this->manback_settings['manback_button'] ); ?>"></input>
	<div id="manbackspinner"></div><span class="hinweis">Die Sicherung kann bis zu 10 Minuten dauern, bitte warten Sie, bis diese beendet wurde.</span>
	<?php
}

function register_autoback_settings() {
	$this->plugin_settings_tabs[$this->autoback_settings_key] = 'Automatisches Backup';
		
	register_setting( $this->autoback_settings_key, $this->autoback_settings_key );
	add_settings_section( 'section_autoback', 'Einstellungen des automatischen Backups', array( &$this, 'section_autoback_desc' ), $this->autoback_settings_key );
	add_settings_field( 'autoback_option', 'Startzeit der automatischen Sicherung', array( &$this, 'field_autoback_option' ), $this->autoback_settings_key, 'section_autoback' );
}
function section_autoback_desc() { echo 'Hier können Sie die Einstellungen des automatischen Backups anpassen.'; }
function field_autoback_option() {
	?>
	<select name="<?php echo $this->autoback_settings_key; ?>[autoback_option]">
		<?php
		for ($i=0;$i<24;$i++) {
		?>
			<option value="<?php echo $i; ?>" <?php
				if ($this->autoback_settings['autoback_option']==$i) { ?>selected="selected"<?php } ?>><?php
				echo $i.':00 Uhr' ?></option>
			<?php
		}
		?>
	</select>
	<?php
}

function register_restore_settings() {
	$this->plugin_settings_tabs[$this->restore_settings_key] = 'Rücksicherung';
		
	register_setting( $this->restore_settings_key, $this->restore_settings_key );
	add_settings_section( 'section_restore', 'Durchführung der Rücksicherung', array( &$this, 'section_restore_desc' ), $this->restore_settings_key );
	add_settings_field( 'restore_option', 'Verfügbare Stände für die Rücksicherung', array( &$this, 'field_restore_option' ), $this->restore_settings_key, 'section_restore' );
	add_settings_field( 'restore_button', 'Rücksicherung starten', array( &$this, 'field_restore_button' ), $this->restore_settings_key, 'section_restore' );
}
function section_restore_desc() { echo 'Hier können Sie eine Rücksicherung Ihrer WordPress-Daten durchführen.<br />Auf Ihrem lokalen Server finden Sie den aktuellen Stand, auf unserem Backupgermany-Server finden Sie die älteren Stände.<br /><br /><span style="color:red;font-size:200%;">Bitte warten Sie, bis die Rücksicherung abgeschlossen ist!</style>'; }
function field_restore_option() {
	?>
	<select name="<?php echo $this->restore_settings_key; ?>[restore_option]">
		<?php
		$upload_dir = wp_upload_dir();
		$zipdir=$upload_dir['basedir'].$this->bpguploads;
    foreach (scandir($zipdir) as $node) {
        if ($node == '.' || $node == '.htaccess' || $node == '..' || $node == '.tmb' || $node == '.quarantine') continue;
        if (is_dir($zipdir)) {
          $files[] = $zipdir . '/' . $node;
        }
        else {
        }
    }
		$bpgfileslog=$zipdir.$this->bpgfilesname;
		if (file_exists($bpgfileslog)) {
    			$inhalt=file($bpgfileslog);
			if ($inhalt!=='') {
				foreach ($inhalt as $zeile) {
					array_push($files,$zeile);
				}
			} else {}
		}
		//$sortok=arsort($files); if ($sortok==false) {$this->Meldung.='Liste ist unsortiert';}
    		foreach ($files as $file) {
				if( in_array(substr($file, strrpos($file, '/')+1), array('.', '..')) )
					continue;
				if ((strlen(basename($file))>15)&&(strlen(basename($file))<19)&&(basename($file)<>$this->bpgfilesname)) {
					if (strlen(basename($file))>16) {$file=substr($file,0,-2);}
					?>
					<option value="<?php echo $file; ?>" <?php
						if ($this->restore_settings['restore_option']==$file) { ?>selected="selected"<?php } ?>><?php
						if (strpos($file,'ttp://')) {echo 'BackupGermany: ';} else {echo 'Lokaler Server:   ';}
						echo basename($file); ?></option>
					<?php
				}
    		}
		?>
	</select>
	<?php
}
function field_restore_button() {
	?>
	<input type="submit" onclick="showspinner()" class="ButtonStandard" name="<?php echo $this->restore_settings_key; ?>[restore_button]" value="<?php echo esc_attr( $this->restore_settings['restore_button'] ); ?>"></input>
	<div id="restorespinner"></div><span class="hinweis">Die Rücksicherung kann bis zu 10 Minuten dauern, bitte warten Sie, bis diese beendet wurde.</span>
	<?php
}


function backuppress_options() {
	if ( !current_user_can( 'manage_options' ) )  {
		wp_die( __( 'Sie haben keine ausreichende Berechtigung, um auf diese Seite zuzugreifen.','BackupPress' ) );
	}
	if ($this->enckey_settings['enckey_option']!==$this->enckey_settings['enckey_confirm']) {
		$tab = $this->enckey_settings_key; $this->Meldung='Verschlüsselungskennwort stimmt nicht mit der Überprüfung überein, bitte setzen Sie es neu!';
	} elseif ($this->enckey_settings['enckey_option']=='') {
		$tab = $this->enckey_settings_key; $this->Meldung='Es wurde kein Verschlüsselungskennwort gesetzt, bitte geben Sie dieses ein!';
	} elseif ( !preg_match('/^(?=.*\d)(?=.*[@#\-_$%^&+=§!\?\.])(?=.*[a-z])(?=.*[A-Z])[0-9A-Za-z@#\-_$%^&+=§!\?\.]{10,50}$/',$this->enckey_settings['enckey_option']) ) {
		$tab = $this->enckey_settings_key; $this->Meldung='Das Verschlüsselungskennwort muss mindestens einen Kleinbuchstaben, einen Großbuchstaben, eine Ziffer und ein Sonderzeichen beinhalten sowie aus mindestens 10 Zeichen bestehen!';
	} else {
		$tab = isset( $_GET['tab'] ) ? $_GET['tab'] : $this->manback_settings_key;
	}
	if (isset($_GET['submit_startbackup'])) {
		if (isset($_POST[$this->manback_settings_key]['manback_option'])) {
			if ($_POST[$this->manback_settings_key]['manback_option']==$this->enckey_settings['enckey_option']) {
    				$this->Meldung='Starte Backup<br />';
    				if ($this->backuppress_man_exec()) {
				} else {$this->Meldung.='Sicherung fehlerhaft!<br />Bitte kontaktieren Sie unseren Support unter service@laempe-riskmanagement.de !';}				
			} else {$this->Meldung='Das eingegebene Verschlüsselungskennwort stimmt nicht mit dem bisher vergebenen Verschlüsselungskennwort überein! Falls Sie das bisherige Verschlüsselungskennwort nicht mehr verfügbar haben, ändern Sie dieses bitte unter dem Reiter "Verschlüsselung" ab.<br />Bitte beachten Sie dabei, dass nur die danach erstellten Sicherungsdateien mit dem neuen Verschlüsselungskennwort verschlüsselt werden!';}
		} else {$this->Meldung='Bitte geben Sie ein Verschlüsselungskennwort ein!';}
	}
	if (isset($_GET['submit_startrestore'])) {
		if (isset($_POST[$this->restore_settings_key]['restore_option'])) {
			if ($this->backuppress_restore_exec($_POST[$this->restore_settings_key]['restore_option'])) {
				$this->Meldung.='Die Rücksicherung war erfolgreich!<br />';
			} else {
				$this->Meldung.='Die Rücksicherung ist fehlgeschlagen, bitte wenden Sie sich an unseren Support unter service@laempe-riskmanagement.de !<br />';
				$subject="BackupPress GENIUS - WordPress-Rücksicherung fehlgeschlagen";
				$pathwp=get_home_url();
				$admin_email = get_option('admin_email');
				$message="Sehr geehrter BackupPress GENIUS-Kunde,\n\nIhre Rücksicherung von WordPress ($pathwp) war nicht erfolgreich.\n\nBitte kontaktieren Sie unseren Support unter service@laempe-riskmanagement.de !\n\nIhr Service-Team";
				wp_mail($admin_email,$subject,$message);
				$messagebpg=$pathwp."\n\n".str_replace("<br />","\n",$this->Meldung);
				wp_mail('service@laempe-group.de',$subject,$messagebpg);
			}
		} else {$this->Meldung.='Keine Datei angegeben!';}
	}
	$autobacktime=time();
	$deltatime=date('G',(current_time('timestamp')-time()));
	$autostarttime=$this->autoback_settings['autoback_option']-$deltatime;
	if ($autostarttime<0) {$autostarttime=$autostarttime+24;}
	if ($autostarttime>23) {$autostarttime=$autostarttime-24;}
	for ($t=0;$t<24;$t++) {
		$pruefzeit = date("Y-m-d H:00:00", strtotime(sprintf("+%d hours", $t)));
		if (date('G', strtotime(sprintf("+%d hours", $t)))==$autostarttime) {
			$autobacktime=strtotime($pruefzeit);
		}
	}
	if( !wp_next_scheduled( 'backuppress_cron_hook' ) ) {
	   	wp_schedule_event( $autobacktime, 'daily', 'backuppress_cron_hook' );
	} else {
		$timestamp = wp_next_scheduled( 'backuppress_cron_hook' );
		if (date('G', $timestamp)!==$autostarttime) {
			wp_unschedule_event($timestamp, 'backuppress_cron_hook' );
			wp_schedule_event( $autobacktime, 'daily', 'backuppress_cron_hook' );
		}
	}

	//Aktualisierung der Pfad.ini
	$bup_license=$this->license_settings['license_option'];
    if (strlen($bup_license)==0) {$bup_license='XXX';}
	$pfadini_content=ABSPATH.'---'.get_home_url().'---'.$autostarttime.'---'.get_option('admin_email').'---'.$bup_license.'---'.$this->version.'---'.$this->autoback_settings['autoback_option'].'---'.'Aktiv';
	$this->pfadini_update($pfadini_content);
    	$file = dirname(__FILE__);
    	// Get correct URL and path to wp-content
    	$content_url = untrailingslashit( dirname( dirname( get_stylesheet_directory_uri() ) ) );
    	$content_dir = untrailingslashit( dirname( dirname( get_stylesheet_directory() ) ) );
    	// Fix path on Windows
    	$file = str_replace( '\\', '/', $file );
    	$content_dir = str_replace( '\\', '/', $content_dir );
    	$url = str_replace( $content_dir, $content_url, $file );
	?>
	<div class="wrap">
		<script language="JavaScript" type="text/javascript">
		function showspinner() {
			show('manbackspinner', true);
			show('restorespinner', true);
		}
		function enabletext() {
			disabletext('licensekey', false);
			disabletext('enckey', false);
			disabletext('enckeycon', false);
		}
		function onReady(callback) {
			var intervalID = window.setInterval(checkReady, 100);
			function checkReady() {
				if (document.getElementsByTagName('body')[0] !== undefined) {
					window.clearInterval(intervalID);
					callback.call(this);
				}
			}
		}

		function show(id, value) {
			var element =  document.getElementById(id);
			if (typeof(element) != 'undefined' && element != null)
			{
				element.style.display = value ? 'inline-block' : 'none';
			}
		}
		function disabletext(id, value) {
			var element =  document.getElementById(id);
			if (typeof(element) != 'undefined' && element != null)
			{
				element.disabled = value ? true : false;
			}
		}
		onReady(function () {
			show('manbackspinner', false);
			show('restorespinner', false);
			disabletext('licensekey', true);
			disabletext('enckey', true);
			disabletext('enckeycon', true);
		});
		</script>
		<div class="laempelogo"><a href="http://backuppress.de" target="_blank"><img src="<?php echo $url ?>/PlugInTitelV1.png" /></a></div>
		<div class="bpgtop"><a href="http://backuppress.de/support/" target="_blank">Weitere Hinweise zur Datensicherung.</a></div>
		<?php
		if ($tab==$this->restore_settings_key) {
			$clientpfadadresse = "";
			$clientpfadadressefeld=explode('/',get_home_url());
			for($i=2;$i<count($clientpfadadressefeld);$i++) {$clientpfadadresse.='_'.$clientpfadadressefeld[$i];}
			$bpgfilesurl = $this->fUrl."/Kundendaten/".$clientpfadadresse."/".$this->bpgfilesname;
			$upload_dir = wp_upload_dir();
			$zipdir = $upload_dir['basedir'].$this->bpguploads;
			$bpgfileslokal = $zipdir.$this->bpgfilesname;
			$downloadok=$this->downloadFile($bpgfilesurl, $bpgfileslokal);
			if ($downloadok===false) {$this->Meldung.='Dateiliste konnte nicht heruntergeladen werden.<br />';}
			$this->plugin_options_tabs( $tab ); ?>
			<form method="post" action="?page=<?php echo $this->plugin_options_key; ?>&tab=<?php echo $this->restore_settings_key; ?>&submit_startrestore=true">
				<?php settings_fields( $tab ); ?>
				<?php do_settings_sections( $tab ); ?>
				<?php if ($this->Meldung!=='') {echo '<p style="color:red;">'.$this->Meldung.'</p>';} ?>
			</form>
		<?php } elseif ($tab==$this->manback_settings_key) {
			$this->plugin_options_tabs( $tab ); ?>
			<form method="post" action="?page=<?php echo $this->plugin_options_key; ?>&tab=<?php echo $this->manback_settings_key; ?>&submit_startbackup=true">
				<?php settings_fields( $tab ); ?>
				<?php do_settings_sections( $tab ); ?>
				<?php if ($this->Meldung!=='') {echo '<p style="color:red;">'.$this->Meldung.'</p>';} ?>
			</form>
		<?php } else {
			$this->plugin_options_tabs( $tab );?>
			<form method="post" action="options.php">
				<?php wp_nonce_field( 'update-options' ); ?>
				<?php settings_fields( $tab ); ?>
				<?php do_settings_sections( $tab ); ?>
				<?php if ($this->Meldung!=='') {echo '<p style="color:red;">'.$this->Meldung.'</p>';} ?>
				<input type="submit" onclick="enabletext()" name="submit" id="submit" class="ButtonStandard" value="Einstellungen übernehmen"  />
			</form>
		<?php } ?>
	</div>
	<?php
}
function plugin_options_tabs( $tab ) {
	$current_tab = $tab;

	screen_icon();
	echo '<h2 class="nav-tab-wrapper">';
	foreach ( $this->plugin_settings_tabs as $tab_key => $tab_caption ) {
		$active = $current_tab == $tab_key ? 'nav-tab-active' : '';
		echo '<a class="nav-tab ' . $active . '" href="?page=' . $this->plugin_options_key . '&tab=' . $tab_key . '">' . $tab_caption . '</a>';	
	}
	echo '</h2>';
}


function encrypt_backuppress($stringtoencrypt,$encryptionkey,$trenner) {
	$aes = new Crypt_AES();
	$aes->setKey($encryptionkey);
	$ergebnis='';
	$contents = base64_encode($aes->encrypt($stringtoencrypt));
	for ($i=0;$i<=strlen($contents);$i++) {$buchstabe=substr($contents,$i,1);$buchstabeAsc=ord($buchstabe);$ergebnis=$ergebnis.$buchstabeAsc.$trenner;}		
	$ergebnis=substr($ergebnis,0,strlen($ergebnis)-strlen($trenner));	
	return $ergebnis;
}
function decrypt_backuppress($stringtodecrypt,$decryptionkey,$trenner) {
	$aes = new Crypt_AES();
	$aes->setKey($decryptionkey);
	$ergebnis='';
	$feld=explode($trenner,$stringtodecrypt);
	for ($i=0;$i<(count($feld));$i++) {$buchstabeAsc = intval($feld[$i]);if ($buchstabeAsc>=0) {$buchstabe=chr($buchstabeAsc);$ergebnis=$ergebnis.$buchstabe;} }
	$contents = $aes->decrypt(base64_decode($ergebnis));
	return $contents;
}

function backup_mysql($host,$user,$pass,$name,$tables = '*',$filedate)
{
	global $wpdb;
	$dbprefix=$wpdb->base_prefix;
	$return = '';
	$link = mysql_connect($host,$user,$pass);
	$charset = mysql_client_encoding($link);
	mysql_select_db($name,$link);
	
	//get all of the tables
	if($tables == '*')
	{
		$tables = array();
		$result = mysql_query("SHOW TABLES LIKE '$dbprefix%'");
		while($row = mysql_fetch_row($result))
		{
			$tables[] = $row[0];
		}
	}
	else
	{
		$tables = is_array($tables) ? $tables : explode(',',$tables);
	}
	
	//cycle through
	foreach($tables as $table)
	{
		$result = mysql_query('SELECT * FROM '.$table);
		$num_fields = mysql_num_fields($result);
		
		$return.= 'DROP TABLE '.$table.';';
		$row2 = mysql_fetch_row(mysql_query('SHOW CREATE TABLE '.$table));
		$return.= "\n\n".$row2[1].";\n\n";
		
		for ($i = 0; $i < $num_fields; $i++) 
		{
			while($row = mysql_fetch_row($result))
			{
				$return.= 'INSERT INTO '.$table.' VALUES(';
				for($j=0; $j<$num_fields; $j++) 
				{
					$row[$j] = addslashes($row[$j]);
					$row[$j] = str_replace("\n", '\n', $row[$j]);
					if (isset($row[$j])) { $return.= '"'.$row[$j].'"' ; } else { $return.= '""'; }
					if ($j<($num_fields-1)) { $return.= ','; }
				}
				$return.= ");\n";
			}
		}
		$return.="\n\n\n";
	}
	
	//save file
	global $BackuppressDir;
	$file_path = $BackuppressDir . '/backups/';
	$this->createPath($file_path);
	$file_path=$file_path.$wpdb->dbname.'-' . $filedate . '.sql';
	$handle = fopen($file_path,'w+');
	$ergebnis=fwrite($handle, $return);
	fclose($handle);
	if ($ergebnis===false) {$this->Meldung.='Fehler beim Schreiben der MySQL-Sicherungsdatei.<br />Bitte kontaktieren Sie unseren Support unter service@laempe-riskmanagement.de !<br />';return false;}
	return $file_path;
}

function Zip_backuppress($source,$zipdir,$zipurl,$datum,$blacklist,$mysqlDatei,$password)
{
    if (!extension_loaded('zip') || !file_exists($source)) {
        return false;
    }
    $source = str_replace('\\', '/', realpath($source));
	$sendallok=true;
    if (is_dir($source) === true)
    {
		$jobpfad=str_replace('\\', '/', $zipdir.$datum);
		$this->createPath($jobpfad);
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source), RecursiveIteratorIterator::SELF_FIRST);
		// Prüfdatei erstellen
		$bpglogfile=$jobpfad.'/'.$this->bpglogfilename;
		file_put_contents($jobpfad.'/'.$this->dateinameupstart,'');
		$dateiupstart=$jobpfad.'/'.$this->dateinameupstart; 
		$dateiupstart=str_replace('\\', '/', $dateiupstart);
		$dateiupstarturl=str_replace(str_replace('\\', '/', $zipdir),$zipurl,$dateiupstart);
		$sendok=$this->sendFile_bup($dateiupstarturl);
		if ($sendok===false) {$this->Meldung.='Kein Hochladen auf den BackupGermany-Server möglich.<br />Bitte kontaktieren Sie unseren Support unter service@laempe-riskmanagement.de !<br />';return false;}
		$dateianzahl=0;
		$mysqlDatei = realpath($mysqlDatei);
		$mysqlDatei = str_replace('\\', '/', $mysqlDatei);
		$source=str_replace('\\', '/', $source);
		$neuzeile=$mysqlDatei.'.zip';
		if (file_exists($bpglogfile)) {
			$inhalt="\n".$neuzeile;
		} else {$inhalt=$neuzeile;}
		$return=file_put_contents($bpglogfile, $inhalt, FILE_APPEND); // | LOCK_EX
		if ($return===false) {$this->Meldung.='Dateienliste konnte nicht aktualisiert werden.<br />Bitte kontaktieren Sie unseren Support unter service@laempe-riskmanagement.de !<br />';return false;}
		$filesqlzipname=$jobpfad.'/'.str_replace($source . '/', '', $mysqlDatei).'.zip';
		global $BackuppressDir;
		$BackuppressDir=str_replace('\\', '/', $BackuppressDir);
		$this->createPath($jobpfad.'/'.str_replace($source . '/', '', $BackuppressDir . '/backups/'));
		if (file_exists($filesqlzipname)) {
			unlink ($filesqlzipname);
		}
		$zipf = new ZipArchive();
		if (!$zipf->open($filesqlzipname, ZIPARCHIVE::CREATE)) {
			return false;
		}
		$zipf->addFromString(str_replace($source . '/', '', $mysqlDatei), file_get_contents($mysqlDatei));
		$zipf->close();
		$contents=$this->encryptContent(file_get_contents($filesqlzipname),$password);
		if ($contents===false) {$this->Meldung.='Fehler beim Verschlüsseln der Datei '.$mysqlDatei.', Sicherung abgebrochen!<br />Bitte kontaktieren Sie unseren Support unter service@laempe-riskmanagement.de !<br />';unlink ($filesqlzipname);return false;}
		if (file_put_contents($filesqlzipname, $contents) === false) {$this->Meldung.='Fehler beim Schreiben der verschlüsselten Daten in Datei '.$filesqlzipname.'<br />Bitte kontaktieren Sie unseren Support unter service@laempe-riskmanagement.de !<br />';unlink ($filesqlzipname);return false;}
		// Nun die erstellte zip-Datei hochladen
		$filesqlzipurl=str_replace(str_replace('\\', '/', $zipdir),$zipurl,$filesqlzipname);
		$sendok=$this->sendFile_bup($filesqlzipurl);
		if ($sendok===false) {$this->Meldung.='Kein Hochladen auf den BackupGermany-Server möglich.<br />Bitte kontaktieren Sie unseren Support unter service@laempe-riskmanagement.de !<br />';return false;}
		$dateianzahl=$dateianzahl+1;
        foreach ($files as $file)
        {
			$file = str_replace('\\', '/', $file);
			$inblacklist=false;
			$ibl='false';
			foreach ($blacklist as $blentry) {
				$blentry = str_replace('\\', '/', $blentry);
				if(strpos($file,$blentry)!==false) {
					$inblacklist=true;
					$ibl='true';
				}
			}
			if ($inblacklist===false) {
				// Ignore "." and ".." folders
				if( in_array(substr($file, strrpos($file, '/')+1), array('.', '..')) )
					continue;
				
				$file = realpath($file);
				$file = str_replace('\\', '/', $file);
				$source=str_replace('\\', '/', $source);
				if (is_dir($file) === true)
				{
					// Verzeichnis anlegen
					$this->createPath($jobpfad.'/'.str_replace($source . '/', '', $file . '/'));
				}
				else if (is_file($file) === true)
				{
					$neuzeile=$file.'.zip';
					if (file_exists($bpglogfile)) {
						$inhalt="\n".$neuzeile;
					} else {$inhalt=$neuzeile;}
					$return=file_put_contents($bpglogfile, $inhalt, FILE_APPEND); // | LOCK_EX

					if ($return===false) {$this->Meldung.='Dateienliste konnte nicht aktualisiert werden.<br />Bitte kontaktieren Sie unseren Support unter service@laempe-riskmanagement.de !<br />';return false;}
					
					$filezipname=$jobpfad.'/'.str_replace($source . '/', '', $file).'.zip';
					if (file_exists($filezipname)) {
						unlink ($filezipname);
					}
					$zipf = new ZipArchive();
					if (!$zipf->open($filezipname, ZIPARCHIVE::CREATE)) {
						return false;
					}
					$zipf->addFromString(str_replace($source . '/', '', $file), file_get_contents($file));
					$zipf->close();
					$contents=$this->encryptContent(file_get_contents($filezipname),$password);
					if ($contents===false) {$this->Meldung.='Fehler beim Verschlüsseln der Datei '.$file.', Sicherung abgebrochen!<br />Bitte kontaktieren Sie unseren Support unter service@laempe-riskmanagement.de !<br />';unlink ($filezipname);return false;}
					if (file_put_contents($filezipname, $contents) === false) {$this->Meldung.='Fehler beim Schreiben der verschlüsselten Daten in Datei '.$filezipname.'<br />Bitte kontaktieren Sie unseren Support unter service@laempe-riskmanagement.de !<br />';unlink ($filezipname);return false;}
					// Nun die erstellte zip-Datei hochladen
					$filezipurl=str_replace(str_replace('\\', '/', $zipdir),$zipurl,$filezipname);
					$sendok=$this->sendFile_bup($filezipurl);
					if ($sendok===false) {$this->Meldung.='Kein Hochladen auf den BackupGermany-Server möglich.<br />Bitte kontaktieren Sie unseren Support unter service@laempe-riskmanagement.de !<br />';return false;}
					$dateianzahl=$dateianzahl+1;
				}
			}
        }
		
		file_put_contents($jobpfad.'/'.$this->dateinameupok,$dateianzahl);
		$dateiupok=$jobpfad.'/'.$this->dateinameupok;
		$dateiupok=str_replace('\\', '/', $dateiupok);
		$dateiupokurl=str_replace(str_replace('\\', '/', $zipdir),$zipurl,$dateiupok);
		$sendok=$this->sendFile_bup($dateiupokurl);
		if ($sendok===false) {$this->Meldung.='Kein Hochladen auf den BackupGermany-Server möglich.<br />Bitte kontaktieren Sie unseren Support unter service@laempe-riskmanagement.de !<br />';return false;}
    }
    else if (is_file($source) === true)
    {
		$filezipname=$source.'.zip';
		if (file_exists($filezipname)) {
			unlink ($filezipname);
		}
		$zipf = new ZipArchive();
		if (!$zipf->open($filezipname, ZIPARCHIVE::CREATE)) {
			return false;
		}
		$zipf->addFromString(str_replace($source . '/', '', $source), file_get_contents($source));
		$zipf->close();
		$contents=$this->encryptContent(file_get_contents($filezipname),$password);
		if ($contents===false) {$this->Meldung.='Fehler beim Verschlüsseln der Datei '.$source.', Sicherung abgebrochen!<br />Bitte kontaktieren Sie unseren Support unter service@laempe-riskmanagement.de !<br />';unlink ($filezipname);return false;}
        if (file_put_contents($filezipname, $contents) === false) {$this->Meldung.='Fehler beim Schreiben der verschlüsselten Daten in Datei '.$filezipname.'<br />Bitte kontaktieren Sie unseren Support unter service@laempe-riskmanagement.de !<br />';unlink ($filezipname);return false;}
		// Nun die erstellte zip-Datei hochladen
		$filezipurl=str_replace($zipdir,$zipurl,$filezipname);
		$sendok=$this->sendFile_bup($filezipurl);
		if ($sendok===false) {$this->Meldung.='Kein Hochladen auf den BackupGermany-Server möglich.<br />Bitte kontaktieren Sie unseren Support unter service@laempe-riskmanagement.de !<br />';return false;}
    }
	//Alte lokale Sicherungsdateien loeschen, nur aktuelle und Dateienliste behalten
	$delfiles = scandir($zipdir);
    foreach ($delfiles as $delfile) {
		$delfile=$zipdir.$delfile;
        if( in_array(substr($delfile, strrpos($delfile, '/')+1), array('.', '..')) )
        	continue;
		if (strpos($delfile,$datum)===false) {
			if (strpos($delfile,$this->bpgfilesname)===false) {
				if (is_dir($delfile)) {
					$this->deleteDir($delfile);
				} else {
					unlink($delfile);
				}
			}
		} else {$this->restore_settings['restore_option']=$delfile;}
    }
	global $BackuppressDir;
	$file_path_sql = $BackuppressDir . '/backups/';
	$delfilessql=scandir($file_path_sql);
	foreach ($delfilessql as $delfilesql) {
		$delfilesql=$file_path_sql.$delfilesql;
		if( in_array(substr($delfilesql, strrpos($delfilesql, '/')+1), array('.', '..')) )
        	continue;
		if (strpos($delfilesql,$datum)===false) {
			if (is_dir($delfilesql)) {
				$this->deleteDir($delfilesql);
			} else {
				unlink($delfilesql);
			}
		}
	}
    return true;
}

function deleteDir($path) {
    if (!is_dir($path)) {
        throw new InvalidArgumentException("$path is not a directory");
    }
    if (substr($path, strlen($path) - 1, 1) != '/') {
        $path .= '/';
    }
    $dotfiles = glob($path . '.*', GLOB_MARK);
    $files = glob($path . '*', GLOB_MARK);
    $files = array_merge($files, $dotfiles);
    foreach ($files as $file) {
        if (basename($file) == '.' || basename($file) == '..') {
            continue;
        } else if (is_dir($file)) {
            self::deleteDir($file);
        } else {
            unlink($file);
        }
    }
    rmdir($path);
}

function encryptContent ($contents,$password) {
	$aes = new Crypt_AES();
    $key = md5($password, true);
    $aes->setKey($key);
	$contents = base64_encode($aes->encrypt($contents));
	return $contents;
}
function decryptContent ($contents,$password) {
	$aes = new Crypt_AES();
    $key = md5($password, true);
    $aes->setKey($key);
	$contents = $aes->decrypt(base64_decode($contents));
	if ($contents===false) {return false;}
	return $contents;
}

function mySQLRestore ($maindir,$filedate) {
    $sqlinhalt='';
    $sqlfile=$this->searchingFile($maindir,$filedate.'.sql');
    $sqlinhalt=file($sqlfile[0]);
    if (mysql_connect(DB_HOST,DB_USER,DB_PASSWORD)) {
	mysql_select_db(DB_NAME) or die('Error selecting MySQL database: ' . mysql_error());
    } else {
		// wp-config.php suchen
		echo $maindir."<br />";
		$file = $this->searchingFile($maindir,'wp-config.php'); echo $file[0].'<br />';
		$inhalt='';
		$inhalt=file($file[0]);
		// mySQl-Anmeldedaten auslesen
		if ($inhalt!=='') {
			foreach ($inhalt as $zeile) {
				if (strpos($zeile,'DB_NAME')) {
					$feld=explode("'",$zeile);
					$mysql_database=$feld[3];
				}
					if (strpos($zeile,'DB_USER')) {
					$feld=explode("'",$zeile);
					$mysql_username=$feld[3];
					}
					if (strpos($zeile,'DB_PASSWORD')) {
					$feld=explode("'",$zeile);
					$mysql_password=$feld[3];
					}
					if (strpos($zeile,'DB_HOST')) {
					$feld=explode("'",$zeile);
					$mysql_host=$feld[3];
					}
			}
		} else {$this->Meldung.= 'Datenbankverbindung nicht gefunden.<br />Bitte kontaktieren Sie unseren Support unter service@laempe-riskmanagement.de !<br />';return false;}

		// Verbinden zum MySQL-Server
		mysql_connect($mysql_host, $mysql_username, $mysql_password) or die('Error connecting to MySQL server: ' . mysql_error());
		// Datenbank auswählen
		mysql_select_db($mysql_database) or die('Error selecting MySQL database: ' . mysql_error());
    }
    // Temporäre Variable für Gebrauch der aktuellen Abfrage
    $templine = '';
    // Jede Zeile der sql-Datei durchgehen
    foreach ($sqlinhalt as $line)
    {
        // Skip it if it's a comment
        if (substr($line, 0, 2) == '--' || $line == '')
            continue;
 
        // Add this line to the current segment
        $templine .= $line;
        // If it has a semicolon at the end, it's the end of the query
        if (substr(trim($line), -1, 1) == ';')
        {
            // Perform the query;
            mysql_query($templine) or ('Error performing query \'<strong>' . $templine . '\': ' . mysql_error() . '<br /><br />');
            // Reset temp variable to empty
            $templine = '';
		}
    }
}


function searchingFile($dir, $ftype) {
    $handle = opendir($dir);
    $resultArray = array();
    while (false !== ($file = readdir($handle))){
        if ($file != '.' && $file != '..') {
 
 
            if (is_dir($dir . '/' . $file)) {
                //unterverzeichnis einlesen
                $subdir = $this->searchingFile($dir . '/' . $file, $ftype);
                $resultArray = array_merge($resultArray, $subdir);
            }
        }
        if(strpos($file, $ftype)!==false) {
            //datei gefunden
            $resultArray[] = $dir.'/'.$file;
        }
    }
    closedir($handle);
    return $resultArray;
}

function createPath($path) {
    if (is_dir($path)) return true;
    $prev_path = substr($path, 0, strrpos($path, '/', -2) + 1 );
    $return = $this->createPath($prev_path);
    return ($return && is_writable($prev_path)) ? mkdir($path) : false;
}
function sendFile_bup ($zipfileurl) {
	$bup_license=$this->license_settings['license_option'];
	$pathwp=get_home_url();
	$upload_dir = wp_upload_dir();
	$zipurl=$upload_dir['baseurl'].$this->bpguploads;
    if (strlen($bup_license)==0) {$bup_license='XXX';}
    $rUrl=$this->fUrl."/Backuppress.aspx?datei=$zipfileurl&lizenz=$bup_license&pfad=$pathwp&zipurl=$zipurl";
    $data=array('datei'=>$zipfileurl,'lizenz'=>$bup_license,'pfad'=>$pathwp,'zipurl'=>$zipurl);
    $query=http_build_query($data);
    $clientpfadadresse='';
    $ch = curl_init($rUrl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $query);
    $response = curl_exec($ch);
    $json = json_decode($response, true);
    curl_close($ch);
    if ($response===false) {$this->Meldung.='Fehler beim Übertragen der Sicherungsdateien.<br />Bitte kontaktieren Sie unseren Support unter service@laempe-riskmanagement.de !<br />';return false;}
    return true;
}
};
add_action( 'plugins_loaded', create_function( '', '$backuppressgenius = new BackuppressGenius;' ) );



?>