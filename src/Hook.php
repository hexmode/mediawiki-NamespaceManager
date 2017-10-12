<?php
/**
 * Hooking into everything
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

namespace MediaWiki\Extension\NamespaceManager;

use Title;
use MWException;

class Hook {
	/**
	 * Is a page is movable in this namespace?
	 *
	 * @param int $index the index of the namespace being checked.
	 * @param bool &$result whether pages in this namespace are movable.
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/NamespaceIsMovable
	 */
	public static function onNamespaceIsMovable( $index, &$result ) {
	}

	/**
	 * Modify the searchable namespaces.
	 *
	 * @param array &$ns namespces [$nsID => $name] which will be searchable
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/SearchableNamespaces
	 */
	public static function onSearchableNamespaces( array &$ns ) {
	}

	/**
	 * Read the JSON config file for the namespaces
	 *
	 * @return StdClass object with contents
	 */
	protected static function getNSConfig() {
		$config = Config::newInstance();

		return json_decode( file_get_contents( $config->get( Config::MAP_FILE ) ) );
	}

	/**
	 * Initialize everything.  Called after extensions are
	 * loaded. Sets up namespaces as desired.
	 */
	public static function init() {
		global $smwgNamespacesWithSemanticLinks;
		global $wgContentNamespaces;
		global $wgExtraNamespaces;
		global $wgGroupPermissions;
		global $wgNamespaceAliases;
		global $wgNamespaceContentModels;
		global $wgNamespaceHideFromRC;
		global $wgNamespacePermissionLockdown;
		global $wgNamespaceProtection;
		global $wgNamespacesToBeSearchedDefault;
		global $wgNamespacesWithSubpages;
		global $wgVisualEditorAvailableNamespaces;
		global $wgCollectionArticleNamespaces;
		global $egApprovedRevsNamespaces;
		global $wgPageTriageNamespaces;

		// Actually assign this one sometime
		global $wgNonincludableNamespaces;

		$nsConf = self::getNSConfig();

		if ( !isset( $nsConf->globalAdmin ) ) {
			throw new MWException( "A Global Admin group needs to be set." );
		}
		$adminGroup = $nsConf->globalAdmin;
		foreach ( $nsConf as $nsName => $conf ) {
			if ( $nsName == "globalAdmin" ) {
				continue;
			}
			if ( !isset( $conf->number ) ) {
				throw new MWException(
					"ns.json needs a number set for '$nsName'."
				);
			}
			$const = $conf->number;
			$talkConst = $conf->number + 1;
			$permission = isset( $conf->permission ) ? $conf->permission : null;
			$group = isset( $conf->group ) ? $conf->group : null;

			if ( $group && $permission !== null ) {
				$wgGroupPermissions['*'][$permission] = false;
				$wgGroupPermissions[ $group ][$permission] = true;
				$wgGroupPermissions[ $adminGroup ][$permission] = true;
				$wgNamespaceProtection[ $const ][] = $permission;
				$wgNamespaceProtection[ $talkConst ][] = $permission;
				// Would like to have this but then you can't include
				// them from your own NS
				// $wgNonincludableNamespaces[] = $const;
				// $wgNonincludableNamespaces[] = $talkConst;
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
				}
			}

			if ( !defined( $conf->const ) ) {
				define( $conf->const, $const );
			} elseif ( eval( "return $const !== {$conf->const};" ) ) {
				throw new MWException(
					$conf->const . " must be set to " . $const
				);
			}

			$talkConstName = $conf->const . "_TALK";
			if ( !defined( $talkConstName ) ) {
				define( $talkConstName, $talkConst );
			} elseif ( eval( "return $talkConstName !== $talkConst;" ) ) {
				throw new MWException(
					$talkConstName . " must be set to " . $talkConst
				);
			}

			$wgExtraNamespaces[ $const ] = $nsName;
			$wgExtraNamespaces[ $talkConst ] = "{$nsName}_talk";
			if ( isset( $conf->alias ) && is_array( $conf->alias ) ) {
				foreach ( $conf->alias as $alias ) {
					$wgNamespaceAliases[ $alias ] = $const;
					$wgNamespaceAliases[ "{$alias}_talk" ] = $talkConst;
					$wgNamespaceAliases[ "{$alias} talk" ] = $talkConst;
				}
			}
			if ( isset( $conf->hasSubpage ) ) {
				$wgNamespacesWithSubpages[ $const ] = $conf->hasSubpage;
			}

			$wgContentNamespaces[] = $const;
			if ( isset( $conf->useVE ) ) {
				$wgVisualEditorAvailableNamespaces[$const] = $conf->useVE;
			}
			if ( isset( $conf->useSMW ) ) {
				$smwgNamespacesWithSemanticLinks[$const] = $conf->useSMW;
			}

			if ( isset( $conf->useFlowForTalk ) && $conf->useFlowForTalk ) {
				$wgNamespaceContentModels[$talkConst] = 'flow-board';
			}

			if ( isset( $conf->defaultSearch ) ) {
				$wgNamespacesToBeSearchedDefault[$const] = $conf->defaultSearch;
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
	 * @param array &$query_options for the database request
	 * @param array &$join_conds for the tables
	 * @param FormOptions $opts for this request
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/ChangesListSpecialPageQuery
	 */
	public static function onChangesListSpecialPageQuery(
		$name, &$tables, &$fields, &$conds, &$query_options, &$join_conds, $opts
	) {
		global $wgNamespaceHideFromRC;

		if ( $name === "Recentchanges" ) {
			if ( count( $wgNamespaceHideFromRC ) ) {
				$conds[] = 'rc_namespace NOT IN (' .
						 implode( ", ", $wgNamespaceHideFromRC ) . ')';
			}
		}
	}

	/**
	 * Possible per-namespace customizations of terms of service summary link
	 *
	 * @param Title $title of page being edited
	 * @param string &$msg message name, defaults to editpage-tos-summary
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/EditPageTosSummary
	 */
	public static function onEditPageTosSummary( Title $title,  &$msg ) {
	}

	/**
	 * Possible per-namespace Allow customization of contribution/copyright notice.
	 *
	 * @param Title $title of page being edited
	 * @param string &$msg message name, defaults to editpage-tos-summary
	 *                     Default is either 'copyrightwarning' or 'copyrightwarning2'
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/EditPageCopyrightWarning
	 */
	public static function onEditPageCopyrightWarning( Title $title, &$msg ) {
	}
}
