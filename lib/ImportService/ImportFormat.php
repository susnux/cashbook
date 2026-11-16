<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cashbook\ImportService;

/**
 * Supported formats for importing a cashbook
 */
enum ImportFormat: string {
	case SumupPosProGermany = 'sumup-pos-pro-de';

	/**
	 * Get the import service which handles this format
	 *
	 * @return class-string<IImportService>
	 */
	public function getImportService(): string {
		return match ($this) {
			self::SumupPosProGermany => SumupPosProGermany::class,
		};
	}
}
