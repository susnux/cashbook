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
	 * @param array{name: list<string>, tmp_name: list<string>, ...} $files The uploaded files in the `$_FILES` format of a multi-file field
	 * @param array{timezone?: string} $options Import options, `timezone` is the timezone of dates without timezone information
	 * @return Cashbook The imported cashbook
	 * @throws InvalidInputException If the files could not be imported
	 * @throws \InvalidArgumentException If the options are invalid
	 */
	public function import(array $files, array $options = []): Cashbook;

}
