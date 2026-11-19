<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2025 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cashbook\ImportService;

use OCA\Cashbook\Db\Cashbook;
use OCA\Cashbook\Exception\InvalidInputException;

/**
 * @psalm-api Import services are instantiated by the import API
 */
interface IImportService {

	/**
	 * Import a cashbook from uploaded files.
	 *
	 * @param array<string, string> $files The paths of the uploaded files by their name, the names are defined by the import service
	 * @param array{timezone?: string} $options Import options, `timezone` is the timezone of dates without timezone information and of the imported cashbook
	 * @return Cashbook The imported cashbook
	 * @throws InvalidInputException If the files could not be imported or the timezone is not supported
	 * @throws \InvalidArgumentException If the options are invalid, or files are unknown or missing
	 */
	public function import(array $files, array $options = []): Cashbook;

}
