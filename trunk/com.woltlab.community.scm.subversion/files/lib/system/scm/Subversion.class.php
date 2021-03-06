<?php
// wcf imports
require_once(WCF_DIR.'lib/system/scm/SCM.class.php');
require_once(WCF_DIR.'lib/system/scm/SubversionException.class.php');

/**
 * Provides subversion access
 *
 * @author	Tim Düsterhus, Alexander Ebert
 * @copyright	2009-2010 WoltLab Community
 * @license	GNU Lesser General Public License <http://opensource.org/licenses/lgpl-license.php>
 * @package	com.woltlab.community.scm.subversion
 * @subpackage	system.scm
 * @category 	PackageBuilder
 */
class Subversion implements SCM {
	/**
	 * @see	SCM::checkout()
	 */
	public static function checkout($url, $directory, Array $loginDetails = array(), Array $options = array()) {
		if (empty($directory)) throw new SubversionException('Subversion checkout: target directory missing.');

		// append directory
		$directory = FileUtil::unifyDirSeperator($directory);
		$options['directory'] = $directory;

		return self::executeCommand('checkout', $url, $loginDetails, $options);
	}

	/**
	 * @see	SCM::getHeadRevision()
	 */
	public static function getHeadRevision($url, Array $loginDetails = array(), Array $options = array()) {
		$options['asXML'] = true;
		$output = self::executeCommand('info', $url, $loginDetails, $options);
		$output = implode($output, '');
		$xml = new XML();
		$xml->loadString($output);
		$tree = $xml->getElementTree('');
		return $tree['children'][0]['attrs']['revision'];
	}

	public static function getLog($url, Array $loginDetails = array(), Array $options = array()) {
		$options['asXML'] = true;
		$output = self::executeCommand('log', $url, $loginDetails, $options);
		$output = implode($output, '');
		$xml = new XML();
		$xml->loadString($output);
		return $xml->getElementTree('');
	}

	public static function ls($url, Array $loginDetails = array(), Array $options = array()) {
		$options['asXML'] = true;
		$output = self::executeCommand('list', $url, $loginDetails, $options);
		$output = implode($output, '');
		$xml = new XML();
		$xml->loadString($output);
		return $xml->getElementTree('');
	}

	public static function cat($url, Array $loginDetails = array(), Array $options = array(), $raw = false) {
		$output = self::executeCommand('cat', $url, $loginDetails, $options, $raw);
		if (!$raw) $output = implode($output, "\n");
		return $output;
	}

	public static function diff($url, Array $loginDetails = array(), Array $options = array()) {
		$output = self::executeCommand('diff', $url, $loginDetails, $options);
		$output = implode($output, "\n");
		return $output;
	}

	/**
	 * Executes a subversion command
	 *
	 * @param	string		$command	Command
	 * @param	string		$url		Repository url
	 * @param	array		$loginDetails	Login details if required
	 * @param	array<array>	$options	Additional options
	 * @return	array
	 */
	protected static function executeCommand($command, $url, $loginDetails, Array $options = array(), $raw = false) {
		self::validateSubversionPath();

		// break if repository url is empty
		if (empty($url)) throw new SubversionException('Subversion checkout: URL missing.');

		// handle login details
		$username = (isset($loginDetails['username'])) ? $loginDetails['username'] : '';
		$password = (isset($loginDetails['password'])) ? $loginDetails['password'] : '';

		// handle options
		$directory = (isset($options['directory'])) ? $options['directory'] : '';
		$asXML = (isset($options['asXML'])) ? true : false;
		$trustServerCert = (isset($options['trustServerCert'])) ? true : false;

		// handle additional, non-generic parameters
		if (isset($options['additionalParameters'])) {
			$additionalParameters = implode(' ', $options['additionalParameters']);
		}

		// build complete shell command
		$shellCommand = escapeshellarg(SUBVERSION_PATH).' '.$command.' --non-interactive --config-dir '.SUBVERSION_TEMPORARY_DIRECTORY;
		if ($asXML) $shellCommand .= ' --xml';
		if (!empty($username)) $shellCommand .= ' --username '.escapeshellcmd($username);
		if (!empty($password)) $shellCommand .= ' --password '.$password;
		if ($trustServerCert) $shellCommand .= ' --trust-server-cert';
		$shellCommand .= ' '.$url;
		if (!empty($directory)) $shellCommand .= ' '.$directory;
		if (!empty($additionalParameters)) $shellCommand .= ' '.$additionalParameters;
		$shellCommand .= ' 2>&1';

		// execute command
		if ($raw) {
			ob_start();
			passthru($shellCommand);
			$output = ob_get_contents();
			ob_end_clean();
			return $output;
		}
		else exec($shellCommand, $output);

		return $output;
	}

	/**
	 * Validates if the subversion path is correctly set
	 */
	protected static function validateSubversionPath() {
		// check wether path is given
		if (!defined('SUBVERSION_PATH') || SUBVERSION_PATH == '') {
			throw new SubversionException('Subversion path not set.');
		}

		// check if file exists
		if (!file_exists(SUBVERSION_PATH)) {
			throw new SubversionException('Subversion path seems to be wrong, no file found.');
		}

		// check wether a temporary directory is given
		if (!defined('SUBVERSION_TEMPORARY_DIRECTORY') || SUBVERSION_TEMPORARY_DIRECTORY == '') {
			throw new SubversionException('Missing temporary folder for subversion.');
		}

		// verify that the folder exist and is writable
		$temporaryDirectory = realpath(SUBVERSION_TEMPORARY_DIRECTORY);
		if (!$temporaryDirectory || !is_writeable($temporaryDirectory)) {
			throw new SubversionException('Temporary directory for subversion does not exist or is not writable.');
		}
	}
}
?>