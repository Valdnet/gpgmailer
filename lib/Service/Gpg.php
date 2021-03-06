<?php
/**
 * @copyright Copyright (c) 2017 Arne Hamann <kontakt+github@arne.email>
 *
 * @author Arne Hamann <gpgmailer@arne.email>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\GpgMailer\Service;

use OCP\Defaults;
use OCP\IConfig;
use OCP\ILogger;
use OCP\IUserManager;
use gnupg;

class Gpg {
	/** @var IConfig */
	private $config;
	/** @var ILogger */
	private $logger;
	/** @var Defaults */
	private $defaults;
	private $appName;

	/**
	 * Gpg constructor.
	 *
	 * @param IConfig $config
	 * @param ILogger $logger
	 * @param Defaults $defaults
	 * @param IUserManager $userManager
	 */
	public function __construct(IConfig $config,
								Defaults $defaults,
								ILogger $logger,
								IUserManager $userManager,
								$appName) {
		$this->config = $config;
		$this->logger = $logger;
		$this->defaults = $defaults;
		$this->userManager = $userManager;
		$this->appName = $appName;
		$this->loadUser(null);
		$this->gpg = new gnupg();
		$this->gpg -> setarmor(1);
		$this->gpg -> setsignmode(gnupg::SIG_MODE_DETACH);
		$debugMode = $this->config->getSystemValue('debug', false);
		if ($debugMode) {
			$this->gpg ->seterrormode(gnupg::ERROR_WARNING);
		}
	}
	/**
	 * Combination of gnupg_addencryptkey and gnupg_encrypt
	 *
	 * @param string $plaintext
	 * @param array $fingerprints fingerprints of the encryption keys
	 * @param string $uid = null
	 * @return string
	 */
	public function encrypt(array $fingerprints,  $plaintext, $uid = null ) {
		$this->loadUser($uid);
		$gpg = $this->gpg;
		$debugMode = $this->config->getSystemValue('debug', false);
		foreach ($fingerprints as $fingerprint){
			$gpg->addencryptkey($fingerprint);
		}
		$gpg_text = $gpg->encrypt($plaintext);
		$gpg->clearencryptkeys();
		if($debugMode) {
			$encrypt_fingerprints_text = '';
			foreach ($fingerprints as $encrypt_fingerprint) {
				$encrypt_fingerprints_text = $encrypt_fingerprints_text . "," . $encrypt_fingerprint;
			}
			$this->logger->debug("GPG encrypted plain message: with encrypt keys:" . $encrypt_fingerprints_text . " to gpg text", ['app'=>$this->appName]);
		}
		return $gpg_text;
	}
	/**
	 * Combination of gnupg_addsignkey gnupg_addencryptkey and gnupg_encryptsign
	 *
	 * @param string $plaintext
	 * @param array $encrypt_fingerprints fingerprints of the encryption keys
	 * @param array $sign_fingerprints fingerprints of the sign keys
	 * @param $uid = null
	 * @return string
	 */
	public function encryptsign(array $encrypt_fingerprints, array $sign_fingerprints,  $plaintext, $uid = null ) {
		$this->loadUser($uid);
		$gpg = $this->gpg;
		$debugMode = $this->config->getSystemValue('debug', false);
		foreach ($encrypt_fingerprints as $fingerprint){
			$gpg->addencryptkey($fingerprint);
		}
		foreach ($sign_fingerprints as $key => $fingerprint){
			if (is_numeric($key)){
				$gpg->addsignkey($fingerprint);
			} else {
				$gpg->addsignkey($key, $fingerprint);
			}
		}
		$gpg_text = $gpg->encryptsign($plaintext);
		$gpg->clearencryptkeys();
		$gpg->clearsignkeys();
		if($debugMode) {
			$sign_fingerprints_text = '';
			foreach ($sign_fingerprints as $key => $sign_fingerprint) {
				if (is_numeric($key)){
					$sign_fingerprints_text = $sign_fingerprints_text . "," . $sign_fingerprint;
				} else {
					$sign_fingerprints_text = $sign_fingerprints_text . "," . $key;
				}
			}
			$encrypt_fingerprints_text = '';
			foreach ($encrypt_fingerprints as $encrypt_fingerprint) {
				$encrypt_fingerprints_text = $encrypt_fingerprints_text . "," . $encrypt_fingerprint;
			}
			$this->logger->debug("GPG encryptsigned plain message: with encrypt keys:" . $encrypt_fingerprints_text . " with sign Keys:" . $sign_fingerprints_text . " to gpg text", ['app'=>$this->appName]);
		}
		return $gpg_text;
	}
	/**
	 * Combination of gnupg_addsignkey and gnupg_sign
	 *
	 * @param string $plaintext
	 * @param array $fingerprints fingerprints of the sign keys
	 * @param $uid = null
	 * @return string
	 */
	public function sign(array $fingerprints,  $plaintext, $uid = null ) {
		$this->loadUser($uid);
		$gpg = $this->gpg;
		$debugMode = $this->config->getSystemValue('debug', false);
		foreach ($fingerprints as $key => $fingerprint){
			if (is_numeric($key)){
				$gpg->addsignkey($fingerprint);
			} else {
				$gpg->addsignkey($key, $fingerprint);
			}
		}
		$gpg_text = $gpg->sign($plaintext);
		$gpg->clearsignkeys();
		$sign_fingerprints_text = '';
		if ($debugMode) {
			foreach ($fingerprints as $key => $sign_fingerprint) {
				if (is_numeric($key)){
					$sign_fingerprints_text = $sign_fingerprints_text . "," . $sign_fingerprint;
				} else {
					$sign_fingerprints_text = $sign_fingerprints_text . "," . $key;
				}
			}
			$this->logger->debug("GPG signed plain message: with sign keys:" . $sign_fingerprints_text, ['app'=>$this->appName]);
		}
		return $gpg_text;
	}
	/**
	 * Mapper for gnupg_import,
	 * with expect that only one key per email can be added.
	 *
	 * @param string $keydata
	 * @return array
	 */
	public function import($keydata, $uid = null ) {
		$this->loadUser($uid);
		$gpg = $this->gpg;
		return $gpg->import($keydata);
	}
	/**
	 * Mapper for gnupg_export,
	 * exports the public key for finterprint.
	 *
	 * @param string $fingerprint
	 * @return string
	 */
	public function export($fingerprint, $uid = null ) {
		$this->loadUser($uid);
		$gpg = $this->gpg;
		return $gpg->export($fingerprint);
	}
	/**
	 * Mapper for gnupg_keyinfo
	 *
	 * @param string $pattern
	 * @return array
	 */
	public function keyinfo($pattern, $uid = null ) {
		$this->loadUser($uid);
		$gpg = $this->gpg;
		return $gpg->keyinfo($pattern);
	}
	/**
	 * Mapper for gnupg_deletekey
	 *
	 * Deletes the key from the keyring. If allowsecret is not set or FALSE it will fail on deleting secret keys.
	 * @param string $fingerprint of the key
	 * @param string|null $uid
	 * @param bool $allowsecret
	 * @return bool
	 */
	public function deletekey($fingerprint, $uid = null, $allowsecret = FALSE ) {
		$this->loadUser($uid);
		$gpg = $this->gpg;
		return $gpg->deletekey($fingerprint,$allowsecret);
	}
	/**
	 * Returns the fingerprint of the first public key matching the email.
	 *
	 * @param string $email
	 * @return string
	 */
	public function getPublicKeyFromEmail($email, $uid = null ) {
		$this->loadUser($uid);
		$gpg = $this->gpg;
		$fingerprint = '';
		$keys = $gpg->keyinfo($email);
		if(sizeof($keys)> 0) {
			foreach($keys as $key){
				if (!$key['disabled'] && !$key['expired'] && !$key['revoked']) {
					return $key['subkeys'][0]['fingerprint'];
				}
			}
		}
		return $fingerprint;
	}
	/**
	 * Returns the fingerprint of the first privat key matching the email.
	 *
	 * @param string $email
	 * @return string
	 */
	public function getPrivatKeyFromEmail($email, $uid = null ) {
		$this->loadUser($uid);
		$gpg = $this->gpg;
		$fingerprint = '';
		$keys = $gpg->keyinfo($email);
		if(sizeof($keys)> 0) {
			foreach($keys as $key){
				if (!$key['disabled'] && !$key['expired'] && !$key['revoked'] && $key['is_secret']) {
					return $key['subkeys'][0]['fingerprint'];
				}
			}
		}
		return $fingerprint;
	}
	/**
	 * generate a new Key Pair, if no parameter given the key is for the server is generated
	 *
	 * @param string $email = ''
	 * @param string $name = ''
	 * @param string $commend = ''
	 * @return string $fingerprint
	 */
	public function generateKey($email = '', $name = '', $commend = '', $uid = null ) {
		$this->loadUser($uid);
		$gpg = $this->gpg;
		$debugMode = $this->config->getSystemValue('debug', false);
		if ($email === '' || $name === '') {
			/* otherwise setUser(X); generateKey(); Would generate a server key for User X); */
			if ($uid === '' || $uid === null) {
				$email = \OCP\Util::getDefaultEmailAddress($this->defaults->getName());
				$name = $this->defaults->getName();
				$commend = $this->defaults->getSlogan();
			} else {
				$this->logger->info("Creating Key without email or name is not possible", ['app'=>$this->appName]);
				return "";
			}
		}
		if ($uid !== null && $uid !=='' ) {
			$home = $this->userManager->get($uid)->getHome();
		} else {
			$home = $this->config->getSystemValue("datadirectory");
		}
		$home = rtrim($home,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
		if ($debugMode) {
			$this->logger->debug("Generate server key for email:".$email, ['app'=>$this->appName]);
		}
		//generate Keys
		$cwd = getcwd();
		chdir($home);
		putenv('GNUPGHOME='.$home.'.gnupg');
		$email = escapeshellcmd($email);
		$name = escapeshellcmd($name);
		$commend = escapeshellcmd($commend);
		$foo = system(
			<<<EFF
cat >foo <<EOF
Key-Type: default
Subkey-Type: default
Name-Real: {$name}
Name-Comment: {$commend}
Name-Email: {$email}
Expire-Date: 0
%no-protection
EFF
			,$out1);
		$timestamp_before = time();
		$foo = exec("gpg --batch --gen-key foo 2>&1",$out2);
		$timestamp_after = time();
		if ($debugMode) {
			$this->logger->debug("gpg --batch --gen-key foo:\n" . print_r($out2,TRUE)."\n This took ".($timestamp_after-$timestamp_before)."seconds.", ['app'=>$this->appName]);
		}
		$foo = system("rm foo",$out);
		chdir($cwd);
		$keys = $gpg->keyinfo($email);
		$fingerprint = "";
		foreach ($keys as $key) {
			if ($key["subkeys"][0]["timestamp"] >= $timestamp_before && $key["subkeys"][0]["timestamp"] <= $timestamp_after) {
				if ($debugMode){
					$this->logger->debug("Found new server key:" .$key["subkeys"][0]["fingerprint"], ['app'=>$this->appName]);
				}
				$fingerprint = $key['subkeys'][0]['fingerprint'];
				$timestamp_before = $key["subkeys"][0]["timestamp"];
			}
		}
		if ($fingerprint === "") {
			$this->logger->warning("No server GPG key found so no signed emails are possible", ['app'=>$this->appName]);
		}
		if ($debugMode){
			$this->logger->debug("Saved server key fingerprint:".$fingerprint." to system config", ['app'=>$this->appName]);
		}
		if ($uid === null || $uid ==='' ) {
			$this->config->setAppValue($this->appName,"GpgServerKey",$fingerprint);
		}
		return $fingerprint;
	}
	/**
	 * Change the GPG home from nextcloud-data/.gnupg to user-home/.gnugp
	 * Takes an empty string to reset it to nextcloud-data
	 *
	 * @param $uid
	 * @return $this
	 */
	private function loadUser($uid) {
		if ($uid === null) {
			$home = $this->config->getSystemValue("datadirectory");
		} else {
			$home =  $this->userManager->get($uid)->getHome();
		}
		$home = rtrim($home,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
		putenv('GNUPGHOME='.$home.'.gnupg');
		if(!is_dir($home.'.gnupg')){
			mkdir($home.'.gnupg');
			chmod($home.'.gnupg',0700);
		}
		if(!file_exists($home.'.gnupg/gpg-agent.conf')){
			file_put_contents($home.'.gnupg/gpg-agent.conf', "allow-loopback-pinentry");
			chmod($home.'.gnupg/gpg-agent.conf',0600);
		}
		if(!file_exists($home.'.gnupg/gpg.conf')){
			file_put_contents($home.'.gnupg/gpg.conf', "pinentry-mode loopback");
			chmod($home.'.gnupg/gpg.conf',0600);
		}
		return $this;
	}
}