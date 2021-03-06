<?php
// pb imports
require_once(PB_DIR.'lib/data/source/Source.class.php');
require_once(PB_DIR.'lib/system/package/PackageBuilder.class.php');
require_once(PB_DIR.'lib/system/package/PackageHelper.class.php');
require_once(PB_DIR.'lib/system/package/PackageReader.class.php');

// wcf imports
require_once(WCF_DIR.'lib/action/AbstractAction.class.php');

/**
 * Builds a package.
 *
 * @author	Alexander Ebert
 * @copyright	2009-2010 WoltLab Community
 * @license	GNU Lesser General Public License <http://opensource.org/licenses/lgpl-license.php>
 * @package	com.woltlab.community.pb
 * @subpackage	action
 * @category 	PackageBuilder
 */
class BuildPackageAction extends AbstractAction {
	/**
	 * Target package resource location
	 *
	 * @var	string
	 */
	public $directory = '';

	/**
	 * Holds data for all referenced packages
	 *
	 * @var	array<array>
	 */
	public $packages = array();

	/**
	 * Save selection permanently
	 *
	 * @var	boolean
	 */
	public $saveSelection = false;

	/**
	 * Filename Pattern for the Archive
	 *
	 * @var	string
	 */
	public $filename = 'pn_pv';

	/**
	 * Source object
	 *
	 * @var	Source
	 */
	public $source = null;
	
	/**
	 * source id
	 * 
	 * @var	integer
	 */
	public $sourceID = 0;
	
	/**
	 * Create a wcf setup afterwards using the given resource
	 * 
	 * @var	string
	 */
	public $wcfSetupResource = '';

	/**
	 * @see Action::readParameters()
	 */
	public function readParameters() {
		parent::readParameters();
		
		if (isset($_POST['filename'])) $this->filename = StringUtil::trim($_POST['filename']);
		if (isset($_POST['saveSelection'])) $this->saveSelection = true;
		if (isset($_POST['sourceID'])) $this->sourceID = intval($_POST['sourceID']);
		
		if (isset($_POST['wcfSetupResource'])) {
			$this->wcfSetupResource = StringUtil::trim($_POST['wcfSetupResource']);
			
			// override package name if building WCFSetup
			$this->filename = 'pn';
		}
		
		$this->source = new Source($this->sourceID);
		if (!$this->source->sourceID) throw new IllegalLinkException();
		if (!$this->source->hasAccess()) throw new PermissionDeniedException();

		// read selected resources
		$this->readPackageSelection();

		// handle current directory resource
		$sourceData = WCF::getSession()->getVar('source'.$this->source->sourceID);
		if ($sourceData === null) throw new SystemException('Resource directory missing');
		
		$sourceData = unserialize($sourceData);
		$this->directory = $sourceData['directory'];
	}

	/**
	 * Reads selected package resources
	 */
	protected function readPackageSelection() {
		if (!isset($_POST['packages']) || !is_array($_POST['packages'])) return;

		// handle package selection
		foreach ($_POST['packages'] as $package) {
			list($hash, $packageName) = explode('-', $package);

			if (isset($_POST[$hash])) {
				$this->packages[$packageName] = array(
					'hash' => $hash,
					'directory' => StringUtil::trim($_POST[$hash])
				);
			}
		}
	}

	/**
	 * @see Action::execute()
	 */
	public function execute() {
		// call execute event
		parent::execute();

		// save selection
		if ($this->saveSelection) {
			$sql = '';
			
			foreach ($this->packages as $packageName => $packageData) {
				if (!empty($sql)) $sql .= ',';

				$sql .= "(".$this->source->sourceID.",
					'".escapeString($this->directory)."',
					'".escapeString($packageName)."',
					'".escapeString($packageData['hash'])."',
					'".escapeString($packageData['directory'])."'
					)";
			}

			if (!empty($sql)) {
				$sql = "INSERT INTO		pb".PB_N."_selected_package
								(sourceID, directory, packageName, hash, resourceDirectory)
					VALUES			".$sql."
					ON DUPLICATE KEY UPDATE	resourceDirectory = VALUES(resourceDirectory)";
				WCF::getDB()->sendQuery($sql);
			}
		}

		// set package resources
		PackageHelper::registerPackageResources($this->packages);

		// read package
		$pr = new PackageReader($this->source->sourceID, $this->directory);
		
		try {
			// build package
			$pb = new PackageBuilder($this->source, $pr, $this->directory, $this->filename);
			
			if ($this->wcfSetupResource) {
				require_once(PB_DIR.'lib/system/package/StandalonePackageBuilder.class.php');
				$spb = new StandalonePackageBuilder($this->source, $this->wcfSetupResource);
				$spb->createWcfSetup($pb->getArchiveLocation());
			}
		}
		// do cleanup
		catch (SystemException $e) {
			PackageHelper::clearTemporaryFiles();
			throw $e;
		}

		// clear previously created archives
		PackageHelper::clearTemporaryFiles();

		// call executed event
		$this->executed();

		// forward
		HeaderUtil::redirect('index.php?page=SourceView&sourceID='.$this->source->sourceID.SID_ARG_2ND_NOT_ENCODED);
		exit;
	}
}
?>