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
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/EditPageTosSummary
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	public static function onEditPageTosSummary( Title $title,  &$msg ) {
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
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
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
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	public static function onNamespaceIsMovable( $index, &$result ) {
	}

	/**
	 * Modify the searchable namespaces.
	 *
	 * @param array &$nsList namespces [$nsID => $name] which will be searchable
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/SearchableNamespaces
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
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

	private static function setNSPermIfUnset( $ns, $perm = 'read', $group = '*' ) {
		global $wgNamespacePermissionLockdown;

		if ( ! isset( $wgNamespacePermissionLockdown [ $ns ][ $perm ] ) ) {
			$wgNamespacePermissionLockdown [ $ns ][ $perm ] = $group;
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

		$const = $conf->number;
		$talkConst = $conf->number + 1;
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
			if ( isset( $conf->lockdown ) && is_array( $conf->lockdown ) ) {
				$wgNamespacePermissionLockdown[ $const ][ '*' ]
					= [ $adminGroup ];
				$wgNamespacePermissionLockdown[ $talkConst ][ '*' ]
					= [ $adminGroup ];
				foreach ( $conf->lockdown as $perm ) {
					$wgNamespacePermissionLockdown[ $const ][ $perm ]
						= [ $group, $adminGroup ];
					$wgNamespacePermissionLockdown[ $talkConst ][ $perm ]
						= [ $group, $adminGroup ];
				}
				if ( ! ( is_array( $conf->lockdown ) && in_array( 'read', $conf->lockdown ) ) ) {
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
	 * @SuppressWarnings(PHPMD.EvalExpression)
	 */
	protected static function checkConst( $constName, $constVal ) {
		if ( !defined( $constName ) ) {
			define( $constName, $constVal );
		} elseif (
			is_int( $constVal ) && eval( "return $constVal !== $constName;" )
		) {
			throw new MWException( "$constName must be set to $constVal" );
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
				$wgNamespaceAliases[ $alias ] = $conf->number;
				$wgNamespaceAliases[ "{$alias}_talk" ] = $conf->number + 1;
				$wgNamespaceAliases[ "{$alias} talk" ] = $conf->number + 1;
			}
		}
	}

	/**
	 * Do any extension configuration
	 *
	 * @param stdClass $conf section from ns.conf
	 * @SuppressWarnings(PHPMD.LongVariable)
	 */
	protected static function setupNSExtensions( $conf ) {
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

		$talkConst = $conf->number + 1;
		$const = $conf->number;

		if ( isset( $conf->hasSubpage ) ) {
			$wgNamespacesWithSubpages[$const] = $conf->hasSubpage;
		}

		if ( isset( $conf->defaultSearch ) ) {
			$wgNamespacesToBeSearchedDefault[$const] = $conf->defaultSearch;
		}

		if ( isset( $conf->useVE ) ) {
			$wgVisualEditorAvailableNamespaces[$const] = $conf->useVE;
		}

		if ( isset( $conf->useSMW ) ) {
			$smwgNamespacesWithSemanticLinks[$const] = $conf->useSMW;
		}

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

		if ( isset( $conf->usePageTriage ) && $conf->usePageTriage ) {
			$wgPageTriageNamespaces[] = $const;
		}

		if ( isset( $conf->usePageImages ) && $conf->usePageImages ) {
			$wgPageImagesNamespces[] = $const;
		}
	}

	/**
	 * Initialize everything.  Called after extensions are
	 * loaded. Sets up namespaces as desired.
	 * @SuppressWarnings(PHPMD.LongVariable)
	 */
	public static function init() {
		global $wgExtraNamespaces;
		$nsConf = self::getNSConfig();

		if ( !isset( $nsConf->globalAdmin ) ) {
			throw new MWException( "A Global Admin group needs to be set." );
		}
		foreach ( $nsConf as $nsName => $conf ) {
			if ( $nsName == "globalAdmin" ) {
				continue;
			}
			if ( !isset( $conf->number ) ) {
				throw new MWException(
					"ns.json needs a number set for '$nsName'."
				);
			}

			self::secureNS( $nsConf->globalAdmin, $conf );

			$talkConstName = $conf->const . "_TALK";
			$talkConst = $conf->number + 1;
			$const = $conf->number;
			self::checkConst( $conf->const, $conf->number );
			self::checkConst( $talkConstName, $talkConst );
			self::setupAliases( $conf );
			self::setupNSExtensions( $conf );

			$wgExtraNamespaces[ $const ] = $nsName;
			$wgExtraNamespaces[ $talkConst ] = "{$nsName}_talk";

			$wgContentNamespaces[] = $const;
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
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	public static function onChangesListSpecialPageQuery(
		$name, &$tables, &$fields, &$conds, &$queryOptions, &$joinConds, $opts
	) {
		global $wgNamespaceHideFromRC;

		if ( $name === "Recentchanges" ) {
			if ( count( $wgNamespaceHideFromRC ) ) {
				$conds[] = 'rc_namespace NOT IN ('
						 . implode( ", ", $wgNamespaceHideFromRC ) . ')';
			}
		}
	}
}
