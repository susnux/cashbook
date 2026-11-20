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
use OCA\Cashbook\Exception\ConflictException;
use OCA\Cashbook\Exception\InvalidInputException;
use OCA\Cashbook\ImportService\IImportService;
use OCA\Cashbook\ImportService\ImportFormat;
use OCA\Cashbook\ResponseDefinitions;
use OCA\Cashbook\Service\CashbookService;
use OCA\Cashbook\Service\EntryService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\AppFramework\OCSController;
use OCP\IL10N;
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

	/** Maximum number of entries loaded by `getCashbookEntriesPrint()`, limits the memory used by a request */
	public const int MAX_PRINT_ENTRIES = 20_000;

	public function __construct(
		IRequest $request,
		protected ContainerInterface $container,
		protected LoggerInterface $logger,
		protected CashbookMapper $cashbookMapper,
		protected EntryMapper $entryMapper,
		protected CashbookService $cashbookService,
		protected EntryService $entryService,
		protected IL10N $l10n,
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
	 * @param string $id The id of the cashbook
	 * @return DataResponse<Http::STATUS_OK, CashbookCashbook, array{}>
	 * @throws OCSNotFoundException Cashbook not found or not owned by the current user
	 *
	 * 200: Cashbook returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/cashbooks/{id}', requirements: ['id' => '\d+'])]
	public function getCashbook(string $id): DataResponse {
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
	 * @param string $id The id of the cashbook
	 * @param int<0, max> $offset Number of entries to skip
	 * @param int<1, 50> $limit Maximum number of entries to return
	 * @return DataResponse<Http::STATUS_OK, list<CashbookEntry>, array{}>
	 * @throws OCSBadRequestException The offset or limit is out of range
	 * @throws OCSNotFoundException Cashbook not found or not owned by the current user
	 *
	 * 200: Entries returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/cashbooks/{id}/entries', requirements: ['id' => '\d+'])]
	public function getCashbookEntries(string $id, int $offset = 0, int $limit = 20): DataResponse {
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
	 * Add an income or expense entry to a cashbook
	 *
	 * The entry is booked after the last entry of the cashbook at the current time.
	 * Entries can not be changed afterwards, mistakes need to be corrected with a new entry.
	 * If the cashbook was changed since `lastSequence` was loaded, the entry is rejected with the current cashbook.
	 *
	 * @param string $id The id of the cashbook
	 * @param int<0, max> $lastSequence The sequence number of the last entry of the cashbook, 0 if it has no entries
	 * @param EntryReason $reason The reason of the entry, only income and expense reasons are allowed
	 * @param string $amount The positive amount as decimal string (e.g. `"12.50"`), expenses are subtracted from the balance
	 * @param string $text The posting text (up to 1000 characters)
	 * @param ?float $tax The tax rate included in the amount (e.g. `0.19` for 19%)
	 * @param ?string $reference The receipt number (up to 127 characters)
	 * @param ?string $referenceDate The date of the receipt (`YYYY-MM-DD`)
	 * @return DataResponse<Http::STATUS_OK, CashbookEntry, array{}>|DataResponse<Http::STATUS_CONFLICT, CashbookCashbook, array{}>
	 * @throws OCSBadRequestException Invalid values or the expense exceeds the cash balance
	 * @throws OCSNotFoundException Cashbook not found or not owned by the current user
	 *
	 * 200: Entry added
	 * 409: The last entry is not `lastSequence`, the current cashbook is returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/cashbooks/{id}/entries', requirements: ['id' => '\d+'])]
	public function addCashbookEntry(
		string $id,
		int $lastSequence,
		EntryReason $reason,
		string $amount,
		string $text = '',
		?float $tax = null,
		?string $reference = null,
		?string $referenceDate = null,
	): DataResponse {
		try {
			$cashbook = $this->cashbookMapper->getCashbook($id);
		} catch (DoesNotExistException) {
			throw new OCSNotFoundException();
		}
		if ($this->userId === null || $this->userId !== $cashbook->getOwner()) {
			throw new OCSNotFoundException();
		}

		try {
			$entry = $this->entryService->add($cashbook, $this->userId, $lastSequence, $reason, $amount, $text, $tax, $reference, $referenceDate);
		} catch (ConflictException) {
			return new DataResponse($this->cashbookMapper->getCashbook($id)->jsonSerialize(), Http::STATUS_CONFLICT);
		} catch (InvalidInputException $error) {
			throw new OCSBadRequestException($error->getHint());
		} catch (InvalidArgumentException $error) {
			throw new OCSBadRequestException($error->getMessage());
		}
		return new DataResponse($entry->jsonSerialize());
	}

	/**
	 * Get all entries of a cashbook within a date range, prepared for printing
	 *
	 * If the range starts or ends within an open day, the whole day is included.
	 * Dates without a time (`YYYY-MM-DD`) are the start of that day in the timezone of the cashbook.
	 *
	 * @param string $id The id of the cashbook
	 * @param string $begin Start of the date range (ISO 8601 date or date time, inclusive)
	 * @param string $end End of the date range (ISO 8601 date or date time, exclusive)
	 * @return DataResponse<Http::STATUS_OK, list<CashbookEntry>, array{}>
	 * @throws OCSBadRequestException Invalid date passed or the range contains too many entries
	 * @throws OCSNotFoundException Cashbook not found or not owned by the current user
	 *
	 * 200: Entries returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/cashbooks/{id}/entries/print', requirements: ['id' => '\d+'])]
	public function getCashbookEntriesPrint(string $id, string $begin, string $end): DataResponse {
		try {
			$cashbook = $this->cashbookMapper->getCashbook($id);
		} catch (DoesNotExistException) {
			throw new OCSNotFoundException();
		}
		if ($this->userId !== $cashbook->getOwner()) {
			throw new OCSNotFoundException();
		}

		try {
			$timezone = $cashbook->getDateTimeZone();
			$utcTimezone = new \DateTimeZone('UTC');
			$dateBegin = (new \DateTimeImmutable($begin, $timezone))->setTimezone($utcTimezone);
			$dateEnd = (new \DateTimeImmutable($end, $timezone))->setTimezone($utcTimezone);
		} catch (Exception) {
			throw new OCSBadRequestException('Invalid date param passed');
		}

		$entries = $this->entryMapper->getEntriesByDate($cashbook, $dateBegin, $dateEnd, self::MAX_PRINT_ENTRIES + 1);
		if (count($entries) > self::MAX_PRINT_ENTRIES) {
			throw new OCSBadRequestException($this->l10n->t('The period contains more than %s entries. Choose a shorter period.', [self::MAX_PRINT_ENTRIES]));
		}

		return new DataResponse(
			array_map(static fn (Entry $entry): array => $entry->jsonSerialize(), $entries)
		);
	}

	/**
	 * Delete a cashbook including all of its entries
	 *
	 * @param string $id The id of the cashbook
	 * @return DataResponse<Http::STATUS_OK, list<empty>, array{}>
	 * @throws OCSNotFoundException Cashbook not found or not owned by the current user
	 *
	 * 200: Cashbook deleted
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/cashbooks/{id}', requirements: ['id' => '\d+'])]
	public function deleteCashbook(string $id): DataResponse {
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
	 * @param ?string $timezone The timezone of the cashbook (IANA timezone ID, defaults to the timezone of the user)
	 * @return DataResponse<Http::STATUS_OK, CashbookCashbook, array{}>
	 * @throws OCSBadRequestException Invalid name, account, currency or timezone
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
		?string $timezone = null,
	): DataResponse {
		if ($this->userId === null) {
			throw new OCSForbiddenException();
		}

		try {
			$cashbook = $this->cashbookService->create($this->userId, $name, $currency, $account, $timezone);
		} catch (InvalidInputException $error) {
			throw new OCSBadRequestException($error->getHint());
		} catch (InvalidArgumentException $error) {
			throw new OCSBadRequestException($error->getMessage());
		}
		return new DataResponse($cashbook->jsonSerialize());
	}

	/**
	 * Change the name and account of a cashbook
	 *
	 * The currency and timezone can not be changed.
	 *
	 * @param string $id The id of the cashbook
	 * @param string $name The new name of the cashbook (1 to 255 characters)
	 * @param string $account The new account the cashbook is kept for (up to 255 characters)
	 * @return DataResponse<Http::STATUS_OK, CashbookCashbook, array{}>
	 * @throws OCSBadRequestException Invalid name or account
	 * @throws OCSNotFoundException Cashbook not found or not owned by the current user
	 *
	 * 200: Cashbook updated
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/cashbooks/{id}', requirements: ['id' => '\d+'])]
	public function updateCashbook(
		string $id,
		string $name,
		string $account = '',
	): DataResponse {
		try {
			$cashbook = $this->cashbookMapper->getCashbook($id);
		} catch (DoesNotExistException) {
			throw new OCSNotFoundException();
		}
		if ($cashbook->getOwner() !== $this->userId) {
			throw new OCSNotFoundException();
		}

		try {
			$cashbook = $this->cashbookService->update($cashbook, $name, $account);
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
	 * The files need to be uploaded as `files[<name>]` form data, the names depend on the import format.
	 * The `sumup-pos-pro-de` format expects the cashbook export as `files[cashbook]`
	 * and optionally the daily closing report as `files[report]`.
	 *
	 * @param ImportFormat $import The import format to use
	 * @param ?string $timezone The timezone of the dates in the uploaded files and of the cashbook (IANA timezone ID, defaults to the timezone of the user)
	 * @return DataResponse<Http::STATUS_OK, array{id: string}, array{}>
	 * @throws OCSBadRequestException No files uploaded, unsupported import format, invalid files or timezone
	 *
	 * 200: Cashbook imported
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/cashbooks/import')]
	public function importCashbook(
		ImportFormat $import,
		?string $timezone = null,
	): DataResponse {
		/** @var IImportService $importer */
		$importer = $this->container->get($import->getImportService());
		try {
			$files = $this->getUploadedFiles('files');
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

	/**
	 * Get the successfully uploaded files of a field with named files (`files[<name>]`), empty file inputs are skipped.
	 *
	 * @return array<string, string> The paths of the uploaded files by their name
	 * @throws InvalidArgumentException If no file was uploaded or the files are not named
	 * @throws InvalidInputException If an upload failed
	 */
	private function getUploadedFiles(string $field): array {
		$upload = $this->request->getUploadedFile($field);
		if (!is_array($upload) || !isset($upload['tmp_name'], $upload['error'])) {
			throw new InvalidArgumentException('No files provided');
		}

		$tmpNames = is_array($upload['tmp_name']) ? $upload['tmp_name'] : null;
		$errors = is_array($upload['error']) ? $upload['error'] : null;
		$uploads = [];
		if ($tmpNames !== null && $errors !== null && array_keys($tmpNames) === array_keys($errors)) {
			foreach (array_keys($errors) as $name) {
				if (is_string($name) && is_string($tmpNames[$name]) && is_int($errors[$name])) {
					$uploads[$name] = ['tmpName' => $tmpNames[$name], 'error' => $errors[$name]];
				}
			}
		}
		if ($errors === null || count($uploads) !== count($errors)) {
			throw new InvalidArgumentException(sprintf('Files need to be uploaded as %s[<name>]', $field));
		}

		$files = [];
		foreach ($uploads as $name => ['tmpName' => $tmpName, 'error' => $error]) {
			if ($error === UPLOAD_ERR_NO_FILE) {
				continue;
			}
			if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
				throw new InvalidInputException('The uploaded file is too large.', $this->l10n->t('The uploaded file is too large.'));
			}
			if ($error !== UPLOAD_ERR_OK) {
				throw new InvalidInputException('The upload of the files failed.', $this->l10n->t('The upload of the files failed.'));
			}
			$files[$name] = $tmpName;
		}

		if ($files === []) {
			throw new InvalidArgumentException('No files provided');
		}
		return $files;
	}
}
