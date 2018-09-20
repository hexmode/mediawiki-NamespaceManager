<?php

/**
 * Maintenance script to dump out all the current settings for all
 * namespaces in json format.
 *
 * Copyright (C) 2017  NicheWork, LLC
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

$basePath = getenv( 'MW_INSTALL_PATH' ) !== false
		  ? getenv( 'MW_INSTALL_PATH' )
		  : __DIR__ . '/../../..';
require_once $basePath . '/maintenance/Maintenance.php';

use Maintenance;
use MWException;
use MWNamespace;
use stdClass;

/**
 * @ingroup Maintenance
 */
class ConfigDumper extends Maintenance {
	protected $nsConf;
	protected $permMap;
	protected $adminGroup;
	protected $ignoreNS = [
		'Form', 'Gadget', 'Gadget_definition', 'Concept', 'Campaign', 'Property', 'Widget'
	];

	/**
	 * A constructor for ye
	 */
	public function __construct() {
		parent::__construct();
		$this->mDescription = "Read all the current settings that NameaspaceManager " .
							"will handle for all namespaces";

		$this->addOption( "json", "Output a json file that could be read in.", false, false, "j" );
		$this->addOption( "var", "Show the php config of a given variable.", false, true, "v" );
		$this->addOption( "user", "Get the user's groups.", false, true, "u" );
		$this->addOption( "ignore", "Comma-separated list of namespaces to ignore",
						  false, true, "i" );
		$this->nsConf = new stdClass();
	}

	public function setIgnoredNamespaces( $ignoreNS ) {
		$this->ignoreNS = array_merge( $this->ignoreNS, preg_split( "/[, ]+/", $ignoreNS ) );
	}

	public function execute() {
		if ( $this->getOption( "ignore" ) ) {
			$this->setIgnoredNamespaces( $this->getOption( "ignore" ) );
		}
		if ( $this->getOption( "json" ) ) {
			$this->loadNSConfig();
			$this->cleanDefaults();
			$this->output( json_encode( $this->nsConf, JSON_PRETTY_PRINT ) . "\n" );
			return;
		}
		if ( $var = $this->getOption( "var" ) ) {
			$this->output( "\$$var = " .
						   eval( "global \$$var; return var_export( \$$var, true);" )
						   . "\n" );
			return;
		}
		if ( $var = $this->getOption( "user" ) ) {
			$user = \User::newFromID($var);
			$this->output( serialize( \User::getAllGroups() ) . "\n" );
			return;
		}
		$this->maybeHelp( true );
	}

	public function cleanDefaults() {
		$globalAdmin = $this->nsConf->globalAdmin;
		foreach ( $this->nsConf as $ns ) {
			// if ( isset( $ns->alias ) && is_array( $ns->alias ) && count( $ns->alias ) === 0 ) {
			//     unset( $ns->alias );
			// }
			if ( !is_object( $ns ) ) {
				continue;
			}
			if ( !isset( $ns->alias ) ) {
				$ns->alias = [];
			}
			if ( !isset( $ns->group ) ) {
				$ns->group = null;
			}
			if ( is_array( $ns->group ) && in_array( $globalAdmin, $ns->group ) ) {
				// Without array_values this will keep the keys which will end up out of order
				// so that when we print it, the json output function will be confused
				// Oh, and right now we only want one.
				$ns->group = array_shift( array_values( array_diff( $ns->group, [ $globalAdmin ] ) ) );
			}
			if ( !isset( $ns->includable ) ) {
				$ns->includable = true;
			}
			if ( !isset( $ns->lockdown ) ) {
				$ns->lockdown = false;
			}
			if ( !isset( $ns->permission ) ) {
				$ns->permission = null;
			}
		}
	}

	public function loadNSConfig() {
		global $wgCollectionArticleNamespaces;
		global $wgContentNamespaces;
		global $wgExtraNamespaces;
		global $wgGroupPermissions;
		global $wgNamespaceAliases;
		global $wgNamespaceContentModels;
		global $wgNamespaceHideFromRC;
		global $wgNamespacePermissionLockdown;
		global $wgNamespaceProtection;
		global $wgNamespacesToBeSearchedDefault;
		global $smwgNamespacesWithSemanticLinks;
		global $wgNamespacesWithSubpages;
		global $wgNonincludableNamespaces;
		global $wgVisualEditorAvailableNamespaces;
		global $wgCirrusSearchNamespaceWeights;
		global $egApprovedRevsNamespaces;

		$this->adminGroup = null;

		$allConst = get_defined_constants(true);
		$nsConst = array_flip(
			array_filter( $allConst['user'],
						  function( $k ) {
							  return substr_compare( $k, "NS_", 0, 3 ) === 0;
						  }, ARRAY_FILTER_USE_KEY ) );

		$nsAlias = [];
		array_map(
			function ($alias) use ( &$nsAlias, $wgNamespaceAliases ) {
				$nsAlias[$wgNamespaceAliases[$alias]][] = $alias;
			}, array_keys( $wgNamespaceAliases ) );

		$nsCollection = array_flip( $wgCollectionArticleNamespaces );

		$permMap = [];
		array_map(
			function ($group) use ( &$permMap, $wgGroupPermissions ) {
				foreach ( $wgGroupPermissions[$group] as $perm => $hasIt ) {
					$permMap[$perm][$group] = $hasIt;
				}
			}, array_keys( $wgGroupPermissions ) );
		$this->permMap = $permMap;

		foreach ( MWNamespace::getCanonicalNamespaces( true ) as $const => $name ) {
			if ( $name === "" ) {
				$name = "Main";
			}
			// skip talk namespaces and Media, Special and special ignorable ones.
			if ( $const % 2 !== 0 || $const < 0 || in_array( $name, $this->ignoreNS ) ) {
				continue;
			}

			$talkConst = $const + 1;
			$this->nsConf->$name = new stdClass();
			$this->nsConf->$name->number = $const;

			$this->nsConf->$name->useCollection = false;
			if ( isset( $nsCollection[$const] ) ) {
				$this->nsConf->$name->useCollection = true;
			}

			if ( isset( $egApprovedRevsNamespaces[$const] ) ) {
				$this->nsConf->$name->useApprovedRevs = true;
			}

			if ( isset( $nsConst[$const] ) ) {
				$this->nsConf->$name->const = $nsConst[$const];
			}

			if ( isset( $wgVisualEditorAvailableNamespaces[$const] ) ) {
				$this->nsConf->$name->useVE = $wgVisualEditorAvailableNamespaces[$const];
			}

			if ( isset( $smwgNamespacesWithSemanticLinks[$const] ) ) {
				$this->nsConf->$name->useSMW = $smwgNamespacesWithSemanticLinks[$const];
			}

			if ( isset( $conf->useSMW ) && $conf->useSMW ) {
				$wgCollectionArticleNamespaces[] = $const;
			}

			if ( isset( $wgNamespaceContentModels[$talkConst] )
				 && $wgNamespaceContentModels[$talkConst] == 'flow-board' ) {
				$this->nsConf->$name->useFlowForTalk = true;
			}

			if ( isset( $wgNamespacesToBeSearchedDefault[$const] ) ) {
				$this->nsConf->$name->defaultSearch = $wgNamespacesToBeSearchedDefault[$const];
			}

			if ( isset( $wgNamespacePermissionLockdown[$const] ) ) {
				foreach ( $wgNamespacePermissionLockdown[$const] as $perm => $groups ) {
					if ( $perm === '*' && count( $groups ) === 1 ) {
						if (
							!isset( $this->adminGroup ) || !$this->adminGroup
							|| $this->adminGroup === $groups[0]
						) {
							$this->nsConf->globalAdmin = $this->adminGroup = $groups[0];
						} else {
							throw new MWException( "New admin group ({$groups[0]}) " .
												   "doesn't match previous one " .
												   "($this->adminGroup) in $name namespace." );
						}
					} else if ( $perm === '*' && count( $groups ) > 1 ) {
						throw new MWException( "Don't know how to handle multiple admins " .
											   "for $name namespace." );
					}

					$adminGroup = $this->adminGroup;
					$group = array_filter( $groups,
										   function( $gg ) use ( $adminGroup ) {
											   return $gg !== $adminGroup;
										   } );
					if ( count( $group ) > 1 ) {
						throw new MWException( "Found extra groups (" .
											   implode( ", ", $group ) . ") in $name " .
											   "namespace." );
					}

					if ( count( $group ) === 0 ) {
						$group = $this->adminGroup;
					} else {
						$group = $group[0];
					}

					if ( $perm !== '*' ) {
						$this->nsConf->$name->lockdown[] = $perm;
						$this->nsConf->$name->group = $group;
					}
				}
			}

			if ( isset( $wgNamespaceProtection[$const] ) ) {
				if ( count( $wgNamespaceProtection[$const] ) === 1 ) {
					$this->nsConf->$name->permission = $wgNamespaceProtection[$const][0];
					if ( !isset( $this->nsConf->$name->group ) ) {
						$this->nsConf->$name->group = $this->getNonAdminWithPermission(
							$name, $this->nsConf->$name->permission
						);
					}
				} else {
					throw new MWException( "Can only handle one permission for now in ".
										   "wgNamespaceProtection! Found " .
										   count( $wgNamespaceProtection[$const] ) .
										   " on $name." );
				}
			}

			$this->nsConf->$name->alias = [];
			if ( isset( $nsAlias[$const] ) ) {
				$this->nsConf->$name->alias = $nsAlias[$const];
			}

			if ( isset( $wgNamespacesWithSubpages[ $const ] ) ) {
				$this->nsConf->$name->hasSubpage = $wgNamespacesWithSubpages[ $const ];
			}

			if (
				isset( $this->nsConf->$name->group ) && $this->nsConf->$name->group === null
				&& $this->nsConf->$name->permission !== null
				&& isset( $this->permMap[$this->nsConf->$name->permission] )
			) {
				$permGroup = $this->getNonAdminWithPermission(
					$name, $this->nsConf->$name->permission
				);
				if( count( $permGroup ) > 1 ) {
					$this->error(
						"There is more than one group with the '" . $this->nsConf->$name->permission
						. "' permission: " . implode( ", ", $permGroup )
					);
				}
				$this->nsConf->$name->group = array_shift( $permGroup );
			}

			if ( isset( $wgCirrusSearchNamespaceWeights ) ) {
				if ( isset( $wgCirrusSearchNamespaceWeights[$const] ) ) {
					$this->nsConf->$name->searchWeight
						= $wgCirrusSearchNamespaceWeights[$const];
				}
			}

			if ( isset( $wgNonincludableNamespaces ) ) {
				if ( in_array( $const, $wgNonincludableNamespaces ) ) {
					$this->nsConf->$name->includable = false;
				}
			}

			global $wgPageTriageNamespaces;
			if ( isset( $wgPageTriageNamespaces ) ) {
				if ( in_array( $const, $wgPageTriageNamespaces ) ) {
					$this->nsConf->$name->usePageTriage = true;
				}
			}

			if ( isset( $wgContentNamespaces ) ) {
				if ( in_array( $const, $wgContentNamespaces ) ) {
					$this->nsConf->$name->content = true;
				}
			}
		}
	}

	private function getNonAdminWithPermission( $name, $permission ) {
		$permMap = $this->permMap;
		$adminGroup = $this->adminGroup;

		if ( isset( $permMap[$permission] ) ) {
			if ( count( $permMap[$permission] ) > 1 && !$adminGroup ) {
				$this->adminGroup = $this->nsConf->globalAdmin = $adminGroup
								  = array_shift( $permMap[$permission] );
			}

			return array_filter(
				array_keys( array_filter( $permMap[$permission] ) ),
				function( $group ) use ( $adminGroup ) {
					return $group !== $adminGroup;
				} );
		}
	}
}

$maintClass = "NamespaceManager\\ConfigDumper";
require_once RUN_MAINTENANCE_IF_MAIN;
