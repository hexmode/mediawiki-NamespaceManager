<?php
/**
 * Hooking into everything
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

namespace MediaWiki\Extension\NamespaceManager;

use DatabaseUpdater;
use FormOptions;
use JsonException;
use MWException;
use stdClass;
use Title;

class Hooks {
	/**
	 * Schema initialization and updating
	 *
	 * @param DatabaseUpdater $updater to manage updates
	 * @return void
	 */
	public static function onLoadExtensionSchemaUpdates(
		DatabaseUpdater $updater
	) {
		$updater->addExtensionTable(
			'namespace_mgr', __DIR__ . '/../sql/namespace_mgr.sql'
		);
	}

	/**
	 * Possible per-namespace customizations of terms of service summary link
	 *
	 * @param Title $title of page being edited
	 * @param string &$msg message name, defaults to editpage-tos-summary
	 * @return void
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/EditPageTosSummary
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) @codingStandardsIgnoreLine
	 */
	public static function onEditPageTosSummary( Title $title, &$msg ) {
	}

	/**
	 * Possible per-namespace allow customization of
	 * contribution/copyright notice.
	 *
	 * @param Title $title of page being edited
	 * @param string &$msg message name, defaults to editpage-tos-summary
	 *                      Default is either 'copyrightwarning' or
	 *                      'copyrightwarning2'
	 * @return void
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/EditPageCopyrightWarning
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) @codingStandardsIgnoreLine
	 */
	public static function onEditPageCopyrightWarning( Title $title, &$msg ) {
	}

	/**
	 * Is a page is movable in this namespace?
	 *
	 * @param int $index the index of the namespace being checked.
	 * @param bool &$result whether pages in this namespace are movable.
	 * @return void
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/NamespaceIsMovable
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) @codingStandardsIgnoreLine
	 */
	public static function onNamespaceIsMovable( $index, &$result ) {
	}

	/**
	 * Modify the searchable namespaces.
	 *
	 * @param array &$nsList namespces [$nsID => $name] which will be searchable
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/SearchableNamespaces
	 * @return void
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) @codingStandardsIgnoreLine
	 */
	public static function onSearchableNamespaces( array &$nsList ) {
	}

	/**
	 * Read the JSON config file for the namespaces
	 *
	 * @return StdClass object with contents
	 */
	protected static function getNSConfig() {
		$config = Config::newInstance();
		$nsConf = $config->get( Config::MAP_FILE );

		$nsConfig = false;
		if ( file_exists( $nsConf ) ) {
			if ( is_readable( $nsConf ) ) {
				try {
					$nsConfig = json_decode(
						file_get_contents( $nsConf ), false, 512, JSON_THROW_ON_ERROR
					);
				} catch ( JsonException $e ) {
					// At this point MW error handling is not set up.
					echo wfMessage( 'nsmgr-json-exception' )->params( $e->getMessage(), $nsConf )->plain();
					exit;
				}
			}
		} else {
			$nsConfig = new stdClass;
		}

		if ( $nsConfig === false ) {
			throw new MWException(
				"Can't read namespace config: $nsConfig."
			);
		}
		if ( $nsConfig === null ) {
			throw new MWException(
				"JSON not well-formed: $nsConfig."
			);
		}
		return $nsConfig;
	}

	/**
	 * @param int $namespace
	 * @param string $perm
	 * @param string $group
	 * @return void
	 */
	private static function setNSPermIfUnset(
		$namespace, $perm = 'read', $group = '*'
	) {
		global $wgNamespacePermissionLockdown;

		if ( !isset( $wgNamespacePermissionLockdown [ $namespace ][ $perm ] ) ) {
			$wgNamespacePermissionLockdown [ $namespace ][ $perm ] = $group;
		}
	}

	/**
	 * Set up permissions for a namespace
	 *
	 * @param string $adminGroup name of the "super user" group
	 * @param stdClass $conf section from ns.conf
	 * @return void
	 */
	protected static function secureNS( $adminGroup, $conf ) {
		global $wgNamespacePermissionLockdown;
		global $wgGroupPermissions;
		global $wgNamespaceHideFromRC;
		global $wgNamespaceProtection;
		global $wgNonincludableNamespaces;
		global $wgGrantPermissions;

		$const = $conf->id;
		$talkConst = $conf->id + 1;
		$permission = isset( $conf->permission ) ? $conf->permission : null;
		$group = isset( $conf->group ) ? $conf->group : null;

		if ( isset( $conf->includable ) && $conf->includable === false ) {
			$wgNonincludableNamespaces[] = $const;
		}

		if ( $group && $permission !== null ) {
			$wgGroupPermissions['*'][$permission] = false;
			$wgGroupPermissions[ $group ][$permission] = true;
			$wgGroupPermissions[ $adminGroup ][$permission] = true;
			$wgGrantPermissions[ 'editprotected' ][$permission] = true;
			$wgNamespaceProtection[ $const ] = $permission;
			$wgNamespaceProtection[ $talkConst ] = $permission;
			$wgNamespaceHideFromRC[] = $const;
			$wgNamespaceHideFromRC[] = $talkConst;
			if ( isset( $conf->lockdown ) ) {
				$wgNamespacePermissionLockdown[ $const ][ '*' ]
					= [ $adminGroup ];
				$wgNamespacePermissionLockdown[ $talkConst ][ '*' ]
					= [ $adminGroup ];
				if ( is_array( $conf->lockdown ) ) {
					foreach ( $conf->lockdown as $perm ) {
						$wgNamespacePermissionLockdown[ $const ][ $perm ]
							= [ $group, $adminGroup ];
						$wgNamespacePermissionLockdown[ $talkConst ][ $perm ]
							= [ $group, $adminGroup ];
					}
				}
				if (
					!( is_array( $conf->lockdown )
						&& in_array( 'read', $conf->lockdown ) )
				) {
					self::setNSPermIfUnset( $const );
					self::setNSPermIfUnset( $talkConst );
					self::setNSPermIfUnset( $talkConst, 'createtalk' );
					self::setNSPermIfUnset( $talkConst, 'edit' );
				}
			}
		}
	}

	/**
	 * Check the value of the constant
	 *
	 * @param string $constName name of the constant
	 * @param string|int $constVal value of the constant
	 * @return void
	 * @throws MWException if they are not equal
	 * @SuppressWarnings(PHPMD.EvalExpression) @codingStandardsIgnoreLine
	 */
	protected static function checkConst( $constName, $constVal ) {
		if ( !defined( $constName ) ) {
			define( $constName, $constVal );
		} elseif (
			is_int( $constVal ) && eval( "return $constVal !== $constName;" )
		) {
			throw new MWException( "$constName must be set to " . constant( $constName ) );
		}
	}

	/**
	 * Set up any aliases
	 *
	 * @param stdClass $conf section from ns.conf
	 * @return void
	 */
	protected static function setupAliases( $conf ) {
		global $wgNamespaceAliases;
		if ( isset( $conf->alias ) && is_array( $conf->alias ) ) {
			foreach ( $conf->alias as $alias ) {
				$wgNamespaceAliases[ $alias ] = $conf->id;
				$wgNamespaceAliases[ "{$alias}_talk" ] = $conf->id + 1;
				$wgNamespaceAliases[ "{$alias} talk" ] = $conf->id + 1;
			}
		}
	}

	/**
	 * Do any extension configuration
	 *
	 * @param stdClass &$conf section from ns.conf
	 * @SuppressWarnings(PHPMD.LongVariable) @codingStandardsIgnoreLine
	 * @return void
	 */
	protected static function setupNSExtensions( &$conf ) {
		global $egApprovedRevsNamespaces; // @codingStandardsIgnoreLine
		global $egApprovedRevsEnabledNamespaces; // @codingStandardsIgnoreLine
		global $smwgNamespacesWithSemanticLinks; // @codingStandardsIgnoreLine
		global $wgCollectionArticleNamespaces;
		global $wgContentNamespaces;
		global $wgNamespaceContentModels;
		global $wgNamespacesToBeSearchedDefault;
		global $wgNamespacesWithSubpages;
		global $wgPageFormsAutoeditNamespaces;
		global $wgPageImagesNamespaces;
		global $wgPageTriageCurationModules;
		global $wgPageTriageNamespaces;
		global $wgUFAllowedNamespaces;
		global $wgVisualEditorAvailableNamespaces;

		$talkConst = $conf->id + 1;
		$const = $conf->id;

		$wgNamespacesWithSubpages[$const]          = $conf->hasSubpages
												   ? $conf->hasSubpages
												   : false;
		$wgNamespacesToBeSearchedDefault[$const]   = $conf->defaultSearch
												   ? $conf->defaultSearch
												   : false;
		$wgVisualEditorAvailableNamespaces[$const] = $conf->useVE
												   ? $conf->useVE
												   : false;
		$smwgNamespacesWithSemanticLinks[$const]   = $conf->useSMW
												   ? $conf->useSMW
												   : false;
		$wgUFAllowedNamespaces[$const]             = $conf->userFunctions
												   ? $conf->userFunctions
												   : false;

		if ( isset( $conf->useFlowForTalk ) && $conf->useFlowForTalk ) {
			$wgNamespaceContentModels[$talkConst] = 'flow-board';
		}

		if ( isset( $conf->content ) && $conf->content ) {
			$wgContentNamespaces[] = $const;
		}

		if ( isset( $conf->useCollection ) && $conf->useCollection ) {
			$wgCollectionArticleNamespaces[] = $const;
		}

		if ( isset( $conf->useApprovedRevs ) ) {
			$egApprovedRevsEnabledNamespaces[$const] = $conf->useApprovedRevs;
			if ( $conf->useApprovedRevs ) {
				$egApprovedRevsNamespaces[] = $const;
			}
		}

		if ( isset( $conf->usePageTriage ) ) {
			if ( $conf->usePageTriage ) {
				$wgPageTriageNamespaces[] = $const;
			} elseif ( in_array( $const, $wgPageTriageNamespaces ) ) {
				unset( $wgPageTriageNamespaces[
					array_search( $const, $wgPageTriageNamespaces )
				] );
			}
		}

		if ( isset( $conf->usePageImages ) && $conf->usePageImages ) {
			$wgPageImagesNamespaces[] = $const;
		}

		if ( isset( $conf->autoEdit ) && $conf->autoEdit ) {
			$wgPageFormsAutoeditNamespaces[] = $const;
		}
	}

	/** @param array $defaults */
	private static $defaults;
	/** @param array $defaults */
	private static $lockdownDefaults;

	/**
	 * @return void
	 */
	private static function setupDefaults( stdClass &$nsConf ) {
		if ( isset( $nsConf->defaults ) ) {
			# We want to make sure we aren't overwriting these two
			unset( $nsConf->defaults->constant );
			unset( $nsConf->defaults->id );

			self::$defaults = $nsConf->defaults;
			unset( $nsConf->defaults );
		}
		if ( isset( $nsConf->lockdownDefaults ) ) {
			self::$lockdownDefaults = $nsConf->lockdownDefaults;
			unset( $nsConf->lockdownDefaults );
		}
	}

	/**
	 * @return void
	 */
	private static function setDefaults( stdClass &$conf ) {
		foreach ( self::$defaults as $key => $value ) {
			if ( !isset( $conf->$key ) ) {
				$conf->$key = $value;
			}
		}
	}

	/**
	 * @return void
	 */
	private static function setLockdownDefaults( stdClass &$conf ) {
		if ( isset( $conf->lockdown ) && $conf->lockdown === true ) {
			$conf->lockdown = self::$lockdownDefaults;
		}
	}

	/**
	 * Reset defaults so everything happens in here.
	 */
	protected static function maybeResetGlobals( stdClass &$conf ) {
		if ( isset( $conf->resetGlobals ) ) {
			if ( isset( $conf->resetGlobals ) ? $conf->resetGlobals : false ) {
				global $egApprovedRevsEnabledNamespaces, $egApprovedRevsNamespaces;
				$egApprovedRevsEnabledNamespaces = [];
				if ( isset( $egApprovedRevsNamespaces ) ) {
					$egApprovedRevsNamespaces = [];
				}
			}
			unset( $conf->resetGlobals );
		}
	}

	/**
	 * Get the global admin
	 */
	protected static function getGlobalAdmin( stdClass &$conf ) {
		$admin = "sysop";
		if ( isset( $conf->globalAdmin ) ) {
			$admin = $conf->globalAdmin;
			unset( $conf->globalAdmin );
		}
		return $admin;
	}

	/**
	 * Initialize everything.  Called after extensions are
	 * loaded. Sets up namespaces as desired.
	 * @return void
	 * @SuppressWarnings(PHPMD.LongVariable) @codingStandardsIgnoreLine
	 */
	public static function init() {
		global $wgExtraNamespaces, $wgPageTriageNamespaces, $wgPageTriageCurationModules;
		$nsConf = self::getNSConfig();
		self::setupDefaults( $nsConf );

		self::maybeResetGlobals( $nsConf );
		$globalAdmin = self::getGlobalAdmin( $nsConf );
		foreach ( $nsConf as $nsName => $conf ) {
			if ( !( isset( $conf->id ) && isset( $conf->constant ) ) ) {
				throw new MWException(
					"$nsConf needs a constant name and an id set for '$nsName'."
				);
			}
			self::setDefaults( $conf );
			self::setLockdownDefaults( $conf );
			self::secureNS( $globalAdmin, $conf );

			$talkConstName = $conf->constant . "_TALK";
			$talkConst = $conf->id + 1;
			$const = $conf->id;
			self::checkConst( $conf->constant, $conf->id );
			self::checkConst( $talkConstName, $talkConst );
			self::setupAliases( $conf );
			self::setupNSExtensions( $conf );

			$wgExtraNamespaces[ $const ] = $nsName;
			$wgExtraNamespaces[ $talkConst ] = "{$nsName}_talk";
		}
		$wgPageTriageNamespaces = is_array( $wgPageTriageNamespaces )
                                ? array_values( $wgPageTriageNamespaces )
                                : [];
		if ( is_array( $wgPageTriageCurationModules ) ) {
			foreach ( array_keys( $wgPageTriageCurationModules ) as $module ) {
				$wgPageTriageCurationModules[$module]['namespace']
					= $wgPageTriageNamespaces;
			}
		}
	}

	/**
	 * Called when building SQL query on pages inheriting from
	 * ChangesListSpecialPage (in core: RecentChanges,
	 * RecentChangesLinked and Watchlist).
	 *
	 * @param string $name name of the special page, e.g. 'Watchlist'
	 * @param array &$tables to be queried
	 * @param array &$fields to select
	 * @param array &$conds for query
	 * @param array &$queryOptions for the database request
	 * @param array &$joinConds for the tables
	 * @param FormOptions $opts for this request
	 * @return void
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/ChangesListSpecialPageQuery
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) @codingStandardsIgnoreLine
	 */
	public static function onChangesListSpecialPageQuery(
		$name, array &$tables, array &$fields, array &$conds, array &$queryOptions,
		array &$joinConds, FormOptions $opts
	) {
		global $wgNamespaceHideFromRC;

		if ( $name === "Recentchanges" ) {
			if ( $wgNamespaceHideFromRC && count( $wgNamespaceHideFromRC ) ) {
				$conds[] = 'rc_namespace NOT IN ('
						 . implode( ", ", $wgNamespaceHideFromRC ) . ')';
			}
		}
	}
}
