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

namespace NamespaceManager;

use Title;

class Hook {
	static public function onNamespaceIsMovable( $index, &$result ) {
	}

	static public function onSearchableNamespaces( array &$ns ) {
	}

	static protected function getNSConfig() {
		global $IP;
		return json_decode( file_get_contents( "$IP/ns.json" ) );
	}

	static public function init( ) {
		global $smwgNamespacesWithSemanticLinks;
		global $wgContentNamespaces;
		global $wgExtraNamespaces;
		global $wgGroupPermissions;
		global $wgNamespaceAliases;
		global $wgNamespaceHideFromRC;
		global $wgNamespaceProtection;
		global $wgNamespaceRestriction;
		global $wgNamespacesToBeSearchedDefault;
		global $wgNamespacesWithSubpages;
		global $wgNonincludableNamespaces;
		global $wgVisualEditorAvailableNamespaces;

		$nsConf = self::getNSConfig();

		foreach( $nsConf as $nsName => $conf ) {
			if ( !isset( $conf->number ) ) {
				throw new MWException( "ns.json needs a number set for '$nsName'." );
			}
			$const = $conf->number;
			$talkConst = $conf->number + 1;
			$permission = isset( $conf->permission ) ? $conf->permission : null;
			$group = isset( $conf->group ) ? $conf->group : null;

			if ( $group && $permission !== null ) {
				$wgGroupPermissions['*'][$permission] = false;
				$wgGroupPermissions[ $group ][$permission] = true;
				$wgGroupPermissions[ 'ksteam' ][$permission] = true;
				$wgNamespaceProtection[ $const ][] = $permission;
				$wgNamespaceProtection[ $talkConst ][] = $permission;
				$wgNamespaceRestriction[ $const ] = $permission;
				$wgNamespaceRestriction[ $talkConst ] = $permission;
				// Would like to have this but then you can't include
				// them from your own NS
				// $wgNonincludableNamespaces[] = $const;
				// $wgNonincludableNamespaces[] = $talkConst;
				$wgNamespaceHideFromRC[] = $const;
				$wgNamespaceHideFromRC[] = $talkConst;
			}

			define( $conf->const, $const );
			define( $conf->const . "_TALK", $talkConst );

			$wgExtraNamespaces[ $const ] = $nsName;
			$wgExtraNamespaces[ $talkConst ] = "{$nsName}_talk";
			if ( isset( $conf->alias ) && is_array( $conf->alias ) ) {
				foreach( $conf->alias as $alias ) {
					$wgNamespaceAliases[ $alias ] = $const;
					$wgNamespaceAliases[ "{$alias}_talk" ] = $talkConst;
					$wgNamespaceAliases[ "{$alias} talk" ] = $talkConst;
				}
			}
			if ( isset( $conf->hasSubpages ) ) {
				$wgNamespacesWithSubpages[ $const ] = $conf->hasSubpage;
			}

			$wgContentNamespaces[] = $const;
			if ( isset( $conf->useVE ) ) {
				$wgVisualEditorAvailableNamespaces[$const] = $conf->useVE;
			}
			if ( isset( $conf->useSMW ) ) {
				$smwgNamespacesWithSemanticLinks[$const] = $conf->useSMW;
			}

			if ( isset( $conf->defaultSearch ) ) {
				$wgNamespacesToBeSearchedDefault[$const] = $conf->defaultSearch;
			}
		}
	}

	static public function onEditPageTosSummary( Title $title,  &$msg ) {
	}

	static public function onEditPageCopyrightWarning( Title $title, &$msg ) {
	}
}