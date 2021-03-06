<?php
// pb imports
require_once(PB_DIR.'lib/data/source/file/SourceFileList.class.php');
require_once(PB_DIR.'lib/system/package/PackageHelper.class.php');

// wcf imports
require_once(WCF_DIR.'lib/page/AbstractPage.class.php');
require_once(WCF_DIR.'lib/system/scm/SCMHelper.class.php');

/**
 * Shows details for a given source.
 *
 * @author	Tim Düsterhus, Alexander Ebert
 * @copyright	2009-2010 WoltLab Community
 * @license	GNU Lesser General Public License <http://opensource.org/licenses/lgpl-license.php>
 * @package	com.woltlab.community.pb
 * @subpackage	page
 * @category 	PackageBuilder
 */
class SourceViewPage extends AbstractPage {
	// system
	public $templateName = 'sourceView';
	public $neededPermissions = 'user.source.general.canViewSources';

	// data
	public $buildDirectory = '';
	public $builds = array();
	public $currentDirectory = '';
	public $currentFilename = 'pn_pv';
	public $currentPackageName = '';
	public $directories = array();
	public $filenames = array();
	public $latestRevision = '';
	public $packages = array();
	
	public $sourceFileList = null;
	
	/**
	 * instance of Source
	 * 
	 * @var	Source
	 */
	public $source = null;

	/**
	 * @see	Page::readParameters()
	 */
	public function readParameters() {
		$sourceID = 0;
		if (isset($_GET['sourceID'])) $sourceID = $_GET['sourceID'];
		
		$this->source = new Source($sourceID);
		if (!$this->source->sourceID) throw new IllegalLinkException();
		if (!$this->source->hasAccess()) throw new PermissionDeniedException();
	}

	/**
	 * @see	Page::readData()
	 */
	public function readData() {
		// read cache
		WCF::getCache()->addResource(
			'packages-'.$this->source->sourceID,
			PB_DIR.'cache/cache.packages-'.$this->source->sourceID.'.php',
			PB_DIR.'lib/system/cache/CacheBuilderPackages.class.php'
		);
		
	 	try {
	 		$packages = WCF::getCache()->get('packages-'.$this->source->sourceID, 'packages');
	 	}
	 	catch(SystemException $e) {
	 		// fallback if no cache available
	 		$packages = array();
	 	}
		
		// handle packages
		foreach ($packages as $package) {
			$this->directories[$package['packageName']] = $package['packageName'];
			$this->packages[$package['directory']] = array(
				'packageName' => $package['packageName'],
				'version' => $package['version']
			);
		}
		
		// remove duplicates and sort directories
		asort($this->directories);
		
		// set build directory
		$this->buildDirectory = $this->source->buildDirectory;

  		if (WCF::getUser()->getPermission('admin.source.canEditSources')) {
  			$this->buildDirectory = StringUtil::replace(FileUtil::unifyDirSeperator(PB_DIR), '', $this->buildDirectory);
		}
		
		// get source configuration
		$sourceData = WCF::getSession()->getVar('source'.$this->source->sourceID);
		if ($sourceData !== null) {
			$sourceData = unserialize($sourceData);
			
			$this->currentDirectory = $sourceData['directory'];
			$this->currentPackageName = $sourceData['packageName'];
		}
		else {
			$sql = "SELECT	directory, packageName
				FROM	pb".PB_N."_user_preference
				WHERE 	userID = ".WCF::getUser()->userID."
					AND sourceID = ".$this->source->sourceID;
			$row = WCF::getDB()->getFirstRow($sql);
			
			$this->currentDirectory = $row['directory'];
			$this->currentPackageName = $row['packageName'];
			
			WCF::getSession()->register('source'.$this->source->sourceID, serialize(array(
				'directory' => $row['directory'],
				'packageName' => $row['packageName']
			)));
		}
		
		// set current filename
		$currentFilename = WCF::getSession()->getVar('filename'.$this->source->sourceID);
		if ($currentFilename !== null) {
			$this->currentFilename = $currentFilename;
		}
		
		// read current builds
		$this->sourceFileList = new SourceFileList();
		$this->sourceFileList->sqlConditions = 'source_file.sourceID = '.$this->source->sourceID;
		$this->sourceFileList->sqlLimit = 0;
		$this->sourceFileList->readObjects();
	}

	/**
	 * @see Page::assignVariables()
	 */
	public function assignVariables() {
		parent::assignVariables();
		
		// assign package name
		$this->generateArchiveName(array(
			'pn',		// packageName.tar.gz
			'pn_pv',	// packageName_packageVersion.tar.gz
			(($this->source->revision) ? 'pn_pr' : ''),	// packageName_packageRevision.tar.gz
			(($this->source->revision) ? 'pn_pv_pr' : ''),	// packageName_packageVersion_packageRevision.tar.gz
			'pn_t',		// packageName_time.tar.gz
			'pn_pv_t',	// packageName_packageVersion_time.tar.gz
			(($this->source->revision) ? 'pn_pr_t' : ''),	// packageName_packageRevision_time.tar.gz
			(($this->source->revision) ? 'pn_pv_pr_t': '')	// packageName_packageVersion_packageRevision_time.tar.gz
		));
		
		// assign variables to template
		WCF::getTPL()->assign(array(
			'allowSpidersToIndexThisPage' => false,
			'buildDirectory' => $this->buildDirectory,
			'builds' => $this->sourceFileList->getObjects(),
			'currentDirectory' => $this->currentDirectory,
			'currentFilename' => $this->currentFilename,
			'currentPackageName' => $this->currentPackageName,
			'directories' => $this->directories,
			'filenames' => $this->filenames,
			'source' => $this->source
		));
	}

	/**
	 * Builds any combination of archive names
	 *
	 * @param	string	$pattern
	 */
	protected function generateArchiveName($pattern) {
		// recursively call method if pattern is an array
		if (is_array($pattern)) {
			foreach ($pattern as $filename) {
				if (!$filename) continue;

				$this->generateArchiveName($filename);
			}

			return;
		}

		// dummy values
		if ($this->currentDirectory === null || !isset($this->packages[$this->currentDirectory])) {
			$data = array(
				'pn' => 'packageName',
				'pv' => 'packageVersion',
				'pr' => 'r'.$this->source->revision,
				't' => 	DateUtil::formatTime('%Y-%m-%d %H:%M:%S', TIME_NOW, false)
			);
		}
		else {
			$data = array(
				'pn' => $this->packages[$this->currentDirectory]['packageName'],
				'pv' => $this->packages[$this->currentDirectory]['version'],
				'pr' => 'r'.$this->source->revision,
				't' => 	DateUtil::formatTime('%Y-%m-%d %H:%M:%S', TIME_NOW, false)
			);
		}

		// get filename
		$this->filenames[$pattern] = PackageHelper::getArchiveName($pattern, $data);
	}
}
?>