<?php
// pb imports
require_once(PB_DIR.'lib/data/source/Source.class.php');

// wcf imports
require_once(WCF_DIR.'lib/data/DatabaseObjectList.class.php');

/**
 * Represents a list of sources.
 *
 * @author 	Tim Düsterhus
 * @copyright	2009-2010 WoltLab Community
 * @license	GNU Lesser General Public License <http://opensource.org/licenses/lgpl-license.php>
 * @package	com.woltlab.community.pb
 * @subpackage	data.source
 * @category 	PackageBuilder
 */
class SourceList extends DatabaseObjectList {
	/**
	 * list of sources
	 *
	 * @var array<Source>
	 */
	public $sources = array();

	/**
	 * sql order by statement
	 *
	 * @var	string
	 */
	public $sqlOrderBy = 'position ASC';
	
	/**
	 * @see DatabaseObjectList::countObjects()
	 */
	public function countObjects() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	pb".PB_N."_sources sources
			".(!empty($this->sqlConditions) ? "WHERE ".$this->sqlConditions : '');
		$row = WCF::getDB()->getFirstRow($sql);
		return $row['count'];
	}
	
	/**
	 * @see DatabaseObjectList::readObjects()
	 */
	public function readObjects() {
		$sql = "SELECT		".(!empty($this->sqlSelects) ? $this->sqlSelects.',' : '')."
					sources.*
			FROM		pb".PB_N."_sources sources
			".$this->sqlJoins."
			".(!empty($this->sqlConditions) ? "WHERE ".$this->sqlConditions : '')."
			".(!empty($this->sqlOrderBy) ? "ORDER BY ".$this->sqlOrderBy : '');
		$result = WCF::getDB()->sendQuery($sql, $this->sqlLimit, $this->sqlOffset);
		while ($row = WCF::getDB()->fetchArray($result)) {
			$this->sources[] = new Source(null, $row);
		}
	}
	
	/**
	 * @see DatabaseObjectList::getObjects()
	 */
	public function getObjects() {
		return $this->sources;
	}
}
?>