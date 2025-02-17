<?php

/** @noinspection PhpMissingParamTypeInspection */
// phpcs:disable MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName

namespace WikiMirror\Mirror;

use MediaWiki\Hook\SkinTemplateNavigation__UniversalHook;
use MediaWiki\Hook\TitleIsAlwaysKnownHook;
use MediaWiki\Page\Hook\WikiPageFactoryHook;
use MediaWiki\Permissions\Hook\GetUserPermissionsErrorsHook;
use MediaWiki\Permissions\PermissionManager;
use MessageSpecifier;
use MWException;
use SkinTemplate;
use SpecialPage;
use Title;
use User;
use Wikimedia\Rdbms\ILoadBalancer;
use WikiPage;

class Hooks implements
	TitleIsAlwaysKnownHook,
	GetUserPermissionsErrorsHook,
	WikiPageFactoryHook,
	SkinTemplateNavigation__UniversalHook
{
	/** @var Mirror */
	private $mirror;
	/** @var PermissionManager */
	private $permManager;
	/** @var ILoadBalancer */
	private $loadBalancer;

	/**
	 * Hooks constructor.
	 *
	 * @param Mirror $mirror
	 * @param PermissionManager $permManager
	 * @param ILoadBalancer $loadBalancer
	 */
	public function __construct( Mirror $mirror, PermissionManager $permManager, ILoadBalancer $loadBalancer ) {
		$this->mirror = $mirror;
		$this->permManager = $permManager;
		$this->loadBalancer = $loadBalancer;
	}

	/**
	 * Determine whether this title exists on the remote wiki
	 *
	 * @param Title $title Title being checked
	 * @param bool|null &$isKnown Set to null to use default logic or a bool to skip default logic
	 */
	public function onTitleIsAlwaysKnown( $title, &$isKnown ) {
		static $cache = [];

		if ( $isKnown !== null ) {
			// some other extension already set this
			return;
		}

		$cacheKey = $title->getPrefixedDBkey();
		if ( array_key_exists( $cacheKey, $cache ) ) {
			$isKnown = $cache[$cacheKey];
			return;
		}

		$dbr = $this->loadBalancer->getConnection( DB_REPLICA );

		// is the page forked? If so short-circuit our checks
		$count = $dbr->selectField( 'forked_titles', 'COUNT(*)', [
			'ft_namespace' => $title->getNamespace(),
			'ft_title' => $title->getDBkey()
		], __METHOD__ );

		if ( $count > 0 ) {
			$cache[$cacheKey] = null;
			return;
		}

		// right now we assume that foreign namespace ids match local namespace ids
		$count = $dbr->selectField( 'remote_page', 'COUNT(*)', [
			'rp_namespace' => $title->getNamespace(),
			'rp_title' => $title->getDBkey()
		], __METHOD__ );

		if ( $count > 0 ) {
			$cache[$cacheKey] = true;
			$isKnown = true;
		} else {
			$cache[$cacheKey] = null;
		}
	}

	/**
	 * Ensure that users can't perform modification actions on mirrored pages until they're forked
	 *
	 * @param Title $title Title being checked
	 * @param User $user User being checked
	 * @param string $action Action to check
	 * @param array|string|MessageSpecifier|false &$result Result of check
	 * @return bool True to use default logic, false to abort hook processing and use our result
	 */
	public function onGetUserPermissionsErrors( $title, $user, $action, &$result ) {
		// read: lets people read the mirrored page
		// fork: lets people fork the page
		$allowedActions = [ 'read', 'fork' ];

		if ( $this->mirror->canMirror( $title ) && !in_array( $action, $allowedActions ) ) {
			// user doesn't have the ability to perform this action with this page
			$result = wfMessageFallback( 'wikimirror-no-' . $action, 'wikimirror-no-action' );
			return false;
		}

		return true;
	}

	/**
	 * Get a WikiPage object for mirrored pages that fetches data from the remote instead of the db
	 *
	 * @param Title $title Title to get WikiPage of
	 * @param WikiPage|null &$page Page to return, or null to use default logic
	 * @return bool True to use default logic, false to abort hook processing and use our page
	 */
	public function onWikiPageFactory( $title, &$page ) {
		if ( $this->mirror->canMirror( $title ) ) {
			$page = new WikiRemotePage( $title );
			return false;
		}

		return true;
	}

	/**
	 * Add a fork tab to relevant pages
	 *
	 * @param SkinTemplate $sktemplate
	 * @param array &$links Structured navigation links. This is used to alter the navigation for
	 *   skins which use buildNavigationUrls such as Vector.
	 * @return void This hook must not abort, it must return no value
	 * @throws MWException
	 */
	public function onSkinTemplateNavigation__Universal( $sktemplate, &$links ): void {
		$title = $sktemplate->getRelevantTitle();
		$user = $sktemplate->getUser();
		$skinName = $sktemplate->getSkinName();

		if ( $this->mirror->canMirror( $title ) ) {
			if ( $this->permManager->quickUserCan( 'fork', $user, $title ) ) {
				$forkTitle = SpecialPage::getTitleFor( 'Fork', $title->getPrefixedDBkey() );
				$links['views']['fork'] = [
					'class' => $sktemplate->getTitle()->isSpecial( 'Fork' ) ? 'selected' : false,
					'href' => $forkTitle->getLocalURL(),
					'text' => wfMessageFallback( "$skinName-action-fork", 'wikimirror-action-fork' )
						->setContext( $sktemplate->getContext() )->text()
				];

				// if the user can watch the page, ensure Fork is before the watchlist star
				if ( array_key_exists( 'watch', $links['views'] ) ) {
					$watch = $links['views']['watch'];
					unset( $links['views']['watch'] );
					$links['views']['watch'] = $watch;
				}
			}
		}
	}
}
