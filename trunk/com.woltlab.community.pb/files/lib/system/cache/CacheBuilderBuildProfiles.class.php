<?php
// wcf imports
require_once(WCF_DIR.'lib/system/cache/CacheBuilder.class.php');

/**
 * Caches build profiles.
 *
 * @author	Alexander Ebert
 * @copyright	2009-2011 WoltLab Community
 * @license	GNU Lesser General Public License <http://opensource.org/licenses/lgpl-license.php>
 * @package	com.woltlab.community.pb
 * @subpackage	cache
 * @category 	PackageBuilder
 */
class CacheBuilderBuildProfiles implements CacheBuilder {
	/**
	 * @see CacheBuilder::getData()
	 */
	public function getData($cacheResource) {
		$data = array();
		
		$sql = "SELECT	packageName, profileName, profileHash
			FROM	pb".PB_N."_build_profile";
		$result = WCF::getDB()->sendQuery($sql);
		while ($row = WCF::getDB()->fetchArray($result)) {
			if (!isset($data[$row['packageName']])) {
				$data[$row['packageName']] = array();
			}
			
			$data[$row['packageName']][] = $row;
		}
		
		return $data;
	}
}
?>