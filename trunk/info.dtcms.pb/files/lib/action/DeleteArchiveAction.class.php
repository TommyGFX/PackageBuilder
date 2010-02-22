<?php
// pb imports
require_once(PB_DIR.'lib/data/source/Source.class.php');

// wcf imports
require_once(WCF_DIR.'lib/action/AbstractAction.class.php');

/**
 * Deletes an archive.
 *
 * @package	info.dtcms.pb
 * @author	Alexander Ebert
 * @copyright	2009 Alexander Ebert IT-Dienstleistungen
 * @license	GNU Lesser Public License <http://www.gnu.org/licenses/lgpl.html>
 * @subpackage	action
 * @category	PackageBuilder
 */
class DeleteArchiveAction extends AbstractAction {
	public $filename = '';
	public $sourceID = 0;

	/**
	 * @see Action::readParameters()
	 */
	public function readParameters() {
		parent::readParameters();

		if (isset($_GET['sourceID'])) $this->sourceID = intval($_GET['sourceID']);
		if (isset($_GET['filename'])) $this->filename = $_GET['filename'];
	}

	/**
	 * @see Action::execute()
	 */
	public function execute() {
		// call execute event
		parent::execute();

		// read source
		$source = new Source($this->sourceID);

		// delete files
		$location = $source->buildDirectory;
		$location .= (!empty($this->filename)) ? $this->filename : '';
		$this->deleteFile($location);

		// call executed event
		$this->executed();

		// forward
		HeaderUtil::redirect('index.php?page=SourceView&sourceID='.$this->sourceID.SID_ARG_2ND_NOT_ENCODED);
		exit;
	}

	/**
	 * Delete all archives or only a given file
	 *
	 * @param	string	$location
	 */
	protected function deleteFile($location) {
		if (is_dir($location)) {
			if ($dh = opendir($location)) {
				while (($file = readdir($dh)) !== false) {
     					if ($file == '.' || $file == '..') continue;

     					if (substr($file, -7) == '.tar.gz') {
     						$this->deleteFile($location.$file);
     					}
				}

				closedir($dh);
			}

			return;
		}

		// delete file
		if (file_exists($location)) {
			@unlink($location);
		}
	}
}
?>