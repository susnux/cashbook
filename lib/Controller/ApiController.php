<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2025 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cashbook\Controller;

use Exception;
use InvalidArgumentException;
use OCA\Cashbook\AppInfo\Application;
use OCA\Cashbook\Db\Cashbook;
use OCA\Cashbook\Db\CashbookMapper;
use OCA\Cashbook\Db\Entry;
use OCA\Cashbook\Db\EntryMapper;
use OCA\Cashbook\Db\EntryReason;
use OCA\Cashbook\ImportService\IImportService;
use OCA\Cashbook\ResponseDefinitions;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * @psalm-import-type CashbookCashbook from ResponseDefinitions
 * @psalm-import-type CashbookEntry from ResponseDefinitions
 *
 * @psalm-api Loaded by the router
 */
final class ApiController extends OCSController {

	public function __construct(
		IRequest $request,
		protected LoggerInterface $logger,
		protected CashbookMapper $cashbookMapper,
		protected EntryMapper $entryMapper,
		protected ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Get all cashbooks of the current user
	 *
	 * @return DataResponse<Http::STATUS_OK, list<CashbookCashbook>, array{}>
	 *
	 * 200: List of cashbooks returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/cashbooks')]
	public function index(): DataResponse {
		if ($this->userId === null) {
			return new DataResponse([]);
		}

		$cashbooks = $this->cashbookMapper->getCashbooksByOwner($this->userId);
		return new DataResponse(
			array_map(static fn (Cashbook $cashbook): array => $cashbook->jsonSerialize(), $cashbooks)
		);
	}

	/**
	 * Get a single cashbook
	 *
	 * @param int $id The id of the cashbook
	 * @return DataResponse<Http::STATUS_OK, CashbookCashbook, array{}>
	 * @throws OCSNotFoundException Cashbook not found or not owned by the current user
	 *
	 * 200: Cashbook returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/cashbooks/{id}')]
	public function getCashbook(int $id): DataResponse {
		try {
			$cashbook = $this->cashbookMapper->getCashbook($id);
		} catch (DoesNotExistException) {
			throw new OCSNotFoundException();
		}
		if ($this->userId !== $cashbook->getOwner()) {
			throw new OCSNotFoundException();
		}
		return new DataResponse($cashbook->jsonSerialize());
	}

	/**
	 * Get a paginated list of entries of a cashbook
	 *
	 * @param int $id The id of the cashbook
	 * @param int<0, max> $offset Number of entries to skip
	 * @param int<1, 50> $limit Maximum number of entries to return
	 * @return DataResponse<Http::STATUS_OK, list<CashbookEntry>, array{}>
	 * @throws OCSBadRequestException The offset or limit is out of range
	 * @throws OCSNotFoundException Cashbook not found or not owned by the current user
	 *
	 * 200: Entries returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/cashbooks/{id}/entries')]
	public function getCashbookEntries(int $id, int $offset = 0, int $limit = 20): DataResponse {
		// The documented ranges are not enforced by the framework, so check them here
		/** @psalm-suppress DocblockTypeContradiction */
		if ($offset < 0 || $limit < 1 || $limit > 50) {
			throw new OCSBadRequestException('Invalid offset or limit');
		}

		try {
			$cashbook = $this->cashbookMapper->getCashbook($id);
		} catch (DoesNotExistException) {
			throw new OCSNotFoundException();
		}
		if ($this->userId !== $cashbook->getOwner()) {
			throw new OCSNotFoundException();
		}

		$entries = $this->entryMapper->getEntries($cashbook, $offset, $limit);
		return new DataResponse(
			array_map(static fn (Entry $entry): array => $entry->jsonSerialize(), $entries)
		);
	}

	/**
	 * Get all entries of a cashbook within a date range, prepared for printing
	 *
	 * Consecutive sales entries with the same tax rate and without a text are collapsed into a single entry.
	 *
	 * @param int $id The id of the cashbook
	 * @param string $begin Start of the date range (ISO 8601 date time)
	 * @param string $end End of the date range (ISO 8601 date time)
	 * @return DataResponse<Http::STATUS_OK, list<CashbookEntry>, array{}>
	 * @throws OCSBadRequestException Invalid date passed
	 * @throws OCSNotFoundException Cashbook not found or not owned by the current user
	 *
	 * 200: Entries returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/cashbooks/{id}/entries/print')]
	public function getCashbookEntriesPrint(int $id, string $begin, string $end): DataResponse {
		try {
			$utcTimezone = new \DateTimeZone('Utc');
			$dateBegin = (new \DateTimeImmutable($begin))->setTimezone($utcTimezone);
			$dateEnd = (new \DateTimeImmutable($end))->setTimezone($utcTimezone);
		} catch (Exception) {
			throw new OCSBadRequestException('Invalid date param passed');
		}

		try {
			$cashbook = $this->cashbookMapper->getCashbook($id);
		} catch (DoesNotExistException) {
			throw new OCSNotFoundException();
		}
		if ($this->userId !== $cashbook->getOwner()) {
			throw new OCSNotFoundException();
		}

		$entries = $this->entryMapper->getEntriesByDate($cashbook, $dateBegin, $dateEnd);
		/** @var list<Entry> $collapsedEntries */
		$collapsedEntries = [];
		foreach ($entries as $entry) {
			$last = end($collapsedEntries);
			if ($last === false
				|| $entry->getReason() !== EntryReason::IncomeSales
				|| $last->getReason() !== $entry->getReason()
				|| $last->getTaxRaw() !== $entry->getTaxRaw()
				|| $last->getText() !== ''
				|| $entry->getText() !== '') {
				$collapsedEntries[] = $entry;
				continue;
			}

			$last->setAmount(
				$last->getAmount()->plus($entry->getAmount())
			);
			$last->setBalance(
				$last->getBalance()->plus($entry->getAmount())
			);
			$last->setDatetime($entry->getDatetime());
		}

		return new DataResponse(
			array_map(static fn (Entry $entry): array => $entry->jsonSerialize(), $collapsedEntries)
		);
	}

	/**
	 * Delete a cashbook
	 *
	 * @param int $id The id of the cashbook
	 * @return DataResponse<Http::STATUS_OK, list<empty>, array{}>
	 * @throws OCSForbiddenException Cashbook not found or not owned by the current user
	 *
	 * 200: Cashbook deleted
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/cashbooks/{id}')]
	public function deleteCashbook(int $id): DataResponse {
		try {
			$cashbook = $this->cashbookMapper->getCashbook($id);
		} catch (DoesNotExistException) {
			throw new OCSForbiddenException();
		}

		// Only owner is allowed to delete the cashbook
		if ($cashbook->getOwner() !== $this->userId) {
			throw new OCSForbiddenException();
		}

		$this->cashbookMapper->delete($cashbook);
		return new DataResponse([]);
	}

	/**
	 * Create a new cashbook for the current user
	 *
	 * @param string $name The name of the cashbook
	 * @param string $currency The currency of the cashbook (ISO 4217 code)
	 * @param string $account The account the cashbook is kept for
	 * @return DataResponse<Http::STATUS_OK, CashbookCashbook, array{}>
	 * @throws OCSForbiddenException No user is logged in
	 *
	 * 200: Cashbook created
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/cashbooks')]
	public function createCashbook(
		string $name,
		string $currency,
		string $account = '',
	): DataResponse {
		if ($this->userId === null) {
			throw new OCSForbiddenException();
		}

		$cashbook = new Cashbook();
		$cashbook->setName($name);
		$cashbook->setAccount($account);
		$cashbook->setCurrency($currency);
		$cashbook->setOwner($this->userId);
		$cashbook = $this->cashbookMapper->insert($cashbook);
		return new DataResponse($cashbook->jsonSerialize());
	}

	/**
	 * Import a cashbook from uploaded files
	 *
	 * The files need to be uploaded as `files[]` form data.
	 *
	 * @param string $import The class name of the import service to use
	 * @return DataResponse<Http::STATUS_OK, array{id: int}, array{}>
	 * @throws OCSBadRequestException No files uploaded, unsupported import format or invalid files
	 *
	 * 200: Cashbook imported
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/cashbooks/import')]
	public function importCashbook(
		string $import,
	): DataResponse {
		/** @var array{name: list<string>, tmp_name: list<string>, ...}|null $files Uploaded as `files[]`, null if no file was uploaded */
		$files = $this->request->getUploadedFile('files');
		if ($files === null) {
			throw new OCSBadRequestException('No files provided');
		}

		// Now check if the format is valid and exists as an importer
		if (!class_exists($import)) {
			throw new OCSBadRequestException('Unsupported import format');
		}
		$implementedInterfaces = class_implements($import);
		if ($implementedInterfaces === false || !in_array(IImportService::class, $implementedInterfaces)) {
			throw new OCSBadRequestException('Unsupported import format');
		}

		/** @var IImportService $importer */
		$importer = \OCP\Server::get($import);
		try {
			$cashbook = $importer->import($files);
			$this->logger->debug('Successfully imported cashbook {name} ({id})', ['name' => $cashbook->getName(), 'id' => $cashbook->getId()]);
			return new DataResponse(['id' => $cashbook->getId()]);
		} catch (InvalidArgumentException $error) {
			$this->logger->debug('Failed to import cashbook', ['exception' => $error]);
			throw new OCSBadRequestException('Invalid file format');
		}
	}

}
