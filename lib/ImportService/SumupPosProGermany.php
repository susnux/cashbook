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
use OCP\IDBConnection;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

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
	/**
	 * The timezone of the cashbook, the exported dates are in this timezone
	 * @psalm-suppress PropertyNotSetInConstructor Set in prepareCashbook() before it is used
	 */
	protected \DateTimeZone $timezone;

	protected const string FILE_CASHBOOK = 'cashbook';
	protected const string FILE_REPORT = 'report';
	protected const string CSV_ENCLOSURE = '"';
	protected const string CSV_DELIMITER = ';';
	/** Maximum number of rows of an imported CSV file, all rows are imported in a single transaction */
	public const int MAX_ROWS = 100_000;

	/** Columns of the cashbook export read by the importer, all of them are required */
	protected const array CSV_HEADER_MAPPING = [
		'Geschäft' => 'store',
		'Geschäfts ID' => 'storeId',
		'Datum und Uhrzeit' => 'datetime',
		'Grund' => 'reason',
		'Buchungstext' => 'text',
		'Währung' => 'currency',
		'Einnahmen' => 'debit',
		'Ausgaben' => 'credit',
		'Mehrwertsteuersatz' => 'tax',
		'Kassenbestand' => 'total',
	];

	/** Columns of the cashbook export which must not be empty in any row */
	protected const array CSV_VALUE_COLUMNS = ['datetime', 'currency', 'total'];

	/** Columns of the daily closing report read by the importer, all of them are required */
	protected const array CSV_REPORT_HEADER_MAPPING = [
		'Eröffnungsdatum' => 'opening',
		'Datum geschlossen' => 'closing',
		'Znumber' => 'znumber',
		'Anfangskasse' => 'balanceStart',
		'Kassen Endbestand' => 'balanceEnd',
	];

	/** Columns of the daily closing report which must not be empty in any row */
	protected const array CSV_REPORT_VALUE_COLUMNS = ['opening', 'closing', 'balanceStart', 'balanceEnd'];

	public function __construct(
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
		$timezone = $options['timezone'] ?? '';
		$timezone = $timezone === '' ? null : $this->cashbookService->parseTimezone($timezone)->getName();

		$unknownFiles = array_diff(array_keys($files), [self::FILE_CASHBOOK, self::FILE_REPORT]);
		if ($unknownFiles !== []) {
			throw new InvalidArgumentException('Unknown files passed: ' . implode(', ', $unknownFiles));
		}
		if (!isset($files[self::FILE_CASHBOOK])) {
			throw new InvalidArgumentException('No cashbook export passed.');
		}

		$this->cashbookIterator = $this->readCsv(
			$files[self::FILE_CASHBOOK],
			self::CSV_HEADER_MAPPING,
			'The selected cashbook export is not a valid SumUp cashbook export.',
			$this->l10n->t('The selected cashbook export is not a valid SumUp cashbook export.'),
			self::CSV_VALUE_COLUMNS,
			fn (int $row, bool $tooManyFields): InvalidInputException => $tooManyFields
				? new InvalidInputException(
					sprintf('Row %d of the cashbook export has too many columns.', $row),
					$this->l10n->t('Row %s of the cashbook export has too many columns.', [$row]),
				)
				: new InvalidInputException(
					sprintf('Row %d of the cashbook export is incomplete.', $row),
					$this->l10n->t('Row %s of the cashbook export is incomplete.', [$row]),
				),
		);
		$this->reportIterator = !isset($files[self::FILE_REPORT]) ? null : $this->readCsv(
			$files[self::FILE_REPORT],
			self::CSV_REPORT_HEADER_MAPPING,
			'The selected daily closing report is not a valid SumUp daily closing report.',
			$this->l10n->t('The selected daily closing report is not a valid SumUp daily closing report.'),
			self::CSV_REPORT_VALUE_COLUMNS,
			fn (int $row, bool $tooManyFields): InvalidInputException => $tooManyFields
				? new InvalidInputException(
					sprintf('Row %d of the daily closing report has too many columns.', $row),
					$this->l10n->t('Row %s of the daily closing report has too many columns.', [$row]),
				)
				: new InvalidInputException(
					sprintf('Row %d of the daily closing report is incomplete.', $row),
					$this->l10n->t('Row %s of the daily closing report is incomplete.', [$row]),
				),
		);

		if ($this->cashbookIterator->valid() === false) {
			throw new InvalidInputException('The cashbook export does not contain any entries.', $this->l10n->t('The cashbook export does not contain any entries.'));
		}

		try {
			$this->prepareCashbook($timezone);
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
	 * The exported dates have no timezone information, they are parsed in the timezone of the cashbook.
	 *
	 * @param ?string $timezone The validated IANA timezone ID of the cashbook, defaults to the timezone of the user
	 * @throws InvalidInputException If the currency, store name or store ID is invalid
	 */
	protected function prepareCashbook(?string $timezone): void {
		$entry = $this->currentRecord($this->cashbookIterator);
		$currency = $entry['currency'];
		try {
			Money::of('0', $currency);
		} catch (UnknownCurrencyException) {
			throw new InvalidInputException('The cashbook export uses an unknown currency.', $this->l10n->t('The cashbook export uses an unknown currency.'));
		}

		try {
			$this->cashbook = $this->cashbookService->build($this->getUserId(), $entry['store'], $currency, $entry['storeId'], $timezone);
		} catch (InvalidInputException|InvalidArgumentException $error) {
			throw new InvalidInputException('The store name or ID of the cashbook export is invalid.', $this->l10n->t('The store name or ID of the cashbook export is invalid.'), previous: $error);
		}
		$this->timezone = $this->cashbook->getDateTimeZone();
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
	 * @throws InvalidInputException If the currency of an entry differs from the cashbook or its balance does not match or is negative
	 */
	protected function iterateCashbook(?\DateTimeImmutable $limit = null): void {
		while ($this->cashbookIterator->valid()) {
			$record = $this->currentRecord($this->cashbookIterator);
			$datetime = $this->parseDateTime($record['datetime']);

			if (($limit !== null && $datetime >= $limit)) {
				break;
			}

			if ($record['currency'] !== $this->cashbook->getCurrency()) {
				throw new InvalidInputException(
					sprintf('The entry of %s uses a different currency than the other entries.', $record['datetime']),
					$this->l10n->t('The entry of %s uses a different currency than the other entries.', [$record['datetime']]),
				);
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
	 * @param array<string, string> $headerMapping The columns of the file, all of them are required
	 * @param string $invalidMessage The English message if the file is not a CSV file with all columns
	 * @param string $invalidHint The translated hint if the file is not a CSV file with all columns
	 * @param list<string> $valueColumns The internal keys of the columns which must not be empty in any row
	 * @param \Closure(int, bool): InvalidInputException $invalidRow Creates the exception for an invalid row (1-based, including the header),
	 *                                                               the flag is true if the row has more fields than the header
	 * @return Iterator<array-key, CsvRecord>
	 * @throws InvalidInputException If the file is not a CSV file, misses a column or has more than `MAX_ROWS` rows,
	 *                               iterating throws the exception of `$invalidRow` for invalid rows
	 */
	protected function readCsv(string $path, array $headerMapping, string $invalidMessage, string $invalidHint, array $valueColumns, \Closure $invalidRow): Iterator {
		try {
			$csv = Reader::from($path, 'r');
			$csv->setHeaderOffset(0);
			$csv->setDelimiter(self::CSV_DELIMITER);
			$csv->setEnclosure(self::CSV_ENCLOSURE);
			$csvHeader = $csv->getHeader();
			$missingColumns = array_diff(array_keys($headerMapping), $csvHeader);
			if ($missingColumns !== []) {
				$this->logger->debug('The CSV file is missing required columns', ['columns' => $missingColumns]);
				throw new InvalidInputException($invalidMessage, $invalidHint);
			}

			if (count($csv) > self::MAX_ROWS) {
				throw new InvalidInputException(
					sprintf('The selected files contain more than %d rows.', self::MAX_ROWS),
					$this->l10n->t('The selected files contain more than %s rows. Export a shorter period.', [self::MAX_ROWS]),
				);
			}

			$header = array_values(array_map(static fn (string $name): string => $headerMapping[$name] ?? $name, $csvHeader));
			if ($header !== array_unique($header)) {
				$this->logger->debug('The CSV file contains duplicate columns after mapping its header', ['header' => $header]);
				throw new InvalidInputException($invalidMessage, $invalidHint);
			}

			// League CSV drops the fields after the last header column, so the records are combined with the header by combineRecords()
			$csv->setHeaderOffset(null);
			/** @var Iterator<int, list<string>> $records */
			$records = $csv->getRecords();
			$records = $this->combineRecords($records, $header, array_values($headerMapping), $valueColumns, $invalidRow);
			$records->rewind();
			return $records;
		} catch (CsvException $error) {
			throw new InvalidInputException($invalidMessage, $invalidHint, previous: $error);
		}
	}

	/**
	 * Combine the records of a CSV file with its header and ensure they have all required values.
	 *
	 * Fields missing at the end of a row count as missing values, empty fields after the last header column are ignored.
	 *
	 * @param Iterator<int, list<string>> $records Records keyed by their offset in the file, the header has offset 0
	 * @param list<string> $header The internal keys of the columns
	 * @param list<string> $requiredColumns The internal keys of the columns every row must contain
	 * @param list<string> $valueColumns The internal keys of the columns which must not be empty
	 * @param \Closure(int, bool): InvalidInputException $invalidRow
	 * @return \Generator<int, CsvRecord>
	 * @throws InvalidInputException If a record misses a required value or has more fields than the header
	 */
	private function combineRecords(Iterator $records, array $header, array $requiredColumns, array $valueColumns, \Closure $invalidRow): \Generator {
		$columnCount = count($header);
		foreach ($records as $offset => $fields) {
			if ($offset === 0) {
				continue;
			}

			$extraFields = array_slice($fields, $columnCount);
			if (array_filter($extraFields, static fn (string $field): bool => trim($field) !== '') !== []) {
				throw $invalidRow($offset + 1, true);
			}

			$record = array_combine($header, array_pad(array_slice($fields, 0, $columnCount), $columnCount, null));
			foreach ($requiredColumns as $column) {
				$value = $record[$column];
				if ($value === null || (trim($value) === '' && in_array($column, $valueColumns, true))) {
					throw $invalidRow($offset + 1, false);
				}
			}
			/** @var CsvRecord $record */
			yield $offset => $record;
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
