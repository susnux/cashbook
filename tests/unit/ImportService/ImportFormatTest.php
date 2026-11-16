<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cashbook\Tests\Unit\ImportService;

use OCA\Cashbook\ImportService\IImportService;
use OCA\Cashbook\ImportService\ImportFormat;
use OCA\Cashbook\ImportService\SumupPosProGermany;
use PHPUnit\Framework\TestCase;

final class ImportFormatTest extends TestCase {

	public function testImportServices(): void {
		$this->assertSame(SumupPosProGermany::class, ImportFormat::SumupPosProGermany->getImportService());

		foreach (ImportFormat::cases() as $format) {
			$this->assertTrue(is_a($format->getImportService(), IImportService::class, true), $format->name);
		}
	}
}
