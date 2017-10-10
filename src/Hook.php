<?php

/*
 * Copyright (C) 2017  Mark A. Hershberger
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
 */

namespace MediaWiki\Extension\NamespaceManager;

use Title;
use MWException;

class Hook {
	public static function onNamespaceIsMovable( $index, &$result ) {
	}

	public static function onSearchableNamespaces( array &$ns ) {
	}

	protected static function getNSConfig() {
		$config = Config::newInstance();

		return json_decode( file_get_contents( $config->get( Config::MAP_FILE ) ) );
	}

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
		global $wgNonincludableNamespaces;
		global $wgVisualEditorAvailableNamespaces;
		global $wgCollectionArticleNamespaces;
		global $egApprovedRevsNamespaces;

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
		}
	}

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
		return true;
	}

	public static function onEditPageTosSummary( Title $title,  &$msg ) {
	}

	public static function onEditPageCopyrightWarning( Title $title, &$msg ) {
	}
}