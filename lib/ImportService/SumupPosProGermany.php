<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2025 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cashbook\ImportService;

use Brick\Math\BigRational;
use Brick\Money\Exception\UnknownCurrencyException;
use Brick\Money\Money;
use Exception;
use InvalidArgumentException;
use Iterator;
use League\Csv\Exception as CsvException;
use League\Csv\Reader;
use OCA\Cashbook\Db\Cashbook;
use OCA\Cashbook\Db\CashbookMapper;
use OCA\Cashbook\Db\Entry;
use OCA\Cashbook\Db\EntryMapper;
use OCA\Cashbook\Db\EntryReason;
use OCA\Cashbook\Exception\InvalidInputException;
use OCA\Cashbook\Service\CashbookService;
use OCP\AppFramework\Db\TTransactional;
use OCP\IDateTimeZone;
use OCP\IDBConnection;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use UnexpectedValueException;

/**
 * Allow to import the cashbook of the SumUp POS Pro (German version)
 *
 * @psalm-type CsvRecord = array<string, string>
 * @psalm-api Instantiated by the import API
 */
final class SumupPosProGermany implements IImportService {
	use TTransactional;

	/** @var ?Iterator<array-key, CsvRecord> */
	protected ?Iterator $reportIterator = null;
	/**
	 * @var Iterator<array-key, CsvRecord>
	 * @psalm-suppress PropertyNotSetInConstructor Set at the beginning of import()
	 */
	protected Iterator $cashbookIterator;
	/** @psalm-suppress PropertyNotSetInConstructor Set in import() before it is used */
	protected Cashbook $cashbook;
	/** The cash balance after the last imported entry, null before the first entry */
	protected ?Money $balance = null;
	/** The sequence number of the last imported entry */
	protected int $sequence = 0;
	/** @psalm-suppress PropertyNotSetInConstructor Set at the beginning of import() */
	protected \DateTimeZone $timezone;

	protected const string FILE_CASHBOOK = 'cashbook';
	protected const string FILE_REPORT = 'report';
	protected const string CSV_ENCLOSURE = '"';
	protected const string CSV_DELIMITER = ';';
	/** Maximum number of rows of an imported CSV file, all rows are imported in a single transaction */
	public const int MAX_ROWS = 100_000;

	protected const array CSV_HEADER_MAPPING = [
		'Geschäft' => 'store',
		'Geschäfts ID' => 'storeId',
		'Datum und Uhrzeit' => 'datetime',
		'Belegnummer' => 'reference',
		'Grund' => 'reason',
		'Buchungstext' => 'text',
		'Währung' => 'currency',
		'Einnahmen' => 'debit',
		'Ausgaben' => 'credit',
		'Mehrwertsteuersatz' => 'tax',
		'Betrag MwSt' => 'taxSum',
		'Kassenbestand' => 'total',
	];

	/** The columns of the cashbook export read by the importer */
	protected const array CSV_REQUIRED_COLUMNS = ['store', 'storeId', 'datetime', 'reason', 'text', 'currency', 'debit', 'credit', 'tax', 'total'];

	protected const array CSV_REPORT_HEADER_MAPPING = [
		'Eröffnungsdatum' => 'opening',
		'Datum geschlossen' => 'closing',
		'Znumber' => 'znumber',
		'Anfangskasse' => 'balanceStart',
		'Kassen Endbestand' => 'balanceEnd',
	];

	public function __construct(
		protected IDateTimeZone $timeZoneFactory,
		protected ?string $userId,
		protected LoggerInterface $logger,
		protected IL10N $l10n,
		protected IDBConnection $db,
		protected CashbookMapper $cashbookMapper,
		protected EntryMapper $entryMapper,
		protected CashbookService $cashbookService,
	) {
	}

	/**
	 * @param array<string, string> $files The cashbook export as `cashbook` and optionally the daily closing report as `report`
	 * @param array{timezone?: string} $options
	 */
	#[\Override]
	public function import(array $files, array $options = []): Cashbook {
		// The service is shared by the container, so nothing of a previous import must be left
		$this->reportIterator = null;
		$this->balance = null;
		$this->sequence = 0;
		$this->timezone = $this->timeZoneFactory->getTimeZone();

		// The exported dates have no timezone information, so this needs to be set before anything is parsed
		if (isset($options['timezone']) && $options['timezone'] !== '') {
			try {
				$this->timezone = new \DateTimeZone($options['timezone']);
			} catch (Exception $error) {
				throw new InvalidArgumentException('Invalid timezone option passed.', previous: $error);
			}
		}

		$unknownFiles = array_diff(array_keys($files), [self::FILE_CASHBOOK, self::FILE_REPORT]);
		if ($unknownFiles !== []) {
			throw new InvalidArgumentException('Unknown files passed: ' . implode(', ', $unknownFiles));
		}
		if (!isset($files[self::FILE_CASHBOOK])) {
			throw new InvalidArgumentException('No cashbook export passed.');
		}

		try {
			$this->cashbookIterator = $this->readCsv($files[self::FILE_CASHBOOK], self::CSV_HEADER_MAPPING, self::CSV_REQUIRED_COLUMNS);
		} catch (UnexpectedValueException $error) {
			throw new InvalidInputException(
				'The selected cashbook export is not a valid SumUp cashbook export.',
				$this->l10n->t('The selected cashbook export is not a valid SumUp cashbook export.'),
				previous: $error,
			);
		}

		if (isset($files[self::FILE_REPORT])) {
			try {
				$this->reportIterator = $this->readCsv($files[self::FILE_REPORT], self::CSV_REPORT_HEADER_MAPPING, array_values(self::CSV_REPORT_HEADER_MAPPING));
			} catch (UnexpectedValueException $error) {
				throw new InvalidInputException(
					'The selected daily closing report is not a valid SumUp daily closing report.',
					$this->l10n->t('The selected daily closing report is not a valid SumUp daily closing report.'),
					previous: $error,
				);
			}
		}

		if ($this->cashbookIterator->valid() === false) {
			throw new InvalidInputException('The cashbook export does not contain any entries.', $this->l10n->t('The cashbook export does not contain any entries.'));
		}

		try {
			$this->prepareCashbook();
			$this->atomic(function (): void {
				$this->cashbookMapper->insert($this->cashbook);
				if ($this->reportIterator !== null) {
					$this->iterateReport();
				}
				$this->iterateCashbook();
			}, $this->db);
		} catch (InvalidInputException $error) {
			throw $error;
		} catch (Exception $error) {
			throw new InvalidInputException('The cashbook export contains invalid values.', $this->l10n->t('The cashbook export contains invalid values.'), previous: $error);
		}
		return $this->cashbook;
	}

	/**
	 * Create the cashbook from the first entry of the cashbook export.
	 *
	 * @throws InvalidInputException If the currency, store name or store ID is invalid
	 */
	protected function prepareCashbook(): void {
		$entry = $this->currentRecord($this->cashbookIterator);
		$currency = $entry['currency'];
		try {
			Money::of('0', $currency);
		} catch (UnknownCurrencyException) {
			throw new InvalidInputException('The cashbook export uses an unknown currency.', $this->l10n->t('The cashbook export uses an unknown currency.'));
		}

		try {
			$this->cashbook = $this->cashbookService->build($this->getUserId(), $entry['store'], $currency, $entry['storeId']);
		} catch (InvalidInputException|InvalidArgumentException $error) {
			throw new InvalidInputException('The store name or ID of the cashbook export is invalid.', $this->l10n->t('The store name or ID of the cashbook export is invalid.'), previous: $error);
		}
	}

	/**
	 * Import the days of the report including the cashbook entries before and within each day.
	 *
	 * @throws InvalidInputException If the balance of a day opening or closing does not match the cashbook export
	 */
	protected function iterateReport(): void {
		if ($this->reportIterator === null) {
			return;
		}

		$reportIterator = $this->reportIterator;
		while ($reportIterator->valid()) {
			$record = $this->currentRecord($reportIterator);
			$opening = $this->parseDateTime($record['opening']);
			$openingBalance = $this->parseMoney($record['balanceStart']);
			$closing = $this->parseDateTime($record['closing']);
			$closingBalance = $this->parseMoney($record['balanceEnd']);

			// Entries before the day, e.g. before the first day of the report or between two days
			$this->iterateCashbook($opening);
			if ($this->balance !== null && !$this->balance->isEqualTo($openingBalance)) {
				$this->logBalanceMismatch($record['opening'], $this->balance, $openingBalance);
				throw new InvalidInputException(
					sprintf('The cash balance at the day opening of %s does not match the cashbook export.', $record['opening']),
					$this->l10n->t('The cash balance at the day opening of %s does not match the cashbook export.', [$record['opening']]),
				);
			}

			$this->entryMapper->insert(Entry::create(
				$this->cashbook,
				++$this->sequence,
				$this->getUserId(),
				$opening,
				'',
				EntryReason::DayOpening,
				Money::of('0', $this->cashbook->getCurrency()),
				$openingBalance,
			));
			$this->balance = $openingBalance;

			$this->iterateCashbook($closing);
			if ($this->balance?->isEqualTo($closingBalance) !== true) {
				$this->logBalanceMismatch($record['closing'], $this->balance, $closingBalance);
				throw new InvalidInputException(
					sprintf('The cash balance at the day closing of %s does not match the cashbook export.', $record['closing']),
					$this->l10n->t('The cash balance at the day closing of %s does not match the cashbook export.', [$record['closing']]),
				);
			}

			$this->entryMapper->insert(Entry::create(
				$this->cashbook,
				++$this->sequence,
				$this->getUserId(),
				$closing,
				'',
				EntryReason::DayClosing,
				Money::of('0', $this->cashbook->getCurrency()),
				$closingBalance,
				reference: $record['znumber'],
			));

			$reportIterator->next();
		}
	}

	/**
	 * Import the cashbook entries until the given limit is reached.
	 *
	 * Every entry is checked against the balance of the previous entry, the balance before the first entry is not known.
	 *
	 * @param ?\DateTimeImmutable $limit Stop before the first entry at or after this date (null to import all remaining entries)
	 * @throws InvalidInputException If the balance of an entry does not match or is negative
	 */
	protected function iterateCashbook(?\DateTimeImmutable $limit = null): void {
		while ($this->cashbookIterator->valid()) {
			$record = $this->currentRecord($this->cashbookIterator);
			$datetime = $this->parseDateTime($record['datetime']);

			if (($limit !== null && $datetime >= $limit)) {
				break;
			}

			$amount = $this->parseRecordAmount($record);
			$balance = $this->parseMoney($record['total']);
			if ($this->balance !== null && !$this->balance->plus($amount)->isEqualTo($balance)) {
				$this->logBalanceMismatch($record['datetime'], $this->balance->plus($amount), $balance);
				throw new InvalidInputException(
					sprintf('The cash balance of the entry of %s does not match the previous cash balance.', $record['datetime']),
					$this->l10n->t('The cash balance of the entry of %s does not match the previous cash balance.', [$record['datetime']]),
				);
			}
			if ($balance->isNegative()) {
				throw new InvalidInputException(
					sprintf('The cash balance is negative after the entry of %s.', $record['datetime']),
					$this->l10n->t('The cash balance is negative after the entry of %s.', [$record['datetime']]),
				);
			}

			$this->entryMapper->insert(Entry::create(
				$this->cashbook,
				++$this->sequence,
				$this->getUserId(),
				$datetime,
				$record['text'],
				$this->mapReason($record['reason']),
				$amount,
				$balance,
				$this->parseTax($record['tax']),
			));
			$this->balance = $balance;

			$this->cashbookIterator->next();
		}
	}

	/**
	 * @param ?Money $calculated The balance calculated from the imported entries
	 * @param Money $exported The balance stated by the export
	 */
	private function logBalanceMismatch(string $date, ?Money $calculated, Money $exported): void {
		$this->logger->debug('Mismatch of the cash balance in the cashbook export', [
			'date' => $date,
			'calculated' => $calculated === null ? 'unknown' : (string)$calculated,
			'exported' => (string)$exported,
		]);
	}

	/**
	 * Read a CSV file and map its localized header to the internal keys.
	 *
	 * @param array<string, string> $headerMapping
	 * @param list<string> $requiredColumns The internal keys of the columns the file needs to contain
	 * @return Iterator<array-key, CsvRecord>
	 * @throws UnexpectedValueException If the file is not a CSV file or misses a required column
	 * @throws InvalidInputException If the file has more than `MAX_ROWS` rows
	 */
	protected function readCsv(string $path, array $headerMapping, array $requiredColumns): Iterator {
		try {
			$csv = Reader::from($path, 'r');
			$csv->setHeaderOffset(0);
			$csv->setDelimiter(self::CSV_DELIMITER);
			$csv->setEnclosure(self::CSV_ENCLOSURE);
			$header = array_map(static fn (string $name): string => $headerMapping[$name] ?? $name, $csv->getHeader());

			$missingColumns = array_diff($requiredColumns, $header);
			if ($missingColumns !== []) {
				throw new UnexpectedValueException('The CSV file is missing the columns: ' . implode(', ', $missingColumns));
			}

			if (count($csv) > self::MAX_ROWS) {
				throw new InvalidInputException(
					sprintf('The selected files contain more than %d rows.', self::MAX_ROWS),
					$this->l10n->t('The selected files contain more than %s rows. Export a shorter period.', [self::MAX_ROWS]),
				);
			}

			/** @var Iterator<array-key, CsvRecord> $records */
			$records = $csv->getRecords($header);
			$records->rewind();
			return $records;
		} catch (CsvException $error) {
			throw new UnexpectedValueException('The file is not a valid CSV file.', previous: $error);
		}
	}

	/**
	 * Get the id of the user the cashbook is imported for.
	 *
	 * @throws \RuntimeException If no user is logged in
	 */
	protected function getUserId(): string {
		if ($this->userId === null) {
			throw new \RuntimeException('Cannot import a cashbook without a user.');
		}
		return $this->userId;
	}

	/**
	 * Normalize a German formatted number (e.g. `1.234,56`) to the plain decimal format (`1234.56`).
	 */
	protected function normalizeNumber(string $number): string {
		$number = trim($number);
		if (str_contains($number, ',')) {
			return str_replace(['.', ','], ['', '.'], $number);
		}
		return $number;
	}

	/**
	 * Get the current record of a CSV iterator.
	 *
	 * @param Iterator<array-key, CsvRecord> $iterator
	 * @return CsvRecord
	 * @throws InvalidInputException If there is no current record
	 */
	protected function currentRecord(Iterator $iterator): array {
		$record = $iterator->current();
		if ($record === null) {
			throw new InvalidInputException('Unexpected end of the CSV file.', $this->l10n->t('Unexpected end of the CSV file.'));
		}
		return $record;
	}

	/**
	 * Get the signed amount of a cashbook record.
	 *
	 * Expenses are subtracted regardless of whether the export lists them as negative or positive numbers.
	 *
	 * @param CsvRecord $record
	 */
	protected function parseRecordAmount(array $record): Money {
		return $this->parseAmount($record['debit'])
			->minus($this->parseAmount($record['credit'])->abs());
	}

	/**
	 * Get the Money value of an optional CSV amount (empty values are zero).
	 */
	protected function parseAmount(string $value): Money {
		return $this->parseMoney(trim($value) === '' ? '0' : $value);
	}

	/**
	 * Get the Money value of a CSV string value.
	 */
	protected function parseMoney(string $value): Money {
		return Money::of($this->normalizeNumber($value), $this->cashbook->getCurrency());
	}

	/**
	 * Get the tax rate of a CSV tax value in percent (empty values are no tax).
	 */
	protected function parseTax(string $value): ?BigRational {
		$value = rtrim(trim($value), '% ');
		if ($value === '') {
			return null;
		}
		return BigRational::of($this->normalizeNumber($value))->dividedBy('100');
	}

	protected function parseDateTime(string $value): \DateTimeImmutable {
		$parsed = new \DateTimeImmutable($value, $this->timezone);
		return $parsed->setTimezone(new \DateTimeZone('UTC'));
	}

	/**
	 * Mapping from string value of the CSV to the `EntryReason` enum.
	 */
	protected function mapReason(string $reason): ?EntryReason {
		$mapped = match($reason) {
			'Einkauf von Material und Waren' => EntryReason::ExpenseGoods,
			'Trinkgeldauszahlung' => EntryReason::ExpenseTips,
			'Erstattung' => EntryReason::ExpenseRefund,
			'Wechselgeld' => EntryReason::ExpenseChange,
			'Andere Ausgaben' => EntryReason::ExpenseMisc,
			'Gehälter / Löhne' => EntryReason::ExpenseSalary,

			'Andere Einnahmen' => EntryReason::IncomeMisc,
			'Sonstige Einnahme' => EntryReason::IncomeSales,

			'Transfer von Kasse zur Bank' => EntryReason::ExpenseBankTransfer,
			'Transfer von Bank zur Kasse' => EntryReason::IncomeBankTransfer,

			'Änderungseintrag' => EntryReason::Correction,

			default => null,
		};

		if ($mapped === null) {
			$this->logger->warning('Unknown SumUp cashbook reason "{reason}", importing the entry without a reason', ['reason' => $reason]);
		}
		return $mapped;
	}
}
