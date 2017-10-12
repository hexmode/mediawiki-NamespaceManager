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

	/**
	 * A constructor for ye
	 */
	public function __construct() {
		parent::__construct();
		$this->mDescription = "Read all the current settings that NameaspaceManager " .
							"will handle for all namespaces";

		$this->addOption( "json", "Output a json file that could be read in.",
						  false, false, "j" );
		$this->addOption( "var", "Show the php config of a given variable.",
						  false, true, "v" );
		$this->addOption( "user", "Get the user's groups.",
						  false, true, "u" );
		$this->nsConf = new stdClass();
	}

	public function execute() {
		if ( $this->getOption( "json" ) ) {
			$this->loadNSConfig();
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

		$ignoreNamespaces = [ 'Form', 'Gadget', 'Gadget_definition', 'Concept',
							  'Campaign', 'Property', 'Widget' ];

		$adminGroup = null;

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

		foreach ( MWNamespace::getCanonicalNamespaces( true ) as $const => $name ) {
			if ( $name === "" ) {
				$name = "Main";
			}
			// skip talk namespaces and Media, Special and special ignorable ones.
			if ( $const % 2 !== 0 || $const < 0 || in_array( $name, $ignoreNamespaces ) ) {
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

			$this->nsConf->$name->permission = null;
			if ( isset( $wgNamespaceProtection[$const] ) ) {
				if ( count( $wgNamespaceProtection[$const] ) === 1 ) {
					$this->nsConf->$name->permission = $wgNamespaceProtection[$const][0];
				} else {
					throw new MWException( "Can only handle one permission for now in ".
										   "wgNamespaceProtection! Found " .
										   count( $wgNamespaceProtection[$const] ) .
										   " on $name." );
				}
			}

			$this->nsConf->$name->group = null;
			$this->nsConf->$name->lockdown = false;
			if ( isset( $wgNamespacePermissionLockdown[$const] ) ) {
				foreach ( $wgNamespacePermissionLockdown[$const] as $perm => $groups ) {
					if ( $perm === '*' && count( $groups ) === 1 ) {
						if ( $adminGroup === null || $adminGroup === $groups[0] ) {
							$adminGroup = $groups[0];
							$this->nsConf->globalAdmin = $adminGroup;
							continue;
						} else {
							throw new MWException( "New admin group ({$groups[0]}) " .
												   "doesn't match previous one " .
												   "($adminGroup) in $name namespace." );
						}
					} else if ( $perm === '*' && count( $groups ) > 1 ) {
						throw new MWException( "Don't know how to handle multiple admins " .
											   "for $name namespace." );
					}

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
						$group = $adminGroup;
					} else {
						$group = $group[0];
					}

					if ( $perm !== '*' ) {
						$this->nsConf->$name->lockdown[] = $perm;
						$this->nsConf->$name->group = $group;
					}
				}
			}

			$this->nsConf->$name->alias = [];
			if ( isset( $nsAlias[$const] ) ) {
				$this->nsConf->$name->alias = $nsAlias[$const];
			}

			if ( isset( $wgNamespacesWithSubpages[ $const ] ) ) {
				$this->nsConf->$name->hasSubpage = $wgNamespacesWithSubpages[ $const ];
			}

			if ( $this->nsConf->$name->group === null && $this->nsConf->$name->permission !== null
				 && isset( $permMap[$this->nsConf->$name->permission] ) ) {
				$permGroup = array_filter(
					array_keys( array_filter( $permMap[$this->nsConf->$name->permission] ) ),
					function( $group ) use ( $adminGroup ) {
						return $group !== $adminGroup;
					} );
				if( count( $permGroup ) > 1 ) {
					var_dump( $permGroup );
					throw new MWException( "More than one group with " .
										   $this->nsConf->$name->permission . " permission." );
				}
				$this->nsConf->$name->group = array_shift( $permGroup );
			}

			if ( isset( $wgCirrusSearchNamespaceWeights ) ) {
				$this->nsConf->$name->searchWeight = null;
				if ( isset( $wgCirrusSearchNamespaceWeights[$const] ) ) {
					$this->nsConf->$name->searchWeight
						= $wgCirrusSearchNamespaceWeights[$const];
				}
			}
		}
	}
}

$maintClass = "NamespaceManager\\ConfigDumper";
require_once RUN_MAINTENANCE_IF_MAIN;
