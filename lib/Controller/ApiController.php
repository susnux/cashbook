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
use OCA\Cashbook\Exception\InvalidInputException;
use OCA\Cashbook\ImportService\IImportService;
use OCA\Cashbook\ImportService\ImportFormat;
use OCA\Cashbook\ResponseDefinitions;
use OCA\Cashbook\Service\CashbookService;
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
use Psr\Container\ContainerInterface;
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
		protected ContainerInterface $container,
		protected LoggerInterface $logger,
		protected CashbookMapper $cashbookMapper,
		protected EntryMapper $entryMapper,
		protected CashbookService $cashbookService,
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
	 * If the range starts or ends within an open day, the whole day is included.
	 *
	 * @param int $id The id of the cashbook
	 * @param string $begin Start of the date range (ISO 8601 date time, inclusive)
	 * @param string $end End of the date range (ISO 8601 date time, exclusive)
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
				|| ($last->getText() ?? '') !== ''
				|| ($entry->getText() ?? '') !== '') {
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
	 * Delete a cashbook including all of its entries
	 *
	 * @param int $id The id of the cashbook
	 * @return DataResponse<Http::STATUS_OK, list<empty>, array{}>
	 * @throws OCSNotFoundException Cashbook not found or not owned by the current user
	 *
	 * 200: Cashbook deleted
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/cashbooks/{id}')]
	public function deleteCashbook(int $id): DataResponse {
		try {
			$cashbook = $this->cashbookMapper->getCashbook($id);
		} catch (DoesNotExistException) {
			throw new OCSNotFoundException();
		}

		// Only owner is allowed to delete the cashbook
		if ($cashbook->getOwner() !== $this->userId) {
			throw new OCSNotFoundException();
		}

		$this->cashbookService->delete($cashbook);
		return new DataResponse([]);
	}

	/**
	 * Create a new cashbook for the current user
	 *
	 * @param string $name The name of the cashbook (1 to 255 characters)
	 * @param string $currency The currency of the cashbook (ISO 4217 code)
	 * @param string $account The account the cashbook is kept for (up to 255 characters)
	 * @return DataResponse<Http::STATUS_OK, CashbookCashbook, array{}>
	 * @throws OCSBadRequestException Invalid name, account or currency
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

		try {
			$cashbook = $this->cashbookService->create($this->userId, $name, $currency, $account);
		} catch (InvalidInputException $error) {
			throw new OCSBadRequestException($error->getHint());
		} catch (InvalidArgumentException $error) {
			throw new OCSBadRequestException($error->getMessage());
		}
		return new DataResponse($cashbook->jsonSerialize());
	}

	/**
	 * Import a cashbook from uploaded files
	 *
	 * The files need to be uploaded as `files[]` form data.
	 *
	 * @param ImportFormat $import The import format to use
	 * @param ?string $timezone The timezone of the dates in the uploaded files (defaults to the timezone of the user)
	 * @return DataResponse<Http::STATUS_OK, array{id: int}, array{}>
	 * @throws OCSBadRequestException No files uploaded, unsupported import format or invalid files
	 *
	 * 200: Cashbook imported
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/cashbooks/import')]
	public function importCashbook(
		ImportFormat $import,
		?string $timezone = null,
	): DataResponse {
		/** @var array{name: list<string>, tmp_name: list<string>, ...}|null $files Uploaded as `files[]`, null if no file was uploaded */
		$files = $this->request->getUploadedFile('files');
		if ($files === null) {
			throw new OCSBadRequestException('No files provided');
		}

		/** @var IImportService $importer */
		$importer = $this->container->get($import->getImportService());
		try {
			$cashbook = $importer->import($files, $timezone === null ? [] : ['timezone' => $timezone]);
			$this->logger->debug('Successfully imported cashbook {name} ({id})', ['name' => $cashbook->getName(), 'id' => $cashbook->getId()]);
			return new DataResponse(['id' => $cashbook->getId()]);
		} catch (InvalidInputException $error) {
			$this->logger->debug('Failed to import cashbook', ['exception' => $error]);
			throw new OCSBadRequestException($error->getHint());
		} catch (InvalidArgumentException $error) {
			throw new OCSBadRequestException($error->getMessage());
		}
	}

}
