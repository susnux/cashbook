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
	protected \DateTimeZone $timezone;

	protected const string CASHBOOK_FILE_PREFIX = 'Kassenbuch_';
	protected const string CSV_ENCLOSURE = '"';
	protected const string CSV_DELIMITER = ';';

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

	protected const array CSV_REPORT_HEADER_MAPPING = [
		'Eröffnungsdatum' => 'opening',
		'Datum geschlossen' => 'closing',
		'Znumber' => 'znumber',
		'Anfangskasse' => 'balanceStart',
		'Kassen Endbestand' => 'balanceEnd',
	];

	public function __construct(
		IDateTimeZone $timeZoneFactory,
		protected ?string $userId,
		protected LoggerInterface $logger,
		protected IL10N $l10n,
		protected IDBConnection $db,
		protected CashbookMapper $cashbookMapper,
		protected EntryMapper $entryMapper,
		protected CashbookService $cashbookService,
	) {
		$this->timezone = $timeZoneFactory->getTimeZone();
	}

	/**
	 * @param array{name: list<string>, tmp_name: list<string>, ...} $files
	 * @param array{timezone?: string} $options
	 */
	#[\Override]
	public function import(array $files, array $options = []): Cashbook {
		// The exported dates have no timezone information, so this needs to be set before anything is parsed
		if (isset($options['timezone']) && $options['timezone'] !== '') {
			try {
				$this->timezone = new \DateTimeZone($options['timezone']);
			} catch (Exception $error) {
				throw new InvalidArgumentException('Invalid timezone option passed.', previous: $error);
			}
		}

		$cashbookCsvFile = '';
		$reportCsvFile = '';
		foreach ($files['name'] as $index => $name) {
			if (str_starts_with($name, self::CASHBOOK_FILE_PREFIX)) {
				$cashbookCsvFile = $files['tmp_name'][$index];
			} else {
				$reportCsvFile = $files['tmp_name'][$index];
			}
		}

		if ($cashbookCsvFile === '') {
			throw new InvalidInputException('No cashbook export (Kassenbuch_….csv) was selected.', $this->l10n->t('No cashbook export (Kassenbuch_….csv) was selected.'));
		}

		try {
			$this->cashbookIterator = $this->readCsv($cashbookCsvFile, self::CSV_HEADER_MAPPING);
			$this->reportIterator = $reportCsvFile === '' ? null : $this->readCsv($reportCsvFile, self::CSV_REPORT_HEADER_MAPPING);
		} catch (Exception $error) {
			throw new InvalidInputException('The selected files are not valid CSV files.', $this->l10n->t('The selected files are not valid CSV files.'), previous: $error);
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
	 * Skip the cashbook entries before the first day of the report and create the cashbook from the first entry.
	 *
	 * @throws InvalidInputException If the cashbook does not match the report
	 */
	protected function prepareCashbook(): void {
		$report = null;
		if ($this->reportIterator !== null) {
			$report = $this->currentRecord($this->reportIterator);
			$reportDate = $this->parseDateTime($report['opening']);

			while ($this->cashbookIterator->valid()) {
				$entry = $this->currentRecord($this->cashbookIterator);
				if ($this->parseDateTime($entry['datetime']) >= $reportDate) {
					break;
				}
				$this->cashbookIterator->next();
			}
		}

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

		// The balance before the first entry is needed to compare it with the report
		$initialBalance = $this->parseMoney($entry['total'])->minus($this->parseRecordAmount($entry));
		if ($report !== null && !$initialBalance->isEqualTo($this->parseMoney($report['balanceStart']))) {
			throw new InvalidInputException('The cash balance of the cashbook export does not match the daily closing report.', $this->l10n->t('The cash balance of the cashbook export does not match the daily closing report.'));
		}
	}

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

			$startEntry = Entry::create(
				$this->cashbook,
				$this->getUserId(),
				$opening,
				'',
				EntryReason::DayOpening,
				Money::of('0', $this->cashbook->getCurrency()),
				$openingBalance,
			);

			$this->entryMapper->insert($startEntry);

			$lastEntry = $this->iterateCashbook($closing);
			if (($lastEntry === null && !$closingBalance->isEqualTo($openingBalance))
				|| ($lastEntry !== null && !$lastEntry->getBalance()->isEqualTo($closingBalance))) {
				$this->logger->debug('Mismatch between closing balance and cashbook balance', [
					'opening' => $opening->format('Y-m-d H:i:s'),
					'closingBalance' => (string)$closingBalance,
					'lastEntryBalance' => $lastEntry !== null ? (string)$lastEntry->getBalance() : 'null',
					'openingBalance' => (string)$openingBalance,
				]);
				throw new InvalidInputException(
					sprintf('The cash balance at the day closing of %s does not match the cashbook export.', $record['closing']),
					$this->l10n->t('The cash balance at the day closing of %s does not match the cashbook export.', [$record['closing']]),
				);
			}

			$endEntry = Entry::create(
				$this->cashbook,
				$this->getUserId(),
				$closing,
				'',
				EntryReason::DayClosing,
				Money::of('0', $this->cashbook->getCurrency()),
				$closingBalance,
				reference: $record['znumber'],
			);
			$this->entryMapper->insert($endEntry);

			$reportIterator->next();
		}
	}

	/**
	 * Import the cashbook entries until the given limit is reached.
	 *
	 * @param ?\DateTimeImmutable $limit Stop before the first entry at or after this date (null to import all remaining entries)
	 * @return ?Entry The last imported entry
	 */
	protected function iterateCashbook(?\DateTimeImmutable $limit = null): ?Entry {
		$entry = null;
		while ($this->cashbookIterator->valid()) {
			$record = $this->currentRecord($this->cashbookIterator);
			$datetime = $this->parseDateTime($record['datetime']);

			if (($limit !== null && $datetime >= $limit)) {
				break;
			}

			$entry = Entry::create(
				$this->cashbook,
				$this->getUserId(),
				$datetime,
				$record['text'],
				$this->mapReason($record['reason']),
				$this->parseRecordAmount($record),
				$this->parseMoney($record['total']),
				$this->parseTax($record['tax']),
			);
			$this->entryMapper->insert($entry);

			$this->cashbookIterator->next();
		}
		return $entry;
	}

	/**
	 * Read a CSV file and map its localized header to the internal keys.
	 *
	 * @param array<string, string> $headerMapping
	 * @return Iterator<array-key, CsvRecord>
	 */
	protected function readCsv(string $path, array $headerMapping): Iterator {
		$csv = Reader::from($path, 'r');
		$csv->setHeaderOffset(0);
		$csv->setDelimiter(self::CSV_DELIMITER);
		$csv->setEnclosure(self::CSV_ENCLOSURE);

		$header = array_map(static fn (string $name): string => $headerMapping[$name] ?? $name, $csv->getHeader());
		/** @var Iterator<array-key, CsvRecord> $records */
		$records = $csv->getRecords($header);
		$records->rewind();
		return $records;
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
