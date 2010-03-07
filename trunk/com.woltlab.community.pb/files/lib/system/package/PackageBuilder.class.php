<?php
// pb imports
require_once(PB_DIR.'lib/data/source/Source.class.php');
require_once(PB_DIR.'lib/system/package/PackageHelper.class.php');
require_once(PB_DIR.'lib/system/package/PackageReader.class.php');

// wcf imports
require_once(WCF_DIR.'lib/system/io/TarWriter.class.php');

/**
 * Builds a package.
 *
 * @author	Tim Düsterhus, Alexander Ebert
 * @copyright	2009-2010 WoltLab Community
 * @license	GNU Lesser General Public License <http://opensource.org/licenses/lgpl-license.php>
 * @package	com.woltlab.community.pb
 * @subpackage	package
 * @category 	PackageBuilder
 */
class PackageBuilder {
	private $archive = null;
	private $excludeFiles = array('.', '..');
	private $ignoreDoFiles = true;
	private $filename = '';
	private $location = '';
	private $package = array();
	private $source = null;

	/**
	 * Builds a package
	 *
	 * @param	integer	$source			id or instance of a source
	 * @param	array	$package		required and optional packages
	 * @param	string	$directory		source directory
	 * @param	mixed	$excludeFiles		files to exclude while packing archive
	 * @param	bool	$ignoreDotFiles		should files beginning with a dot be ignored
	 */
	public function __construct($source, PackageReader $package, $directory, $filename, $excludeFiles = array(), $ignoreDotFiles = true, $removeAfter = false) {
		// read source
		$this->source = ($source instanceof Source) ? $source : new Source($source);

		// read package
		$this->package = $package->getPackageData();
		if (!isset($this->package['name'])) {
			throw new SystemException('Missing package name in "'.$directory.'", package.xml is not valid');
		}
		$this->ignoreDotFiles = $ignoreDotFiles;
		
		// add additional files whitch should be excluded
		if (!empty($excludeFiles)) {
			if (!is_array($excludeFiles)) {
				$excludeFiles = array($excludeFiles);
			}

			$this->excludeFiles = array_merge($this->excludeFiles, $excludeFiles);
		}

		
		// get data for filename
		$data = array(
			'pn' => $this->package['name'],
			'pv' => $this->package['version'],
			'pr' => 'r'.$this->source->revision,
			't' => 	DateUtil::formatTime('%D %T', TIME_NOW, false)
		);

		// set archive name
		$this->filename = PackageHelper::getArchiveName($filename, $data);
		// mark package as built
		$buildDirectory = $this->source->buildDirectory.'/';
		$location = $buildDirectory.$this->filename;
		PackageHelper::addPackageData($this->package['name'], $location);
		
		// check requirements
		$this->verifyPackages('requiredpackage', $directory);
		$this->verifyPackages('optionalpackage', $directory);

		// intialize archive
		$this->location = $this->createArchive($directory, $this->filename, $removeAfter);
	}

	/**
	 * Verifies if all neccessary packages are present
	 *
	 * @param	string	$packageType
	 * @param	string	$directory
	 */
	public function verifyPackages($packageType, $directory) {
		$directory = $this->source->sourceDirectory.$directory;

		// break if package type is unknown
		if (!isset($this->package[$packageType])) return;
		foreach ($this->package[$packageType] as $packageName => $package) {
			// we do not care about referenced packages with an empty file attribute
			if (empty($package['file'])) continue;

			// check for file in optionals/requiredments folder
			if (file_exists($directory.$package['file'])) continue;

			// look for previously built packages
			$location = PackageHelper::searchPackage($packageName);
			if (!is_null($location)) {
				if (!@copy($location, $directory.$package['file'])) {
					throw new SystemException('Unable to copy archive ('.$package['file'].'), check permissions for directory '.$directory);
				}

				// register temporary file
				PackageHelper::registerTemporaryFile($directory.$package['file']);

				continue;
			}

			// set minimum required version or null if version does not matter
			$minVersion = (isset($package['minversion'])) ? $package['minversion'] : null;

			// search within cached packages
			$location = PackageHelper::searchCachedPackage($this->source->sourceID, $packageName, $minVersion);
			if (!is_null($location)) {
				$packageData = new PackageReader($this->source, $location);
				$pb = new PackageBuilder($this->source, $packageData, $location, 'pn', array(), true, true);
				// copy archive
				if (!@copy($pb->getArchiveLocation(), $directory.$package['file'])) {
					throw new SystemException('Unable to copy archive ('.$package['file'].'), check permissions for directory '.$directory);
				}

				// register temporary file
				PackageHelper::registerTemporaryFile($directory.$package['file']);

				continue;
			}

			// we were unable to locate or build package, thus we have no chance to build this package
			throw new SystemException('Can not build package, '.$package['file'].' not found.');
		}
	}

	/**
	 * Creates complete archive.
	 *
	 * @param	string	$directory
	 * @param	string	$filename
	 */
	public function createArchive($directory, $filename, $removeAfter) {
		$buildDirectory = '';
		$directories = array('acptemplates', 'files', 'pip', 'templates');
		$directory = $this->source->sourceDirectory.$directory.'/';
		$location = '';

		// skip if no directory was given
		if (!is_dir($directory)) throw new SystemException('Given directory "'.$directory.'" is not valid.');

		// try to open directory
		$dir = DirectoryUtil::getInstance($directory, false);

		$buildDirectory = $this->source->buildDirectory.'/';
		$location = $buildDirectory.$filename;
		$package = new TarWriter($location, true);

		foreach($dir->getFiles() as $filename) {
			// skip directories
			if (in_array($filename, $this->excludeFiles)) continue;
			if ($this->ignoreDotFiles && substr($filename, 0,1) == '.') continue;
			// handle files
			if (!is_dir($directory.$filename)) {
				// add file
				$package->add($directory.$filename, '', $directory);
				continue;
			}

			// skip directories
			if (in_array($filename, $directories)) {
				// create tarball from special directories
				$archive = new TarWriter($buildDirectory.$filename.'.tar', false);
				$this->addFilesRecursive($archive, $directory, $filename, $filename.'/');
				$archive->create();

				// add previously created tarball
				$package->add($buildDirectory.$filename.'.tar', '', $buildDirectory);
			}
			else {
				// add sourceDirectory
				$this->addFilesRecursive($package, $directory, $filename);
			}
		}

		// create complete package
		$package->create();

		// cleanup, remove previous created tarballs
		DirectoryUtil::kill($this->source->buildDirectory);
		$dir = DirectoryUtil::getInstance($this->source->buildDirectory);
		$dir->removePattern('/.*\.tar$/');
		
		if($removeAfter) PackageHelper::registerTemporaryFile($location);
		return $location;
	}

	/**
	 * Add files and sourceDirectory recursive
	 *
	 * @param	object	$archive
	 * @param	string	$directory
	 * @param	string	$file
	 * @param	string	$removeDir
	 */
	public function addFilesRecursive(&$archive, $directory, $file, $removeDir = '') {
		// handle files
		if (!is_dir($directory.$file)) {
			// add file
			$archive->add($directory.$file, '', $directory.$removeDir);
			return;
		}

		// add trailing slash
		$file .= '/';

		// write directory header
		$directoryHeader = str_replace($removeDir, '', $file);
		if (!empty($directoryHeader)) {
			$status = $archive->writeHeaderBlock($directoryHeader, 0, filemtime($directory.$file), fileperms($directory.$file), '5');

			if ($status === false) {
				throw new SystemException('Unable to write header block for "'.$directory.$file.'".');
			}
		}
		$dir = DirectoryUtil::getInstance($directory.$file, false);
		// proceed with directory content
		foreach($dir->getFiles() as $filename) {
			if (!in_array($filename, $this->excludeFiles) && (!$this->ignoreDotFiles || substr($filename, 0,1) != '.')) {
				$this->addFilesRecursive($archive, $directory, $file.$filename, $removeDir);
			}
		}
	}

	/**
	 * Returns the archive location
	 *
	 * @return	string
	 */
	public function getArchiveLocation() {
		return $this->location;
	}
}
?>