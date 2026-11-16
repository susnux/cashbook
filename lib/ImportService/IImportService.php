<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2025 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cashbook\ImportService;

use OCA\Cashbook\Db\Cashbook;

/**
 * @psalm-api Import services are instantiated by class name from the API request
 */
interface IImportService {

	/**
	 * Import a cashbook from uploaded files.
	 *
	 * @param array{name: list<string>, tmp_name: list<string>, ...} $files The uploaded files in the `$_FILES` format of a multi-file field
	 * @return Cashbook The imported cashbook
	 * @throws \InvalidArgumentException If the files could not be imported
	 */
	public function import(array $files): Cashbook;

}
