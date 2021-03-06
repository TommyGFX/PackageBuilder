<?php
// pb imports
require_once(PB_DIR.'lib/data/source/Source.class.php');
require_once(PB_DIR.'lib/system/package/PackageReader.class.php');

/**
 * Providing methods for packages.
 *
 * @author	Tim Düsterhus, Alexander Ebert
 * @copyright	2009-2010 WoltLab Community
 * @license	GNU Lesser General Public License <http://opensource.org/licenses/lgpl-license.php>
 * @package	com.woltlab.community.pb
 * @subpackage	system
 * @category 	PackageBuilder
 */
abstract class PackageHelper {
	/**
	 * Holds a list of already built packages with their location
	 *
	 * @var	array
	 */
	public static $builtPackages = array();

	/**
	 * Stores already built packages from cache
	 *
	 * @var	array
	 */
	public static $cachedPackages = array();
	
	/**
	 * Stores source-wide package cache
	 * 
	 * @var	array
	 */
	public static $packageCache = null;
	
	/**
	 * Stores package pre-selection used by build profiles
	 * 
	 * @var	array
	 */
	public static $packageSelection = array();

	/**
	 * User defined package resources
	 *
	 * @param	array<array>
	 */
	public static $packageResources = array();

	/**
	 * Holds package data
	 *
	 * @var	array
	 */
	public static $packages = array();

	/**
	 * Source instance
	 *
	 * @var	object
	 */
	public static $source = null;
	
	/**
	 * list of accessible sources
	 * 
	 * @var	array<Source>
	 */
	public static $sources = array();

	/**
	 * Stores temporary created files
	 *
	 * @var	array
	 */
	public static $temporaryFiles = array();

	/**
	 * Reads a source for available packages
	 *
	 * @param	mixed	$source
	 */
	public static function readPackages($source) {
		// load source
		self::$source = ($source instanceof Source) ? $source : new Source($source);

		// verify directory
		if (!is_dir(self::$source->sourceDirectory)) {
			throw new SystemException('Source directory for sourceID '.self::$source->sourceID.' is invalid.');
		}
		
		// clear existing WCFSetup resources
		$sql = "DELETE FROM	pb".PB_N."_setup_resource
			WHERE		sourceID = ".self::$source->sourceID;
		WCF::getDB()->sendQuery($sql);
		
		// read available packages
		self::readDirectories(self::$source->sourceDirectory);

		// break if no packages are available
		if (empty(self::$packages)) {
			$sql = "DELETE FROM	pb".PB_N."_source_package
				WHERE		sourceID = ".self::$source->sourceID;
			WCF::getDB()->sendQuery($sql);

			// clear cache
			self::registerCache();

			WCF::getCache()->clearResource('packages-'.self::$source->sourceID);
			WCF::getCache()->clear(PB_DIR.'cache/', 'cache.packages-'.self::$source->sourceID.'.php');
			return;
		}

		$hashes = array();
		$referencedPackages = array();
		$sql = '';

		// update data for available packages
		foreach (self::$packages as $directory => $data) {
			if (!empty($sql)) $sql .= ',';

			$hash = self::getHash(self::$source->sourceID, $data['name'], $directory);
			$sql .= "(".self::$source->sourceID.",
				'".$hash."',
				'".escapeString($data['name'])."',
				'".escapeString($data['version'])."',
				'".escapeString($directory)."',
				'".escapeString($data['packageType'])."')";
			
			// set all required or optional packages
			$referencedPackages[$hash] = self::getReferencedPackages($data);

			// register hash
			$hashes[] = "'".$hash."'";
		}

		if (!empty($sql)) {
			// build complete sql query
			$sql = "INSERT INTO		pb".PB_N."_source_package
							(sourceID, hash, packageName, version, directory, packageType)
				VALUES			".$sql."
				ON DUPLICATE KEY UPDATE	version = VALUES(version),
							packageType = VALUES(packageType)";
			// update data
			WCF::getDB()->sendQuery($sql);

			// remove removed packages
			$sql = "DELETE FROM	pb".PB_N."_source_package
				WHERE		hash NOT IN (".implode(',', $hashes).")
				AND		sourceID = ".self::$source->sourceID;
			WCF::getDB()->sendQuery($sql);

			// remove data for each hash
			$sql = "DELETE FROM	pb".PB_N."_referenced_package
				WHERE		hash IN (".implode(',', $hashes).")
				AND		sourceID = ".self::$source->sourceID;
			WCF::getDB()->sendQuery($sql);
		}

		// insert referenced packages
		$sql = '';

		foreach ($referencedPackages as $hash => $packages) {
			foreach ($packages as $packageName => $packageData) {
				if (!empty($sql)) $sql .= ',';
				$sql .= "(".self::$source->sourceID.",
					'".$hash."',
					'".escapeString($packageName)."',
					'".escapeString($packageData['minversion'])."',
					'".escapeString($packageData['file'])."')";
			}
		}

		if (!empty($sql)) {
			$sql = "INSERT INTO	pb".PB_N."_referenced_package
						(sourceID, hash, packageName, minVersion, file)
				VALUES		".$sql;
			WCF::getDB()->sendQuery($sql);
		}

		// clear cache
		self::registerCache();

		WCF::getCache()->clearResource('packages-'.self::$source->sourceID);
		WCF::getCache()->clear(PB_DIR.'cache/', 'cache.packages-'.self::$source->sourceID.'.php');

		WCF::getCache()->clearResource('package-dependency-'.self::$source->sourceID);
		WCF::getCache()->clear(PB_DIR.'cache/', 'cache.package-dependency-'.self::$source->sourceID.'.php');
		
		WCF::getCache()->clearResource('wcfsetup-resources');
		WCF::getCache()->clear(PB_DIR . 'cache/', 'cache.wcfsetup-resources.php');
	}

	/**
	 * Calculates hash for a given package
	 *
	 * @param	integer	$sourceID
	 * @param	string	$packageName
	 * @param	string	$directory
	 * @return	string
	 */
	public static function getHash($sourceID, $packageName, $directory) {
		return StringUtil::getHash($sourceID.':'.$packageName.':'.$directory);
	}

	/**
	 * Returns a list of all referenced packages and their minversion
	 *
	 * @param	array	$data
	 * @return	array<array>
	 */
	protected static function getReferencedPackages(array $data) {
		$packages = array();
		$tmp = array();

		// handle required packages
		if (array_key_exists('requiredpackage', $data)) $tmp = $data['requiredpackage'];

		// handle optional packages
		if (array_key_exists('optionalpackage', $data)) {
			$tmp = (empty($tmp)) ? $data['optionalpackage'] : array_merge($tmp, $data['optionalpackage']);
		}

		foreach ($tmp as $packageName => $packageData) {
			$packages[$packageName] = $packageData;
		}

		return $packages;
	}

	/**
	 * Searching inside directory and sub directories for package.xml
	 *
	 * @param	integer		$maxDimension
	 */
	protected static function readDirectories($directory, $maxDimension = 3) {
		// scan current dir for package.xml
		if (file_exists($directory.'package.xml')) {
			$relativeDirectory = str_replace(self::$source->sourceDirectory, '', $directory);
			$pr = new PackageReader(self::$source, $relativeDirectory);
			$packageData = $pr->getPackageData();
			
			// remove optional or required packages shipped within the package
			// source, preventing dependency issues if using build profiles
			foreach ($packageData as $type => $data) {
				if ($type == 'optionalpackage' || $type == 'requiredpackage') {
					if (!is_array($data)) continue;
					
					foreach ($data as $packageName => $packageAttributes) {
						if (empty($packageAttributes['file'])) continue;
						
						$path = $directory . $packageAttributes['file'];
						if (file_exists($path)) {
							unset($packageData[$type][$packageName]);
						}
					}
				}
			}
			
			self::$packages[$relativeDirectory] = $packageData;
		}
		else {
			// look for WCFSetup resources
			$pathinfo = pathinfo(FileUtil::getRealPath($directory));
			$path = explode('/', $pathinfo['basename']);
			
			if (array_pop($path) == 'wcfsetup') {
				self::addWcfResource($directory);
			}
			else if ($maxDimension) {
				if (is_dir($directory)) {
					if ($dh = opendir($directory)) {
						$maxDimension--;
	
						while (($file = readdir($dh)) !== false) {
							if (!in_array($file, array('.', '..', '.svn'))) {
								self::readDirectories($directory.$file.'/', $maxDimension);
							}
						}
	
						closedir($dh);
					}
				}
			}
		}
	}
	
	/**
	 * Adds WCFSetup resources.
	 * 
	 * @param	string		$directory
	 */
	protected static function addWcfResource($directory) {
		$directory = FileUtil::addTrailingSlash($directory);
		$resources = array();
		
		// try to look for child directories, as we may have
		// encountered a tag or branch
		if (is_dir($directory . 'install') && is_dir($directory . 'setup')) {
			$resources[] = $directory;
		}
		else {
			$it = new DirectoryIterator($directory);
			foreach ($it as $obj) {
				if ($obj->isDot() || !$obj->isDir()) continue;
				
				$subDirectory = $directory . FileUtil::addTrailingSlash($obj->getFilename());
				if (is_dir($subDirectory . 'install') && is_dir($subDirectory) . 'setup') {
					$resources[] = $subDirectory;
				}
			}
		}
		
		$insertSQL = '';
		foreach ($resources as $resource) {
			if (!empty($insertSQL)) $insertSQL .= ',';
			$insertSQL .= "(".self::$source->sourceID.", '".escapeString($resource)."')";
		}
		
		// insert resource location
		if (!empty($insertSQL)) {
			$sql = "INSERT INTO	pb".PB_N."_setup_resource
						(sourceID, directory)
				VALUES		".$insertSQL;
			WCF::getDB()->sendQuery($sql);
		}
	}

	/**
	 * Assigns a new package
	 *
	 * @param	string	$packageName
	 * @param	string	$location
	 * @return	void
	 */
	public static function addPackageData($packageName, $location) {
		self::$builtPackages[$packageName] = $location;
	}

	/**
	 * Searches for already added packages and returns its location if possible
	 *
	 * @param	string	$packageName
	 * @return	mixed
	 */
	public static function searchPackage($packageName) {
		if (isset(self::$builtPackages[$packageName])) {
			return self::$builtPackages[$packageName];
		}

		return null;
	}

	/**
	 * Searches for an already built package using cache
	 *
	 * @param	integer	$sourceID	Source
	 * @param	string	$name		Packagename
	 * @param	string	$minVersion	Minimum required version
	 */
	public static function searchCachedPackage($sourceID, $name, $minVersion = null) {
		$highestVersion = $minVersion;
		$location = null;
		
		// try to find package within preselection
		if (isset(self::$packageSelection[$name])) {
			return self::resolveDirectory($sourceID, $name, self::$packageSelection[$name]);
		}
		
		// read cache on first request
		if (!isset(self::$cachedPackages[$sourceID])) self::getCachedPackages($sourceID);
		
		// user choosen directory resource overrides everything
  		$location = self::getPackageResource($name);
  		if ($location !== null) return $location;
		
		// search for package name
		foreach (self::$cachedPackages[$sourceID] as $package) {
			// break if package name does not match
			if ($package['packageName'] != $name) continue;

			// set default values
			if (empty($highestVersion)) {
				$highestVersion = $package['version'];
				$location = $package['directory'];

				continue;
			}

			if (version_compare($highestVersion, $package['version']) < 1) {
				$highestVersion = $package['version'];
				$location = $package['directory'];
			}
		}

		// return latest version path
		return $location;
	}

	/**
	 * Reads cache for already built packages per source
	 *
	 * @param	integer	$sourceID	Source
	 */
	protected static function getCachedPackages($sourceID) {
		// read cache
		self::registerCache($sourceID);

		self::$cachedPackages[$sourceID] = WCF::getCache()->get('packages-'.$sourceID);
	}

	/**
	 * Registers cache resource
	 */
	protected static function registerCache($sourceID = null) {
		if ($sourceID === null) $sourceID = self::$source->sourceID;

		WCF::getCache()->addResource(
			'packages-'.$sourceID,
			PB_DIR.'cache/cache.packages-'.$sourceID.'.php',
			PB_DIR.'lib/system/cache/CacheBuilderPackages.class.php'
		);

		WCF::getCache()->addResource(
			'package-dependency-'.$sourceID,
			PB_DIR.'cache/cache.package-dependency-'.$sourceID.'.php',
			PB_DIR.'lib/system/cache/CacheBuilderPackageDependency.class.php'
		);
		
		WCF::getCache()->addResource(
			'wcfsetup-resources',
			PB_DIR.'cache/cache.wcfsetup-resources.php',
			PB_DIR.'lib/system/cache/CacheBuilderWcfSetupResources.class.php'
		);
	}

	/**
	 * Register a temporary file to remove after build process
	 *
	 * @param	string	$file
	 */
	public static function registerTemporaryFile($file) {
		if (!in_array($file, self::$temporaryFiles)) {
			self::$temporaryFiles[] = $file;
		}
	}

	/**
	 * Removes previously created packages
	 */
	public static function clearTemporaryFiles() {
		// remove files
		foreach (self::$temporaryFiles as $file) {
			@unlink($file);
		}

		// clear cache
		self::$temporaryFiles = array();
	}
	
	/**
	 * Returns true if given file is temporarily created and will be deleted afterwards.
	 * 
	 * @param	string		$filename
	 * @return	boolean
	 */
	public static function isTemporaryFile($filename) {
		return in_array($filename, self::$temporaryFiles);
	}

	/**
	 * Build filenames on a given pattern
	 *
	 * @param	string	$pattern
	 * @param	array	$data
	 * @return	string
	 */
	public static function getArchiveName($pattern, $data) {
		$name = '';
		$pattern = explode('_', $pattern);

		foreach ($pattern as $part) {
			if (isset($data[$part])) {
				// append seperator
				if (!empty($name)) $name .= '_';

				$name .= str_replace(' ', '_', $data[$part]);
			}
		}

		return $name.'.tar.gz';
	}

	/**
	 * Registers package resources
	 *
	 * @param	array	$packageResources
	 */
	public static function registerPackageResources($packageResources) {
		if (is_array($packageResources) && !empty($packageResources)) {
			self::$packageResources = $packageResources;
		}
	}

	/**
	 * Returns a directory resource or null if package name is unknown
	 *
	 * @param	string	$packageName
	 * @return	string
	 */
	public static function getPackageResource($packageName) {
		if (!array_key_exists($packageName, self::$packageResources)) return null;

		return self::$packageResources[$packageName]['directory'];
	}
	
	/**
	 * Registers a package selection used with build profiles.
	 * 
	 * @param	array	$packageSelection
	 */
	public static function registerPackageSelection(array $packageSelection) {
		self::$packageSelection = $packageSelection;
	}
	
	protected static function resolveDirectory($sourceID, $packageName, $hash) {
		if (self::$packageCache === null) {
			self::readPackageCache();
		}
		
		if (!isset(self::$packageCache['hashes'][$packageName])) {
			die('x');
			throw new SystemException("Unable to find package data for package '".$packageName."', removed or insufficient permissions.");
		}
		
		if (!in_array($hash, self::$packageCache['hashes'][$packageName])) {
			die('y');
			throw new SystemException("Package '".$packageName."' identified by '".$hash."' not found, removed or insufficient permissions.");
		}
		
		return self::getRelativePath($sourceID, self::$packageCache['packages'][$hash]['sourceID'], self::$packageCache['packages'][$hash]['directory']);
	}
	
	protected static function readPackageCache() {
		self::$packageCache = array(
			'hashes' => array(),
			'packages' => array()
		);
		
		// read accessible sources
		require_once(PB_DIR.'lib/data/source/SourceList.class.php');
		$sourceList = new SourceList();
		$sourceList->sqlLimit = 0;
		$sourceList->hasAccessCheck = true;
		$sourceList->readObjects();
		$sources = $sourceList->getObjects();
		
		foreach ($sources as $source) {
			self::$sources[$source->sourceID] = $source;
		}
		
		foreach (WCF::getUser()->getAccessibleSourceIDs() as $sourceID) {
			$cacheName = 'packages-' . $sourceID;
			WCF::getCache()->addResource(
				$cacheName,
				PB_DIR . 'cache/cache.' . $cacheName . '.php',
				PB_DIR . 'lib/system/cache/CacheBuilderPackages.class.php'
			);
			
			$cache = WCF::getCache()->get($cacheName);
			
			self::$packageCache['hashes'] = array_merge(self::$packageCache['hashes'], $cache['hashes']);
			self::$packageCache['packages'] = array_merge(self::$packageCache['packages'], $cache['packages']);
		}
	}
	
	protected static function getRelativePath($baseSourceID, $targetSourceID, $path) {
		$basePath = self::$sources[$baseSourceID]->sourceDirectory;
		$targetPath = FileUtil::getRealPath(FileUtil::addTrailingSlash(self::$sources[$targetSourceID]->sourceDirectory) . $path);
		$relativePath = FileUtil::getRelativePath($basePath, $targetPath);
		
		return FileUtil::getRealPath($relativePath);
	}
}
?>