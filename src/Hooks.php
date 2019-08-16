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
use MWException;
use stdClass;
use Title;

class Hooks {
	/**
	 * Schema initialization and updating
	 *
	 * @param DatabaseUpdater $updater to manage updates
	 */
	public static function onLoadExtensionSchemaUpdates(
		DatabaseUpdater $updater
	) :void {
		$updater->addExtensionTable(
			'namespace_mgr', __DIR__ . '/../sql/namespace_mgr.sql'
		);
	}

	/**
	 * Possible per-namespace customizations of terms of service summary link
	 *
	 * @param Title $title of page being edited
	 * @param string &$msg message name, defaults to editpage-tos-summary
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/EditPageTosSummary
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) @codingStandardsIgnoreLine
	 */
	public static function onEditPageTosSummary( Title $title, &$msg ) :void {
	}

	/**
	 * Possible per-namespace allow customization of
	 * contribution/copyright notice.
	 *
	 * @param Title $title of page being edited
	 * @param string &$msg message name, defaults to editpage-tos-summary
	 *                      Default is either 'copyrightwarning' or
	 *                      'copyrightwarning2'
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

		if ( file_exists( $nsConf ) ) {
			if ( is_readable( $nsConf ) ) {
				$nsConfig = json_decode( file_get_contents( $nsConf ) );
			} else {
				throw new MWException(
					"Can't read namespace config: $nsConfig."
				);
			}
		} else {
			$nsConfig = new stdClass;
			$nsConfig->globalAdmin = "sysop";
		}

		return $nsConfig;
	}

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
	 */
	protected static function secureNS( $adminGroup, $conf ) {
		global $wgNamespacePermissionLockdown;
		global $wgGroupPermissions;
		global $wgNamespaceHideFromRC;
		global $wgNamespaceProtection;
		global $wgNonincludableNamespaces;

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
			$wgNamespaceProtection[ $const ][] = $permission;
			$wgNamespaceProtection[ $talkConst ][] = $permission;
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
	 * @param string $constVal value of the constant
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
	 */
	protected static function setupNSExtensions( &$conf ) {
		global $wgVisualEditorAvailableNamespaces;
		global $wgCollectionArticleNamespaces;
		global $wgPageTriageNamespaces;
		global $smwgNamespacesWithSemanticLinks; // @codingStandardsIgnoreLine
		global $egApprovedRevsNamespaces; // @codingStandardsIgnoreLine
		global $wgNamespaceContentModels;
		global $wgPageImagesNamespaces;
		global $wgContentNamespaces;
		global $wgNamespacesToBeSearchedDefault;
		global $wgNamespacesWithSubpages;
		global $wgUFAllowedNamespaces;

		$talkConst = $conf->id + 1;
		$const = $conf->id;

		$wgNamespacesWithSubpages[$const]          = $conf->hasSubpages ?? false;
		$wgNamespacesToBeSearchedDefault[$const]   = $conf->defaultSearch ?? false;
		$wgVisualEditorAvailableNamespaces[$const] = $conf->useVE ?? false;
		$smwgNamespacesWithSemanticLinks[$const]   = $conf->useSMW ?? false;
		$wgUFAllowedNamespaces[$const]             = $conf->userFunctions ?? false;

		if ( isset( $conf->useFlowForTalk ) && $conf->useFlowForTalk ) {
			$wgNamespaceContentModels[$talkConst] = 'flow-board';
		}

		if ( isset( $conf->content ) && $conf->content ) {
			$wgContentNamespaces[] = $const;
		}

		if ( isset( $conf->useCollection ) && $conf->useCollection ) {
			$wgCollectionArticleNamespaces[] = $const;
		}

		if ( isset( $conf->useApprovedRevs ) && $conf->useApprovedRevs ) {
			$egApprovedRevsNamespaces[] = $const;
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
	}

	private static $defaults;
	private static $lockdownDefaults;

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

	private static function setDefaults( stdClass &$conf ) {
		foreach ( self::$defaults as $key => $value ) {
			if ( !isset( $conf->$key ) ) {
				$conf->$key = $value;
			}
		}
	}

	private static function setLockdownDefaults( stdClass &$conf ) {
		if ( isset( $conf->lockdown ) && $conf->lockdown === true ) {
			$conf->lockdown = self::$lockdownDefaults;
		}
	}

	/**
	 * Initialize everything.  Called after extensions are
	 * loaded. Sets up namespaces as desired.
	 * @SuppressWarnings(PHPMD.LongVariable) @codingStandardsIgnoreLine
	 */
	public static function init() {
		global $wgExtraNamespaces;
		$nsConf = self::getNSConfig();
		self::setupDefaults( $nsConf );

		if ( !isset( $nsConf->globalAdmin ) ) {
			throw new MWException( "A Global Admin group needs to be set." );
		}
		foreach ( $nsConf as $nsName => $conf ) {
			if ( $nsName == "globalAdmin" ) {
				continue;
			}

			if ( !( isset( $conf->id ) && isset( $conf->constant ) ) ) {
				throw new MWException(
					"ns.json needs a constant name and an id set for '$nsName'."
				);
			}
			self::setDefaults( $conf );
			self::setLockdownDefaults( $conf );
			self::secureNS( $nsConf->globalAdmin, $conf );

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
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/ChangesListSpecialPageQuery
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) @codingStandardsIgnoreLine
	 */
	public static function onChangesListSpecialPageQuery(
		$name, &$tables, &$fields, &$conds, &$queryOptions, &$joinConds, $opts
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
