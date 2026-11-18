<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2025 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cashbook\Controller;

use OCA\Cashbook\Db\CashbookMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Util;

/**
 * @psalm-api Loaded by the router
 */
final class PageController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		protected ?string $userId,
		protected IURLGenerator $urlGenerator,
		protected CashbookMapper $cashbookMapper,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired()]
	#[NoCSRFRequired()]
	#[FrontpageRoute(verb: 'GET', url: '/')]
	public function index(): TemplateResponse {
		Util::addScript($this->appName, 'cashbook-main');
		Util::addStyle($this->appName, 'cashbook-main');

		return new TemplateResponse($this->appName, 'main');
	}

	/**
	 * Page shown by the frontend when a cashbook does not exist
	 */
	#[NoAdminRequired()]
	#[NoCSRFRequired()]
	#[FrontpageRoute(verb: 'GET', url: '/not-found')]
	public function notFound(): TemplateResponse {
		return $this->index();
	}

	#[NoAdminRequired()]
	#[NoCSRFRequired()]
	#[FrontpageRoute(verb: 'GET', url: '/{cashbookId}', requirements: ['cashbookId' => '\d+'])]
	public function showCashbook(string $cashbookId): TemplateResponse|RedirectResponse {
		if (!$this->isAccessible($cashbookId)) {
			return $this->redirectToNotFound();
		}
		return $this->index();
	}

	/**
	 * Printable page of the entries of a cashbook
	 */
	#[NoAdminRequired()]
	#[NoCSRFRequired()]
	#[FrontpageRoute(verb: 'GET', url: '/{cashbookId}/export', requirements: ['cashbookId' => '\d+'])]
	public function exportCashbook(string $cashbookId): TemplateResponse|RedirectResponse {
		if (!$this->isAccessible($cashbookId)) {
			return $this->redirectToNotFound();
		}

		Util::addScript($this->appName, 'cashbook-export');
		Util::addStyle($this->appName, 'cashbook-export');

		return new TemplateResponse($this->appName, 'export', renderAs: TemplateResponse::RENDER_AS_BASE);
	}

	/**
	 * Check if the cashbook exists and is owned by the current user
	 */
	private function isAccessible(string $cashbookId): bool {
		try {
			return $this->cashbookMapper->getCashbook($cashbookId)->getOwner() === $this->userId;
		} catch (DoesNotExistException) {
			return false;
		}
	}

	private function redirectToNotFound(): RedirectResponse {
		return new RedirectResponse(
			$this->urlGenerator->linkToRouteAbsolute('cashbook.page.notFound')
		);
	}
}
