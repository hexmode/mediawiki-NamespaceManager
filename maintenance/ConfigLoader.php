<?php

/**
 * Maintenance script to dump out all the current settings for all
 * namespaces in json format.
 *
 * Copyright © 2017 NicheWork, LLC
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @author Mark A. Hershberger <mah@nichework.com>
 */

namespace NamespaceManager;

$IP = getenv( 'MW_INSTALL_PATH' ) !== false
	? getenv( 'MW_INSTALL_PATH' )
	: __DIR__ . '/../../..';
if ( !file_exists( $IP . '/maintenance/Maintenance.php' ) ) {
	die( "Please set the MW_INSTALL_PATH environment variable.\n" );
}
require_once $IP . '/maintenance/Maintenance.php';

use Maintenance;
use MWException;
use MWNamespace;
use stdClass;

/**
 * @ingroup Maintenance
 */
class ConfigLoader extends Maintenance {
	/**
	 * A constructor for ye
	 */
	public function __construct() {
		parent::__construct();
		$this->mDescription
			= "Read all the current settings that NameaspaceManager "
			. "will handle for all namespaces";

		$this->addOption(
			"json", "Output a json blob.", false, false, "j"
		);
		$this->addOption(
			"db", "Load the current configuration into the database",
			false, false
		);
		$this->addOption(
			"var", "Show the php config of a given variable.",
			false, true, "v"
		);
		$this->addOption(
			"user", "Get the user's groups.", false, true, "u"
		);
	}

	/**
	 * Where all the action starts
	 */
	public function execute() {
		if ( $this->getOption( "json" ) ) {
			$conf = $this->loadNSConfig();
			$this->output( json_encode( $conf, JSON_PRETTY_PRINT ) . "\n" );
			return;
		}

		if ( $this->getOption( "db" ) ) {
			$conf = $this->loadNSConfig();
			$this->importNSConfig( $conf );
			return;
		}

		$var = $this->getOption( "var" );
		if ( $var ) {
			$this->printVar( $var );
			return;
		}

		$var = $this->getOption( "user" );
		if ( $var ) {
			$user = \User::newFromID( $var );
			$this->printUserGroups( $user );
			return;
		}
		$this->maybeHelp( true );
	}

	/**
	 * Print out the value of a variable.
	 *
	 * @param string $var Name of variable to print
	 * @SuppressWarnings(PHPMD.EvalExpression)
	 */
	public function printVar( $var ) {
		$this->output(
			"\$$var = "
			. eval( "global \$$var; return var_export( \$$var, true);" ) . "\n"
		);
	}

	/**
	 * Set up the ns for this iteration
	 *
	 * @param string $name of namespace
	 * @param int $const for namespace
	 */
	public function setupNS( $name, $const ) {
		if ( $name === "" ) {
			$name = "Main";
		}

		$this->nsConf->$name = new stdClass( [] );
		$this->currentConf = $this->nsConf->$name;
		$this->currentConf->number = $const;
		$this->currentName = $name;
	}

	/**
	 * Dump what we've read from the current configuration into the DB.
	 *
	 * @param stdClass $json a json-style object
	 */
	public function importNSConfig( stdClass $json ) {
		foreach ( $json as $name => $conf ) {

			$const = null;
			if ( isset( $conf->number ) ) {
				$const = $conf->number;
				unset( $conf->number );
			}

			$owner = 'core';
			if ( isset( $conf->owner ) ) {
				$owner = $conf->owner;
				unset( $conf->owner );
			}

			$readOnly = false;
			if ( $const < 0 ) {
				$readOnly = true;
			}

			$dbw = wfGetDB( DB_MASTER );
			$dbw->insert( "namespace_mgr",
						  [
							  'ns_id' => $const,
							  'ns_name' => $name,
							  'ns_owner' => $owner,
							  'ns_read_only' => $readOnly,
							  'ns_config' => json_encode( $conf )
						  ] );
		}
	}

	protected function loadLockdown( $perm, $groups ) {
		if ( $perm === '*' && count( $groups ) === 1 ) {
			if (
				$this->nsConf->globalAdmin === null
				|| $this->nsConf->globalAdmin === $groups[0]
			) {
				$this->nsConf->globalAdmin = $groups[0];
				return;
			} else {
				throw new MWException(
					"New admin group ({$groups[0]}) doesn't match "
					. "previous one ({$this->nsConf->globalAdmin}) in "
					. $this->currentName . " namespace."
				);
			}
		} elseif ( $perm === '*' && count( $groups ) > 1 ) {
			throw new MWException(
				"Don't know how to handle multiple admins "
				. "for $this->currentName namespace."
			);
		}

		$nsGroup = $this->getLockdownNSGroup( $groups );

		if ( $perm !== '*' ) {
			$this->currentConf->lockdown[] = $perm;
			$this->currentConf->group = $nsGroup;
		}
	}

	public function getLockdownNSGroup( $groups ) {
		$nsConf = $this->nsConf;
		$group = array_filter(
			$groups, function ( $group ) use ( $nsConf ) {
				return $group !== $nsConf->globalAdmin;
			}
		);
		if ( count( $group ) > 1 ) {
			throw new MWException(
				"Found extra groups (" . implode( ", ", $group )
				. ") in '$this->currentName' namespace." );
		}
		if ( count( $group ) === 0 ) {
			$group = $this->nsConf->globalAdmin;
		} else {
			$group = $group[0];
		}

		return $group;
	}

	public function getPermMap() {
		global $wgGroupPermissions;

		$permMap = [];
		foreach ( $wgGroupPermissions as $group => $perms ) {
			foreach ( $perms as $perm => $hasIt ) {
				$permMap[$perm][$group] = $hasIt;
			}
		}
		return $permMap;
	}

	public function getNSConstants() {
		$allConst = get_defined_constants( true );
		$nsConst = array_flip( array_filter(
			$allConst['user'], function ( $key ) {
				return substr_compare( $key, "NS_", 0, 3 ) === 0;
			}, ARRAY_FILTER_USE_KEY
		) );

		return $nsConst;
	}

	public function maybeSetNSGroup() {
		$permMap = $this->getPermMap();

		if ( $this->currentConf->group !== null ) {
			return;
		}
		if (
			$this->currentConf->permission !== null
			&& isset( $permMap[$this->currentConf->permission] )
		) {
			$permGroup = array_filter(
				array_keys( array_filter(
					$permMap[$this->currentConf->permission]
				) ), function ( $group ) use ( $nsConf ) {
					return $group !== $this->nsConf->globalAdmin;
				}
			);
			if ( count( $permGroup ) > 1 ) {
				$this->error(
					"More than one group with " .
					$this->currentConf->permission . " permission."
				);
			}
			$this->currentConf->group = array_shift( $permGroup );
		}
	}

	public function maybeSetNSPermission() {
		global $wgNamespaceProtection;

		if ( isset( $wgNamespaceProtection[$this->currentConf->number] ) ) {
			if ( count(
				$wgNamespaceProtection[$this->currentConf->number]
			) === 1 ) {
				$this->currentConf->permission
					= $wgNamespaceProtection[$this->currentConf->number][0];
			} else {
				throw new MWException(
					"Can only handle one permission for now in "
					. "wgNamespaceProtection! Found"
					. count(
						$wgNamespaceProtection[$this->currentConf->number]
					) . " on {$this->currentName}."
				);
			}
		}
	}

	public function getAliases() {
		global $wgNamespaceAliases;

		$nsAlias = [];
		foreach ( $wgNamespaceAliases as $alias => $ns ) {
			$nsAlias[ $ns ][] = $alias;
		};
		return $nsAlias;
	}

	public function maybeSetAliases() {
		static $nsAlias = null;
		if ( $nsAlias === null ) {
			$nsAlias = $this->getAliases();
		}

		$this->currentConf->alias = [];
		if ( isset( $nsAlias[$this->currentConf->number] ) ) {
			$this->currentConf->alias = $nsAlias[$this->currentConf->number];
		}
	}
	public function maybeHasSubPages() {
		global $wgNamespacesWithSubpages;

		if ( isset(
			$wgNamespacesWithSubpages[ $this->currentConf->number ]
		) ) {
			$this->currentConf->hasSubpage
				= $wgNamespacesWithSubpages[ $this->currentConf->number ];
		}
	}

	public function maybeSetNSSearchWeight() {
		global $wgCirrusSearchNamespaceWeights;

		if ( isset( $wgCirrusSearchNamespaceWeights ) ) {
			$this->currentConf->searchWeight = null;
			$num = $this->currentConf->number;
			if ( isset( $wgCirrusSearchNamespaceWeights[$num] ) ) {
				$this->currentConf->searchWeight
					= $wgCirrusSearchNamespaceWeights[$num];
			}
		}
	}

	public function maybeSetPermissionLockdown() {
		global $wgNamespacePermissionLockdown;

		$this->currentConf->group = null;
		$this->currentConf->lockdown = false;
		if ( isset(
			$wgNamespacePermissionLockdown[$this->currentConf->number]
		) ) {
			foreach (
				$wgNamespacePermissionLockdown[$this->currentConf->number]
				as $perm => $groups
			) {
				$this->loadLockdown( $perm, $groups );
			}
		}
	}

	public function maybeSetCollection() {
		global $wgCollectionArticleNamespaces;

		if ( !is_array( $wgCollectionArticleNamespaces ) ) {
			return;
		}
		$nsCollection = array_flip( $wgCollectionArticleNamespaces );

		$this->currentConf->useCollection
			= isset( $nsCollection[$this->currentConf->number] )
			? true
			: false;
	}

	public function maybeAddToDefaultSearch() {
		global $wgNamespacesToBeSearchedDefault;

		$num = $this->currentConf->number;
		if ( isset( $wgNamespacesToBeSearchedDefault[$num] ) ) {
			$this->currentConf->defaultSearch
				= $wgNamespacesToBeSearchedDefault[$num];
		}
	}

	public function maybeUseApprovedRevs() {
		global $egApprovedRevsNamespaces;

		$num = $this->curentConf->number;
		if ( isset( $egApprovedRevsNamespaces[$num] ) ) {
			$this->currentConf->useApprovedRevs = true;
		}
	}

	public function maybeUseVE() {
		global $wgVisualEditorAvailableNamespaces;

		$num = $this->currentConf->number;
		if ( isset( $wgVisualEditorAvailableNamespaces[$num] ) ) {
			$this->currentConf->useVE
				= $wgVisualEditorAvailableNamespaces[$num];
		}
	}

	public function maybeSetConst() {
		static $nsConst;
		if ( !$nsConst ) {
			$nsConst = $this->getNSConstants();
		}

		if ( isset( $nsConst[$this->currentConf->number] ) ) {
			$this->currentConf->const = $nsConst[$this->currentConf->number];
		}
	}

	public function maybeUseSMW() {
		global $smwgNamespacesWithSemanticLinks;

		if ( isset(
			$smwgNamespacesWithSemanticLinks[$this->currentConf->number]
		) ) {
			$this->currentConf->useSMW
				= $smwgNamespacesWithSemanticLinks[$this->currentConf->number];
		}
	}

	public function maybeUseContentModel() {
		global $wgNamespaceContentModels;
		$num = $this->currentConf->number;
		if ( isset( $wgNamespaceContentModels[$num] ) ) {
			$this->currentConf->useContentModel = $wgNamespaceContentModels[$num];
		}
	}

	public function maybeUseFlowForTalk() {
		global $wgNamespaceContentModels;

		$talkConst = $this->currentConf->number + 1;
		if ( isset( $wgNamespaceContentModels[$talkConst] )
			 && $wgNamespaceContentModels[$talkConst] == 'flow-board' ) {
			$this->currentConf->useFlowForTalk = true;
		}
	}

	public function maybeUsePageTriage() {
		global $wgPageTriageNamespaces;

		$triageNS = array_flip( $wgPageTriageNamespaces );
		$num = $this->currentConf->number;
		if ( isset( $triageNS[$num] ) ) {
			$this->currentConf->usePageTriage = true;
		}
	}

	public function loadNSConfig() {
		// The following are not currently used.  Use them?
		global $wgNamespaceHideFromRC;
		global $wgNonincludableNamespaces;

		$this->nsConf = new stdClass();
		$ignoreNamespaces = [
			'Form', 'Gadget', 'Gadget_definition', 'Concept', 'Campaign',
			'Property', 'Widget'
		];

		foreach (
			MWNamespace::getCanonicalNamespaces( true ) as $const => $name
		) {
			$this->setupNS( $name, $const );

			$this->maybeSetAliases();
			$this->maybeUseFlowForTalk();
			$this->maybeSetConst();
			$this->maybeSetNSGroup();
			$this->maybeHasSubPages();
			$this->maybeSetPermissionLockdown();
			$this->maybeSetNSPermission();
			$this->maybeSetCollection();
			$this->maybeUseVE();
			$this->maybeUseSMW();
			$this->maybeAddToDefaultSearch();
			$this->maybeSetNSSearchWeight();
			$this->maybeUsePageTriage();
			$this->maybeUseContentModel();
			// skip talk namespaces and Media, Special and special
			// ignorable ones.
			if (
				$const % 2 !== 0 || $const < 0
				|| in_array( $name, $ignoreNamespaces )
			) {
				continue;
			}

			$this->maybeUseApprovedRevs();
		}

		return $this->nsConf;
	}
}

$maintClass = "NamespaceManager\\ConfigLoader";
require_once RUN_MAINTENANCE_IF_MAIN;
